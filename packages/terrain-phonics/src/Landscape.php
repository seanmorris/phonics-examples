<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Authoritative bounded heightfield. Lazy packed nodes feed one C1 reconstruction for
 * rendering, contacts and picking. Edits become visible only at explicit commit boundaries.
 */
final class Landscape implements TerrainSurface
{
	/**
	 * A tile key is (row + TILE_BIAS) * TILE_SPAN + column + TILE_BIAS, and the largest key stays below 2^31 for the
	 * 32-bit browser build. Rows and columns must therefore stay within TILE_BIAS of the origin, or two tiles share a
	 * key. Recipe refuses a world whose edge tile lies beyond TILE_REACH, which leaves room for the halo and
	 * neighbour tiles the stencils read one or two tiles past the edge.
	 */
	private const TILE_BIAS = 20000;
	private const TILE_SPAN = 40000;
	public const TILE_REACH = 19990;
	/** Names of the sixteen stencil nodes in row-major order, as HeightTile::layout() assigns them. */
	private const SLOTS = ['a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p'];
	/** Immutable procedural recipe and normalized parameters. */
	private Recipe $generator;
	private array $parameters;
	/** Half the world edge, the lattice spacing and the tile edge, copied out for the per-query path. */
	private float $half, $spacing;
	private int $tileSize;
	/** LRU tile map; PHP insertion order tracks most recent use without an unbounded queue. */
	private array $tiles = [];
	/** Key and tile of the last entry in the tile map, or -1 and null when that entry is unknown. */
	private int $recentKey = -1;
	private ?HeightTile $recentTile = null;
	/** Tile reads of a stencil, indexed by the local position of its first node. See parts(). */
	private array $stencils = [];
	/** Edit transaction history, current applied prefix, and staged transaction. */
	private array $history = [], $operations = [], $pending = [];
	private ?EditIndex $heightEdits = null;
	private int $cursor = 0, $version = 0;
	/** Residency leases refer to tile keys and survive geometry revisions. */
	private array $leases = [];
	private int $nextLease = 1;
	/** Diagnostics count actual procedural node generation and cache behavior. */
	private int $generated = 0, $hits = 0, $evictions = 0;
	private bool $closed = false;
	/** Conservative extrema of edited lattice nodes. Reconstruction adds an overshoot allowance. */
	private array $range;
	/** Bounded per-query intermediate edit memo, discarded after each lattice node. */
	private array $memo = [];
	/** Bounded caches for ray block intervals and sparsely sampled mesh vertices. */
	private array $blockBounds = [], $meshNodes = [];
	/** Row polynomials of recently sampled physics cells, shared by overlapping hull probes: [row][column]. */
	private array $cellRows = [];
	/** Ring of the cached cells in insertion order. A sixty-fifth cell replaces the oldest. */
	private array $queueX = [], $queueZ = [];
	private int $queueNext = 0, $queueCount = 0;

	/**
	 * Construct an empty lazy cache; the world is centered on X/Z zero.
	 * @param array $recipe Procedural world recipe and bounded cache options.
	 * @return void Initializes recipe and empty bounded residency state.
	 */
	public function __construct(array $recipe = [])
	{
		$this->generator = new Recipe($recipe);
		$this->parameters = $this->generator->values();
		$this->range = $this->generator->bounds();
		$this->half = $this->parameters['worldSize'] / 2;
		$this->spacing = $this->parameters['spacing'];
		$this->tileSize = $this->parameters['tileSize'];
	}

	/**
	 * Return normalized serializable recipe parameters.
	 * @return array Normalized immutable recipe values.
	 */
	public function recipe(): array { return $this->parameters; }

	/**
	 * Share the committed road planes with the rendering index.
	 * @return array Normalized splines in application order.
	 */
	public function roads(): array
	{
		$roads = [];
		foreach($this->operations as $op) if($op['type'] === 'road') $roads[] = $op;
		return $roads;
	}

	/** Portable active roads keyed by their position in the terrain edit order. */
	public function roadRecords(): array
	{
		$records = [];
		foreach($this->operations as $i=>$op) if($op['type'] === 'road') $records[$i] = Edits::serialize($op);
		return $records;
	}

	/**
	 * Index committed edit footprints with the cubic reconstruction halo.
	 * @param bool $includeRoads Include spline footprints when CPU reconstruction is needed there.
	 * @return EditIndex Spatial lookup of affected rectangles in world coordinates.
	 */
	public function editIndex(bool $includeRoads = true): EditIndex
	{
		$extent = $this->parameters['worldSize'];
		$half = $extent / 2;
		$pad = 2 * $this->spacing;
		$boxes = [];
		foreach($this->operations as $operation)
		{
			if(!$includeRoads && $operation['type'] === 'road') continue;
			foreach(Edits::footprints($operation, max(24., 2 * $pad)) as [$x, $z, $r, $b])
				$boxes[] = [max(-$half, $x-$pad), max(-$half, $z-$pad), min($half, $r+$pad), min($half, $b+$pad)];
		}
		return new EditIndex($extent, $boxes);
	}

	/** Conservative footprints of active edits that can change elevation, shared until the next commit. */
	public function heightEditIndex(): EditIndex
	{
		if($this->heightEdits !== null) return $this->heightEdits;
		$pad = 2*$this->spacing;
		$boxes = [];
		foreach($this->operations as $op)
		{
			if($op['type'] === 'material' || ($op['amount'] ?? 1.) == 0.) continue;
			// Use the precomputed road bounds. This lookup costs at most one rectangle per edit.
			[$x,$z,$right,$back] = Edits::bounds($op);
			$boxes[] = [$x-$pad,$z-$pad,$right+$pad,$back+$pad];
		}
		return $this->heightEdits = new EditIndex($this->parameters['worldSize'],$boxes);
	}

	/**
	 * Return the monotonically increasing committed revision.
	 * @return int Monotonically increasing committed surface revision.
	 */
	public function revision(): int { return $this->version; }

	/**
	 * Return interpolated elevation; at most sixteen missing lattice nodes are requested.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @return float Committed reconstructed elevation in meters.
	 */
	public function height(float $x, float $z): float
	{
		$half = $this->half;

		// Every legal query passes this chain without a call. NaN fails it, so coordinates() names the fault.
		if($this->closed || !($x >= -$half && $x <= $half && $z >= -$half && $z <= $half)) $this->coordinates($x, $z);

		$spacing = $this->spacing;
		$u = $x / $spacing;
		$v = $z / $spacing;
		// Truncation plus one step down for a negative fraction equals floor() anywhere inside the world.
		$ix = (int)$u;
		$iz = (int)$v;

		if($u < $ix) $ix--;

		if($v < $iz) $iz--;

		$u -= $ix;
		$v -= $iz;

		// Mesh vertices normally lie on the lattice. Their exact cubic value is the node itself.
		if($u === 0.0 && $v === 0.0) return $this->node($ix, $iz);

		$c = $this->cellRows[$iz][$ix] ?? $this->cell($ix, $iz);
		// Horner's rule along each of the four rows, then Catmull-Rom through the four results.
		$p0 = $c[0] + $u * ($c[1] + $u * ($c[2] + $u * $c[3]));
		$p1 = $c[4] + $u * ($c[5] + $u * ($c[6] + $u * $c[7]));
		$p2 = $c[8] + $u * ($c[9] + $u * ($c[10] + $u * $c[11]));
		$p3 = $c[12] + $u * ($c[13] + $u * ($c[14] + $u * $c[15]));

		return $p1 + $v * (
			.5 * ($p2 - $p0)
			+ $v * (($p0 - 2.5 * $p1 + 2 * $p2 - .5 * $p3) + $v * (.5 * (-$p0 + 3 * $p1 - 3 * $p2 + $p3)))
		);
	}

	/**
	 * Return elevation and exact derivatives of the same reconstructed surface.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @return array Height, derivatives, upward unit normal, material and revision.
	 */
	public function sample(float $x, float $z): array
	{
		$half = $this->half;

		// The same validation and cell lookup as height(), repeated here to keep both free of calls.
		if($this->closed || !($x >= -$half && $x <= $half && $z >= -$half && $z <= $half)) $this->coordinates($x, $z);

		$spacing = $this->spacing;
		$u = $x / $spacing;
		$v = $z / $spacing;
		$ix = (int)$u;
		$iz = (int)$v;

		if($u < $ix) $ix--;

		if($v < $iz) $iz--;

		$u -= $ix;
		$v -= $iz;

		// Mesh vertices normally lie on the lattice. Their exact cubic value/gradient
		// need one/five nodes rather than gathering sixteen zero-weight contributions.
		if($u === 0.0 && $v === 0.0)
		{
			$height = $this->node($ix, $iz);
			$dx = ($this->node($ix + 1, $iz) - $this->node($ix - 1, $iz)) / (2 * $spacing);
			$dz = ($this->node($ix, $iz + 1) - $this->node($ix, $iz - 1)) / (2 * $spacing);
		}
		else
		{
			$c = $this->cellRows[$iz][$ix] ?? $this->cell($ix, $iz);
			// Value and X slope of each row polynomial at u.
			$p0 = $c[0] + $u * ($c[1] + $u * ($c[2] + $u * $c[3]));
			$p1 = $c[4] + $u * ($c[5] + $u * ($c[6] + $u * $c[7]));
			$p2 = $c[8] + $u * ($c[9] + $u * ($c[10] + $u * $c[11]));
			$p3 = $c[12] + $u * ($c[13] + $u * ($c[14] + $u * $c[15]));
			$s0 = $c[1] + $u * (2 * $c[2] + 3 * $u * $c[3]);
			$s1 = $c[5] + $u * (2 * $c[6] + 3 * $u * $c[7]);
			$s2 = $c[9] + $u * (2 * $c[10] + 3 * $u * $c[11]);
			$s3 = $c[13] + $u * (2 * $c[14] + 3 * $u * $c[15]);
			// Catmull-Rom coefficients through the four row values.
			$c1 = .5 * ($p2 - $p0);
			$c2 = $p0 - 2.5 * $p1 + 2 * $p2 - .5 * $p3;
			$c3 = .5 * (-$p0 + 3 * $p1 - 3 * $p2 + $p3);
			$height = $p1 + $v * ($c1 + $v * ($c2 + $v * $c3));

			$dx = ($s1 + $v * (
				.5 * ($s2 - $s0)
				+ $v * (($s0 - 2.5 * $s1 + 2 * $s2 - .5 * $s3) + $v * (.5 * (-$s0 + 3 * $s1 - 3 * $s2 + $s3)))
			)) / $spacing;

			$dz = ($c1 + $v * (2 * $c2 + 3 * $v * $c3)) / $spacing;
		}

		$length = sqrt(1 + $dx * $dx + $dz * $dz);

		return [
			'height' => $height
			, 'dx' => $dx
			, 'dz' => $dz
			, 'normal' => [-$dx / $length, 1 / $length, -$dz / $length]
			// Without edits no operation can paint a material, and the lookup returns null.
			, 'material' => $this->operations ? Edits::material($x, $z, $this->operations) : null
			, 'revision' => $this->version
		];
	}

	/**
	 * Query painted surface material without generating height or normal samples.
	 * @param float $x World horizontal X coordinate in meters.
	 * @param float $z World horizontal Z coordinate in meters.
	 * @return string|null Most recent covering material, or null for recipe shading.
	 */
	public function material(float $x, float $z): ?string
	{
		$this->coordinates($x, $z);

		return Edits::material($x, $z, $this->operations);
	}

	/** Encoded paint sample for areas where later brush edits supersede GPU road grading. */
	public function paintTexel(float $x, float $z): string
	{
		$this->coordinates($x,$z);
		$material = Edits::material($x,$z,$this->operations,$offset);
		return RoadAtlas::paintTexel($material,$offset);
	}

	/**
	 * Validate and stage one operation. A failed enqueue never alters committed geometry.
	 * @param array $operation Validated brush or road parameters to stage.
	 * @return void Stages the validated operation without changing visible geometry.
	 */
	public function queue(array $operation): void
	{
		$this->open();

		if(($operation['type'] ?? null) === 'smooth') $operation['sampleStep'] ??= $this->parameters['spacing'];

		$operation = Edits::normalize($operation);
		$box = Edits::bounds($operation);
		$half = $this->parameters['worldSize'] / 2;

		if($box[0] > $half || $box[2] < -$half || $box[1] > $half || $box[3] < -$half)
		throw new \OutOfBoundsException('Edit does not intersect the world');

		$candidate = self::replay($this->operations, [...$this->pending, $operation]);
		$this->operationLimit($candidate);
		$this->pending[] = $operation;
	}

	/**
	 * Atomically publish the staged transaction; callers commit before simulation integration.
	 * @return bool Whether a nonempty staged transaction was published.
	 */
	public function commit(): bool
	{
		$this->open();

		if(!$this->pending) return false;

		$operations = self::replay($this->operations, $this->pending);
		$range = $this->editedBounds($operations);
		$history = array_slice($this->history, 0, $this->cursor);
		$history[] = array_map([Edits::class, 'serialize'], $this->pending);
		$this->history = $history;
		$this->cursor = count($history);
		$this->pending = [];
		$this->publish($operations, $range);

		return true;
	}

	/**
	 * Undo one committed transaction. Staged edits must be committed or discarded first.
	 * @return bool Whether one committed transaction was undone.
	 */
	public function undo(): bool
	{
		$this->open();

		if($this->pending) throw new \LogicException('Cannot undo with queued edits');

		if($this->cursor === 0) return false;

		$operations = $this->prefix($this->cursor - 1);
		$range = $this->editedBounds($operations);
		$this->cursor--;
		$this->publish($operations, $range);

		return true;
	}

	/**
	 * Reapply one undone transaction without reinterpreting its parameters.
	 * @return bool Whether one undone transaction was restored.
	 */
	public function redo(): bool
	{
		$this->open();

		if($this->pending) throw new \LogicException('Cannot redo with queued edits');

		if($this->cursor >= count($this->history)) return false;

		$operations = $this->prefix($this->cursor + 1);
		$range = $this->editedBounds($operations);
		$this->cursor++;
		$this->publish($operations, $range);

		return true;
	}

	/**
	 * Discard only uncommitted edits, for a canceled authoring transaction.
	 * @return void Clears only the uncommitted transaction.
	 */
	public function discard(): void
	{
		$this->open();
		$this->pending = [];
	}

	/**
	 * Return the conservative dirty rectangle relative to another committed surface. Null means the recipe changed
	 * globally; an empty array means identical edits. Include every operator after the common prefix because later
	 * smoothing/roads can depend on an earlier edit, then add the reconstruction's two-node halo.
	 * @param self $other Committed surface used as the comparison baseline.
	 * @return array|null Dirty rectangle, empty array for no change, or null for a changed recipe.
	 */
	public function changedRegion(self $other): ?array
	{
		$this->open();
		$other->open();

		if($this->parameters !== $other->parameters) return null;

		$index = 0;
		$limit = min(count($this->operations), count($other->operations));

		while($index < $limit && Edits::serialize($this->operations[$index]) === Edits::serialize($other->operations[
			$index
		]))
		$index++;
		$changed = [...array_slice($this->operations, $index), ...array_slice($other->operations, $index)];

		if(!$changed) return [];

		$bounds = [INF,INF,-INF,-INF];

		foreach($changed as $operation)
		{
			$box = Edits::bounds($operation);
			$bounds = [
				min($bounds[0], $box[0]), min($bounds[1], $box[1])
				, max($bounds[2], $box[2]), max($bounds[3], $box[3])
			];
		}

		$halo = 2 * $this->parameters['spacing'];
		$half = $this->parameters['worldSize'] / 2;

		return [
			max(-$half, $bounds[0] - $halo), max(-$half, $bounds[1] - $halo)
			, min($half, $bounds[2] + $halo), min($half, $bounds[3] + $halo)
		];
	}

	/**
	 * Serialize recipe, transaction order and redo state; cache and pending work are omitted.
	 * @return string Portable JSON preserving recipe, transaction order and redo state.
	 */
	public function save(): string
	{
		$this->open();

		if($this->pending) throw new \LogicException('Commit or discard queued edits before saving');

		$format = 1;
		foreach($this->history as $batch) foreach($batch as $edit)
			if(isset($edit['replaceAt']) || isset($edit['handles'])) { $format = 2; break 2; }

		return json_encode([
			'format' => 'terrain-phonics'
			, 'version' => $format
			, 'recipe' => $this->parameters
			, 'history' => $this->history
			, 'cursor' => $this->cursor
			, 'revision' => $this->version
		], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
		);
	}

	/**
	 * Restore validated persistent edits into an empty bounded cache.
	 * @param string $json Serialized terrain document including transaction history.
	 * @return self Validated landscape with an empty lazy sample cache.
	 */
	public static function load(string $json): self
	{
		if(strlen($json) > 4 * 1024 * 1024) throw new \InvalidArgumentException('Terrain document is too large');

		$value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

		if(!is_array($value) || ($value['format'] ?? null) !== 'terrain-phonics' || !in_array($value['version'] ?? null,[1,2],true)
			|| !is_array($value['recipe'] ?? null) || !is_array($value['history'] ?? null)
			|| !is_int($value['cursor'] ?? null) || !is_int($value['revision'] ?? null)
			|| $value['cursor'] < 0 || $value['cursor'] > count($value['history']) || $value['revision'] < 0
		) throw new \InvalidArgumentException('Invalid terrain document');

		$result = new self($value['recipe'] + ['version' => 1]);
		$all = [];

		foreach($value['history'] as $batch)
		{
			if(!is_array($batch) || !array_is_list($batch) || !$batch)
			throw new \InvalidArgumentException('Invalid terrain transaction');

			$normalized = [];

			foreach($batch as $operation)
			{
				if(!is_array($operation)) throw new \InvalidArgumentException('Invalid terrain operation');

				$normalized[] = Edits::normalize($operation);
			}

			$all = self::replay($all, $normalized);
			$result->operationLimit($all);
			$result->history[] = array_map([Edits::class, 'serialize'], $normalized);
			if(count($result->history) === $value['cursor']) $result->operations = $all;
		}

		$result->cursor = $value['cursor'];
		$result->version = $value['revision'];
		$result->range = $result->editedBounds($result->operations);

		return $result;
	}

	/**
	 * Return conservative world bounds, optionally restricting the horizontal query rectangle.
	 * @param array|null $region Horizontal rectangle [minX,minZ,maxX,maxZ] in meters.
	 * @return array Conservative axis-aligned bounds with named minimum and maximum coordinates.
	 */
	public function bounds(?array $region = null): array
	{
		$this->open();
		$half = $this->parameters['worldSize'] / 2;
		$region = $region === null ? [-$half,-$half,$half,$half] : $this->region($region);

		if($region[0] > $half || $region[1] > $half || $region[2] < -$half || $region[3] < -$half)
		throw new \OutOfBoundsException('Bounds rectangle does not intersect the world');

		[$min,$max] = $this->range;
		// Tensor Catmull-Rom has total absolute weight at most 1.25 squared.
		$padding = .3 * ($max - $min) + 1e-7;

		return [
			'minX' => max(-$half, $region[0])
			, 'minY' => $min - $padding
			, 'minZ' => max(-$half, $region[1])
			, 'maxX' => min($half, $region[2])
			, 'maxY' => $max + $padding
			, 'maxZ' => min($half, $region[3])
		];
	}

	/**
	 * Bound the surface over the square block of lattice cells around a point, generating no nodes. Blocks of one
	 * size tile the lattice from its origin. Every height() with minX <= x < maxX and minZ <= z < maxZ lies between
	 * minY and maxY, so a caller following a moving point can keep the answer until the point leaves the rectangle
	 * or the revision changes. A smaller block gives a tighter interval.
	 * @param float $x World X coordinate in meters of a point inside the block.
	 * @param float $z World Z coordinate in meters of a point inside the block.
	 * @param int $cells Number of lattice cells along each edge of the block.
	 * @return array Block rectangle and conservative elevations, named as in bounds().
	 */
	public function localBounds(float $x, float $z, int $cells): array
	{
		$this->coordinates($x, $z);

		if($cells < 1) throw new \InvalidArgumentException('Terrain block needs at least one cell');

		$spacing = $this->parameters['spacing'];
		$left = (int)floor(floor($x / $spacing) / $cells) * $cells;
		$front = (int)floor(floor($z / $spacing) / $cells) * $cells;
		[$low,$high] = $this->blockRange($left, $front, $cells);

		return [
			'minX' => $left * $spacing
			, 'minY' => $low
			, 'minZ' => $front * $spacing
			, 'maxX' => ($left + $cells) * $spacing
			, 'maxY' => $high
			, 'maxZ' => ($front + $cells) * $spacing
		];
	}

	/**
	 * Bound a world-aligned render block in lattice cells, including fine-to-coarse height samples.
	 * @param int $column Left block edge.
	 * @param int $row Front block edge.
	 * @param int $size Cells along each block edge.
	 * @param int $halo Extra cells neighboring the block that can contribute to morphed heights.
	 * @return array Lower and upper elevation limits.
	 */
	public function renderBlockRange(int $column, int $row, int $size, int $halo): array
	{
		return $this->blockRange($column - $halo, $row - $halo, $size + 2 * $halo);
	}

	/**
	 * Reserve all tile blocks supporting a rectangle, including its interpolation halo. Reservation is atomic and
	 * generates no height samples. Refuse leases exceeding capacity.
	 * @param array $region Horizontal rectangle [minX,minZ,maxX,maxZ] in meters.
	 * @return int New atomic residency lease token.
	 */
	public function pin(array $region): int
	{
		$this->open();
		$region = $this->region($region);
		$half = $this->parameters['worldSize'] / 2;

		if($region[0] < -$half || $region[1] < -$half || $region[2] > $half || $region[3] > $half)
		throw new \OutOfBoundsException('Residency rectangle outside world');

		$spacing = $this->parameters['spacing'];
		$size = $this->parameters['tileSize'];
		$x0 = (int)floor((floor($region[0] / $spacing) - 1) / $size);
		$z0 = (int)floor((floor($region[1] / $spacing) - 1) / $size);
		$x1 = (int)floor((floor($region[2] / $spacing) + 2) / $size);
		$z1 = (int)floor((floor($region[3] / $spacing) + 2) / $size);

		if(($x1 - $x0 + 1) * ($z1 - $z0 + 1) > $this->parameters['maxTiles'])
		throw new \OverflowException('Residency request exceeds terrain cache budget');

		$keys = [];

		for($z = $z0; $z <= $z1; $z++)
		for($x = $x0; $x <= $x1; $x++) $keys[($z + self::TILE_BIAS) * self::TILE_SPAN + $x + self::TILE_BIAS] = true;
		$pinned = $keys;

		foreach($this->tiles as $key => $tile) if($tile->pins) $pinned[$key] = true;

		if(count($pinned) > $this->parameters['maxTiles'])
		throw new \OverflowException('Residency leases exceed terrain cache budget');

		foreach($keys as $key => $_)
		{
			$tile = $this->tile($key);

			if($tile === null) throw new \LogicException('Validated residency capacity was lost');

			$tile->pins++;
		}

		$token = $this->nextLease++;
		$this->leases[$token] = array_keys($keys);

		return $token;
	}

	/**
	 * Release a residency lease; unknown or already released tokens are harmless.
	 * @param int $token Residency lease token returned by pin().
	 * @return void Releases the lease without deleting other users' reservations.
	 */
	public function unpin(int $token): void
	{
		foreach($this->leases[$token] ?? [] as $key) if(isset($this->tiles[$key])) $this->tiles[$key]->pins--;
		unset($this->leases[$token]);
	}

	/**
	 * Return bounded-cache statistics, including reserved payload rather than a misleading sample count.
	 * @return array Cache, edit and revision diagnostics.
	 */
	public function stats(): array
	{
		$bytes = 0;
		$samples = 0;
		$pinned = 0;

		foreach($this->tiles as $tile)
		{
			$bytes += $tile->bytes();
			$samples += $tile->samples;
			$pinned += (int)($tile->pins > 0);
		}

		return [
			'tiles' => count($this->tiles)
			, 'meshSamples' => count($this->meshNodes)
			, 'samples' => $samples
			, 'pinnedTiles' => $pinned
			, 'cacheBytes' => $bytes
			, 'maxTiles' => $this->parameters['maxTiles']
			, 'maxCacheBytes' => $this->parameters['maxTiles'] * $this->parameters['tileSize'] ** 2 * 9
			, 'generated' => $this->generated
			, 'cacheHits' => $this->hits
			, 'evictions' => $this->evictions
			, 'revision' => $this->version
			, 'pending' => count($this->pending)
			, 'operations' => count($this->operations)
			, 'closed' => $this->closed
		];
	}

	/**
	 * Retire samples, leases and edits. Closing repeatedly is safe.
	 * @return void Permanently releases all landscape-owned samples, leases and history.
	 */
	public function close(): void
	{
		$this->heightEdits = null;
		$this->tiles = $this->leases = $this->history = $this->operations = $this->pending = $this->memo = [];
		$this->blockBounds = $this->meshNodes = $this->cellRows = $this->queueX = $this->queueZ = [];
		$this->queueNext = $this->queueCount = 0;
		$this->recentKey = -1;
		$this->recentTile = null;
		$this->closed = true;
	}

	/**
	 * Intersect a world-space ray with the authoritative bicubic surface. Each crossed lattice cell becomes a
	 * degree-six polynomial; Bernstein subdivision cannot skip a narrow crest merely because both cell endpoints lie
	 * above it.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $maxDistance Maximum ray travel in meters.
	 * @return array|null Nearest surface hit with distance, position, normal, material and revision, or null.
	 */
	public function raycast(array $origin, array $direction, float $maxDistance = 10000): ?array
	{
		$steps = $this->raycastSteps($origin, $direction, $maxDistance);

		foreach($steps as $_)
		{
			/* Compatibility API deliberately drains the exact query. */
		}

		return $steps->getReturn();
	}

	/**
	 * Create a resumable exact picker without generating any terrain cells immediately.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $maxDistance Maximum ray travel in meters.
	 * @return RayQuery Revision-guarded query that the caller advances within its frame budget.
	 */
	public function rayQuery(array $origin, array $direction, float $maxDistance = 10000): RayQuery
	{
		$this->open();

		return new RayQuery($this, $origin, $direction, $maxDistance);
	}

	/**
	 * Bound reconstructed heights in a square of physical cells. A smooth edit can
	 * depend on earlier terrain outside this square, so expand its input rectangle
	 * before evaluating preceding operations. All other edit bounds are local.
	 * @param int $x First cell column.
	 * @param int $z First cell row.
	 * @param int $size Number of cells per edge.
	 * @return array Conservative lower and upper reconstructed heights.
	 */
	private function blockRange(int $x, int $z, int $size): array
	{
		$key = "$size:$x:$z";
		if(isset($this->blockBounds[$key])) return $this->blockBounds[$key];
		$s = $this->parameters['spacing'];
		$box = [($x-1)*$s,($z-1)*$s,($x+$size+1)*$s,($z+$size+1)*$s];
		$affecting = [];
		for($i=count($this->operations)-1; $i>=0; $i--)
		{
			$op = $this->operations[$i];
			$b = Edits::bounds($op);
			if($b[0]>$box[2] || $b[2]<$box[0] || $b[1]>$box[3] || $b[3]<$box[1]) continue;
			$affecting[] = $i;
			if($op['type']==='smooth') $box = [$box[0]-$s,$box[1]-$s,$box[2]+$s,$box[3]+$s];
		}
		[$lo,$hi] = $this->generator->regionalBounds($box);
		foreach(array_reverse($affecting) as $i)
			[$lo,$hi] = Edits::heightBounds($this->operations[$i], $lo, $hi);
		// The absolute weight sum of a 1D Catmull-Rom stencil is <= 5/4.
		// Tensor weights sum to one, hence their negative mass is <= 9/32.
		$pad = 9/32 * ($hi-$lo) + 1e-8*(1+max(abs($lo),abs($hi)));
		if(count($this->blockBounds)>=4096) unset($this->blockBounds[array_key_first($this->blockBounds)]);
		return $this->blockBounds[$key] = [$lo-$pad,$hi+$pad];
	}

	/**
	 * Identify the cell entered at a boundary using the same crossing time as traversal.
	 * @param float $origin Ray origin along this axis.
	 * @param float $direction Normalized ray component.
	 * @param float $time Distance traveled.
	 * @param float $spacing Lattice spacing.
	 * @return int Index on the forward side of a crossed edge.
	 */
	private static function enteredCell(float $origin, float $direction, float $time, float $spacing): int
	{
		$cell = (int)floor(($origin + $direction * $time) / $spacing);
		if(abs($direction) < 1e-14) return $cell;
		$edge = ($cell + (int)($direction > 0)) * $spacing;
		if(($edge - $origin) / $direction <= $time) $cell += $direction > 0 ? 1 : -1;
		return $cell;
	}

	/**
	 * Traverse exact interpolation cells cooperatively, yielding after bounded blocks and cells.
	 * The generator returns the same nearest hit as raycast; resumption rejects changed
	 * geometry. One cell and its bounded polynomial isolation remain an indivisible unit.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $maxDistance Maximum ray travel in meters.
	 * @return \Generator Completed work-unit counts, followed by an exact hit or null as the return value.
	 */
	public function raycastSteps(array $origin, array $direction, float $maxDistance = 10000): \Generator
	{
		$this->open();
		$origin = $this->vector($origin);
		$direction = $this->vector($direction);
		$revision = $this->version;

		if(!is_finite($maxDistance) || $maxDistance <= 0 || $maxDistance > 1000000)
		throw new \InvalidArgumentException('Invalid ray distance');

		$scale = max(array_map('abs', $direction));

		if($scale === 0.0) throw new \InvalidArgumentException('Ray direction must be nonzero');

		$direction = array_map(/** Scale a direction component before computing its length.
		 * @param float $v Direction component.
		 * @return float Transformed direction component.
		 */
			static fn(float $v): float => $v / $scale, $direction
		);

		$length = sqrt(array_sum(array_map(/** Square a safely scaled direction component.
		 * @param float $v Direction component.
		 * @return float Transformed direction component.
		 */
			static fn(float $v): float => $v * $v, $direction
		)));

		$direction = array_map(/** Normalize a direction component to unit length.
		 * @param float $v Direction component.
		 * @return float Transformed direction component.
		 */
			static fn(float $v): float => $v / $length, $direction
		);

		$bounds = $this->bounds();
		$near = 0.0;
		$far = $maxDistance;

		foreach([['minX','maxX'],['minY','maxY'],['minZ','maxZ']] as $axis => [$min,$max])
		{
			if(abs($direction[$axis]) < 1e-14)
			{
				if($origin[$axis] < $bounds[$min] || $origin[$axis] > $bounds[$max]) return null;

				continue;
			}

			$a = ($bounds[$min] - $origin[$axis]) / $direction[$axis];
			$b = ($bounds[$max] - $origin[$axis]) / $direction[$axis];
			$near = max($near, min($a, $b));
			$far = min($far, max($a, $b));

			if($far < $near) return null;
		}

		$spacing = $this->parameters['spacing'];
		$time = $near;
		$cells = 0;
		$tested = [];
		yield 0;

		while($time <= $far && $cells++ < 300000)
		{
			$this->open();

			if($this->version !== $revision) throw new \LogicException('Terrain changed during ray traversal');

			$ix = self::enteredCell($origin[0], $direction[0], $time, $spacing);
			$iz = self::enteredCell($origin[2], $direction[2], $time, $spacing);

			foreach([128,16,4] as $size)
			{
				$bx = (int)floor($ix/$size)*$size;
				$bz = (int)floor($iz/$size)*$size;
				if(($tested[$size] ?? null) === [$bx,$bz]) continue;
				$tested[$size] = [$bx,$bz];
				$end = $far;
				foreach([[0,$bx],[2,$bz]] as [$axis,$first])
				{
					$d = $direction[$axis];
					if(abs($d)<1e-14) continue;
					$edge = ($first + ($d>0 ? $size : 0))*$spacing;
					$end = min($end, ($edge-$origin[$axis])/$d);
				}
				if($end <= $time) continue;
				[$low,$high] = $this->blockRange($bx,$bz,$size);
				$y0 = $origin[1]+$direction[1]*$time;
				$y1 = $origin[1]+$direction[1]*$end;
				if(min($y0,$y1) <= $high && max($y0,$y1) >= $low) continue;
				if($end >= $far) return null;
				$time = $end;
				yield $cells;
				continue 2;
			}
			$end = $far;
			foreach([[0,$ix],[2,$iz]] as [$axis,$cell])
			{
				if(abs($direction[$axis]) < 1e-14) continue;
				$edge = ($cell + (int)($direction[$axis] > 0)) * $spacing;
				$crossing = ($edge - $origin[$axis]) / $direction[$axis];
				if($crossing > $time) $end = min($end, $crossing);
			}
			$rows = $this->patch($ix, $iz);
			$lo = min(min($rows[0]),min($rows[1]),min($rows[2]),min($rows[3]));
			$hi = max(max($rows[0]),max($rows[1]),max($rows[2]),max($rows[3]));
			$pad = 9/32*($hi-$lo) + 1e-8*(1+max(abs($lo),abs($hi)));
			$y0 = $origin[1]+$direction[1]*$time;
			$y1 = $origin[1]+$direction[1]*$end;
			if(min($y0,$y1) > $hi+$pad || max($y0,$y1) < $lo-$pad)
			{
				if($end >= $far) return null;
				$time = $end;
				yield $cells;
				continue;
			}
			$polynomial = $this->rayPolynomial($rows, $origin, $direction, $time, $end, $ix, $iz);
			$bernstein = [];

			for($i = 0; $i <= 6; $i++)
			{
				$bernstein[$i] = 0.0;

				for($j = 0; $j <= $i; $j++)
				$bernstein[$i] += $polynomial[$j] * self::choose($i, $j) / self::choose(6, $j);
			}

			$budget = 4096;
			$root = $this->root($bernstein, 0.0, 1.0, 1e-6 / max(1.0, $end - $time), $budget);

			if($root !== null)
			{
				$distance = $time + $root * ($end - $time);
				$point = [
					$origin[0] + $direction[0] * $distance
					, $origin[1] + $direction[1] * $distance
					, $origin[2] + $direction[2] * $distance
				];

				$half = $this->parameters['worldSize'] / 2;
				$point[0] = max(-$half, min($half, $point[0]));
				$point[2] = max(-$half, min($half, $point[2]));
				$sample = $this->sample($point[0], $point[2]);

				return [
					'distance' => $distance
					, 'position' => $point
					, 'normal' => $sample['normal']
					, 'material' => $sample['material']
					, 'revision' => $this->version
				];
			}

			if($end >= $far) return null;

			$time = $end;
			yield $cells;
		}

		if($cells >= 300000) throw new \RuntimeException('Ray exceeded bounded cell traversal');

		return null;
	}

	/**
	 * Gather a cell's stencil once and remember its four row polynomials. At most sixty-four cells stay cached.
	 * @param int $ix Lattice column of the cell.
	 * @param int $iz Lattice row of the cell.
	 * @return array Sixteen power-basis coefficients, four for each stencil row.
	 */
	private function cell(int $ix, int $iz): array
	{
		$slot = $this->queueNext;

		// The oldest cell leaves before the gather. A failed generation therefore still costs that cell.
		if($this->queueCount >= 64)
		{
			$oldest = $this->queueZ[$slot];
			unset($this->cellRows[$oldest][$this->queueX[$slot]]);

			if(!$this->cellRows[$oldest]) unset($this->cellRows[$oldest]);

			$this->queueCount--;
		}

		[
			'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d
			, 'e' => $e, 'f' => $f, 'g' => $g, 'h' => $h
			, 'i' => $i, 'j' => $j, 'k' => $k, 'l' => $l
			, 'm' => $m, 'n' => $n, 'o' => $o, 'p' => $p
		] = $this->stencil($ix, $iz);

		// Each line converts one row of four nodes exactly as cubic() does.
		$rows = [
			$b, .5 * ($c - $a), $a - 2.5 * $b + 2 * $c - .5 * $d, .5 * (-$a + 3 * $b - 3 * $c + $d)
			, $f, .5 * ($g - $e), $e - 2.5 * $f + 2 * $g - .5 * $h, .5 * (-$e + 3 * $f - 3 * $g + $h)
			, $j, .5 * ($k - $i), $i - 2.5 * $j + 2 * $k - .5 * $l, .5 * (-$i + 3 * $j - 3 * $k + $l)
			, $n, .5 * ($o - $m), $m - 2.5 * $n + 2 * $o - .5 * $p, .5 * (-$m + 3 * $n - 3 * $o + $p)
		];

		$this->cellRows[$iz][$ix] = $rows;
		$this->queueX[$slot] = $ix;
		$this->queueZ[$slot] = $iz;
		$this->queueNext = ($slot + 1) & 63;
		$this->queueCount++;

		return $rows;
	}

	/**
	 * Gather a shared four-by-four stencil, including globally addressed tile halos.
	 * @param int $x World X coordinate, or integer lattice column for node access.
	 * @param int $z World Z coordinate, or integer lattice row for node access.
	 * @return array Four rows of four neighboring node elevations.
	 */
	private function patch(int $x, int $z): array
	{
		$s = $this->stencil($x, $z);

		return [
			[$s['a'], $s['b'], $s['c'], $s['d']]
			, [$s['e'], $s['f'], $s['g'], $s['h']]
			, [$s['i'], $s['j'], $s['k'], $s['l']]
			, [$s['m'], $s['n'], $s['o'], $s['p']]
		];
	}

	/**
	 * Gather the sixteen nodes around a cell. The result, the counters and the tile order match sixteen node()
	 * calls in row-major order. Resident tiles answer with one read each instead of one per node.
	 * @param int $x Lattice column of the cell.
	 * @param int $z Lattice row of the cell.
	 * @return array Node elevations keyed 'a' to 'p' in row-major order, in no particular array order.
	 */
	private function stencil(int $x, int $z): array
	{
		$size = $this->tileSize;
		$lx = ($x - 1) % $size;
		$lz = ($z - 1) % $size;

		if($lx < 0) $lx += $size;

		if($lz < 0) $lz += $size;

		// Both differences are multiples of the tile size, so the quotients stay integers.
		$key = (($z - 1 - $lz) / $size + self::TILE_BIAS) * self::TILE_SPAN + ($x - 1 - $lx) / $size + self::TILE_BIAS;
		$parts = $this->stencils[$lz * $size + $lx] ?? $this->parts($lx, $lz);
		$values = null;

		foreach($parts as [$offset, $layout, $px, $pz])
		{
			$tile = $this->tiles[$key + $offset] ?? null;

			// Creating a tile can evict another one at any node. Only the node-by-node path reproduces that.
			if($tile === null)
			{
				$values = [];

				for($slot = 0; $slot < 16; $slot++)
				$values[self::SLOTS[$slot]] = $this->node($x - 1 + ($slot & 3), $z - 1 + ($slot >> 2));

				return $values;
			}

			$block = $tile->block($layout, $px, $pz);
			$values = $values === null ? $block : $values + $block;
		}

		// A hole reads as NaN and a sum of finite heights never does, so one sum finds any hole.
		$sum = $values['a'] + $values['b'] + $values['c'] + $values['d'] + $values['e'] + $values['f']
			+ $values['g'] + $values['h'] + $values['i'] + $values['j'] + $values['k'] + $values['l']
			+ $values['m'] + $values['n'] + $values['o'] + $values['p'];

		if($sum === $sum)
		{
			// Sixteen hits leave the tiles most recently used in the order of the parts.
			foreach($parts as [$offset])
			{
				$used = $key + $offset;

				if($used === $this->recentKey) continue;

				$tile = $this->tiles[$used];
				unset($this->tiles[$used]);
				$this->tiles[$used] = $tile;
				$this->recentKey = $used;
				$this->recentTile = $tile;
			}

			$this->hits += 16;

			return $values;
		}

		// Some nodes are missing. Visit all sixteen in order, so that a generation which throws leaves the
		// tile order and the counters exactly where sixteen node() calls would.
		$columns = $size - $lx;
		$rows = $size - $lz;

		for($slot = 0; $slot < 16; $slot++)
		{
			$i = $slot & 3;
			$j = $slot >> 2;
			$left = $i < $columns;
			$top = $j < $rows;
			$used = $key + ($left ? 0 : 1) + ($top ? 0 : self::TILE_SPAN);

			if($used !== $this->recentKey) $this->tile($used);

			$name = self::SLOTS[$slot];
			$value = $values[$name];

			if($value === $value)
			{
				$this->hits++;

				continue;
			}

			// Unedited terrain is the recipe itself. generateNode() adds only the edit history.
			$value = $this->operations
				? $this->generateNode($x - 1 + $i, $z - 1 + $j)
				: $this->generator->height(($x - 1 + $i) * $this->spacing, ($z - 1 + $j) * $this->spacing);

			$this->generated++;
			$this->recentTile->set($left ? $lx + $i : $i - $columns, $top ? $lz + $j : $j - $rows, $value);
			$values[$name] = $value;
		}

		return $values;
	}

	/**
	 * Plan the tile reads for a stencil whose first node sits at a given local position. A stencil continues
	 * into the tile to the right, the tile below, or both, when fewer than four nodes remain before the edge.
	 * @param int $lx Local column of the stencil's first node.
	 * @param int $lz Local row of the stencil's first node.
	 * @return array Parts in the order node() first reaches them, each [key offset, layout, local x, local z].
	 */
	private function parts(int $lx, int $lz): array
	{
		$size = $this->tileSize;
		$columns = min(4, $size - $lx);
		$rows = min(4, $size - $lz);
		$parts = [[0, HeightTile::layout($size, 0, $columns, 0, $rows), $lx, $lz]];

		if($columns < 4) $parts[] = [1, HeightTile::layout($size, $columns, 4 - $columns, 0, $rows), 0, $lz];

		if($rows < 4) $parts[] = [self::TILE_SPAN, HeightTile::layout($size, 0, $columns, $rows, 4 - $rows), $lx, 0];

		if($columns < 4 && $rows < 4)
		{
			$layout = HeightTile::layout($size, $columns, 4 - $columns, $rows, 4 - $rows);
			$parts[] = [self::TILE_SPAN + 1, $layout, 0, 0];
		}

		return $this->stencils[$lz * $size + $lx] = $parts;
	}

	/**
	 * Request a single lazy node, bypassing storage when every cache tile is pinned elsewhere.
	 * @param int $x World X coordinate, or integer lattice column for node access.
	 * @param int $z World Z coordinate, or integer lattice row for node access.
	 * @return float Cached or freshly generated lattice-node height.
	 */
	private function node(int $x, int $z): float
	{
		$size = $this->tileSize;
		$lx = $x % $size;
		$lz = $z % $size;

		if($lx < 0) $lx += $size;

		if($lz < 0) $lz += $size;

		// Both differences are multiples of the tile size, so the quotients stay integers.
		$key = (($z - $lz) / $size + self::TILE_BIAS) * self::TILE_SPAN + ($x - $lx) / $size + self::TILE_BIAS;
		// Moving the last tile of the map to its end changes nothing, so that case needs no call.
		$tile = $key === $this->recentKey ? $this->recentTile : $this->tile($key);
		$value = $tile?->get($lx, $lz);

		if($value !== null)
		{
			$this->hits++;

			return $value;
		}

		// Unedited terrain is the recipe itself. generateNode() adds only the edit history.
		$value = $this->operations
			? $this->generateNode($x, $z)
			: $this->generator->height($x * $this->spacing, $z * $this->spacing);

		$this->generated++;
		$tile?->set($lx, $lz, $value);

		return $value;
	}

	/**
	 * Evaluate the ordered edit prefix with bounded intermediate smoothing memoization.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @param int $count Number of ordered edit operations to apply.
	 * @return float Node elevation after the requested edit prefix.
	 */
	private function edited(float $x, float $z, int $count): float
	{
		$key = $count . ':' . sprintf('%.9F:%.9F', $x, $z);

		if(isset($this->memo[$key])) return $this->memo[$key];

		if(count($this->memo) >= 65536)
		throw new \OverflowException('Terrain edit evaluation exceeds bounded workspace');

		if($count === 0) return $this->memo[$key] = $this->generator->height($x, $z);

		$operation = $this->operations[$count - 1];
		$value = $this->edited($x, $z, $count - 1);

		return $this->memo[$key] = Edits::apply(
			$x
			, $z
			, $value
			, $operation
			, /** Evaluate the surface immediately before the current smoothing operation.
			 * @param float $px Neighbor world X coordinate.
			 * @param float $pz Neighbor world Z coordinate.
			 * @return float Previous-stage neighbor elevation.
			 */
			fn(float $px, float $pz): float => $this->edited($px, $pz, $count - 1)
		);
	}

	/**
	 * Sample a coarse mesh without allocating an entire physical tile for one lattice point.
	 * @param float $wx World X in meters.
	 * @param float $wz World Z in meters.
	 * @param float $step Mesh spacing in meters.
	 * @return float Authoritative reconstructed height.
	 */
	public function meshHeight(float $wx, float $wz, float $step): float
	{
		$s = $this->parameters['spacing'];
		if($step < $s*$this->parameters['tileSize']) return $this->height($wx,$wz);
		$this->coordinates($wx,$wz);
		$x = $wx/$s; $z = $wz/$s;
		if($x !== floor($x) || $z !== floor($z)) return $this->height($wx,$wz);
		$key = (int)$x . ':' . (int)$z;
		if(isset($this->meshNodes[$key]))
		{
			$this->hits++;
			return $this->meshNodes[$key];
		}
		if(count($this->meshNodes)>=4096) unset($this->meshNodes[array_key_first($this->meshNodes)]);
		$this->generated++;
		return $this->meshNodes[$key] = $this->generateNode((int)$x,(int)$z);
	}

	/**
	 * Evaluate a lattice node through the committed edit history.
	 * @param int $x Lattice column.
	 * @param int $z Lattice row.
	 * @return float Edited height in meters.
	 */
	private function generateNode(int $x, int $z): float
	{
		if(!$this->operations)
		$value = $this->generator->height($x * $this->parameters['spacing'], $z * $this->parameters['spacing']);
		else
		{
			$this->memo = [];

			try
			{
				$value = $this->edited(
					$x * $this->parameters['spacing']
					, $z * $this->parameters['spacing']
					, count($this->operations)
				);
			}
			finally
			{
				$this->memo = [];
			}
		}

		return $value;
	}

	/**
	 * Get/create an LRU tile without exceeding the configured tile count.
	 * @param int $key Globally addressed tile cache key.
	 * @return HeightTile|null Existing or newly reserved tile, or null when all capacity is pinned elsewhere.
	 */
	private function tile(int $key): ?HeightTile
	{
		if(isset($this->tiles[$key]))
		{
			$tile = $this->tiles[$key];
			unset($this->tiles[$key]);
			$this->tiles[$key] = $tile;
			$this->recentKey = $key;

			return $this->recentTile = $tile;
		}

		if(count($this->tiles) >= $this->parameters['maxTiles'])
		{
			foreach($this->tiles as $old => $tile)
			{
				if($tile->pins) continue;

				unset($this->tiles[$old]);
				$this->evictions++;
				break;
			}

			if(count($this->tiles) >= $this->parameters['maxTiles']) return null;
		}

		$this->recentKey = $key;

		return $this->tiles[$key] = $this->recentTile = new HeightTile($this->parameters['tileSize'], $this->version);
	}

	/**
	 * Publish a prepared revision and preserve empty reserved tiles for active leases.
	 * @param array $operations Ordered normalized edit operations.
	 * @param array $range Prepared conservative node-height extrema.
	 * @return void Replaces committed geometry and cache state under one revision.
	 */
	private function publish(array $operations, array $range): void
	{
		$tiles = [];
		$version = $this->version + 1;

		foreach($this->tiles as $key => $old)
		{
			if(!$old->pins) continue;

			$tile = new HeightTile($old->size, $version);
			$tile->pins = $old->pins;
			$tiles[$key] = $tile;
		}

		$this->blockBounds = $this->meshNodes = $this->cellRows = $this->queueX = $this->queueZ = [];
		$this->queueNext = $this->queueCount = 0;
		$this->recentKey = -1;
		$this->recentTile = null;
		$this->operations = $operations;
		$this->heightEdits = null;
		$this->range = $range;
		$this->version = $version;
		$this->tiles = $tiles;
	}

	/**
	 * Merge transaction prefixes while preserving operator order.
	 * @param int $cursor Number of committed transactions to include.
	 * @return array Flat ordered operations from the requested transaction prefix.
	 */
	private function prefix(int $cursor): array
	{
		$result = [];

		foreach(array_slice($this->history, 0, $cursor) as $batch) $result = self::replay($result,$batch);
		return array_map([Edits::class, 'normalize'], $result);
	}

	/** Resolve replacements at the original position so later brushes retain their effect. */
	private static function replay(array $operations, array $batch): array
	{
		foreach($batch as $edit) {
			if(!isset($edit['replaceAt'])) { $operations[] = $edit; continue; }
			$i = $edit['replaceAt'];
			if(($operations[$i]['type'] ?? null) !== 'road')
				throw new \InvalidArgumentException('The road to replace does not exist.');
			unset($edit['replaceAt']);
			$operations[$i] = $edit;
		}
		return $operations;
	}

	/**
	 * Bound both persistent log size and the branching cost of smoothing.
	 * @param array $operations Ordered normalized edit operations.
	 * @return void Rejects histories exceeding bounded edit or road work.
	 */
	private function operationLimit(array $operations): void
	{
		if(count($operations) > 128) throw new \OverflowException('Terrain v1 supports at most 128 edit operations');

		$smooth = 0;
		$kernels = 0;

		foreach($operations as $operation)
		{
			$smooth += (int)($operation['type'] === 'smooth');

			if($operation['type'] === 'smooth' && $operation['sampleStep'] !== $this->parameters['spacing'])
			throw new \InvalidArgumentException('Smoothing sampleStep must equal terrain lattice spacing');

			if($operation['type'] === 'road') $kernels += count($operation['_road']['samples']);
		}

		if($smooth > 8) throw new \OverflowException('Terrain v1 supports at most eight smoothing operations');

		if($kernels > 16384) throw new \OverflowException('Road history exceeds 16384 total kernels');
	}

	/**
	 * Propagate conservative node ranges through all committed operators.
	 * @param array $operations Ordered normalized edit operations.
	 * @return array Conservative minimum and maximum edited node elevation.
	 */
	private function editedBounds(array $operations): array
	{
		[$min,$max] = $this->generator->bounds();

		foreach($operations as $operation) [$min,$max] = Edits::heightBounds($operation, $min, $max);

		if(!is_finite($min) || !is_finite($max)) throw new \InvalidArgumentException('Edit bounds must remain finite');

		return [$min,$max];
	}

	/**
	 * Reject non-finite or out-of-world public queries; hidden interpolation halo nodes remain legal.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @return void Rejects non-finite, closed or out-of-world public queries.
	 */
	private function coordinates(float $x, float $z): void
	{
		$this->open();
		$half = $this->parameters['worldSize'] / 2;

		if(!is_finite($x) || !is_finite($z)) throw new \InvalidArgumentException('Terrain coordinates must be finite');

		if(abs($x) > $half || abs($z) > $half) throw new \OutOfBoundsException('Terrain coordinate outside world');
	}

	/**
	 * Validate ordered finite horizontal rectangle coordinates.
	 * @param array $region Horizontal rectangle [minX,minZ,maxX,maxZ] in meters.
	 * @return array Validated finite rectangle with ascending bounds.
	 */
	private function region(array $region): array
	{
		if(count($region) !== 4 || !array_is_list($region))
		throw new \InvalidArgumentException('Expected [minX,minZ,maxX,maxZ]');

		foreach($region as $value)
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('Region coordinates must be finite');
		$region = array_map('floatval', $region);

		if($region[0] > $region[2] || $region[1] > $region[3])
		throw new \InvalidArgumentException('Region bounds are reversed');

		return $region;
	}

	/**
	 * Validate a finite three-dimensional vector.
	 * @param array $vector Finite three-component coordinate vector.
	 * @return array Validated floating-point vector.
	 */
	private function vector(array $vector): array
	{
		if(count($vector) !== 3 || !array_is_list($vector))
		throw new \InvalidArgumentException('Expected [x,y,z] vector');

		foreach($vector as $value)
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('Vector coordinates must be finite');

		return array_map('floatval', $vector);
	}

	/**
	 * Guard all operations requiring live landscape ownership.
	 * @return void Rejects operations on a retired landscape.
	 */
	private function open(): void { if($this->closed) throw new \LogicException('Landscape is closed'); }

	/**
	 * Convert four uniformly spaced samples to interpolating cubic coefficients.
	 * @param array $p Four consecutive lattice samples.
	 * @return array Four power-basis coefficients for Catmull-Rom interpolation.
	 */
	private static function cubic(array $p): array
	{
		return [
			$p[1]
			, .5 * ($p[2] - $p[0])
			, $p[0] - 2.5 * $p[1] + 2 * $p[2] - .5 * $p[3]
			, .5 * (-$p[0] + 3 * $p[1] - 3 * $p[2] + $p[3])
		];
	}

	/**
	 * Small exact binomial coefficients used for polynomial basis conversion.
	 * @param int $n Nonnegative binomial order.
	 * @param int $k Selected binomial index.
	 * @return float Small exact binomial coefficient represented as a float.
	 */
	private static function choose(int $n, int $k): float
	{
		$result = 1.0;

		for($i = 1; $i <= $k; $i++) $result *= ($n - $i + 1) / $i;

		return $result;
	}

	/**
	 * Substitute a normalized cell ray interval into the tensor cubic, subtracting ray height.
	 * @param array $rows Four-by-four shared height stencil.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $start Ray distance at the start of this lattice interval.
	 * @param float $end Ray distance at the end of this lattice interval.
	 * @param int $ix Integer X coordinate of the crossed lattice cell.
	 * @param int $iz Integer Z coordinate of the crossed lattice cell.
	 * @return array Seven power-basis coefficients for terrain minus ray height.
	 */
	private function rayPolynomial(
		array $rows
		, array $origin
		, array $direction
		, float $start
		, float $end
		, int $ix
		, int $iz
	): array {
		$coefficients = array_map(self::cubic(...), $rows);
		$tensor = [];

		for($i = 0; $i < 4; $i++) $tensor[$i] = self::cubic(array_column($coefficients, $i));
		$s = $this->parameters['spacing'];
		$duration = $end - $start;
		$u = ($origin[0] + $direction[0] * $start) / $s - $ix;
		$v = ($origin[2] + $direction[2] * $start) / $s - $iz;
		$du = $direction[0] * $duration / $s;
		$dv = $direction[2] * $duration / $s;
		$result = array_fill(0, 7, 0.0);

		for($i = 0; $i < 4; $i++)
		for($j = 0; $j < 4; $j++)
		for($a = 0; $a <= $i; $a++)
		for($b = 0; $b <= $j; $b++)
		$result[$a + $b] += $tensor[$i][$j] * self::choose($i, $a) * self::choose($j, $b)
			* $u ** ($i - $a) * $du ** $a * $v ** ($j - $b) * $dv ** $b;
		$result[0] -= $origin[1] + $direction[1] * $start;
		$result[1] -= $direction[1] * $duration;

		return $result;
	}

	/**
	 * Isolate the earliest zero using the convex-hull property of Bernstein coefficients.
	 * @param array $values Bernstein coefficients bounding the current polynomial interval.
	 * @param float $lo Normalized lower interval endpoint.
	 * @param float $hi Normalized upper interval endpoint.
	 * @param float $tolerance Maximum normalized root-isolation interval length.
	 * @param int $budget Remaining bounded subdivision-node budget, decremented in place.
	 * @return float|null Earliest isolated normalized root, or null when the interval excludes zero.
	 */
	private function root(array $values, float $lo, float $hi, float $tolerance, int &$budget): ?float
	{
		if(--$budget < 0) throw new \RuntimeException('Ray root isolation exceeded bounded work');

		$min = min($values);
		$max = max($values);

		if($min > 1e-10 || $max < -1e-10) return null;

		if(max(abs($min), abs($max)) < 1e-10) return $lo;

		if($hi - $lo <= $tolerance) return ($lo + $hi) / 2;

		$left = [$values[0]];
		$right = [$values[6]];

		for($level = 6; $level > 0; $level--)
		{
			$next = [];

			for($i = 0; $i < $level; $i++) $next[] = .5 * ($values[$i] + $values[$i + 1]);
			$left[] = $next[0];
			$right[] = $next[$level - 1];
			$values = $next;
		}

		$middle = ($lo + $hi) / 2;

		return $this->root($left, $lo, $middle, $tolerance, $budget)
			?? $this->root(array_reverse($right), $middle, $hi, $tolerance, $budget);
	}
}

<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Authoritative bounded heightfield. Lazy packed nodes feed one C1 reconstruction for
 * rendering, contacts and picking. Edits become visible only at explicit commit boundaries.
 */
final class Landscape implements TerrainSurface
{
	/** Immutable procedural recipe and normalized parameters. */
	private Recipe $generator;
	private array $parameters;
	/** LRU tile map; PHP insertion order tracks most recent use without an unbounded queue. */
	private array $tiles = [];
	/** Edit transaction history, current applied prefix, and staged transaction. */
	private array $history = [], $operations = [], $pending = [];
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
	}

	/**
	 * Return normalized serializable recipe parameters.
	 * @return array Normalized immutable recipe values.
	 */
	public function recipe(): array { return $this->parameters; }

	/**
	 * Return the monotonically increasing committed revision.
	 * @return int Monotonically increasing committed surface revision.
	 */
	public function revision(): int { return $this->version; }

	/**
	 * Return interpolated elevation; at most sixteen missing lattice nodes are requested.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @return float Committed reconstructed elevation in metres.
	 */
	public function height(float $x, float $z): float
	{
		$this->coordinates($x, $z);

		return $this->interpolate($x, $z, false)[0];
	}

	/**
	 * Return elevation and exact derivatives of the same reconstructed surface.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @return array Height, derivatives, upward unit normal, material and revision.
	 */
	public function sample(float $x, float $z): array
	{
		$this->coordinates($x, $z);
		[$height,$dx,$dz] = $this->interpolate($x, $z, true);
		$length = sqrt(1 + $dx * $dx + $dz * $dz);

		return [
			'height' => $height
			, 'dx' => $dx
			, 'dz' => $dz
			, 'normal' => [-$dx / $length, 1 / $length, -$dz / $length]
			, 'material' => Edits::material($x, $z, $this->operations)
			, 'revision' => $this->version
		];
	}

	/**
	 * Query painted surface material without generating height or normal samples.
	 * @param float $x World horizontal X coordinate in metres.
	 * @param float $z World horizontal Z coordinate in metres.
	 * @return string|null Most recent covering material, or null for recipe shading.
	 */
	public function material(float $x, float $z): ?string
	{
		$this->coordinates($x, $z);

		return Edits::material($x, $z, $this->operations);
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

		$candidate = [...$this->operations, ...$this->pending, $operation];
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

		$operations = [...$this->operations, ...$this->pending];
		$range = $this->editedBounds($operations);
		$history = array_slice($this->history, 0, $this->cursor);
		$history[] = $this->pending;
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
	 * Discard only uncommitted edits, for a cancelled authoring transaction.
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

		$history = [];

		foreach($this->history as $batch)
		{
			$history[] = array_map(
				/** Strip derived acceleration fields from a persistent operation.
			 * @param array $operation Normalized edit parameters.
			 * @return array Portable edit parameters without cache data.
			 */
				static function(array $operation): array {
					foreach(array_keys($operation) as $key)
					if(is_string($key) && str_starts_with($key, '_')) unset($operation[$key]);

					return $operation;
				}
				, $batch
			);
		}

		return json_encode([
			'format' => 'terrain-phonics'
			, 'version' => 1
			, 'recipe' => $this->parameters
			, 'history' => $history
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

		if(!is_array($value) || ($value['format'] ?? null) !== 'terrain-phonics' || ($value['version'] ?? null) !== 1
			|| !is_array($value['recipe'] ?? null) || !is_array($value['history'] ?? null)
			|| !is_int($value['cursor'] ?? null) || !is_int($value['revision'] ?? null)
			|| $value['cursor'] < 0 || $value['cursor'] > count($value['history']) || $value['revision'] < 0
		) throw new \InvalidArgumentException('Invalid terrain document');

		$result = new self($value['recipe']);
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

			$all = [...$all, ...$normalized];
			$result->operationLimit($all);
			$result->history[] = $normalized;
		}

		$result->cursor = $value['cursor'];
		$result->version = $value['revision'];
		$result->operations = $result->prefix($result->cursor);
		$result->range = $result->editedBounds($result->operations);

		return $result;
	}

	/**
	 * Return conservative world bounds, optionally restricting the horizontal query rectangle.
	 * @param array|null $region Horizontal rectangle [minX,minZ,maxX,maxZ] in metres.
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
	 * Reserve all tile blocks supporting a rectangle, including its interpolation halo. Reservation is atomic and
	 * generates no height samples. Refuse leases exceeding capacity.
	 * @param array $region Horizontal rectangle [minX,minZ,maxX,maxZ] in metres.
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

		for($z = $z0; $z <= $z1; $z++) for($x = $x0; $x <= $x1; $x++) $keys[$x . ':' . $z] = true;
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
		$this->tiles = $this->leases = $this->history = $this->operations = $this->pending = $this->memo = [];
		$this->closed = true;
	}

	/**
	 * Intersect a world-space ray with the authoritative bicubic surface. Each crossed lattice cell becomes a
	 * degree-six polynomial; Bernstein subdivision cannot skip a narrow crest merely because both cell endpoints lie
	 * above it.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $maxDistance Maximum ray travel in metres.
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
	 * @param float $maxDistance Maximum ray travel in metres.
	 * @return RayQuery Revision-guarded query that the caller advances within its frame budget.
	 */
	public function rayQuery(array $origin, array $direction, float $maxDistance = 10000): RayQuery
	{
		$this->open();

		return new RayQuery($this, $origin, $direction, $maxDistance);
	}

	/**
	 * Traverse exact interpolation cells cooperatively, yielding after every cell.
	 * The generator returns the same nearest hit as raycast; resumption rejects changed
	 * geometry. One cell and its bounded polynomial isolation remain an indivisible unit.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $maxDistance Maximum ray travel in metres.
	 * @return \Generator Completed-cell counts, followed by an exact hit or null as the return value.
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
		yield 0;

		while($time <= $far && $cells++ < 300000)
		{
			$this->open();

			if($this->version !== $revision) throw new \LogicException('Terrain changed during ray traversal');

			$x = $origin[0] + $direction[0] * $time;
			$z = $origin[2] + $direction[2] * $time;
			$ix = (int)floor($x / $spacing);
			$iz = (int)floor($z / $spacing);

			if($direction[0] < 0 && abs($x / $spacing - $ix) < 1e-9) $ix--;

			if($direction[2] < 0 && abs($z / $spacing - $iz) < 1e-9) $iz--;

			$end = $far;

			foreach([[0,$ix],[2,$iz]] as [$axis,$cell])
			{
				if(abs($direction[$axis]) < 1e-14) continue;

				$edge = ($cell + (int)($direction[$axis] > 0)) * $spacing;
				$crossing = ($edge - $origin[$axis]) / $direction[$axis];

				if($crossing > $time + 1e-10) $end = min($end, $crossing);
			}

			$polynomial = $this->rayPolynomial($this->patch($ix, $iz), $origin, $direction, $time, $end, $ix, $iz);
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
	 * Return tensor cubic interpolation and optional world derivatives.
	 * @param float $x World X coordinate, or integer lattice column for node access.
	 * @param float $z World Z coordinate, or integer lattice row for node access.
	 * @param bool $derivatives Whether to evaluate exact reconstruction derivatives.
	 * @return array Elevation and optional world-space X/Z derivatives.
	 */
	private function interpolate(float $x, float $z, bool $derivatives): array
	{
		$spacing = $this->parameters['spacing'];
		$u = $x / $spacing;
		$v = $z / $spacing;
		$ix = (int)floor($u);
		$iz = (int)floor($v);
		$u -= $ix;
		$v -= $iz;

		// Mesh vertices normally lie on the lattice. Their exact cubic value/gradient
		// need one/five nodes rather than gathering sixteen zero-weight contributions.
		if($u === 0.0 && $v === 0.0)
		{
			$height = $this->node($ix, $iz);

			return $derivatives
				? [
					$height
					, ($this->node($ix + 1, $iz) - $this->node($ix - 1, $iz)) / (2 * $spacing)
					, ($this->node($ix, $iz + 1) - $this->node($ix, $iz - 1)) / (2 * $spacing)
				]
				: [$height];
		}

		$rows = $this->patch($ix, $iz);
		$values = [];
		$slopes = [];

		foreach($rows as $row)
		{
			$c = self::cubic($row);
			$values[] = self::evaluate($c, $u);

			if($derivatives) $slopes[] = $c[1] + $u * (2 * $c[2] + 3 * $u * $c[3]);
		}

		$c = self::cubic($values);
		$height = self::evaluate($c, $v);

		return $derivatives
			? [
				$height
				, self::evaluate(self::cubic($slopes), $v) / $spacing
				, ($c[1] + $v * (2 * $c[2] + 3 * $v * $c[3])) / $spacing
			]
			: [$height];
	}

	/**
	 * Gather a shared four-by-four stencil, including globally addressed tile halos.
	 * @param int $x World X coordinate, or integer lattice column for node access.
	 * @param int $z World Z coordinate, or integer lattice row for node access.
	 * @return array Four rows of four neighboring node elevations.
	 */
	private function patch(int $x, int $z): array
	{
		$rows = [];

		for($j = -1; $j <= 2; $j++)
		{
			$row = [];

			for($i = -1; $i <= 2; $i++) $row[] = $this->node($x + $i, $z + $j);
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Request a single lazy node, bypassing storage when every cache tile is pinned elsewhere.
	 * @param int $x World X coordinate, or integer lattice column for node access.
	 * @param int $z World Z coordinate, or integer lattice row for node access.
	 * @return float Cached or freshly generated lattice-node height.
	 */
	private function node(int $x, int $z): float
	{
		$size = $this->parameters['tileSize'];
		$tx = (int)floor($x / $size);
		$tz = (int)floor($z / $size);
		$tile = $this->tile($tx . ':' . $tz);
		$lx = $x - $tx * $size;
		$lz = $z - $tz * $size;
		$value = $tile?->get($lx, $lz);

		if($value !== null)
		{
			$this->hits++;

			return $value;
		}

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
	 * Get/create an LRU tile without exceeding the configured tile count.
	 * @param string $key Globally addressed tile cache key.
	 * @return HeightTile|null Existing or newly reserved tile, or null when all capacity is pinned elsewhere.
	 */
	private function tile(string $key): ?HeightTile
	{
		if(isset($this->tiles[$key]))
		{
			$tile = $this->tiles[$key];
			unset($this->tiles[$key]);
			$this->tiles[$key] = $tile;

			return $tile;
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

		return $this->tiles[$key] = new HeightTile($this->parameters['tileSize'], $this->version);
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

		$this->operations = $operations;
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

		foreach(array_slice($this->history, 0, $cursor) as $batch) $result = [...$result, ...$batch];

		return $result;
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
	 * @param array $region Horizontal rectangle [minX,minZ,maxX,maxZ] in metres.
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
	 * Evaluate a cubic with Horner's rule.
	 * @param array $c Cubic power-basis coefficients.
	 * @param float $t Normalized interpolation coordinate.
	 * @return float Cubic value at the requested parameter.
	 */
	private static function evaluate(array $c, float $t): float
	{
		return $c[0] + $t * ($c[1] + $t * ($c[2] + $t * $c[3]));
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

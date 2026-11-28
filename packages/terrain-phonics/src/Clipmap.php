<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** A camera-centered stack of toroidal GPU elevation textures and one world overview. */
final class Clipmap
{
	private Program $generator;
	// Only the last set of overlay footprints is retained. Each LOD snaps and changes visibility independently.
	private array $overlayRegions = [], $overlayClips = [];
	private bool $joined = false;
	private const AXES = 1, DETAIL_SHAPE = 2, GULLY_CUT = 4, COAST_PROFILE = 8;
	private int $formFeatures = 0;
	private ?TerrainAppearance $chartAppearance = null;
	private ?TriangleShadows $shadows = null;
	private ?SurfaceLayers $chartLayers = null;
	private ?Program $boundChartProgram = null;
	private array $vao = [], $framebuffer = [], $permutation = [], $levels = [], $overview = [];
	private array $chartDetail = [];
	private const CHART_DETAIL = -2;
	private ?Landscape $source = null;
	private EditIndex $edits;
	private ?EditIndex $overviewEdits = null;
	private array $roads = [], $roadTexture = [], $roadOverlays = [], $lastAtlas = [];
	private int $roadOverlayBuilds = 0;
	private ?RoadsideDetail $roadside = null;
	// Road mask windows and their occupied bounds are cached independently at each spacing.
	private array $roadsideTextures = [], $roadsideSizes = [], $roadsideWindows = [], $roadsideBounds = [];
	private array $roadsideRects = [], $capacities = [];
	private int $roadBytes = 0;
	// Parent normals sample two cells beyond the drawn grid.
	private const HALO = 2;
	// Maximum displacement of the fine grid as a fraction of its width.
	private const LEAD_FRACTION = 1 / 6;
	private ?array $fineFocus = null;
	private int $revision = -1;
	private array $spans = [];
	private ?int $growingForkRing = null;
	private array $forkSpans = [];
	private array $shadowReceiverRange = [0.,0.];
	private ?array $dirtyRegion = null;

	/**
	 * Mark the elevation cache stale after an edit.
	 *
	 * @param ?array $region Affected rectangle in world coordinates or null for the full landscape.
	 * @return void No value.
	 */
	public function invalidate(?array $region = null): void
	{
		$this->dirtyRegion = $region;
		$this->revision = -1;
	}

	private ?bool $packed = null;
	private array $recipe = [];
	/**
	 * Edited texels waiting for PHP's heights and paint ink, finest level first: [level key, first cell X, first
	 * cell Z, width, height, step, offset, regions left]. update() spends at most its deadline on them, so driving
	 * over sculpted ground cannot stall a frame; the level reports pending until they are done.
	 */
	private array $paintQueue = [];
	/** Running estimate of the seconds one painted texel takes, so a turn that would pass the deadline waits. */
	private float $texelSeconds = 40e-6;
	public bool $pending = true;
	public int $texels = 0, $uploads = 0;

	/**
	 * Create the generator program and GPU objects shared by the clipmap levels.
	 *
	 * @return void No value.
	 */
	public function __construct()
	{
		$this->generator = self::elevationProgram(false);

		glGenVertexArrays(1, $this->vao);
		glGenFramebuffers(1, $this->framebuffer);
		glGenTextures(1, $this->permutation);
	}

	/**
	 * Compile the elevation pass with the features used by the landscape.
	 *
	 * @param bool $joined Enable terrain transitions between landscapes.
	 * @param int $features Enabled procedural shape features.
	 * @return Program Linked height generation program.
	 */
	private static function elevationProgram(bool $joined, int $features = 0): Program
	{
		$root = dirname(__DIR__) . '/shaders/';
		$fragment = file_get_contents($root . 'elevation.frag');
		$defines = $joined ? "\n#define JOINED_TERRAIN" : '';

		foreach([
			self::AXES => 'FORM_AXES'
			, self::DETAIL_SHAPE => 'DETAIL_SHAPE'
			, self::GULLY_CUT => 'GULLY_CUT'
			, self::COAST_PROFILE => 'COAST_PROFILE'
		] as $bit => $flag)
		if($features & $bit) $defines .= "\n#define " . $flag;
		$fragment = str_replace('#version 300 es', '#version 300 es' . $defines, $fragment);

		return new Program(file_get_contents($root . 'shadows.vert'), $fragment);
	}

	/**
	 * Bind a clipmap texture and configure its sampling parameters.
	 *
	 * @param int $id GPU texture handle.
	 * @return void No value.
	 */
	private static function texture(int $id): void
	{
		glBindTexture(GL_TEXTURE_2D, $id);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
	}

	/**
	 * Allocate the textures for one elevation grid.
	 *
	 * @param int $n Side length of each texture in texels.
	 * @return array Texture handles, initial origin and storage size.
	 */
	private function allocate(int $n): array
	{
		$ids = [];
		glGenTextures(2, $ids);
		self::texture($ids[0]);

		if($this->packed !== true) glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA32F, $n, $n, 0, GL_RGBA, GL_FLOAT, null);

		if($this->packed === null)
		{
			glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $ids[0], 0);
			$this->packed = glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE;
		}

		if($this->packed) glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $n, $n, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);

		self::texture($ids[1]);
		glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $n, $n, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);

		return ['textures' => $ids,'n' => $n,'origin' => null];
	}

	/**
	 * Prepare the GPU generator and noise data for the active landscape.
	 *
	 * @param Landscape $land Landscape supplying elevation and committed edits.
	 * @return void No value.
	 */
	private function generate(Landscape $land): void
	{
		if($this->chartDetail) $this->chartDetail['origin'] = null;

		$newRecipe = $this->recipe !== $land->recipe();
		$this->source = $land;
		$this->revision = $land->revision();
		$this->edits = $land->editIndex(false);
		$roads = $land->roads();
		$r = $land->recipe();
		// Only the fixed overview can sample between lattice nodes. In that case use PHP's cubic
		// reconstruction across the complete edited footprint for this one texture.
		$overviewStride = $r['worldSize'] / (256 * $r['spacing']);
		$this->overviewEdits = $roads && $overviewStride !== floor($overviewStride) ? $land->editIndex() : null;
		$joined = isset($r['join']);
		$features = $this->formFeatures;

		if($newRecipe)
		{
			$features = 0;

			foreach([$r,...($r['join']['destinations'] ?? [])] as $form)
			{
				if($form['ridgeStretch'] != 1 || $form['domainRotation'] != 0) $features |= self::AXES;

				if($form['directionalRise'] != 0 && ($form['ridgeStretch'] != 1
					|| $form['coastBearing'] != 0 || $form['coastWidth'] != 4500
				)) $features |= self::COAST_PROFILE;

				if($form['detailShape'] != 0) $features |= self::DETAIL_SHAPE;

				if($form['gullyDepth'] > 0) $features |= self::GULLY_CUT;
			}
		}

		$switched = $joined !== $this->joined || $features !== $this->formFeatures;

		if($switched)
		{
			$program = self::elevationProgram($joined, $features);
			$this->formFeatures = $features;
			$this->generator->close();
			$this->generator = $program;
			$this->generator->use();
			$this->joined = $joined;
		}

		if($switched || $roads !== $this->roads || ($roads && $r['worldSize'] !== ($this->recipe['worldSize'] ?? null)))
		$this->uploadRoads($roads, $r['worldSize']);

		if(!$newRecipe) return;

		$this->recipe = $r;
		self::texture($this->permutation[0]);
		$p = $this->generator;
		$recipes = [$r, ...($r['join']['destinations'] ?? [])];
		$bytes = '';

		if($joined)
		{
			$origins = JoinedTerrain::origins($r['join']['span'], count($recipes) - 1);
			glUniform1i($p->location('uFormCount'), count($recipes));
			glUniform1i($p->location('uBlendProfile'), (int)(($r['join']['version'] ?? 1) >= 2));
			glUniform1f($p->location('uJoinWidth'), JoinedTerrain::GAP);
		}

		foreach($recipes as $i => $form)
		{
			$bytes .= pack('C*', ...(new Noise($form['seed']))->permutation());
			$this->uploadForm($form, $joined ? "Set[$i]" : '');

			if($joined)
			glUniform3f(
				$p->location("uFormOrigin[$i]")
				, $origins[$i][0]
				, $origins[$i][1]
				, $r['waterLevel'] - $form['waterLevel']
			);
		}

		glTexImage2D(GL_TEXTURE_2D, 0, GL_R8, 256, count($recipes), 0, GL_RED, GL_UNSIGNED_BYTE, $bytes);

		glUniform4f(
			$p->location('uRegion')
			, $r['worldSize'] / 2
			, array_search($r['surround'], ['none','sea','plateau','mountains'], true)
			, $r['transitionWidth']
			, $r['waterLevel']
		);

		glUniform1i(
			$p->location('uNaturalMountains')
			, (int)($r['ridgeHeight'] > 0 && $r['wallHeight'] == 0 && $r['directionalRise'] == 0)
		);
	}

	/**
	 * Upload the shape parameters for one source landscape.
	 *
	 * @param array $r Normalized terrain recipe.
	 * @param string $suffix Uniform suffix for the selected region.
	 * @return void No value.
	 */
	private function uploadForm(array $r, string $suffix): void
	{
		$p = $this->generator;

		/** Read one component of a form uniform.
		 * @param string $key Recipe field name.
		 * @return float|int Configured shape coefficient.
		 */
		$fieldValue = static fn($key) => $r[$key];

		foreach([
			'uShape' => ['baseBias','rolling','directionalRise','ridgeHeight']
			, 'uProfile' => ['ridgeRoundness','wallHeight','floorWidth','wallWidth']
			, 'uField' => ['heightScale','baseFrequency','warpAmplitude','warpFrequency']
			, 'uRock' => ['secondary','terraceSpacing','terraceStrength','bedding']
			, 'uDetail' => ['detailAmplitude','persistence','lacunarity','damping']
		] as $uniform => $fields)
		glUniform4f($p->location($uniform . $suffix), ...array_map($fieldValue, $fields));

		// Optional uniforms use the same name in both variants, with an array index only on a join.
		$slot = $suffix ?: 'Set';

		if($this->formFeatures & (self::AXES | self::COAST_PROFILE))
		{
			$axes = Recipe::axes($r);

			if($this->formFeatures & self::AXES) glUniform4f($p->location('uMacroAxes' . $slot), ...$axes['macro']);

			glUniform4f($p->location('uDetailCoast' . $slot), ...array_merge($axes['rotation'], $axes['coast']));
		}

		if($this->formFeatures & self::DETAIL_SHAPE)
		glUniform1f($p->location('uDetailShape' . $slot), $r['detailShape']);

		if($this->formFeatures & self::GULLY_CUT)
		glUniform2f($p->location('uGully' . $slot), $r['gullyDepth'], $r['gullySpacing']);

		/**
		 * Measure the precision lost when a coordinate is stored in a shader float.
		 *
		 * @param float $f Double-precision coordinate.
		 * @return float Residual beyond the single-precision representation.
		 */
		$residual = static fn(float $f): float => $f - unpack('g', pack('g', $f))[1];
		$x = $r['noiseOffsetX'];
		$z = $r['noiseOffsetZ'];
		glUniform4f($p->location('uNoiseOffset' . $suffix), floor($x), floor($z), $x - floor($x), $z - floor($z));

		glUniform2f(
			$p->location('uFieldResidual' . $suffix)
			, $residual($r['baseFrequency'])
			, $residual($r['warpFrequency'])
		);

		// Rounding the already-converted base frequency times 22 moves the drainage
		// contours at large coordinates. Pass the exact recipe product and its residual.
		$erosion = 22 * $r['baseFrequency'];
		glUniform2f($p->location('uErosion' . $suffix), $erosion, $residual($erosion));
		glUniform1f($p->location('uSlopeScale' . $suffix), $r['slopeScale']);
		glUniform1i($p->location('uOctaves' . $suffix), $r['detailOctaves']);

	}

	/**
	 * Replace the resident spatial index, including the empty state after undo.
	 * @param array $roads Normalized road operations in application order.
	 * @param float $extent Width of the editable square in meters.
	 * @return void Updates the generator uniforms and the texture byte counters.
	 */
	private function uploadRoads(array $roads, float $extent): void
	{
		$this->roads = $roads;
		$p = $this->generator;

		if(!$roads)
		{
			glUniform4i($p->location('uRoadGrid'), 0, 0, 0, 0);

			if($this->roadTexture) glDeleteTextures(1, $this->roadTexture);

			foreach($this->roadsideTextures as $texture) glDeleteTextures(1, $texture);
			$this->roadsideTextures = $this->roadsideSizes = $this->roadTexture = $this->lastAtlas = $this
				->roadOverlays = [];

			$this->roadBytes = 0;

			return;
		}

		$atlas = RoadAtlas::build($roads, $extent, $this->roadOverlays);
		$this->roadOverlayBuilds += $atlas['rebuilt'];

		if(!$this->roadTexture) glGenTextures(1, $this->roadTexture);

		self::texture($this->roadTexture[0]);
		$width = $atlas['width'];
		$height = $atlas['height'];
		$data = $atlas['bytes'];

		if(($this->lastAtlas['width'] ?? 0) !== $width || $this->lastAtlas['height'] !== $height)
		{
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA32F, $width, $height, 0, GL_RGBA, GL_FLOAT, $data);
			$this->uploads += strlen($data);
		} else
		{
			// Retain identical rows of the composed GPU atlas, including unmodified road overlays.
			$row = 16 * $width;
			$start = null;

			for($y = 0; $y <= $height; $y++)
			{
				$changed = $y < $height && substr($data, $y * $row, $row) !== substr(
					$this->lastAtlas['bytes']
					, $y * $row
					, $row
				);

				if($changed)
				{
					$start ??= $y;
					continue;
				}

				if($start === null) continue;

				$bytes = substr($data, $start * $row, ($y - $start) * $row);
				glTexSubImage2D(GL_TEXTURE_2D, 0, 0, $start, $width, $y - $start, GL_RGBA, GL_FLOAT, $bytes);
				$this->uploads += strlen($bytes);
				$start = null;
			}
		}

		$this->lastAtlas = $atlas;
		$this->roadBytes = strlen($data);

		glUniform4i(
			$p->location('uRoadGrid')
			, $atlas['directory']
			, $atlas['blocksAcross']
			, $atlas['minTile']
			, (int)$atlas['cell']
		);
	}

	/**
	 * Left/right strips plus top/bottom strips over the shared X interval. Half-open integer rectangles.
	 *
	 * @param int $x First column of the new rectangle.
	 * @param int $z First row of the new rectangle.
	 * @param int $n Number of cells across the grid.
	 * @param ?array $old Previous grid origin, when available.
	 * @param ?int $oldSize Previous number of covered columns.
	 * @param ?int $rows Number of grid rows to cover.
	 * @param ?int $oldRows Previous number of covered rows.
	 * @return array Half-open rectangles of newly exposed grid cells.
	 */
	public static function exposed(
		int $x
		, int $z
		, int $n
		, ?array $old
		, ?int $oldSize = null
		, ?int $rows = null
		, ?int $oldRows = null
	): array {
		$rows ??= $n;

		if($old === null) return [[$x,$z,$x + $n,$z + $rows]];

		$oldSize ??= $n;
		$oldRows ??= $oldSize;
		[$ox,$oz] = $old;
		$l = max($x, $ox);
		$t = max($z, $oz);
		$r = min($x + $n, $ox + $oldSize);
		$b = min($z + $rows, $oz + $oldRows);

		if($r <= $l || $b <= $t) return [[$x,$z,$x + $n,$z + $rows]];

		$rects = [];

		if($x < $l) $rects[] = [$x,$z,$l,$z + $rows];

		if($r < $x + $n) $rects[] = [$r,$z,$x + $n,$z + $rows];

		if($z < $t) $rects[] = [$l,$z,$r,$t];

		if($b < $z + $rows) $rects[] = [$l,$b,$r,$z + $rows];

		return $rects;
	}

	/**
	 * Render newly exposed rectangles into a level and schedule their edit overlays.
	 *
	 * @param array $level Resident grid record with its size and textures.
	 * @param array $rects Half-open rectangles of cells to update.
	 * @param float $step World-space distance between grid samples.
	 * @param float $offset World-space grid offset.
	 * @param int $key Level identifier for the pending work.
	 * @return void No value.
	 */
	private function fill(array $level, array $rects, float $step, float $offset, int $key): void
	{
		if(!$rects) return;

		$p = $this->generator;
		$n = $level['n'];
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->permutation[0]);
		glUniform1i($p->location('uPermutation'), 0);
		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1f($p->location('uStep'), $step);
		glUniform2f($p->location('uWorldOffset'), $offset, $offset);

		foreach($level['textures'] as $i => $id)
		glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0 + $i, GL_TEXTURE_2D, $id, 0);
		glDrawBuffers(2, [GL_COLOR_ATTACHMENT0,GL_COLOR_ATTACHMENT0 + 1]);

		$editIndex = $offset !== 0. && $this->overviewEdits ? $this->overviewEdits : $this->edits;

		foreach(self::wrapped($rects, $n) as [$x,$z,$u,$v,$w,$h])
		{
			glViewport($u, $v, $w, $h);
			glUniform2i($p->location('uOriginCell'), $x, $z);
			glUniform2i($p->location('uPixelOrigin'), $u, $v);
			glDrawArrays(GL_TRIANGLES, 0, 3);
			$this->texels += $w * $h;

			if(!$editIndex->isEmpty()) $this->paintQueue[] = [$key, $x, $z, $w, $h, $step, $offset, null];
		}
	}

	/**
	 * Split world-cell rectangles at texture boundaries, keeping both logical and physical origins.
	 *
	 * @param array $rects Half-open rectangles of cells to update.
	 * @param int $n Number of cells across the grid.
	 * @return \Generator World coordinates, texture coordinates, width and height of each contiguous write.
	 */
	public static function wrapped(array $rects, int $n): \Generator
	{
		foreach($rects as [$x0,$z0,$x1,$z1])
		{
			for($z = $z0; $z < $z1; $z += $h)
			for($x = $x0; $x < $x1; $x += $w)
			{
				$u = (($x % $n) + $n) % $n;
				$v = (($z % $n) + $n) % $n;
				$w = min($x1 - $x, $n - $u);
				$h = min($z1 - $z, $n - $v);
				yield [$x,$z,$u,$v,$w,$h];
			}
		}
	}

	/**
	 * Edited cell rectangles inside a filled block, in cell indices: [left, front, right, back], inclusive.
	 * @param int $x First cell X of the block.
	 * @param int $z First cell Z of the block.
	 * @param int $w Block width in cells.
	 * @param int $h Block height in cells.
	 * @param float $step Cell spacing in meters.
	 * @param float $offset World offset of cell zero.
	 * @return array Rectangles whose texels need PHP's heights and ink.
	 */
	private function editedCells(int $x, int $z, int $w, int $h, float $step, float $offset): array
	{
		$index = $offset !== 0. && $this->overviewEdits ? $this->overviewEdits : $this->edits;
		$cells = [];

		foreach($index->intersect(
			[$x * $step + $offset,$z * $step + $offset,($x + $w - 1) * $step + $offset,($z + $h - 1) * $step + $offset]
		) as [$left,$front,$right,$back]){
			$l = (int)ceil(($left - $offset) / $step);
			$r = (int)floor(($right - $offset) / $step);
			$f = (int)ceil(($front - $offset) / $step);
			$b = (int)floor(($back - $offset) / $step);

			if($l <= $r && $f <= $b) $cells[] = [$l,$f,$r,$b];
		}

		return $cells;
	}

	/**
	 * Replace the generated heights and ink of some cells with PHP's, which include every edit.
	 * @param array $textures Height and ink textures of the level.
	 * @param int $n Level storage size, which wraps cell indices to texels.
	 * @param array $cells [left, front, right, back] cell indices, inclusive, inside one wrap of the storage.
	 * @param float $step Cell spacing in meters.
	 * @param float $offset World offset of cell zero.
	 * @return void Uploads the cells' heights and ink.
	 */
	private function paint(array $textures, int $n, array $cells, float $step, float $offset): void
	{
		[$l,$f,$r,$b] = $cells;
		$heights = $ink = '';

		for($j = $f; $j <= $b; $j++)
		for($i = $l; $i <= $r; $i++)
		{
			$wx = $i * $step + $offset;
			$wz = $j * $step + $offset;
			$height = $this->source->height($wx, $wz);
			$heights .= $this->packed ? pack('g', $height) : pack('g4', $height, 0, 0, 1);
			$ink .= $this->source->paintTexel($wx, $wz);
		}

		glActiveTexture(GL_TEXTURE0 + 1);

		foreach([$heights,$ink] as $k => $bytes)
		{
			glBindTexture(GL_TEXTURE_2D, $textures[$k]);

			glTexSubImage2D(
				GL_TEXTURE_2D
				, 0
				, (($l % $n) + $n) % $n
				, (($f % $n) + $n) % $n
				, $r - $l + 1
				, $b - $f + 1
				, GL_RGBA
				, $k === 0 && !$this->packed ? GL_FLOAT : GL_UNSIGNED_BYTE
				, $bytes
			);

			$this->uploads += strlen($bytes);
		}

		glBindTexture(GL_TEXTURE_2D, 0);
		glActiveTexture(GL_TEXTURE0);
	}

	/**
	 * Paint queued edited cells until the deadline, a few rows at a time, finest level first. A queued block that
	 * has scrolled out of its level's window is clipped to what remains, so recycled texels never get stale heights.
	 * @param float|null $deadline microtime(true) to stop at, or null to paint everything.
	 * @return void Leaves whatever did not fit queued for the next update.
	 */
	private function drainPaint(?float $deadline): void
	{
		// Each turn paints at most this many texels; at about 40 microseconds a texel that is under 1.5 ms.
		$batch = 32;
		$painted = $visited = false;

		while($this->paintQueue !== [] && (!$visited || $deadline === null || microtime(true) < $deadline))
		{
			// WebGL setup may already have spent the frame budget. Still advance one bounded queue entry.
			$visited = true;
			$k = array_key_first($this->paintQueue);
			[$key,$x,$z,$w,$h,$step,$offset,$regions] = $this->paintQueue[$k];
			$level = match($key)
			{
				self::CHART_DETAIL => $this->chartDetail,
				-1 => $this->overview,
				default => $this->levels[$key] ?? [],
			};

			if(!$level || ($level['step'] ?? null) !== $step || $level['origin'] === null)
			{
				unset($this->paintQueue[$k]);
				continue;
			}

			// Clip the block to the level's current window; cells that left it now hold other cells' texels.
			[$ox,$oz] = $level['origin'];
			$n = $level['n'];
			$cells = $level['cells'];
			$l = max($x, $ox);
			$r = min($x + $w, $ox + $cells) - 1;
			$f = max($z, $oz);
			$b = min($z + $h, $oz + $level['rows']) - 1;

			if($l > $r || $f > $b)
			{
				unset($this->paintQueue[$k]);
				continue;
			}

			if($regions === null)
			{
				// Split wide rectangles into column chunks so a turn never paints more than one batch.
				$regions = [];

				foreach($this->editedCells($l, $f, $r - $l + 1, $b - $f + 1, $step, $offset) as [$cl,$cf,$cr,$cb])
				for($c = $cl; $c <= $cr; $c += $batch) $regions[] = [$c,$cf,min($cr, $c + $batch - 1),$cb];
			}

			if($regions === [])
			{
				unset($this->paintQueue[$k]);
				continue;
			}

			[$rl,$rf,$rr,$rb] = $regions[0];
			$rl = max($rl, $l);
			$rr = min($rr, $r);
			$rf = max($rf, $f);
			$rb = min($rb, $b);

			if($rl <= $rr && $rf <= $rb)
			{
				$rows = max(1, intdiv($batch, $rr - $rl + 1));
				$last = min($rb, $rf + $rows - 1);
				$texels = ($rr - $rl + 1) * ($last - $rf + 1);

				// Leave a turn that would overrun the deadline for the next update, but always paint at least one.
				if($painted && $deadline !== null && microtime(true) + $texels * $this->texelSeconds > $deadline) break;

				$started = microtime(true);
				$this->paint($level['textures'], $n, [$rl,$rf,$rr,$last], $step, $offset);
				$this->texelSeconds = .8 * $this->texelSeconds + .2 * (microtime(true) - $started) / $texels;
				$painted = true;
				$regions[0][1] = $last + 1;

				if($last < $rb)
				{
					$this->paintQueue[$k][7] = $regions;
					continue;
				}
			}

			array_shift($regions);

			if($regions === []) unset($this->paintQueue[$k]);
			else $this->paintQueue[$k][7] = $regions;
		}
	}

	/**
	 * Delete the textures owned by a level.
	 *
	 * @param array $level Grid record holding the texture handles to release.
	 * @return void No value.
	 */
	private static function release(array $level): void
	{
		if($level) glDeleteTextures(2, $level['textures']);
	}

	/**
	 * Grid widths, from the near field to the horizon, in cells of their own level.
	 *
	 * @param array $recipe Landscape recipe including its seed and shape settings.
	 * @param float $quality Configured detail multiplier.
	 * @param bool $focused Whether the fine rings lead the camera.
	 * @return array Active grid widths in order from finest to coarsest.
	 */
	public static function spans(array $recipe, float $quality, bool $focused = false): array
	{
		$base = $quality < .75 ? 24 : ($quality > 1.5 ? 120 : 56);
		$peak = $quality < .75 ? 120 : 248;
		$wall = $recipe['wallHeight'] > 0 && $recipe['heightScale'] > 0;
		$reach = $recipe['surround'] === 'none' ? $recipe['worldSize'] : 90000;
		$spans = [];
		$step = $recipe['spacing'];
		$inner = 0;

		do
		{
			$level = count($spans);
			$span = $base;

			if($wall)
			{
				// Preserve the steep walls at midrange, where the small camera-centered rings were
				// choosing a coarse grid. Tighten the mesh over the wider midrange on Detailed.
				$start = $quality > 1.5 ? 2 : 3;
				$end = $quality > 1.5 ? 5 : 4;

				if($level >= $start && $level <= $end)
				$span = $level === 3 && $quality >= .75 && $quality <= 1.5 ? 184 : $peak;
				elseif($level > 0 && $level < $start) $span = max($base, $peak === 120 ? 56 : 120);
			}

			if($focused && $level === 0) $span = 4 * (int)ceil($base / 3);

			// In parent cells, allow for the fine grid's maximum lead as well as its snap margin.
			$lead = $focused && $level === 1 ? $inner * self::LEAD_FRACTION / 2 : 0.;

			while($span / 2 - self::transition($span) < $inner / 4 + $lead + 4) $span += 4;
			$spans[] = $inner = $span;

			if((self::centeredSpan($span, $focused && $level === 0) / 2 - 2) * $step >= $reach) break;

			$step *= 2;
		}
		while(true);

		return $spans;
	}

	/**
	 * Width guaranteed around the eye even when the fine grid leads at its maximum offset.
	 *
	 * @param int $span Width of the grid at this level.
	 * @param bool $focused Whether the fine rings lead the camera.
	 * @return int Width in cells remaining centered on the camera.
	 */
	private static function centeredSpan(int $span, bool $focused): int
	{
		return $focused ? $span - 2 * (int)ceil($span * self::LEAD_FRACTION) : $span;
	}

	/**
	 * Calculate the width of the blend into the next coarser level.
	 *
	 * @param int $span Width of the grid at this level.
	 * @return float Transition width in cells.
	 */
	public static function transition(int $span): float
	{
		return max(2., min(20., $span * .1, $span / 4 - 4));
	}

	/**
	 * Size the wrapping texture for a level.
	 *
	 * @param int $span Width of the grid at this level.
	 * @return int Texture side length in texels.
	 */
	private static function storageSize(int $span): int
	{
		$size = 1;

		while($size < $span + 1 + 2 * self::HALO) $size *= 2;

		return $size;
	}

	/**
	 * Maximum roadside extension in cells. The four driving rings share the same world-space mask.
	 *
	 * @param int $span Width of the grid at this level.
	 * @param float $step World-space distance between grid samples.
	 * @return int Maximum extension in cells on either side.
	 */
	public static function roadsidePad(int $span, float $step): int
	{
		return $step > 8. ? 0 : 2 * (int)floor(min($span / 2, 48., 384. / $step) / 2);
	}

	/**
	 * Grow the fine draw rectangle only into road coverage and the parent's unmorphed interior. All four edges lie on
	 * the parent lattice, including west and south of the world origin.
	 *
	 * @param array $origin Origin used to position this grid or palette.
	 * @param int $span Width of the grid at this level.
	 * @param int $pad Permitted extension in cells.
	 * @param float $step World-space distance between grid samples.
	 * @param array $bounds Horizontal limits of the affected area.
	 * @param array $eye World-space camera position.
	 * @param float $parentRadius Unmorphed interior radius of the parent ring.
	 * @return ?array Extended grid bounds or null when the road misses this level.
	 */
	public static function roadsideRect(
		array $origin
		, int $span
		, int $pad
		, float $step
		, array $bounds
		, array $eye
		, float $parentRadius
	): ?array {
		$x = $origin[0] + self::HALO;
		$z = $origin[1] + self::HALO;
		$r = $x + $span;
		$b = $z + $span;

		[$l,$t,$rr,$bb] = array_map(
			/**
			 * Convert a world bound to level cell coordinates.
			 *
			 * @param float $v World coordinate.
			 * @return float Coordinate measured in level cells.
			 */
			static fn($v) => $v / $step
			, $bounds
		);

		if($l > $r + $pad || $rr < $x - $pad || $t > $b + $pad || $bb < $z - $pad) return null;

		$west = min(
			$x
			, max($x - $pad, 2 * (int)floor($l / 2) - 2, 2 * (int)ceil(($eye[0] / $step - $parentRadius) / 2))
		);

		$north = min(
			$z
			, max($z - $pad, 2 * (int)floor($t / 2) - 2, 2 * (int)ceil(($eye[2] / $step - $parentRadius) / 2))
		);

		$east = max(
			$r
			, min($r + $pad, 2 * (int)ceil($rr / 2) + 2, 2 * (int)floor(($eye[0] / $step + $parentRadius) / 2))
		);

		$south = max(
			$b
			, min($b + $pad, 2 * (int)ceil($bb / 2) + 2, 2 * (int)floor(($eye[2] / $step + $parentRadius) / 2))
		);

		// A short road can end inside the ordinary rectangle while its bank still crosses the morph strip.
		return [$west,$north,$east,$south];
	}

	/**
	 * A cold view of a fork needs enough samples to distinguish its two shoulders.
	 *
	 * @param array $roads Road records in terrain edit order.
	 * @param Landscape $land Landscape supplying elevation and committed edits.
	 * @param array $eye World-space camera position.
	 * @return bool Whether the eye is close enough to a junction to warm its finer rings.
	 */
	private function enteringFork(array $roads, Landscape $land, array $eye): bool
	{
		if(!$roads) return false;

		if($this->source === $land)
		{
			$i = min(2, count($this->levels) - 1);
			$near = $this->levels[$i] ?? [];

			if(isset($near['origin']))
			{
				[$x,$z] = $near['base'];
				$s = $near['step'];

				if($eye[0] >= $x * $s && $eye[0] <= ($x + $this->spans[$i]) * $s
					&& $eye[2] >= $z * $s && $eye[2] <= ($z + $this->spans[$i]) * $s
				) return false;
			}
		}

		foreach($roads as $road)
		{
			$caps = $road['_road']['ink']['forkCaps'] ?? 0;

			if(!$caps) continue;

			$points = $road['points'];

			foreach([0,array_key_last($points)] as $end => $i)
			{
				if(($caps & (1 << $end)) && hypot($points[$i][0] - $eye[0], $points[$i][2] - $eye[2]) <= 512.)
				return true;
			}
		}

		return false;
	}

	/**
	 * Refresh only cells newly exposed to the camera or changed by committed edits.
	 *
	 * @param Landscape $land Landscape supplying elevation and committed edits.
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param float $quality Terrain detail setting.
	 * @param ?float $deadline Latest wall-clock time allocated to this update.
	 * @param ?array $detailFocus Optional driving focus for positioning finer grid levels.
	 * @return bool Whether this update changed the visible terrain.
	 */
	public function update(
		Landscape $land
		, Camera $camera
		, float $quality
		, ?float $deadline = null
		, ?array $detailFocus = null
	): bool {
		$changed = $this->source !== $land || $this->revision !== $land->revision();
		$region = $this->dirtyRegion;
		$this->dirtyRegion = null;
		$recipe = $land->recipe();
		$step = $recipe['spacing'];
		$spans = self::spans($recipe, $quality, $detailFocus !== null);
		$roads = $changed ? $land->roads() : $this->roads;
		$bootstrap = $this->enteringFork($roads, $land, $camera->position);

		if($bootstrap)
		{
			$this->growingForkRing = count($spans) - 2;
			$this->forkSpans = [];
			$inner = 0;

			foreach($spans as $i => $width)
			{
				// Keep the approach and the point where the two arms diverge in view
				// from above. Each temporary texture fits in at most 64 by 64 texels.
				$size = min($width, max(24, min(56, 4 * (int)ceil(96 / ($step * (2 ** $i))))));

				while($size / 2 - self::transition($size) < $inner / 4 + 4) $size += 4;
				$this->forkSpans[$i] = $inner = $size;
			}
		}
		elseif($this->source !== $land) $this->growingForkRing = null;
		elseif($this->growingForkRing !== null)
		$this->growingForkRing = min($this->growingForkRing - 1, count($spans) - 1);

		// A small, centered footprint at every spacing keeps the branches connected on the
		// first frame. Grow one ring per update, outside in, preserving its stitched parent.
		while($this->growingForkRing !== null && $this->growingForkRing >= 0
			&& ($spans[$this->growingForkRing] ?? 0) <= ($this->forkSpans[$this->growingForkRing] ?? 0)
			&& !($detailFocus && $this->growingForkRing < 4)
		) --$this->growingForkRing;

		if($this->growingForkRing !== null && $this->growingForkRing < 0) $this->growingForkRing = null;

		$compact = $this->growingForkRing ?? -1;

		for($i = 0; $i <= $compact; $i++) $spans[$i] = min($spans[$i], $this->forkSpans[$i]);

		if($changed && $roads !== $this->roads)
		{
			$this->roadside = null;
			$this->roadsideWindows = $this->roadsideBounds = [];
		}

		$previousFocus = $this->fineFocus;
		$this->fineFocus = null;

		if($detailFocus !== null && $compact < 0)
		{
			$dx = $detailFocus[0] - $camera->position[0];
			$dz = $detailFocus[1] - $camera->position[2];
			$distance = hypot($dx, $dz);
			$limit = $spans[0] * $step * self::LEAD_FRACTION;
			$scale = $distance > $limit ? $limit / $distance : 1.;
			$this->fineFocus = [$camera->position[0] + $scale * $dx, $camera->position[2] + $scale * $dz];
		}

		$count = count($spans);
		$origins = $coverage = $sizes = $capacities = $masks = [];
		$previousRects = $this->roadsideRects;
		$this->roadsideRects = [];

		foreach($spans as $l => $span)
		{
			$s = $step * (2 ** $l);
			$focus = $l === 0 && $this->fineFocus ? $this->fineFocus : [$camera->position[0],$camera->position[2]];
			$x = 2 * (int)floor($focus[0] / (2 * $s)) - intdiv($span, 2);
			$z = 2 * (int)floor($focus[1] / (2 * $s)) - intdiv($span, 2);
			$coverage[$l] = [$x,$z];
			$origins[$l] = [$x - self::HALO,$z - self::HALO];
			$pad = $detailFocus !== null && $roads && $l > $compact && $l < 4 && isset($spans[$l + 1])
				? self::roadsidePad($span, $s)
				: 0;

			$parentSpace = isset($spans[$l + 1]) ? 2 * ($spans[$l + 1] / 2 - self::transition($spans[$l + 1]) - 3) : 0.;
			// Reserve storage at boarding, so driving into a cut never discards a resident level.
			// The parent lattice bounds the maximum rectangle even with the fine grid's forward lead.
			$maxSpan = $pad ? max($span, min($span + 2 * $pad, (int)ceil(2 * $parentSpace) + 2)) : $span;
			$capacities[$l] = self::storageSize($maxSpan);
			$regular = $bootstrap || $l <= $compact || $pad || ($l === 0 && $detailFocus !== null)
				? $span + 1 + 2 * self::HALO
				: $capacities[$l];

			$sizes[$l] = [$regular,$regular];

			if(!$pad) continue;

			$this->roadside ??= new RoadsideDetail($roads);
			$window = RoadsideDetail::window($focus, ($span / 2 + $pad + 2) * $s);

			if($window !== ($this->roadsideWindows[$l] ?? null))
			{
				[$bytes,$this->roadsideBounds[$l]] = $this->roadside->sampleWindow($window);

				if($this->roadsideBounds[$l] !== null) $masks[$l] = $bytes;

				$this->roadsideWindows[$l] = $window;
			}

			if($this->roadsideBounds[$l] === null) continue;

			$rect = self::roadsideRect(
				$origins[$l]
				, $span
				, $pad
				, $s
				, $this->roadsideBounds[$l]
				, $camera->position
				, $parentSpace
			);

			if($rect === null) continue;

			$this->roadsideRects[$l] = $rect;
			[$left,$top,$right,$bottom] = $rect;
			$origins[$l] = [$left - self::HALO,$top - self::HALO];
			$sizes[$l] = [$right - $left + 1 + 2 * self::HALO,$bottom - $top + 1 + 2 * self::HALO];
		}

		$gridChanged = $this->spans !== $spans || $this->roadsideRects !== $previousRects
			|| $origins !== array_column($this->levels, 'origin') || $coverage !== array_column($this->levels, 'base');

		if(!$changed && !$this->pending && !$gridChanged && !$masks) return $this->fineFocus !== $previousFocus;

		$previous = $this->beginElevation();

		try
		{
			glActiveTexture(GL_TEXTURE0);

			foreach($masks as $l => $mask)
			{
				if(!isset($this->roadsideTextures[$l]))
				{
					$ids = [];
					glGenTextures(1, $ids);
					$this->roadsideTextures[$l] = $ids;
					self::texture($ids[0]);
					glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
					glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
				} else glBindTexture(GL_TEXTURE_2D, $this->roadsideTextures[$l][0]);

				$n = $this->roadsideWindows[$l][2];

				if($n === ($this->roadsideSizes[$l] ?? 0))
				glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, $n, $n, GL_RED, GL_UNSIGNED_BYTE, $mask);
				else
				{
					glTexImage2D(GL_TEXTURE_2D, 0, GL_R8, $n, $n, 0, GL_RED, GL_UNSIGNED_BYTE, $mask);
					$this->roadsideSizes[$l] = $n;
				}
			}

			if($changed) $this->generate($land);

			if($changed || $this->spans !== $spans)
			{
				// Fade out the actual mesh elevation before the overview and the mesh have
				// comparable spacing. The inner part of each ring precedes its morph strip.
				$texel = $recipe['worldSize'] / 256.;
				$near = 0.;
				$far = $step;

				foreach($spans as $i => $span)
				{
					$spacing = $step * (2 ** $i);

					if($spacing > $texel / 2.) break;

					$centered = self::centeredSpan($span, $i === 0 && $detailFocus !== null);
					$far = ($centered / 2 - self::transition($span) - 3) * $spacing;

					if($spacing <= $texel / 8.) $near = $far;
				}

				$this->shadowReceiverRange = [$near,max($near + $step, $far)];
			}

			glActiveTexture(GL_TEXTURE0 + 2);
			glBindTexture(GL_TEXTURE_2D, $this->roadTexture[0] ?? $this->permutation[0]);
			glUniform1i($this->generator->location('uRoadAtlas'), 2);
			glActiveTexture(GL_TEXTURE0);

			// A full rebuild queues every edited texel again, so earlier entries would only repeat the work.
			if($changed && $region === null) $this->paintQueue = [];

			if($this->spans !== $spans || $this->capacities !== $capacities)
			{
				// Changing the near footprint can usually reuse every other toroidal texture.
				// Keep pending paint for those levels and for the fixed overview.
				$resident = array_fill(0, $count, []);

				foreach($this->levels as $i => $level)
				{
					if($level && isset($capacities[$i]) && $capacities[$i] === $level['n'])
					$resident[$i] = $level;
					else self::release($level);
				}

				$this->paintQueue = array_values(
					array_filter(
						$this->paintQueue
						, /**
						 * Keep queued work for overview and resident grid levels.
						 *
						 * @param array $entry Queued paint work item.
						 * @return bool Whether this entry can still update a resident texture.
						 */
						static fn($entry) => $entry[0] < 0 || !empty($resident[$entry[0]])
					)
				);

				$this->levels = $resident;
				$this->spans = $spans;
				$this->capacities = $capacities;
			}

			$half = $this->recipe['worldSize'] / 2;

			$this->refreshOverview($half, $changed, $region);

			$this->pending = $compact >= 0;
			$newLevel = false;

			for($l = $count - 1; $l >= 0; $l--)
			{
				$level = $this->levels[$l];
				$n = $capacities[$l];
				[$cells,$rows] = $sizes[$l];
				$origin = $level['origin'] ?? null;
				$needsFull = $origin === null
					|| ($changed && $region === null)
					|| abs($origin[0] - $origins[$l][0]) >= $cells
					|| abs($origin[1] - $origins[$l][1]) >= $rows;

				if($needsFull && $newLevel && !$bootstrap)
				{
					// Keep the newly centered coarse rings visible while finer rings reload.
					for($finer = $l; $finer >= 0; $finer--)
					if($this->levels[$finer]) $this->levels[$finer]['origin'] = null;
					$this->pending = true;
					break;
				}

				if(!$level) $this->levels[$l] = $this->allocate($n);

				$newLevel = $newLevel || $needsFull;
				$s = $step * (2 ** $l);
				$this->refresh($this->levels[$l], $origins[$l], $s, 0., $changed, $region, $l, $cells, $rows);
				$this->levels[$l]['base'] = $coverage[$l];
			}

			// The finest level surrounds the camera, so its edits come first; the overview goes last.
			usort(
				$this->paintQueue
				, /**
					 * Order pending paint work by level priority.
					 *
					 * @param array $a First queued job.
					 * @param array $b Second queued job.
					 * @return int Relative ordering of the two jobs.
					 */
				static fn(array $a, array $b) => ($a[0] < 0 ? PHP_INT_MAX : $a[0]) <=> ($b[0] < 0
					? PHP_INT_MAX
					: $b[
				0
					]
				)
			);

			$firstLevel = $this->paintQueue[0][0] ?? null;
			$this->drainPaint($deadline);
			$nextLevel = $this->paintQueue[array_key_first($this->paintQueue)][0] ?? null;

			if($this->paintQueue !== []) $this->pending = true;
		} finally
		{
			glBindFramebuffer(GL_FRAMEBUFFER, is_array($previous) ? $previous[0] : $previous);
		}

		// A paused camera can keep displaying its last frame between completed levels. Rendering the whole scene
		// for each 32-texel upload makes software GPUs starve the very queue that is preparing the new view.
		return $changed || $gridChanged || $firstLevel !== $nextLevel || !$this->pending;
	}

	/**
	 * Move a level to its current origin and refill its exposed or invalidated cells.
	 *
	 * @param array $level Resident grid record with its size and textures.
	 * @param array $origin World-cell origin of the new region.
	 * @param float $step World-space distance between grid samples.
	 * @param float $offset World offset applied when converting cells to sampling positions.
	 * @param bool $changed Whether the stored landscape revision changed.
	 * @param ?array $region Changed world bounds, or null to refresh the full area.
	 * @param int $key Level identifier for the pending work.
	 * @param int $cells Number of grid columns to cover.
	 * @param ?int $rows Number of grid rows to cover.
	 * @return void No value.
	 */
	private function refresh(
		array &$level
		, array $origin
		, float $step
		, float $offset
		, bool $changed
		, ?array $region
		, int $key
		, int $cells
		, ?int $rows = null
	): void {
		$rows ??= $cells;
		[$x,$z] = $origin;
		$refill = $changed && $region === null;

		$rects = self::exposed(
			$x
			, $z
			, $cells
			, $refill ? null : $level['origin']
			, $level['cells'] ?? null
			, $rows
			, $level['rows'] ?? null
		);

		if(!$refill && $changed && $region && $level['origin'] !== null)
		{
			$l = max($x, (int)ceil(($region[0] - $offset) / $step));
			$r = min($x + $cells, (int)floor(($region[2] - $offset) / $step) + 1);
			$f = max($z, (int)ceil(($region[1] - $offset) / $step));
			$b = min($z + $rows, (int)floor(($region[3] - $offset) / $step) + 1);

			if($l < $r && $f < $b) $rects[] = [$l,$f,$r,$b];
		}

		$level['origin'] = $origin;
		$level['step'] = $step;
		$level['cells'] = $cells;
		$level['rows'] = $rows;
		$this->fill($level, $rects, $step, $offset, $key);
	}

	/**
	 * Bind the elevation framebuffer and configure the generator draw.
	 *
	 * @return mixed Previous framebuffer binding to restore after generation.
	 */
	private function beginElevation(): mixed
	{
		$previous = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glBindVertexArray($this->vao[0]);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_BLEND);
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDisable(GL_DITHER);
		$this->generator->use();

		return $previous;
	}

	/**
	 * Update the coarse elevation and paint textures covering the entire landscape.
	 *
	 * @param float $half Half-width of the landscape in meters.
	 * @param bool $changed Whether terrain content has changed since the previous update.
	 * @param ?array $region Changed world bounds, or null to refresh the full area.
	 * @return void No value.
	 */
	private function refreshOverview(float $half, bool $changed, ?array $region): void
	{
		if(!$this->overview || $changed)
		{
			if(!$this->overview) $this->overview = $this->allocate(257);

			$this->refresh($this->overview, [0,0], $half / 128, -$half, $changed, $region, -1, $this->overview['n']);
		}
	}

	/**
	 * Populate an unedited recipe's north-up chart without allocating camera rings or road meshes.
	 *
	 * @param Landscape $land Landscape supplying elevation and committed edits.
	 * @return void No value.
	 */
	public function prepareOverview(Landscape $land): void
	{
		if($land->revision() !== 0) throw new \InvalidArgumentException('A recipe overview requires unedited terrain.');

		$previous = $this->beginElevation();

		try
		{
			glActiveTexture(GL_TEXTURE0);
			$this->generate($land);
			glActiveTexture(GL_TEXTURE0 + 2);
			glBindTexture(GL_TEXTURE_2D, $this->permutation[0]);
			glUniform1i($this->generator->location('uRoadAtlas'), 2);
			glActiveTexture(GL_TEXTURE0);
			$this->refreshOverview($land->recipe()['worldSize'] / 2, true, null);
			$this->paintQueue = [];
		} finally
		{
			glBindFramebuffer(GL_FRAMEBUFFER, is_array($previous) ? $previous[0] : $previous);
		}
	}

	/**
	 * Read the texture handles for the full-landscape overview.
	 *
	 * @return array Overview texture handles or zero handles before allocation.
	 */
	public function overview(): array {return $this->overview['textures'] ?? [0,0];}

	/**
	 * Describe the finest grid near the eye that is at least a given width across, for a pass that reads its heights.
	 *
	 * @param float $width Least width of ground the grid must cover, in meters.
	 * @return ?array The grid's height texture, the meters between its heights, and the lowest X and Z and highest
	 * X and Z, in meters, that heights can be read between with their neighbors; or null while no such grid is ready.
	 */
	public function fine(float $width): ?array
	{
		foreach($this->levels as $level)
		{
			if(!isset($level['origin'], $level['step'], $level['cells']) || $level['cells'] * $level['step'] < $width)
			continue;

			[$x, $z] = $level['origin'];
			$step = $level['step'];
			$rows = $level['rows'] ?? $level['cells'];

			return [
				$level['textures'][0]
				, $step
				, ($x + 2) * $step
				, ($z + 2) * $step
				, ($x + $level['cells'] - 3) * $step
				, ($z + $rows - 3) * $step
			];
		}

		return null;
	}

	/**
	 * Use the finest resident caster grid spanning at least 1.2 km, including terrain behind the camera.
	 *
	 * @param array $eye World-space camera position.
	 * @param array $sun Normalized direction toward the sun.
	 * @param int $revision Committed landscape edit revision.
	 * @return void No value.
	 */
	public function updateShadows(array $eye, array $sun, int $revision): void
	{
		if($sun[1] > 0 && $this->overview)
		{
			foreach($this->spans as $i => $span)
			{
				$step = $this->recipe['spacing'] * (2 ** $i);
				$span = self::centeredSpan($span, $i === 0 && $this->fineFocus !== null);

				if($span * $step < 1200. && $i !== array_key_last($this->spans)) continue;

				$level = $this->levels[$i];

				if(!isset($level['origin'])) break;

				$this->shadows ??= new TriangleShadows;

				$this->shadows->update(
					$level
					, $span
					, $eye
					, $sun
					, $this->overview['textures'][0]
					, $this->recipe['worldSize']
					, $this->packed
					, $this->recipe['surround'] === 'none' ? $this->recipe['worldSize'] / 2 : 0.
					, $revision
					, $this->vao[0]
				);

				return;
			}
		}

		$this->shadows?->invalidate();
	}

	/**
	 * Bind cached terrain shadow data to a receiving program.
	 *
	 * @param Program $program Active GPU program receiving the bindings.
	 * @return void No value.
	 */
	public function bindShadows(Program $program): void
	{
		glUniform2f($program->location('uShadowReceiverRange'), ...$this->shadowReceiverRange);

		if($this->shadows) $this->shadows->bind($program);
		else glUniform3f($program->location('uShadowGrid'), 0, 0, 0);
	}

	/**
	 * Check the active elevation texture representation.
	 *
	 * @return bool Whether the generator uses packed elevation samples.
	 */
	public function packed(): bool {return $this->packed === true;}

	/**
	 * Vertex helpers shared by terrain and overlays drawn on its triangles.
	 *
	 * @return string Shared GLSL vertex sampling functions.
	 */
	public static function gridShader(): string
	{
		return file_get_contents(dirname(__DIR__) . '/shaders/clip-grid.glsl');
	}

	/**
	 * Bind one level and its parent so the shader can stitch their elevations.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param int $index Index of the selected level.
	 * @param array $level Resident grid record with its size and textures.
	 * @return void No value.
	 */
	private function bindGrid(Program $p, Camera $camera, int $index, array $level): void
	{
		[$ox,$oz] = $level['origin'];
		$x = $ox + self::HALO;
		$z = $oz + self::HALO;
		$s = $level['step'];
		$span = $this->spans[$index];
		$flank = $this->roadsideRects[$index] ?? null;
		glUniform1f($p->location('uRoadsidePad'), $flank ? self::roadsidePad($span, $s) : 0.);

		if($flank)
		{
			[$x,$z,$right,$bottom] = $flank;
			glUniform2i($p->location('uRingMax'), $right, $bottom);
			[$ox,$oz,$n] = $this->roadsideWindows[$index];

			glUniform3f(
				$p->location('uRoadsideUV')
				, (.5 - $ox) / $n
				, (.5 - $oz) / $n
				, 1. / ($n * RoadsideDetail::CELL)
			);

			glUniform3f(
				$p->location('uParentSafe')
				, $camera->position[0] / $s
				, $camera->position[2] / $s
				, 2 * ($this->spans[$index + 1] / 2 - self::transition($this->spans[$index + 1]) - 3)
			);

			glActiveTexture(GL_TEXTURE0 + 10);
			glBindTexture(GL_TEXTURE_2D, $this->roadsideTextures[$index][0]);
		}

		glUniform1i($p->location('uRoadsideMask'), $flank ? 10 : 7);
		glUniform2i($p->location('uRingMin'), $x, $z);
		glUniform1i($p->location('uSpan'), $span);
		glUniform1i($p->location('uHasParent'), (int)isset($this->levels[$index + 1]['origin']));
		// Morph around the same continuous center used to snap this ring, including terrain overlays.
		$focus = $index === 0 ? $this->fineFocus : null;

		glUniform2f(
			$p->location('uEyeCell')
			, ($focus[0] ?? $camera->position[0]) / $s
			, ($focus[1] ?? $camera->position[2]) / $s
		);

		glUniform1f($p->location('uMorphWidth'), self::transition($span));
		glUniform1f($p->location('uGridStep'), $s);
		glUniform1i($p->location('uTextureMask'), $level['n'] - 1);

		foreach($level['textures'] as $i => $tex)
		{
			glActiveTexture(GL_TEXTURE0 + 7 + $i);
			glBindTexture(GL_TEXTURE_2D, $tex);
		}
	}

	/**
	 * Report the rectangle a resident terrain grid covers. An overlay sizes itself to this before calling
	 * drawGroundPatch() on the finest grid or drawGroups() on the first few.
	 * @param int $index Grid to report, zero for the finest. Each one after it has twice the spacing and reach.
	 * @return array|null Minimum X and Z, then maximum X and Z, in meters, or null while that grid is not resident.
	 */
	public function groundPatchWindow(int $index = 0): ?array
	{
		$level = $this->levels[$index] ?? null;

		if(!isset($level['origin'])) return null;

		$s = $level['step'];
		$x = $level['origin'][0] + self::HALO;
		$z = $level['origin'][1] + self::HALO;
		$span = $this->spans[$index];
		[$l, $t, $r, $b] = $this->roadsideRects[$index] ?? [$x, $z, $x + $span, $z + $span];
		$window = [$l * $s, $t * $s, $r * $s, $b * $s];

		if($this->recipe['surround'] !== 'none') return $window;

		$half = $this->recipe['worldSize'] / 2;

		return [
			max($window[0], -$half)
			, max($window[1], -$half)
			, min($window[2], $half)
			, min($window[3], $half)
		];
	}

	/**
	 * Redraw a rectangle on the finest resident terrain grid using the active overlay program. Bounds are [minX, minZ,
	 * maxX, maxZ] in meters. An uncovered rectangle is left to the caller.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param array $bounds Horizontal limits of the affected area.
	 * @return bool Whether the requested patch was drawn.
	 */
	public function drawGroundPatch(Program $p, Camera $camera, array $bounds): bool
	{
		$level = $this->levels[0] ?? null;

		if(!isset($level['origin']) || $bounds[2] <= $bounds[0] || $bounds[3] <= $bounds[1]) return false;

		$s = $level['step'];
		$left = (int)floor($bounds[0] / $s);
		$top = (int)floor($bounds[1] / $s);
		$right = (int)ceil($bounds[2] / $s);
		$bottom = (int)ceil($bounds[3] / $s);
		$x = $level['origin'][0] + self::HALO;
		$z = $level['origin'][1] + self::HALO;
		[$l,$t,$r,$b] = $this->roadsideRects[0] ?? [$x,$z,$x + $this->spans[0],$z + $this->spans[0]];

		if($left < $l || $top < $t || $right > $r || $bottom > $b) return false;

		if($this->recipe['surround'] === 'none')
		{
			$half = $this->recipe['worldSize'] / 2;

			if($left * $s < -$half || $top * $s < -$half || $right * $s > $half || $bottom * $s > $half) return false;
		}

		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1i($p->location('uElevations'), 7);
		glUniform1i($p->location('uInk'), 8);
		$this->bindGrid($p, $camera, 0, $level);
		glBindVertexArray($this->vao[0]);
		glUniform2i($p->location('uDrawCell'), $left, $top);
		glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 2 * ($right - $left + 1), $bottom - $top);
		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);

		return true;
	}

	/**
	 * Draw a solid inner grid and rectangular strips around each finer level.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param array $planes Camera frustum planes.
	 * @param array $vertical Conservative terrain elevation interval for culling.
	 * @param bool $water Elevation of the water surface in meters.
	 * @param int $materialRegion Restrict drawing to this journey material region.
	 * @return int Number of terrain draw calls submitted.
	 */
	public function draw(
		Program $p
		, Camera $camera
		, array $planes
		, array $vertical
		, bool $water = false
		, int $materialRegion = -1
	): int {
		return $this->drawGroups(
			$p
			, $camera
			, $this->visibleGroups($camera, $planes, $vertical, $water, $materialRegion)
		);
	}

	/**
	 * Cull once when multiple ground passes draw the same stitched geometry.
	 *
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param array $planes Camera frustum planes.
	 * @param array $vertical Elevation interval for terrain culling.
	 * @param bool $water Elevation of the water surface in meters.
	 * @param int $materialRegion Restrict drawing to this journey material region.
	 * @return array Visible draw strips grouped by clipmap level.
	 */
	public function visibleGroups(
		Camera $camera
		, array $planes
		, array $vertical
		, bool $water = false
		, int $materialRegion = -1
	): array {
		$groups = [];
		$inner = null;
		$halfWorld = $this->recipe['worldSize'] / 2;
		$half = $this->recipe['surround'] === 'none' ? $halfWorld : 0;

		foreach($this->levels as $index => $level)
		{
			if(!isset($level['origin'])) continue;

			[$ox,$oz] = $level['origin'];
			$span = $this->spans[$index];
			$offset = self::HALO;
			$x = $ox + $offset;
			$z = $oz + $offset;
			$r = $x + $span;
			$b = $z + $span;

			if(isset($this->roadsideRects[$index])) [$x,$z,$r,$b] = $this->roadsideRects[$index];

			$s = $level['step'];

			if($inner === null) $rects = [[$x,$z,$r,$b]];
			else
			{
				[$l,$t,$rr,$bb] = $inner;
				$rects = [[$x,$z,$r,$t],[$x,$bb,$r,$b],[$x,$t,$l,$bb],[$rr,$t,$r,$bb]];
			}

			$inner = [intdiv($x, 2),intdiv($z, 2),intdiv($r, 2),intdiv($b, 2)];
			$visible = [];

			foreach($rects as [$left,$top,$right,$bottom])
			{
				if($half > 0)
				{
					$limit = (int)ceil($half / $s);
					$left = max($left, -$limit);
					$top = max($top, -$limit);
					$right = min($right, $limit);
					$bottom = min($bottom, $limit);
				}

				// Clip the draw to the part where this material can contribute, including cells across the seam.
				if($materialRegion >= 0)
				{
					$edge = (int)ceil(JoinedTerrain::GAP / (2 * $s));

					if($materialRegion === 0) $right = min($right, $edge);
					else
					{
						$left = max($left, -$edge);

						if(count($this->recipe['join']['destinations']) === 2)
						{
							if($materialRegion === 1) $bottom = min($bottom, $edge);
							else $top = max($top, -$edge);
						}
					}
				}

				$width = $right - $left;
				$rows = $bottom - $top;

				if($width <= 0 || $rows <= 0) continue;

				$box = ['x' => $left * $s, 'z' => $top * $s, 'size' => $width * $s, 'depth' => $rows * $s];

				if(Camera::rejectingPlane($planes, $camera->position, $box, $vertical[0], $vertical[1], -1) >= 0)
				continue;

				if($this->recipe['wallHeight'] <= 0 && $span <= 120)
				{
					$visible[] = [$left,$top,$right,$bottom];
					continue;
				}

				// World-aligned blocks reuse conservative bounds as the clipmap moves across them.
				$blockSize = 64;
				$stride = 2 ** $index;
				$previous = [];

				for($front = (int)floor($top / $blockSize) * $blockSize; $front < $bottom; $front += $blockSize)
				{
					$z0 = max($front, $top);
					$lines = min($front + $blockSize, $bottom) - $z0;
					$row = [];
					$run = null;

					for($west = (int)floor($left / $blockSize) * $blockSize; $west < $right; $west += $blockSize)
					{
						$x0 = max($west, $left);
						$columns = min($west + $blockSize, $right) - $x0;
						$block = ['x' => $x0 * $s, 'z' => $z0 * $s, 'size' => $columns * $s, 'depth' => $lines * $s];
						[$low,$high] = $vertical;

						if($this->source !== null && $blockSize * $s <= 512
							&& (min($west, $front) - 2) * $s >= -$halfWorld
							&& (max($west, $front) + $blockSize + 2) * $s <= $halfWorld
						){
							[$groundMin,$groundMax] = $this->source->renderBlockRange(
								$west * $stride
								, $front * $stride
								, $blockSize * $stride
								, 2 * $stride
							);

							// Allow for the float elevation shader's rounding relative to the PHP field.
							if($water && $groundMin > $high + .05) continue;

							if(!$water) [$low,$high] = [$groundMin - .05, $groundMax + .05];
						}

						if(Camera::rejectingPlane($planes, $camera->position, $block, $low, $high) >= 0) continue;

						// Collect continuous visible columns, then join equal spans in adjacent rows.
						if($run !== null && $row[$run] === $x0) $row[$run] += $columns;
						else
						{
							$run = $x0;
							$row[$run] = $x0 + $columns;
						}
					}

					foreach($row as $x0 => $x1)
					{
						$above = $previous[$x0] ?? null;

						if($above !== null && $visible[$above][2] === $x1 && $visible[$above][3] === $z0)
						$visible[$above][3] = $z0 + $lines;
						else
						{
							$previous[$x0] = count($visible);
							$visible[] = [$x0, $z0, $x1, $z0 + $lines];
						}
					}
				}
			}

			if($visible) $groups[$index] = $visible;
		}

		return $groups;
	}

	/** Intersect a world-space decal footprint with the strips actually drawn at each LOD. */
	public function drawRegion(Program $program,Camera $camera,array $visible,array $bounds): void
	{
		$this->drawRegions($program,$camera,$visible,[$bounds]);
	}

	/**
	 * Draw disjoint decal footprints together so their strips share grid bindings.
	 * Each region is keyed by its material and contains [minX, minZ, maxX, maxZ] in world space.
	 */
	public function drawRegions(
		Program $program, Camera $camera, array $visible, array $regions, ?callable $bindRegion = null
	): void {
		if($groups = $this->overlayGroups($visible, $regions)) $this->drawGroups($program, $camera, $groups, $bindRegion);
	}

	/** A moving camera can keep the same integer strips on some levels while others scroll. */
	private function overlayGroups(array $visible, array $regions): array
	{
		if($regions !== $this->overlayRegions) {
			$this->overlayRegions = $regions;
			$this->overlayClips = [];
		}
		$groups = [];
		foreach($visible as $level => $strips) {
			$step = $this->levels[$level]['step'];
			$cached = $this->overlayClips[$level] ?? null;
			if($cached !== null && $step === $cached[0] && $strips === $cached[1]) {
				if($cached[2]) $groups[$level] = $cached[2];
				continue;
			}
			foreach($strips as [$x0, $z0, $x1, $z1]) {
				// Stitched vertices may use the next cell. Reject misses before converting world bounds to cells.
				$left = ($x0 - 1) * $step; $top = ($z0 - 1) * $step;
				$right = ($x1 + 1) * $step; $bottom = ($z1 + 1) * $step;
				foreach($regions as $key => [$l, $t, $r, $b]) {
					if($l >= $right || $r <= $left || $t >= $bottom || $b <= $top) continue;
					$groups[$level][] = [
						max($x0, (int)floor($l / $step) - 1), max($z0, (int)floor($t / $step) - 1),
						min($x1, (int)ceil($r / $step) + 1), min($z1, (int)ceil($b / $step) + 1), $key
					];
				}
			}
			$this->overlayClips[$level] = [$step, $strips, $groups[$level] ?? []];
		}
		return $groups;
	}

	/**
	 * Submit the previously culled terrain strips for this program.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @param Camera $camera Camera defining the viewpoint and projection.
	 * @param array $groups Visible clipmap strips from the culling pass.
	 * @param callable|null $bindRegion Selects the material for strips tagged with a region key.
	 * @return int Number of submitted draws.
	 */
	public function drawGroups(Program $p, Camera $camera, array $groups, ?callable $bindRegion = null): int
	{
		$count = 0;
		$activeRegion = null;
		$drawCell = $p->location('uDrawCell');
		glBindVertexArray($this->vao[0]);
		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1i($p->location('uElevations'), 7);
		glUniform1i($p->location('uInk'), 8);
		$halfWorld = $this->recipe['worldSize'] / 2;
		$half = $this->recipe['surround'] === 'none' ? $halfWorld : 0;
		glUniform1f($p->location('uPlayHalf'), $half);

		foreach($groups as $index => $visible)
		{
			$this->bindGrid($p, $camera, $index, $this->levels[$index]);

			foreach($visible as $strip)
			{
				if($bindRegion !== null && $strip[4] !== $activeRegion) {
					$activeRegion = $strip[4];
					$bindRegion($activeRegion);
				}
				[$left,$top,$right,$bottom] = $strip;
				glUniform2i($drawCell, $left, $top);
				glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 2 * ($right - $left + 1), $bottom - $top);
			}

			$count += count($visible);
		}

		return $count;
	}

	/**
	 * World meters across the heading-up, vehicle-centered chart.
	 *
	 * @param float $extent Width of the landscape in world units.
	 * @param array $position Center of the chart in world coordinates.
	 * @param float $bearing Chart rotation in radians.
	 * @param bool $wide Use the wider map view.
	 * @return float World width of the vehicle chart in meters.
	 */
	public static function chartSpan(float $extent, array $position, float $bearing, bool $wide): float
	{
		// At the middle of the world, even a 45° chart fits at this scale.
		$maximum = $extent / M_SQRT2;
		$local = min(4000., $maximum / 1.5);

		if(!$wide) return $local;

		$zoomedOut = min(12000., $maximum);
		$diagonal = abs(cos($bearing)) + abs(sin($bearing));
		$edgeRoom = $extent / 2 - max(abs($position[0]), abs($position[1]));
		$fits = 2 * $edgeRoom / $diagonal;

		// Near the boundary, leave a useful difference between the two zoom levels.
		return max(min($zoomedOut, 1.5 * $local), min($zoomedOut, $fits));
	}

	/**
	 * Sample a zoomed editor view on its own lattice when the driving camera is elsewhere.
	 *
	 * @param array $center World-space center of the requested chart.
	 * @param float $span Width of the grid at this level.
	 * @param array $resident Grid level already available for sampling.
	 * @param int $cells Number of grid columns to cover.
	 * @return array Selected grid and number of cells across the map.
	 */
	private function editorLevel(array $center, float $span, array $resident, int $cells): array
	{
		$n = 128;
		$step = $this->recipe['spacing'];

		while(($n - 4) * $step < $span) $step *= 2;

		if($step >= $this->overview['step']) return [$resident,$cells];

		if($cells && $resident['step'] <= $step)
		{
			[$x,$z] = $resident['base'];
			$s = $resident['step'];

			if($center[0] - $span / 2 >= $x * $s && $center[0] + $span / 2 < ($x + $cells - 1) * $s
				&& $center[1] - $span / 2 >= $z * $s && $center[1] + $span / 2 < ($z + $cells - 1) * $s
			) return [$resident,$cells];
		}

		$base = [(int)floor($center[0] / $step) - intdiv($n, 2),(int)floor($center[1] / $step) - intdiv($n, 2)];
		$old = $this->chartDetail;

		if(($old['step'] ?? null) === $step && $old['origin'] === $base) return [$old,$n];

		$previous = $this->beginElevation();

		try
		{
			glActiveTexture(GL_TEXTURE0);

			if(!$old) $this->chartDetail = $this->allocate($n);

			glActiveTexture(GL_TEXTURE0 + 2);
			glBindTexture(GL_TEXTURE_2D, $this->roadTexture[0] ?? $this->permutation[0]);
			glUniform1i($this->generator->location('uRoadAtlas'), 2);
			$refill = ($old['step'] ?? null) !== $step || $old['origin'] === null;

			if($refill)
			$this->paintQueue = array_values(
				array_filter(
					$this->paintQueue
					, /**
					 * Retain paint jobs unrelated to the superseded chart detail.
					 *
					 * @param array $entry Queued paint work item.
					 * @return bool Whether the job still applies.
					 */
					static fn($entry) => $entry[0] !== self::CHART_DETAIL
				)
			);

			$this->refresh($this->chartDetail, $base, $step, 0., $refill, null, self::CHART_DETAIL, $n);
			$this->chartDetail['base'] = $base;

			if($this->paintQueue) $this->pending = true;
		} finally
		{
			glBindFramebuffer(GL_FRAMEBUFFER, is_array($previous) ? $previous[0] : $previous);
		}

		return [$this->chartDetail,$n];
	}

	/**
	 * Return the drawn span for overlays that need the same world-to-chart scale.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @param array $position Center of the chart in world coordinates.
	 * @param float $bearing Chart rotation in radians.
	 * @param bool $wide Use the wider map view.
	 * @param int $w Render target width in pixels.
	 * @param int $h Render target height in pixels.
	 * @param float $water Elevation of the water surface in meters.
	 * @param array $waterColor Linear RGB used for water in the map.
	 * @param SurfaceLayers $layers Ordered material layers.
	 * @param TerrainAppearance $appearance Surface colors and material defaults for this landscape.
	 * @param ?int $size Side length of the requested grid.
	 * @param ?float $localView Optional local map coverage in meters.
	 * @param bool $circular Clip the map to a circular boundary.
	 * @param ?float $fixedSpan Optional fixed map width in meters.
	 * @param int $regions Number of material regions being drawn.
	 * @param int $regionIndex Index of the material region being drawn.
	 * @param array $origin Origin used to position this grid or palette.
	 * @param ?string $seed Seed for the procedural material field.
	 * @param bool $sharedPalette Use palette uniforms already bound by the scene.
	 * @return ?float Width of the rendered chart in meters, or null if unavailable.
	 */
	public function chart(
		Program $p
		, array $position
		, float $bearing
		, bool $wide
		, int $w
		, int $h
		, float $water
		, array $waterColor
		, SurfaceLayers $layers
		, TerrainAppearance $appearance
		, ?int $size = null
		, ?float $localView = null
		, bool $circular = false
		, ?float $fixedSpan = null
		, int $regions = 1
		, int $regionIndex = 0
		, array $origin = [0.,0.,0.]
		, ?string $seed = null
		, bool $sharedPalette = false
	): ?float {
		if(!$this->overview) return null;

		$p->use();

		if($p !== $this->boundChartProgram || $layers !== $this->chartLayers || $appearance !== $this->chartAppearance)
		{
			$appearance->bindPaint($p);
			$this->chartAppearance = $appearance;

			if(!$sharedPalette) $layers->bind($p);

			$this->chartLayers = $layers;
			$this->boundChartProgram = $p;
		}

		if(!$sharedPalette)
		{
			$this->paletteOrigin($p, $seed);
			glUniform3f($p->location('uJoinMaterial'), $regions > 1 ? $regions : 0, $regionIndex, JoinedTerrain::GAP);
			glUniform3f($p->location('uMaterialOrigin'), ...$origin);
		}

		$n = min($size ?? (int)round(min(184, $w * .235, $h * .245)), $w - 10, $h - 10);

		if($n <= 0) return null;

		$c = cos($bearing);
		$s = sin($bearing);
		$extent = $this->recipe['worldSize'];
		$span = self::chartSpan($extent, $position, $bearing, $wide);

		if($fixedSpan !== null) $span = max(1., min($extent, $fixedSpan));
		elseif(!$wide && $localView !== null) $span = max(1., min($span, $localView));

		$level = $this->overview;
		$localSpan = 0;

		foreach($this->levels as $index => $resident)
		{
			if(!isset($resident['origin'])) continue;

			// Outside a resident patch the chart samples the fixed overview. A coarser ring
			// cannot improve on that map, even when it covers the entire requested view.
			if($resident['step'] >= $this->overview['step']) break;

			$level = $resident;
			$localSpan = $this->spans[$index];

			if($level['step'] * $localSpan >= $span * 1.5) break;
		}

		if($fixedSpan !== null && $bearing === 0. && $span < $extent)
		{
			[$level,$localSpan] = $this->editorLevel($position, $span, $level, $localSpan);
			$p->use();
		}

		$step = $level['step'];
		glUniform2f($p->location('uHeading'), $c, $s);
		glUniform1i($p->location('uCircular'), (int)$circular);
		glUniform4f($p->location('uChart'), $position[0], $position[1], $span, $n);

		glUniform4f(
			$p->location('uLocal')
			, ($level['base'][0] ?? $level['origin'][0] + self::HALO) * $step
			, ($level['base'][1] ?? $level['origin'][1] + self::HALO) * $step
			, $step
			, $localSpan
		);

		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1f($p->location('uExtent'), $extent);
		glUniform1f($p->location('uWaterLevel'), $water);
		glUniform3f($p->location('uWaterColor'), ...$waterColor);
		glUniform2f($p->location('uViewportOrigin'), 10, 10);
		$names = ['uOverview','uMapInk','uFine','uFineInk'];

		foreach([...$this->overview['textures'],...$level['textures']] as $i => $tex)
		{
			glActiveTexture(GL_TEXTURE0 + $i);
			glBindTexture(GL_TEXTURE_2D, $tex);
			glUniform1i($p->location($names[$i]), $i);
		}

		glViewport(10, 10, $n, $n);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_DEPTH_TEST);

		if($regionIndex > 0)
		{
			glEnable(GL_BLEND);
			glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
		} else glDisable(GL_BLEND);

		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glViewport(0, 0, $w, $h);

		if($regionIndex > 0) glDisable(GL_BLEND);

		return $span;
	}

	/**
	 * Upload the seeded origin used to sample material fields.
	 *
	 * @param Program $p Active GPU program receiving terrain uniforms.
	 * @param ?string $seed Seed for the procedural material field.
	 * @return void No value.
	 */
	public function paletteOrigin(Program $p, ?string $seed = null): void
	{
		$seed = crc32($seed ?? (string)$this->recipe['seed']);
		glUniform2f($p->location('uPaletteOffset'), $seed & 65535, ($seed >> 16) & 65535);
	}

	/**
	 * Read clipmap coverage and workload counters.
	 *
	 * @return array Current residency, generation and drawing statistics.
	 */
	public function stats(): array
	{
		$live = array_filter(
			$this->levels
			, /**
			 * Select the levels which have a resident grid origin.
			 *
			 * @param array $level Clipmap level state.
			 * @return bool Whether the level has been positioned.
			 */
			static fn(array $level) => isset($level['origin'])
		);

		$spacing = $live ? reset($live)['step'] : null;
		$texels = ($this->overview['n'] ?? 0) ** 2 + ($this->chartDetail['n'] ?? 0) ** 2;

		foreach($this->levels as $level) $texels += ($level['n'] ?? 0) ** 2;
		$coverage = $roadside = [];

		foreach($live as $index => $level)
		{
			[$left,$front] = $level['base'];
			$step = $level['step'];

			if(isset($this->roadsideRects[$index]))
			{
				$roadside[$index] = [];

				foreach($this->roadsideRects[$index] as $bound) $roadside[$index][] = $bound * $step;
			}

			$coverage[] = [$left * $step, $front * $step, $this->spans[$index] * $step, $step];
		}

		return [
			'backend' => 'clipmap'
			, 'patches' => count($live)
			, 'visible' => count($live)
			, 'pending' => $this->pending ? max(1, count($this->levels) - count($live)) : 0
			, 'cameraSpacing' => $spacing
			, 'finestVisibleSpacing' => $spacing
			, 'visibleCoverage' => $coverage
			, 'roadsideCoverage' => $roadside[0] ?? null
			, 'roadsideRings' => $roadside
			, 'heightTexelsWritten' => $this->texels
			, 'editUploadBytes' => $this->uploads
			, 'triangleShadowGeneration' => $this->shadows?->generation ?? 0
			, 'roadOverlayBuilds' => $this->roadOverlayBuilds
			, 'roadTextureBytes' => $this->roadBytes
			, 'meshBytes' => 0
			, 'terrainTextureBytes' => 256 + $this->roadBytes + array_sum(
				array_map(
					/**
					 * Count the cells in one square roadside allocation.
					 *
					 * @param int $size Side length in cells.
					 * @return int Area in cells.
					 */
					static fn($size) => $size ** 2
					, $this->roadsideSizes
				)
			) + ($this->packed ? 8 : 20) * $texels
			, 'proceduralVertexBytes' => 0
			, 'packedHeight' => $this->packed
		];
	}

	/**
	 * Release the programs, framebuffers, textures and geometry owned by the clipmap.
	 *
	 * @return void No value.
	 */
	public function close(): void
	{
		foreach($this->roadsideTextures as $texture) glDeleteTextures(1, $texture);

		foreach($this->levels as $level) self::release($level);
		self::release($this->overview);
		self::release($this->chartDetail);

		if($this->roadTexture) glDeleteTextures(1, $this->roadTexture);

		glDeleteTextures(1, $this->permutation);
		glDeleteVertexArrays(1, $this->vao);
		glDeleteFramebuffers(1, $this->framebuffer);
		$this->generator->close();
		$this->shadows?->close();
	}
}

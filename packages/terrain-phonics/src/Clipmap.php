<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** A camera-centered stack of toroidal GPU elevation textures and one world overview. */
final class Clipmap
{
	private Program $generator, $chart;
	private ?TriangleShadows $shadows = null;
	private ?SurfaceLayers $chartLayers = null;
	private array $vao = [], $framebuffer = [], $permutation = [], $levels = [], $overview = [];
	private ?Landscape $source = null;
	private EditIndex $edits;
	private ?EditIndex $overviewEdits = null;
	private array $roads = [], $roadTexture = [];
	private int $roadBytes = 0;
	// Parent normals sample two cells beyond the drawn grid.
	private const HALO = 2;
	private int $revision = -1;
	private array $spans = [];
	private array $shadowReceiverRange = [0.,0.];
	private ?array $dirtyRegion = null;

	public function invalidate(?array $region = null): void
	{
		$this->dirtyRegion = $region;
		$this->revision = -1;
	}

	private ?bool $packed = null;
	private array $recipe = [];
	public bool $pending = true;
	public int $texels = 0, $uploads = 0;

	public function __construct()
	{
		$root = dirname(__DIR__) . '/shaders/';

		$this->generator = new Program(
			file_get_contents($root . 'shadows.vert')
			, file_get_contents($root . 'elevation.frag')
		);

		$this->chart = new Program(
			file_get_contents($root . 'shadows.vert')
			, str_replace(
				'// LAYERS'
				, file_get_contents($root . 'surface-layers.glsl')
				, file_get_contents($root . 'chart.frag')
			)
		);

		glGenVertexArrays(1, $this->vao);
		glGenFramebuffers(1, $this->framebuffer);
		glGenTextures(1, $this->permutation);
	}

	private static function texture(int $id): void
	{
		glBindTexture(GL_TEXTURE_2D, $id);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
	}

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

	private function generate(Landscape $land): void
	{
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
		if($roads !== $this->roads || ($roads && $r['worldSize'] !== ($this->recipe['worldSize'] ?? null)))
			$this->uploadRoads($roads, $r['worldSize']);

		if(!$newRecipe) return;

		$this->recipe = $r;
		self::texture($this->permutation[0]);
		$perm = (new Noise($r['seed']))->permutation();
		glTexImage2D(GL_TEXTURE_2D, 0, GL_R8, 256, 1, 0, GL_RED, GL_UNSIGNED_BYTE, pack('C*', ...$perm));
		$p = $this->generator;

		foreach([
			'uShape' => ['baseBias','rolling','directionalRise','ridgeHeight']
			, 'uProfile' => ['ridgeRoundness','wallHeight','floorWidth','wallWidth']
			, 'uField' => ['heightScale','baseFrequency','warpAmplitude','warpFrequency']
			, 'uRock' => ['secondary','terraceSpacing','terraceStrength','bedding']
			, 'uDetail' => ['detailAmplitude','persistence','lacunarity','damping']
		] as $uniform => $fields) glUniform4f($p->location($uniform), ...array_map(static fn($key) => $r[$key], $fields));

		$residual = static fn(float $f): float => $f - unpack('g', pack('g', $f))[1];
		glUniform2f($p->location('uFieldResidual'), $residual($r['baseFrequency']), $residual($r['warpFrequency']));
		// Rounding the already-converted base frequency times 22 moves the drainage
		// contours at large coordinates. Pass the exact recipe product and its residual.
		$erosion = 22 * $r['baseFrequency'];
		glUniform2f($p->location('uErosion'), $erosion, $residual($erosion));
		glUniform1f($p->location('uSlopeScale'), $r['slopeScale']);
		glUniform1i($p->location('uOctaves'), $r['detailOctaves']);

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
			glDeleteTextures(1, $this->roadTexture);
			$this->roadTexture = [];
			$this->roadBytes = 0;
			return;
		}
		$atlas = RoadAtlas::build($roads, $extent);
		if(!$this->roadTexture) glGenTextures(1, $this->roadTexture);
		self::texture($this->roadTexture[0]);
		glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA32F, $atlas['width'], $atlas['height'], 0, GL_RGBA, GL_FLOAT, $atlas['bytes']);
		$this->roadBytes = strlen($atlas['bytes']);
		$this->uploads += $this->roadBytes;
		glUniform4i($p->location('uRoadGrid'), $atlas['directory'], $atlas['blocksAcross'], $atlas['minTile'], (int)$atlas['cell']);
	}

	/** Left/right strips plus top/bottom strips over the shared X interval. Half-open integer rectangles. */
	public static function exposed(int $x, int $z, int $n, ?array $old): array
	{
		if($old === null || abs($old[0] - $x) >= $n || abs($old[1] - $z) >= $n) return [[$x,$z,$x + $n,$z + $n]];

		[$ox,$oz] = $old;
		$rects = [];

		if($x < $ox) $rects[] = [$x,$z,$ox,$z + $n];

		if($x > $ox) $rects[] = [$ox + $n,$z,$x + $n,$z + $n];

		$l = max($x, $ox);
		$r = min($x, $ox) + $n;

		if($z < $oz) $rects[] = [$l,$z,$r,$oz];

		if($z > $oz) $rects[] = [$l,$oz + $n,$r,$z + $n];

		return $rects;
	}

	private function fill(array $level, array $rects, float $step, float $offset): void
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

		foreach($rects as [$x0,$z0,$x1,$z1])
		{
			for($z = $z0; $z < $z1; $z += $h)
			for($x = $x0; $x < $x1; $x += $w)
			{
				$u = (($x % $n) + $n) % $n;
				$v = (($z % $n) + $n) % $n;
				$w = min($x1 - $x, $n - $u);
				$h = min($z1 - $z, $n - $v);
				glViewport($u, $v, $w, $h);
				glUniform2i($p->location('uOriginCell'), $x, $z);
				glUniform2i($p->location('uPixelOrigin'), $u, $v);
				glDrawArrays(GL_TRIANGLES, 0, 3);
				$this->texels += $w * $h;
				$this->paint($level['textures'], $x, $z, $u, $v, $w, $h, $step, $offset);
			}
		}
	}

	private function paint(
		array $textures
		, int $x
		, int $z
		, int $u
		, int $v
		, int $w
		, int $h
		, float $step
		, float $offset
	): void {
		$index = $offset !== 0. && $this->overviewEdits ? $this->overviewEdits : $this->edits;
		$regions = $index->intersect(
			[$x * $step + $offset,$z * $step + $offset,($x + $w - 1) * $step + $offset,($z + $h - 1) * $step + $offset]
		);

		if(!$regions) return;

		foreach($regions as [$left,$front,$right,$back])
		{
			$l = (int)ceil(($left - $offset) / $step);
			$r = (int)floor(($right - $offset) / $step);
			$f = (int)ceil(($front - $offset) / $step);
			$b = (int)floor(($back - $offset) / $step);

			if($l > $r || $f > $b) continue;

			$heights = $ink = '';

			for($j = $f; $j <= $b; $j++)
			for($i = $l; $i <= $r; $i++)
			{
				$wx = $i * $step + $offset;
				$wz = $j * $step + $offset;
				$height = $this->source->height($wx, $wz);
				$id = array_search($this->source->material($wx, $wz), RoadAtlas::MATERIALS, true);
				$heights .= $this->packed ? pack('g', $height) : pack('g4', $height, 0, 0, 1);
				$ink .= pack('C4', $id === false?0:$id + 1, 0, 0, 255);
			}

			glActiveTexture(GL_TEXTURE0 + 1);

			foreach([$heights,$ink] as $k => $bytes)
			{
				glBindTexture(GL_TEXTURE_2D, $textures[$k]);

				glTexSubImage2D(
					GL_TEXTURE_2D
					, 0
					, $u + $l - $x
					, $v + $f - $z
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
	}

	private static function release(array $level): void
	{
		if($level) glDeleteTextures(2, $level['textures']);
	}

	/** Grid widths, from the near field to the horizon, in cells of their own level. */
	public static function spans(array $recipe, float $quality): array
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

			// Keep the inner ring and its snap margin away from this ring's outer blend.
			while($span / 2 - self::transition($span) < $inner / 4 + 4) $span += 4;
			$spans[] = $inner = $span;
			if(($span / 2 - 2) * $step >= $reach) break;
			$step *= 2;
		}
		while(true);

		return $spans;
	}

	public static function transition(int $span): float
	{
		return max(2., min(20., $span * .1, $span / 4 - 4));
	}

	private static function storageSize(int $span): int
	{
		$size = 1;
		while($size < $span + 1 + 2 * self::HALO) $size *= 2;

		return $size;
	}

	/** Refresh only cells newly exposed to the camera or changed by committed edits. */
	public function update(Landscape $land, Camera $camera, float $quality): bool
	{
		$changed = $this->source !== $land || $this->revision !== $land->revision();
		$region = $this->dirtyRegion;
		$this->dirtyRegion = null;
		$recipe = $land->recipe();
		$step = $recipe['spacing'];
		$spans = self::spans($recipe, $quality);
		$count = count($spans);
		$origins = [];

		foreach($spans as $l => $span)
		{
			$s = $step * (2 ** $l);
			$margin = intdiv($span, 2) + self::HALO;
			$origins[$l] = [
				2 * (int)floor($camera->position[0] / (2 * $s)) - $margin
				, 2 * (int)floor($camera->position[2] / (2 * $s)) - $margin
			];
		}

		if(!$changed && !$this->pending && $this->spans === $spans && $origins === array_column($this->levels, 'origin'))
		return false;

		$previous = glGetIntegerv(GL_FRAMEBUFFER_BINDING);
		glBindFramebuffer(GL_FRAMEBUFFER, $this->framebuffer[0]);
		glBindVertexArray($this->vao[0]);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_BLEND);
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDisable(GL_DITHER);
		$this->generator->use();

		try
		{
			glActiveTexture(GL_TEXTURE0);
			if($changed) $this->generate($land);
			if($changed || $this->spans !== $spans)
			{
				// Fade out the actual mesh elevation before the overview and the mesh have
				// comparable spacing. The inner part of each ring precedes its morph strip.
				$texel = $recipe['worldSize']/256.;
				$near = 0.;
				$far = $step;
				foreach($spans as $i => $span)
				{
					$spacing = $step*(2 ** $i);
					if($spacing > $texel/2.) break;
					$far = ($span/2-self::transition($span)-3)*$spacing;
					if($spacing <= $texel/8.) $near = $far;
				}
				$this->shadowReceiverRange = [$near,max($near+$step,$far)];
			}
			glActiveTexture(GL_TEXTURE0 + 2);
			glBindTexture(GL_TEXTURE_2D, $this->roadTexture[0] ?? $this->permutation[0]);
			glUniform1i($this->generator->location('uRoadAtlas'), 2);
			glActiveTexture(GL_TEXTURE0);

			if($this->spans !== $spans)
			{
				foreach($this->levels as $level) self::release($level);
				$this->levels = array_fill(0, $count, []);
				$this->spans = $spans;
			}

			$half = $this->recipe['worldSize'] / 2;

			if(!$this->overview || $changed)
			{
				if(!$this->overview) $this->overview = $this->allocate(257);

				$this->refresh($this->overview, [0,0], $half / 128, -$half, $changed, $region);
			}

			$this->pending = false;
			$newLevel = false;

			for($l = $count - 1; $l >= 0; $l--)
			{
				$level = $this->levels[$l];
				$n = $level['n'] ?? self::storageSize($spans[$l]);
				$origin = $level['origin'] ?? null;
				$needsFull = $origin === null
					|| ($changed && $region === null)
					|| abs($origin[0] - $origins[$l][0]) >= $n
					|| abs($origin[1] - $origins[$l][1]) >= $n;

				if($needsFull && $newLevel)
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
				$this->refresh($this->levels[$l], $origins[$l], $s, 0., $changed, $region);
			}
		} finally
		{
			glBindFramebuffer(GL_FRAMEBUFFER, is_array($previous) ? $previous[0] : $previous);
		}

		return true;
	}

	private function refresh(
		array &$level
		, array $origin
		, float $step
		, float $offset
		, bool $changed
		, ?array $region
	): void {
		[$x,$z] = $origin;
		$n = $level['n'];
		$rects = self::exposed($x, $z, $n, $changed && $region === null ? null : $level['origin']);

		if($changed && $region && $level['origin'] !== null)
		{
			$l = max($x, (int)ceil(($region[0] - $offset) / $step));
			$r = min($x + $n, (int)floor(($region[2] - $offset) / $step) + 1);
			$f = max($z, (int)ceil(($region[1] - $offset) / $step));
			$b = min($z + $n, (int)floor(($region[3] - $offset) / $step) + 1);

			if($l < $r && $f < $b) $rects[] = [$l,$f,$r,$b];
		}

		$this->fill($level, $rects, $step, $offset);
		$level['origin'] = $origin;
		$level['step'] = $step;
	}

	public function overview(): array {return $this->overview['textures'] ?? [0,0];}

	/** Use the finest resident caster grid spanning at least 1.2 km, including terrain behind the camera. */
	public function updateShadows(array $eye, array $sun, int $revision): void
	{
		if($sun[1] > 0 && $this->overview)
		{
			foreach($this->spans as $i => $span)
			{
				$step = $this->recipe['spacing'] * (2 ** $i);
				if($span*$step < 1200. && $i !== array_key_last($this->spans)) continue;
				$level = $this->levels[$i];
				if(!isset($level['origin'])) break;
				$this->shadows ??= new TriangleShadows;
				$this->shadows->update($level,$span,$eye,$sun,$this->overview['textures'][0],
					$this->recipe['worldSize'],$this->packed,$this->recipe['surround'] === 'none' ? $this->recipe['worldSize']/2 : 0.,
					$revision,$this->vao[0]);
				return;
			}
		}
		$this->shadows?->invalidate();
	}

	public function bindShadows(Program $program): void
	{
		glUniform2f($program->location('uShadowReceiverRange'),...$this->shadowReceiverRange);
		if($this->shadows) $this->shadows->bind($program);
		else glUniform3f($program->location('uShadowGrid'),0,0,0);
	}

	public function packed(): bool {return $this->packed === true;}

	/** Vertex helpers shared by terrain and overlays drawn on its triangles. */
	public static function gridShader(): string
	{
		return file_get_contents(dirname(__DIR__) . '/shaders/clip-grid.glsl');
	}

	private function bindGrid(Program $p, Camera $camera, int $index, array $level): void
	{
		[$ox,$oz] = $level['origin'];
		$x = $ox + self::HALO; $z = $oz + self::HALO;
		$s = $level['step']; $span = $this->spans[$index];
		glUniform2i($p->location('uRingMin'), $x, $z);
		glUniform1i($p->location('uSpan'), $span);
		glUniform1i($p->location('uHasParent'), (int)isset($this->levels[$index + 1]['origin']));
		glUniform2f($p->location('uEyeCell'), $camera->position[0] / $s, $camera->position[2] / $s);
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
	 * Redraw a rectangle on the finest resident terrain grid using the active overlay program.
	 * Bounds are [minX, minZ, maxX, maxZ] in meters. An uncovered rectangle is left to the caller.
	 */
	public function drawGroundPatch(Program $p, Camera $camera, array $bounds): bool
	{
		$level = $this->levels[0] ?? null;
		if(!isset($level['origin']) || $bounds[2] <= $bounds[0] || $bounds[3] <= $bounds[1]) return false;
		$s = $level['step'];
		$left = (int)floor($bounds[0]/$s); $top = (int)floor($bounds[1]/$s);
		$right = (int)ceil($bounds[2]/$s); $bottom = (int)ceil($bounds[3]/$s);
		$x = $level['origin'][0] + self::HALO; $z = $level['origin'][1] + self::HALO;
		if($left < $x || $top < $z || $right > $x + $this->spans[0] || $bottom > $z + $this->spans[0]) return false;
		if($this->recipe['surround'] === 'none')
		{
			$half = $this->recipe['worldSize']/2;
			if($left*$s < -$half || $top*$s < -$half || $right*$s > $half || $bottom*$s > $half) return false;
		}

		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1i($p->location('uElevations'), 7);
		glUniform1i($p->location('uInk'), 8);
		$this->bindGrid($p, $camera, 0, $level);
		glBindVertexArray($this->vao[0]);
		glUniform2i($p->location('uDrawCell'), $left, $top);
		glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 2*($right-$left+1), $bottom-$top);
		glBindVertexArray(0);
		glActiveTexture(GL_TEXTURE0);
		return true;
	}

	/** Draw a solid inner grid and rectangular strips around each finer level. */
	public function draw(Program $p, Camera $camera, array $planes, array $vertical, bool $water = false): int
	{
		$count = 0;
		$inner = null;
		glBindVertexArray($this->vao[0]);
		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1i($p->location('uElevations'), 7);
		glUniform1i($p->location('uInk'), 8);
		$halfWorld = $this->recipe['worldSize'] / 2;
		$half = $this->recipe['surround'] === 'none' ? $halfWorld : 0;
		glUniform1f($p->location('uPlayHalf'), $half);

		foreach($this->levels as $index => $level)
		{
			if(!isset($level['origin'])) continue;

			[$ox,$oz] = $level['origin'];
			$span = $this->spans[$index];
			$x = $ox + self::HALO;
			$z = $oz + self::HALO;
			$r = $x + $span;
			$b = $z + $span;
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
							&& (max($west, $front) + $blockSize + 2) * $s <= $halfWorld)
						{
							[$groundMin,$groundMax] = $this->source->renderBlockRange(
								$west * $stride, $front * $stride, $blockSize * $stride, 2 * $stride
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
			if(!$visible) continue;
			$this->bindGrid($p, $camera, $index, $level);

			foreach($visible as [$left,$top,$right,$bottom])
			{
				glUniform2i($p->location('uDrawCell'), $left, $top);
				glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 2 * ($right - $left + 1), $bottom - $top);
			}
			$count += count($visible);
		}

		return $count;
	}

	/** World meters across the heading-up, vehicle-centered chart. */
	public static function chartSpan(float $extent, array $position, float $bearing, bool $wide): float
	{
		// At the middle of the world, even a 45° chart fits at this scale.
		$maximum = $extent / M_SQRT2;
		$local = min(4000., $maximum/1.5);
		if(!$wide) return $local;

		$zoomedOut = min(12000., $maximum);
		$diagonal = abs(cos($bearing)) + abs(sin($bearing));
		$edgeRoom = $extent/2 - max(abs($position[0]), abs($position[1]));
		$fits = 2*$edgeRoom/$diagonal;
		// Near the boundary, leave a useful difference between the two zoom levels.
		return max(min($zoomedOut, 1.5*$local), min($zoomedOut, $fits));
	}

	/** Return the drawn span for overlays that need the same world-to-chart scale. */
	public function chart(
		array $position
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
	): ?float {
		if(!$this->overview) return null;

		$p = $this->chart;
		$p->use();

		if($layers !== $this->chartLayers)
		{
			$layers->bind($p);
			$this->chartLayers = $layers;
		}

		$this->paletteOrigin($p);
		$n = min($size ?? (int)round(min(184, $w * .235, $h * .245)), $w - 10, $h - 10);

		if($n <= 0) return null;

		$c = cos($bearing);
		$s = sin($bearing);
		$extent = $this->recipe['worldSize'];
		$span = self::chartSpan($extent, $position, $bearing, $wide);
		if(!$wide && $localView !== null) $span = max(1.,min($span,$localView));
		$level = $this->overview;
		$localSpan = 0;

		foreach($this->levels as $index => $resident)
		{
			if(!isset($resident['origin'])) continue;

			$level = $resident;
			$localSpan = $this->spans[$index];

			if($level['step'] * $localSpan >= $span * 1.5) break;
		}

		$step = $level['step'];
		glUniform2f($p->location('uHeading'), $c, $s);
		glUniform1i($p->location('uCircular'), (int)$circular);
		glUniform4f($p->location('uChart'), $position[0], $position[1], $span, $n);

		glUniform4f(
			$p->location('uLocal')
			, ($level['origin'][0] + self::HALO) * $step
			, ($level['origin'][1] + self::HALO) * $step
			, $step
			, $localSpan
		);

		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1f($p->location('uExtent'), $extent);
		glUniform1f($p->location('uWaterLevel'), $water);
		glUniform3f($p->location('uWaterColor'), ...$waterColor);
		$colors = [];

		foreach(['rockColor','grassColor','sandColor','snowColor','roadColor'] as $key)
		array_push($colors, ...TerrainAppearance::linear($appearance->settings[$key]));
		glUniform3fv($p->location('uPaintColor[0]'), 5, $colors);
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
		glDisable(GL_BLEND);
		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glViewport(0, 0, $w, $h);

		return $span;
	}

	public function paletteOrigin(Program $p): void
	{
		$seed = crc32((string)$this->recipe['seed']);
		glUniform2f($p->location('uPaletteOffset'), $seed & 65535, ($seed >> 16) & 65535);
	}

	public function stats(): array
	{
		$live = array_filter($this->levels, static fn(array $level) => isset($level['origin']));
		$spacing = $live ? reset($live)['step'] : null;
		$texels = ($this->overview['n'] ?? 0) ** 2;

		foreach($this->levels as $level) $texels += ($level['n'] ?? 0) ** 2;
		$coverage = [];
		foreach($live as $index => $level)
		{
			[$left,$front] = $level['origin'];
			$step = $level['step'];
			$coverage[] = [($left + self::HALO) * $step, ($front + self::HALO) * $step, $this->spans[$index] * $step, $step];
		}

		return [
			'backend' => 'clipmap'
			, 'patches' => count($live)
			, 'visible' => count($live)
			, 'pending' => $this->pending ? max(1, count($this->levels) - count($live)) : 0
			, 'cameraSpacing' => $spacing
			, 'finestVisibleSpacing' => $spacing
			, 'visibleCoverage' => $coverage
			, 'heightTexelsWritten' => $this->texels
			, 'editUploadBytes' => $this->uploads
			, 'triangleShadowGeneration' => $this->shadows?->generation ?? 0
			, 'roadTextureBytes' => $this->roadBytes
			, 'meshBytes' => 0
			, 'terrainTextureBytes' => 256 + $this->roadBytes + ($this->packed ? 8 : 20) * $texels
			, 'proceduralVertexBytes' => 0
			, 'packedHeight' => $this->packed
		];
	}

	public function close(): void
	{
		foreach($this->levels as $level) self::release($level);
		self::release($this->overview);
		if($this->roadTexture) glDeleteTextures(1, $this->roadTexture);
		glDeleteTextures(1, $this->permutation);
		glDeleteVertexArrays(1, $this->vao);
		glDeleteFramebuffers(1, $this->framebuffer);
		$this->generator->close();
		$this->chart->close();
		$this->shadows?->close();
	}
}

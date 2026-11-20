<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/** A camera-centred stack of toroidal GPU elevation textures and one world overview. */
final class Clipmap
{
	private Program $generator, $chart;
	private ?SurfaceLayers $chartLayers = null;
	private array $vao = [], $framebuffer = [], $permutation = [], $levels = [], $overview = [];
	private ?Landscape $source = null;
	private EditIndex $edits;
	private int $revision = -1, $span = 0;
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
		$this->edits = $land->editIndex();

		if(!$newRecipe) return;

		$r = $this->recipe = $land->recipe();
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
		$regions = $this->edits->intersect(
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
				$id = array_search($this->source->material($wx, $wz), ['rock','grass','sand','snow','road'], true);
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

	/** Refresh only cells newly exposed to the camera or changed by committed edits. */
	public function update(Landscape $land, Camera $camera, float $quality): bool
	{
		$changed = $this->source !== $land || $this->revision !== $land->revision();
		$region = $this->dirtyRegion;
		$this->dirtyRegion = null;
		$n = $quality < .75 ? 32 : ($quality > 1.5 ? 128 : 64);
		$span = $n - 4;
		$recipe = $land->recipe();
		$step = $recipe['spacing'];
		$reach = $recipe['surround'] === 'none' ? $recipe['worldSize'] : 90000;
		$count = 1;

		while(($span / 2 - 2) * $step * 2 ** ($count - 1) < $reach) $count++;
		$origins = [];

		for($l = 0; $l < $count; $l++)
		{
			$s = $step * (2 ** $l);
			$origins[$l] = [
				2 * (int)floor($camera->position[0] / (2 * $s)) - intdiv($span, 2) - 1
				, 2 * (int)floor($camera->position[2] / (2 * $s)) - intdiv($span, 2) - 1
			];
		}

		if(!$changed && !$this->pending && $this->span === $span && $origins === array_column($this->levels, 'origin'))
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
			if($changed) $this->generate($land);

			if($this->span !== $span || count($this->levels) !== $count)
			{
				foreach($this->levels as $level) self::release($level);
				$this->levels = array_fill(0, $count, []);
				$this->span = $span;
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
				$origin = $level['origin'] ?? null;
				$needsFull = $origin === null
					|| ($changed && $region === null)
					|| abs($origin[0] - $origins[$l][0]) >= $n
					|| abs($origin[1] - $origins[$l][1]) >= $n;

				if($needsFull && $newLevel)
				{
					// Keep the newly centred coarse rings visible while finer rings reload.
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

	public function packed(): bool {return $this->packed === true;}

	/** Draw a solid inner grid and rectangular strips around each finer level. */
	public function draw(Program $p, Camera $camera, array $planes, array $vertical): int
	{
		$count = 0;
		$inner = null;
		glBindVertexArray($this->vao[0]);
		glUniform1i($p->location('uPacked'), (int)$this->packed);
		glUniform1i($p->location('uSpan'), $this->span);
		glUniform1i($p->location('uElevations'), 7);
		glUniform1i($p->location('uInk'), 8);
		$half = $this->recipe['surround'] === 'none' ? $this->recipe['worldSize'] / 2 : 0;
		glUniform1f($p->location('uPlayHalf'), $half);

		foreach($this->levels as $level)
		{
			if(!isset($level['origin'])) continue;

			[$ox,$oz] = $level['origin'];
			$x = $ox + 1;
			$z = $oz + 1;
			$r = $x + $this->span;
			$b = $z + $this->span;
			$s = $level['step'];

			if($inner === null) $rects = [[$x,$z,$r,$b]];
			else
			{
				[$l,$t,$rr,$bb] = $inner;
				$rects = [[$x,$z,$r,$t],[$x,$bb,$r,$b],[$x,$t,$l,$bb],[$rr,$t,$r,$bb]];
			}

			$inner = [intdiv($x, 2),intdiv($z, 2),intdiv($r, 2),intdiv($b, 2)];
			glUniform2i($p->location('uRingMin'), $x, $z);
			glUniform1f($p->location('uGridStep'), $s);

			foreach($level['textures'] as $i => $tex)
			{
				glActiveTexture(GL_TEXTURE0 + 7 + $i);
				glBindTexture(GL_TEXTURE_2D, $tex);
			}

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

				glUniform2i($p->location('uDrawCell'), $left, $top);
				glDrawArraysInstanced(GL_TRIANGLE_STRIP, 0, 2 * ($width + 1), $rows);
				$count++;
			}
		}

		return $count;
	}

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
	): void {
		if(!$this->overview) return;

		$p = $this->chart;
		$p->use();

		if($layers !== $this->chartLayers)
		{
			$layers->bind($p);
			$this->chartLayers = $layers;
		}

		$this->paletteOrigin($p);
		$n = (int)round(min(184, $w * .235, $h * .245));

		if(!$n) return;

		$c = cos($bearing);
		$s = sin($bearing);
		$extent = $this->recipe['worldSize'];
		$span = $wide ? $extent * (abs($c) + abs($s)) : min(4000., $extent);
		$level = $this->overview;

		foreach($this->levels as $resident)
		{
			if(!isset($resident['origin'])) continue;

			$level = $resident;

			if($level['step'] * $this->span >= $span * 1.5) break;
		}

		$step = $level['step'];
		glUniform2f($p->location('uHeading'), $c, $s);
		glUniform4f($p->location('uChart'), $wide?0:$position[0], $wide?0:$position[1], $span, $n);

		glUniform4f(
			$p->location('uLocal')
			, ($level['origin'][0] + 1) * $step
			, ($level['origin'][1] + 1) * $step
			, $step
			, $this->span
		);

		glUniform1i($p->location('uWide'), (int)$wide);
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

		return [
			'backend' => 'clipmap'
			, 'patches' => count($live)
			, 'visible' => count($live)
			, 'pending' => (int)$this->pending
			, 'cameraSpacing' => $spacing
			, 'finestVisibleSpacing' => $spacing
			, 'heightTexelsWritten' => $this->texels
			, 'editUploadBytes' => $this->uploads
			, 'meshBytes' => 0
			, 'terrainTextureBytes' => 256 + ($this->packed ? 8 : 20) * $texels
			, 'proceduralVertexBytes' => 0
			, 'packedHeight' => $this->packed
		];
	}

	public function close(): void
	{
		foreach($this->levels as $level) self::release($level);
		self::release($this->overview);
		glDeleteTextures(1, $this->permutation);
		glDeleteVertexArrays(1, $this->vao);
		glDeleteFramebuffers(1, $this->framebuffer);
		$this->generator->close();
		$this->chart->close();
	}
}

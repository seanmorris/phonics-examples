<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\Lod;
use TerrainPhonics\Scene;
use TerrainPhonics\TerrainAppearance;

/** Stream resident relief around the F5. Coarse tiles cover the map while the scene refines. */
final class FlightMap
{
	private ?Landscape $land = null;
	private int $revision = -1;
	private array $sent = [], $style = [];
	private float $nextPoll = 0;
	private ?bool $wide = null;

	public function update(Session $s, Scene $scene, array $jet, float $now): array
	{
		$land = $s->landscape;
		$recipe = $land->recipe();
		$extent = $recipe['worldSize'];
		$wide = $s->jetWideMap;
		$span = $wide ? $extent : min(4000.0, $extent);
		$centerX = $wide ? 0.0 : $jet['x'];
		$centerZ = $wide ? 0.0 : $jet['z'];
		$delta = [];
		if($this->land !== $land || $this->revision !== $land->revision())
		{
			$this->land = $land;
			$this->revision = $land->revision();
			$this->sent = $this->style = [];
			$this->wide = null;
			$this->nextPoll = 0;
			$delta['reset'] = true;
		}
		if($wide !== $this->wide)
		{
			$this->wide = $wide;
			$this->nextPoll = 0;
			$delta['wide'] = $wide;
			$delta['span'] = $span;
		}
		$style = [$s->terrainAppearance, $s->settings['waterColor'], $s->water->level()];
		if($style !== $this->style)
		{
			$this->style = $style;
			$delta['style'] = $s->terrainAppearance->settings + ['preset'=>TerrainAppearance::style($recipe),
				'waterColor'=>$style[1], 'waterLevel'=>$style[2]];
		}
		if($now < $this->nextPoll) return $delta;

		// Only keep tiles around this chart. A square on the screen needs at most eight 32-cell patches across.
		$finest = max($recipe['spacing'] * Lod::CELLS, $span / 8);
		$half = $extent / 2;
		$visible = [];
		$sentNow = 0;
		for($level = 0, $size = $extent; $level === 0 || $size >= $finest; $level++, $size /= 2)
		{
			$last = (1 << $level) - 1;
			$x0 = max(0, (int)floor(($centerX - $span*.55 + $half)/$size));
			$x1 = min($last, (int)floor(($centerX + $span*.55 + $half)/$size));
			$z0 = max(0, (int)floor(($centerZ - $span*.55 + $half)/$size));
			$z1 = min($last, (int)floor(($centerZ + $span*.55 + $half)/$size));
			for($z = $z0; $z <= $z1; $z++)
			for($x = $x0; $x <= $x1; $x++)
			{
				$key = "$level:$x:$z";
				$visible[$key] = true;
				if(isset($this->sent[$key]) || $sentNow >= 2 && !$s->paused) continue;
				$tile = $scene->surfacePatch($key);
				if($tile === null) continue;
				$delta['tiles'][] = [$key, $x*$size-$half, $z*$size-$half, $size,
					base64_encode(pack('g*', ...$tile['heights'])), base64_encode($tile['materials'])];
				$this->sent[$key] = true;
				$sentNow++;
			}
		}
		$drop = array_diff_key($this->sent, $visible);
		if($drop) $delta['drop'] = array_keys($drop);
		$this->sent = array_intersect_key($this->sent, $visible);
		$this->nextPoll = $now + ($sentNow === 2 ? .04 : .2);
		return $delta;
	}
}

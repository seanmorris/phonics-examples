<?php
declare(strict_types=1);
namespace DoomPhonics\Map;

/** Uniform-grid acceleration for map collision, use traces, hitscan, and ray rendering. */
final class SpatialIndex
{
	/** @var array<string,list<int>> */
	private array $cells = [];

	public function __construct(public MapData $map, public int $cellSize = 128)
	{
		if($cellSize < 16) throw new \InvalidArgumentException('Spatial cells must be at least 16 map units');

		foreach($map->linedefs as $line)
		{
			$a = $map->vertices[$line['from']];
			$b = $map->vertices[$line['to']];
			$minX = $this->cell(min($a['x'], $b['x']));
			$maxX = $this->cell(max($a['x'], $b['x']));
			$minY = $this->cell(min($a['y'], $b['y']));
			$maxY = $this->cell(max($a['y'], $b['y']));

			for($y = $minY; $y <= $maxY; $y++)
			for($x = $minX; $x <= $maxX; $x++)
			$this->cells["$x:$y"][] = $line['index'];
		}
	}

	/** @return list<int> Unique linedefs intersecting an axis-aligned region. */
	public function near(float $minX, float $minY, float $maxX, float $maxY): array
	{
		$result = [];

		for($y = $this->cell($minY); $y <= $this->cell($maxY); $y++)
		for($x = $this->cell($minX); $x <= $this->cell($maxX); $x++)
		foreach($this->cells["$x:$y"] ?? [] as $line) $result[$line] = true;

		return array_keys($result);
	}

	/**
	 * Return ray/segment intersections ordered nearest first.
	 * @return list<array{line:int,distance:float,along:float,x:float,y:float}>
	 */
	public function cast(float $x, float $y, float $dx, float $dy, float $distance): array
	{
		$length = hypot($dx, $dy);

		if($length <= 1e-9 || $distance <= 0) return [];

		$dx /= $length;
		$dy /= $length;
		$cx = $this->cell($x);
		$cy = $this->cell($y);
		$stepX = $dx < 0 ? -1 : 1;
		$stepY = $dy < 0 ? -1 : 1;
		$nextX = ($stepX > 0 ? $cx + 1 : $cx) * $this->cellSize;
		$nextY = ($stepY > 0 ? $cy + 1 : $cy) * $this->cellSize;
		$tMaxX = abs($dx) < 1e-12 ? INF : ($nextX - $x) / $dx;
		$tMaxY = abs($dy) < 1e-12 ? INF : ($nextY - $y) / $dy;
		$tDeltaX = abs($dx) < 1e-12 ? INF : abs($this->cellSize / $dx);
		$tDeltaY = abs($dy) < 1e-12 ? INF : abs($this->cellSize / $dy);
		$candidates = [];

		for($travel = 0, $guard = 0; $travel <= $distance && $guard++ < 65536;)
		{
			foreach($this->cells["$cx:$cy"] ?? [] as $line) $candidates[$line] = true;

			if($tMaxX < $tMaxY)
			{
				$travel = $tMaxX;
				$tMaxX += $tDeltaX;
				$cx += $stepX;
			}
			else
			{
				$travel = $tMaxY;
				$tMaxY += $tDeltaY;
				$cy += $stepY;
			}
		}

		$hits = [];

		foreach(array_keys($candidates) as $id)
		{
			$line = $this->map->linedefs[$id];
			$a = $this->map->vertices[$line['from']];
			$b = $this->map->vertices[$line['to']];
			$sx = $b['x'] - $a['x'];
			$sy = $b['y'] - $a['y'];
			$denominator = $dx * $sy - $dy * $sx;

			if(abs($denominator) < 1e-9) continue;

			$ax = $a['x'] - $x;
			$ay = $a['y'] - $y;
			$t = ($ax * $sy - $ay * $sx) / $denominator;
			$u = ($ax * $dy - $ay * $dx) / $denominator;

			if($t < 1e-5 || $t > $distance || $u < -1e-7 || $u > 1.0000001) continue;

			$hits[] = ['line' => $id, 'distance' => $t, 'along' => $u, 'x' => $x + $dx * $t, 'y' => $y + $dy * $t];
		}

		usort($hits, static fn(array $a, array $b): int => $a['distance'] <=> $b['distance']);

		return $hits;
	}

	private function cell(float $coordinate): int { return (int)floor($coordinate / $this->cellSize); }
}

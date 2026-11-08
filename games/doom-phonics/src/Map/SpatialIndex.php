<?php
declare(strict_types=1);
namespace DoomPhonics\Map;

/** Uniform-grid acceleration for map collision, use traces, hitscan, and ray rendering. */
final class SpatialIndex
{
	/** @var array<int,list<int>> Lines by packed cell key. */
	private array $cells = [];
	/**
	 * Cells are clamped to +/-16384 per axis so packed keys stay below 2^31: the browser runs 32-bit PHP, where a
	 * larger key becomes a float. A ray far outside the map shares the edge cells, whose lines its caller rejects.
	 */
	private const CELL_LIMIT = 16384;

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
			$this->cells[self::pack($x, $y)][] = $line['index'];
		}
	}

	/**
	 * Unique linedefs, in ascending order, registered in the cells along a segment widened by `$radius`. Lines are
	 * registered in every cell their bounds overlap, and a circle of that radius sweeping the segment can touch only
	 * these lines, so callers test a number of lines proportional to the segment's length instead of its bounding
	 * box's area. Where the segment passes exactly through a cell corner, both neighbouring cells are included.
	 *
	 * @return list<int>
	 */
	public function swept(float $x, float $y, float $dx, float $dy, float $radius): array
	{
		$reach = (int)ceil(max(0, $radius) / $this->cellSize);
		$cells = [];
		$cx = $this->cell($x);
		$cy = $this->cell($y);
		$endX = $this->cell($x + $dx);
		$endY = $this->cell($y + $dy);
		$stepX = $dx < 0 ? -1 : 1;
		$stepY = $dy < 0 ? -1 : 1;
		$tMaxX = abs($dx) < 1e-12 ? INF : ((($stepX > 0 ? $cx + 1 : $cx) * $this->cellSize) - $x) / $dx;
		$tMaxY = abs($dy) < 1e-12 ? INF : ((($stepY > 0 ? $cy + 1 : $cy) * $this->cellSize) - $y) / $dy;
		$tDeltaX = abs($dx) < 1e-12 ? INF : abs($this->cellSize / $dx);
		$tDeltaY = abs($dy) < 1e-12 ? INF : abs($this->cellSize / $dy);

		// Walk the cells the segment passes through, with t measured in segment lengths.
		for($guard = 0; $guard < 65536; $guard++)
		{
			$cells[$cx][$cy] = true;

			if(($cx === $endX && $cy === $endY) || min($tMaxX, $tMaxY) > 1) break;

			if(abs($tMaxX - $tMaxY) < 1e-9)
			{
				$cells[$cx + $stepX][$cy] = true;
				$cells[$cx][$cy + $stepY] = true;
			}

			if($tMaxX < $tMaxY)
			{
				$cx += $stepX;
				$tMaxX += $tDeltaX;
			}
			else
			{
				$cy += $stepY;
				$tMaxY += $tDeltaY;
			}
		}

		$result = [];

		foreach($cells as $cellX => $column)
		foreach(array_keys($column) as $cellY)
		for($oy = -$reach; $oy <= $reach; $oy++)
		for($ox = -$reach; $ox <= $reach; $ox++)
		foreach($this->cells[self::pack($cellX + $ox, $cellY + $oy)] ?? [] as $line) $result[$line] = true;

		$lines = array_keys($result);
		sort($lines);

		return $lines;
	}

	/** @return list<int> Unique linedefs intersecting an axis-aligned region. */
	public function near(float $minX, float $minY, float $maxX, float $maxY): array
	{
		$result = [];
		$fromX = $this->cell($minX);
		$toX = $this->cell($maxX);
		$toY = $this->cell($maxY);

		for($y = $this->cell($minY); $y <= $toY; $y++)
		for($x = $fromX; $x <= $toX; $x++)
		foreach($this->cells[self::pack($x, $y)] ?? [] as $line) $result[$line] = true;

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
			foreach($this->cells[self::pack($cx, $cy)] ?? [] as $line) $candidates[$line] = true;

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

	/** Distinct keys for every clamped cell, at most 2^30 - 1, so they stay integers in 32-bit PHP. */
	private static function pack(int $x, int $y): int
	{
		$x = max(-self::CELL_LIMIT, min(self::CELL_LIMIT - 1, $x));
		$y = max(-self::CELL_LIMIT, min(self::CELL_LIMIT - 1, $y));

		return ($x + self::CELL_LIMIT) * 2 * self::CELL_LIMIT + $y + self::CELL_LIMIT;
	}
}

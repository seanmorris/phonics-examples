<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Build rectangular stair treads for generated Doom maps.
 *
 * Dimensions are in map units. The caller supplies the lower and upper landings;
 * this helper fills the intermediate heights and preserves surrounding sector
 * properties when carving the treads into an existing rectangular layout.
 */
final class Staircase
{
	/**
	 * Return treads from the starting landing toward the destination landing.
	 *
	 * x/y is the leading edge at the start, with width extending along positive Y
	 * for east/west stairs or positive X for north/south stairs. Rise is the maximum
	 * height difference, including the final landing; descending flights also work.
	 *
	 * @return list<array{x0:int,y0:int,x1:int,y1:int,floor:int}>
	 */
	public static function treads(
		int $x, int $y, int $fromHeight, int $toHeight,
		int $rise = 16, int $run = 64, int $width = 128, string $direction = 'east'
	): array {
		if($rise <= 0 || $run <= 0 || $width <= 0)
		throw new \InvalidArgumentException('Stair rise, run and width must be positive');

		[$dx,$dy] = match($direction) {
			'east' => [1,0], 'west' => [-1,0],
			'north' => [0,1], 'south' => [0,-1],
			default => throw new \InvalidArgumentException('Unknown stair direction ' . $direction)
		};
		$height = $fromHeight;
		$sign = $toHeight <=> $fromHeight;
		$treads = [];
		while(abs($toHeight - $height) > $rise)
		{
			$height += $sign * $rise;
			$nextX = $x + $dx * $run;
			$nextY = $y + $dy * $run;
			$treads[] = [
				'x0' => min($x,$nextX), 'y0' => min($y,$nextY),
				'x1' => max($x,$nextX) + ($dy ? $width : 0),
				'y1' => max($y,$nextY) + ($dx ? $width : 0), 'floor' => $height
			];
			$x = $nextX;
			$y = $nextY;
		}
		return $treads;
	}

	/** Carve treads into rectangular cells, retaining their tags, textures and other properties. */
	public static function carve(array $cells, array $treads): array
	{
		foreach($treads as $tread)
		{
			$next = [];
			foreach($cells as $cell)
			{
				$x0 = max($cell['x0'],$tread['x0']);
				$y0 = max($cell['y0'],$tread['y0']);
				$x1 = min($cell['x1'],$tread['x1']);
				$y1 = min($cell['y1'],$tread['y1']);
				if($x0 >= $x1 || $y0 >= $y1)
				{
					$next[] = $cell;
					continue;
				}
				foreach([
					[$cell['x0'],$cell['y0'],$x0,$cell['y1']],
					[$x1,$cell['y0'],$cell['x1'],$cell['y1']],
					[$x0,$cell['y0'],$x1,$y0], [$x0,$y1,$x1,$cell['y1']]
				] as [$left,$bottom,$right,$top])
				{
					if($left < $right && $bottom < $top)
					$next[] = array_replace($cell, ['x0' => $left,'y0' => $bottom,'x1' => $right,'y1' => $top]);
				}
				$next[] = array_replace($cell, [
					'x0' => $x0,'y0' => $y0,'x1' => $x1,'y1' => $y1,'floor' => $tread['floor']
				]);
			}
			$cells = $next;
		}
		return $cells;
	}
}

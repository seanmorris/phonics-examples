<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/**
 * Greedily merge adjacent rectangular faces without filling holes in their union.
 *
 * Callers partition faces by material and live plane ownership first. This pass
 * only joins complete shared edges; slanted or clipped BSP polygons stay intact.
 */
final class PlaneRectangles
{
	/** Merge matching rows and columns until no complete shared edge remains. */
	public static function merge(array $polygons): array
	{
		$rectangles = $other = [];
		foreach($polygons as $points)
		{
			$xs = array_values(array_unique(array_column($points,0)));
			$ys = array_values(array_unique(array_column($points,1)));
			if(count($points) !== 4 || count($xs) !== 2 || count($ys) !== 2)
			{
				$other[] = $points;
				continue;
			}
			$winding = ($points[1][0] - $points[0][0]) * ($points[2][1] - $points[0][1])
				- ($points[1][1] - $points[0][1]) * ($points[2][0] - $points[0][0]);
			$rectangles[] = [min($xs),min($ys),max($xs),max($ys),$winding > 0 ? 1 : -1];
		}
		do
		{
			$count = count($rectangles);
			foreach([0,1] as $axis)
			{
				$groups = [];
				foreach($rectangles as $r)
				$groups[$r[1 - $axis] . ':' . $r[3 - $axis] . ':' . $r[4]][] = $r;
				$rectangles = [];
				foreach($groups as $group)
				{
					usort($group, static fn(array $a, array $b): int => $a[$axis] <=> $b[$axis]);
					$current = array_shift($group);
					foreach($group as $r)
					{
						if($current[$axis + 2] === $r[$axis]) $current[$axis + 2] = $r[$axis + 2];
						else { $rectangles[] = $current; $current = $r; }
					}
					$rectangles[] = $current;
				}
			}
		} while(count($rectangles) < $count);
		foreach($rectangles as [$x0,$y0,$x1,$y1,$winding])
		{
			$points = [[$x0,$y0],[$x1,$y0],[$x1,$y1],[$x0,$y1]];
			$other[] = $winding > 0 ? $points : array_reverse($points);
		}
		return $other;
	}
}

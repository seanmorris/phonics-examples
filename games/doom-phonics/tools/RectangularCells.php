<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Coalesce authored rectangles before writing map sectors and linedefs.
 *
 * Only complete shared edges with identical non-coordinate properties can merge.
 * Heights, textures, lighting, tags and any caller-supplied ownership metadata stay
 * distinct. Holes and nonrectangular outlines are preserved as multiple rectangles.
 */
final class RectangularCells
{
	/** Merge horizontal and vertical neighbors until no rectangular union remains. */
	public static function merge(array $cells): array
	{
		do
		{
			$count = count($cells);
			foreach([['x','y'],['y','x']] as [$axis,$cross])
			{
				$groups = [];
				foreach($cells as $cell)
				{
					$properties = array_diff_key($cell, array_flip(['x0','y0','x1','y1']));
					ksort($properties);
					$key = serialize([$properties,$cell[$cross . '0'],$cell[$cross . '1']]);
					$groups[$key][] = $cell;
				}
				$cells = [];
				foreach($groups as $group)
				{
					usort($group, static fn(array $a, array $b): int => $a[$axis . '0'] <=> $b[$axis . '0']);
					$current = array_shift($group);
					foreach($group as $cell)
					{
						if($current[$axis . '1'] === $cell[$axis . '0'])
						$current[$axis . '1'] = $cell[$axis . '1'];
						else
						{
							$cells[] = $current;
							$current = $cell;
						}
					}
					$cells[] = $current;
				}
			}
		} while(count($cells) < $count);
		usort($cells, static fn(array $a, array $b): int =>
			[$a['y0'],$a['x0'],$a['y1'],$a['x1']] <=> [$b['y0'],$b['x0'],$b['y1'],$b['x1']]);
		return $cells;
	}
}

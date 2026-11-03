<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . "/PolygonGeometry.php";

/**
 * Select connected polygonal sectors below a common maximum water surface.
 *
 * Shared edges and explicitly supplied wall-portal connections carry water only
 * through open vertical intervals. This is an authoring flood fill, not a timed
 * fluid simulation; all selected sectors can share one moving liquid model.
 */
final class FloodBasin
{

	/** Return reachable cell IDs; portal pairs must use the same architectural Z coordinates. */
	public static function find(array $cells, int $seed, int $surface, array $portals = []): array
	{
		if(!isset($cells[$seed])) throw new \InvalidArgumentException('Unknown flood seed');
		$neighbors = [];
		$join = static function(int $a, int $b) use ($cells,$surface,&$neighbors): void {
			if(!isset($cells[$a],$cells[$b])) throw new \InvalidArgumentException('Unknown flood connection');
			$bottom = max($cells[$a]['floor'],$cells[$b]['floor']);
			$top = min($cells[$a]['ceiling'],$cells[$b]['ceiling']);
			if($bottom >= min($surface,$top)) return;
			$neighbors[$a][] = $b;
			$neighbors[$b][] = $a;
		};
		foreach($cells as $a => $cell)
		foreach($cells as $b => $other)
		{
			if($b <= $a) continue;
			if(isset($cell['polygon']) || isset($other['polygon']))
			{
				if(PolygonGeometry::shared(PolygonGeometry::points($cell),PolygonGeometry::points($other))) $join($a,$b);
				continue;
			}
			$vertical = ($cell['x1'] === $other['x0'] || $other['x1'] === $cell['x0'])
				&& max($cell['y0'],$other['y0']) < min($cell['y1'],$other['y1']);
			$horizontal = ($cell['y1'] === $other['y0'] || $other['y1'] === $cell['y0'])
				&& max($cell['x0'],$other['x0']) < min($cell['x1'],$other['x1']);
			if($vertical || $horizontal) $join($a,$b);
		}
		foreach($portals as [$a,$b]) $join($a,$b);
		$pending = [$seed];
		$wet = [];
		while($pending)
		{
			$id = array_pop($pending);
			if(isset($wet[$id]) || $cells[$id]['floor'] >= min($surface,$cells[$id]['ceiling'])) continue;
			$wet[$id] = true;
			foreach($neighbors[$id] ?? [] as $next) if(!isset($wet[$next])) $pending[] = $next;
		}
		ksort($wet,SORT_NUMERIC);
		return array_keys($wet);
	}
}

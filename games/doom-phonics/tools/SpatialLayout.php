<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Place independently authored layers by joining matching entrance frames.
 *
 * Architectural translations describe the imagined building. Packed translations
 * keep overlapping storeys separate in a WAD. This prototype supports translation
 * and opposed, axis-aligned anchors; rotations require an explicit future solver.
 */
final class SpatialLayout
{

	/** Solve all pins from one fixed layer, rejecting disconnected or contradictory layouts. */
	public static function solve(array $layers, array $pins, string $root, array $origin): array
	{
		if(!isset($layers[$root]) || count($origin) !== 3)
		throw new \InvalidArgumentException('A layout needs a known root and a three-coordinate origin');
		$positions = [$root => $origin];
		foreach($pins as [$from,$fromAnchor,$to,$toAnchor])
		{
			$a = $layers[$from]['anchors'][$fromAnchor] ?? null;
			$b = $layers[$to]['anchors'][$toAnchor] ?? null;
			if(!$a || !$b || $a['size'] !== $b['size']
				|| $a['normal'] !== array_map(static fn($n) => -$n, $b['normal']))
			throw new \InvalidArgumentException("Incompatible entrance frames: $from/$fromAnchor -> $to/$toAnchor");
		}
		do
		{
			$changed = false;
			foreach($pins as [$from,$fromAnchor,$to,$toAnchor])
			{
				if(!isset($positions[$from]) && isset($positions[$to]))
				{ [$from,$fromAnchor,$to,$toAnchor] = [$to,$toAnchor,$from,$fromAnchor]; }
				if(!isset($positions[$from])) continue;
				$a = $layers[$from]['anchors'][$fromAnchor]['point'];
				$b = $layers[$to]['anchors'][$toAnchor]['point'];
				$expected = [];
				for($axis = 0; $axis < 3; $axis++)
				$expected[] = $positions[$from][$axis] + $a[$axis] - $b[$axis];
				if(isset($positions[$to]))
				{
					if($positions[$to] !== $expected)
					throw new \RuntimeException("Inconsistent spatial pin cycle at $to/$toAnchor");
				}
				else { $positions[$to] = $expected; $changed = true; }
			}
		} while($changed);
		if(count($positions) !== count($layers)) throw new \RuntimeException('Unpinned layer in spatial layout');
		return $positions;
	}

	/** Translate a local point into architectural or packed map coordinates. */
	public static function point(array $point, array $translation): array
	{
		return [$point[0] + $translation[0],$point[1] + $translation[1],$point[2] + $translation[2]];
	}
}

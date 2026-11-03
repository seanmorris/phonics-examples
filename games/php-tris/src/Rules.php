<?php
declare(strict_types=1);
namespace PhpTris;
use PhpTris\Component\Board;

/** Board transformations shared by the independently scheduled resolution systems. */
final class Rules
{

	/**
	 * Find orthogonal neighbors without wrapping across board edges.
	 *
	 * @param int $key Linear board cell index.
	 * @return array Computed values in iteration order.
	 */
	public static function neighbors(int $key): array
	{
		static $cache = [];

		if(isset($cache[$key])) return $cache[$key];

		$r = [];

		if($key % 10) $r[] = $key - 1;

		if($key % 10 < 9) $r[] = $key + 1;

		if($key >= 10) $r[] = $key - 10;

		if($key < 390) $r[] = $key + 10;

		return $cache[$key] = $r;
	}

	/**
	 * Partition settled cells into connected gravity groups.
	 *
	 * @param Board $b Settled board to inspect or transform.
	 * @param string $mode Selected rule track identifier.
	 * @param bool $individual Whether each cell falls independently.
	 * @return array Computed values in iteration order.
	 */
	public static function groups(Board $b, string $mode, bool $individual = false): array
	{
		$remaining = $b->cells;
		$groups = [];

		while($remaining)
		{
			$start = array_key_first($remaining);
			$cell = $remaining[$start];
			unset($remaining[$start]);
			$group = [$start];

			for($i = 0; $i < count($group); $i++)
			{
				if($individual || $cell['kind'] === 'garbage' || $cell['kind'] === 'goal') continue;

				foreach(self::neighbors($group[$i]) as $key)
				{
					if(!isset($remaining[$key])) continue;

					$c = $remaining[$key];
					$join = $mode === 'bonds'
						? $cell['kind'] === 'normal' && $c['kind'] === 'normal' && $c['color'] === $cell['color']
						: $c['group'] === $cell['group'] && ($mode !== 'targets' || $c['color'] === $cell['color']);

					if(!$join) continue;

					$group[] = $key;
					unset($remaining[$key]);
				}
			}

			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * Move unsupported groups bottom-first by one cell.
	 *
	 * @param Board $b Settled board to inspect or transform.
	 * @param string $mode Selected rule track identifier.
	 * @param bool $individual Whether each cell falls independently.
	 * @return array Computed values in iteration order.
	 */
	public static function fall(Board $b, string $mode, bool $individual = false): array
	{
		$groups = [];

		foreach(self::groups($b, $mode, $individual) as $group) $groups[max($group)] = $group;
		krsort($groups, SORT_NUMERIC);

		$motions = [];

		foreach($groups as $group)
		{
			$own = array_fill_keys($group, true);
			$can = true;

			foreach($group as $key)
			{
				if($key >= 390 || $b->cells[$key]['kind'] === 'core'
					|| (isset($b->cells[$key + 10]) && !isset($own[$key + 10]))
				){
					$can = false;
					break;
				}
			}

			if(!$can) continue;

			$cells = [];

			foreach($group as $key)
			{
				$cells[$key + 10] = $b->cells[$key];
				unset($b->cells[$key]);
			}

			foreach($cells as $key => $cell)
			{
				$b->cells[$key] = $cell;
				$motions[$key] = 10;
			}
		}

		if($motions) $b->revision++;

		return $motions;
	}

	/**
	 * Attach reachable charges to the persistent reactor core.
	 *
	 * @param Board $b Settled board to inspect or transform.
	 * @return int Computed count, position, or deterministic value.
	 */
	public static function connect(Board $b): int
	{
		$queue = [];

		foreach($b->cells as $key => $c) if($c['kind'] === 'core') $queue[] = $key;
		$count = 0;

		for($i = 0; $i < count($queue); $i++)
		foreach(self::neighbors($queue[$i]) as $key)
		if(($b->cells[$key]['kind'] ?? '') === 'charge')
		{
			$b->cells[$key]['kind'] = 'core';
			$queue[] = $key;
			$count++;
		}

		if($count) $b->revision++;

		return $count;
	}

	/**
	 * Remove normal color groups containing at least twenty-five cells.
	 *
	 * @param Board $b Settled board to inspect or transform.
	 * @return int Computed count, position, or deterministic value.
	 */
	public static function mass(Board $b): int
	{
		$count = 0;

		foreach(self::groups($b, 'bonds') as $group)
		{
			if(count($group) < 25 || $b->cells[$group[0]]['kind'] !== 'normal') continue;

			foreach($group as $key)
			{
				unset($b->cells[$key]);
				$count++;
			}
		}

		if($count) $b->revision++;

		return $count;
	}

	/**
	 * Find full rows containing at least one removable cell.
	 *
	 * @param Board $b Settled board to inspect or transform.
	 * @return array Computed values in iteration order.
	 */
	public static function rows(Board $b): array
	{
		$counts = [];
		$removable = [];

		foreach($b->cells as $key => $cell)
		{
			$y = intdiv($key, 10);
			$counts[$y] = ($counts[$y] ?? 0) + 1;

			if(!in_array($cell['kind'], ['core','charge'], true)) $removable[$y] = true;
		}

		$rows = [];

		foreach($counts as $y => $n) if($n === 10 && isset($removable[$y])) $rows[] = $y;

		return $rows;
	}

	/**
	 * Mark four whole pieces forming an unmarked four-by-four square.
	 *
	 * @param Board $b Settled board to inspect or transform.
	 * @return void Updates owned state without returning a value.
	 */
	public static function squares(Board $b): void
	{
		$counts = [];

		foreach($b->cells as $c) $counts[$c['group']] = ($counts[$c['group']] ?? 0) + 1;

		for($y = 0; $y <= 36; $y++)
		for($x = 0; $x <= 6; $x++)
		{
			$keys = [];
			$groups = [];
			$shapes = [];

			for($dy = 0; $dy < 4; $dy++)
			for($dx = 0; $dx < 4; $dx++)
			{
				$key = ($y + $dy) * 10 + $x + $dx;
				$c = $b->cells[$key] ?? null;

				if(!$c || $c['square'] || !$c['shape'] || ($counts[$c['group']] ?? 0) !== 4) continue 3;

				$keys[] = $key;
				$groups[$c['group']] = true;
				$shapes[$c['shape']] = true;
			}

			if(count($groups) !== 4) continue;

			$id = ++$b->serial;

			foreach($keys as $key)
			{
				$b->cells[$key]['square'] = $id;
				$b->cells[$key]['bonus'] = count($shapes) === 1?10:5;
			}

			$b->revision++;
		}
	}
}

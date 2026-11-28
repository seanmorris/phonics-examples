<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** A centerline graph of passages and forks extracted from the traversable floor cells. */
final class CorridorGraph
{
	/**
	 * Thin the floor from both sides without severing connections, then find its junctions.
	 * @return \Generator A bounded map of distance to a corridor and the junction cell IDs.
	 */
	public static function trace(array $open, int $side): \Generator
	{
		$cells = array_filter($open);
		// A fully surrounded cell cannot be removed in either half-pass. Once an edge
		// is tested, only a deletion in its eight neighbors can change that decision.
		$edge = [];
		$visited = 0;
		foreach($cells as $id => $_)
		{
			if(++$visited%256 === 0) yield;
			$x = $id%$side;
			if($id < $side || $id >= $side*($side-1) || $x === 0 || $x === $side-1) continue;
			if(!isset($cells[$id-$side],$cells[$id+1],$cells[$id+$side],$cells[$id-1])) $edge[$id] = true;
		}
		$pending = [$edge,$edge];
		unset($edge);
		$neighbors = [-$side,-$side+1,1,$side+1,$side,$side-1,-1,-$side-1];
		while($pending[0] || $pending[1])
		{
			foreach([0,1] as $pass)
			{
				$remove = [];
				$visited = 0;
				foreach($pending[$pass] as $id => $_)
				{
					if(++$visited%256 === 0) yield;
					if(!isset($cells[$id])) continue;
					$ring = self::ring($cells,$id,$side);
					$degree = array_sum($ring);
					if($degree<2 || $degree>6 || self::branches($ring)!==1) continue;
					[$n,,$e,,$s,,$w] = $ring;
					if($pass ? ($n && $e && $w || $n && $s && $w)
						: ($n && $e && $s || $e && $s && $w)) continue;
					$remove[] = $id;
				}
				$pending[$pass] = [];
				foreach($remove as $id) unset($cells[$id]);
				foreach($remove as $id)
				{
					if(++$visited%256 === 0) yield;
					foreach($neighbors as $offset)
					{
						$next = $id + $offset;
						if(!isset($cells[$next]) || $next < $side || $next >= $side*($side-1)) continue;
						$x = $next%$side;
						if($x === 0 || $x === $side-1) continue;
						$pending[0][$next] = $pending[1][$next] = true;
					}
				}
			}
		}

		$junctions = [];
		$distance = [];
		$queue = array_keys($cells);
		foreach($queue as $id)
		{
			$distance[$id] = 0;
			$x = $id%$side;
			$z = intdiv($id,$side);
			if($x>0 && $z>0 && $x<$side-1 && $z<$side-1 && self::branches(self::ring($cells,$id,$side))>=3)
				$junctions[] = $id;
		}

		// A short distance field lets the route planner favor the middle of a corridor.
		for($i = 0; $i<count($queue); $i++)
		{
			if($i%256 === 0) yield;
			$id = $queue[$i];
			if($distance[$id] === 4) continue;
			$x = $id%$side;
			$z = intdiv($id,$side);
			foreach([[0,-1],[1,0],[0,1],[-1,0]] as [$dx,$dz])
			{
				$nx = $x+$dx;
				$nz = $z+$dz;
				if(min($nx,$nz)<0 || max($nx,$nz)>=$side) continue;
				$next = $nz*$side+$nx;
				if($open[$next] && !isset($distance[$next]))
				{
					$distance[$next] = $distance[$id]+1;
					$queue[] = $next;
				}
			}
		}
		return [$distance,$junctions];
	}

	private static function ring(array $cells, int $id, int $n): array
	{
		return [isset($cells[$id-$n]),isset($cells[$id-$n+1]),isset($cells[$id+1]),isset($cells[$id+$n+1]),
			isset($cells[$id+$n]),isset($cells[$id+$n-1]),isset($cells[$id-1]),isset($cells[$id-$n-1])];
	}

	private static function branches(array $ring): int
	{
		$branches = 0;
		foreach($ring as $i => $occupied) $branches += (int)($occupied && !$ring[($i+7)%8]);
		return $branches;
	}
}

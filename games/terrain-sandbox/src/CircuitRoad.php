<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Grades the regional route as one continuous road, following the existing floor and banks. */
final class CircuitRoad
{
	public const WIDTH = 36.;
	private const SHOULDER = 16.;

	/** @return \Generator The road edit records for a validated centreline. */
	public static function grade(Landscape $land, Circuit $circuit): \Generator
	{
		$count = (int)ceil($circuit->length/40);
		$line = $circuit->stations(array_map(fn(int $i): float => $i*$circuit->length/$count,range(0,$count-1)));
		$ground = [];
		foreach($line as $i => [$x,$z])
		{
			if($i%16 === 0) yield;
			$ground[] = $land->height($x,$z);
		}
		$points = $banks = [];
		foreach($line as $i => [$x,$z])
		{
			if($i%16 === 0) yield;
			$p = ($i+$count-1)%$count;
			$n = ($i+1)%$count;
			[$px,$pz] = $line[$p];
			[$nx,$nz] = $line[$n];
			$height = .2*($ground[$p]+$ground[$n])+.6*$ground[$i];
			$points[] = [$x,$height,$z];
			$length = hypot($nx-$px,$nz-$pz);
			if($length<1.) return null;
			$leftX = ($pz-$nz)/$length;
			$leftZ = ($nx-$px)/$length;
			$turn = ($x-$px)*($nz-$z)-($z-$pz)*($nx-$x);
			$left = $land->height($x+14*$leftX,$z+14*$leftZ);
			$right = $land->height($x-14*$leftX,$z-14*$leftZ);
			$bank = max(-.18,min(.18,($left-$right)/28));
			// Carry the existing bank into a corner when the outside wall already rises.
			$banks[] = $bank*$turn<0 ? $bank*min(1.,abs($turn)/140) : 0.;
		}

		$edits = [];
		// Share two segments at each seam so both parts grade to the same spline through the join.
		for($start = 0; $start<$count; $start+=64)
		{
			$end = min($start+64,$count);
			$ids = array_map(static fn(int $i): int => ($i+$count)%$count,range($start-2,$end+2));
			$edits[] = ['type'=>'road','points'=>array_map(fn(int $i): array => $points[$i],$ids),
				'banks'=>array_map(fn(int $i): float => $banks[$i],$ids),'width'=>self::WIDTH,
				'shoulder'=>self::SHOULDER,'crown'=>.08,'material'=>'road'];
		}
		return $edits;
	}

	/** Measure the prepared road's grade and width, and return its height range. */
	public static function clearance(Landscape $land, Circuit $circuit, float $dry): \Generator
	{
		$count = (int)ceil($circuit->length/10);
		$line = $circuit->stations(array_map(fn(int $i): float => $i*$circuit->length/$count,range(0,$count-1)));
		$high = -INF;
		$low = INF;
		$previous = null;
		foreach([...$line,$line[0]] as $i => [$x,$z,$yaw])
		{
			if($i%8 === 0) yield;
			$h = $land->height($x,$z);
			$dx = 12*cos($yaw);
			$dz = 12*sin($yaw);
			$left = $land->height($x-$dx,$z+$dz);
			$right = $land->height($x+$dx,$z-$dz);
			if(min($h,$left,$right)<=$dry || abs($left-$right)>6. || $land->material($x,$z)!=='road') return null;
			if($previous !== null && abs($h-$previous[2])>.46*hypot($x-$previous[0],$z-$previous[1])) return null;
			$previous = [$x,$z,$h];
			$high = max($high,$h,$left,$right);
			$low = min($low,$h,$left,$right);
		}
		return [$low,$high];
	}
}

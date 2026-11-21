<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Terrain sight lines from above the car to the center and corners of the crate lid. */
final class PickupCover
{
	private const EYE = 2.55;
	private const LID = 1.5; // The rendered crate reaches 1.33 m, independently of the smoke above it.
	private const POINTS = [[0.,0.],[-1.05,-.83],[1.05,-.83],[1.05,.83],[-1.05,.83]];

	/** Save a point on each sight line where the ground stands above the crate. */
	public static function find(Landscape $land, array $car, array $site): \Generator
	{
		$eye = $car['y']+self::EYE;
		$dy = $site['y']+self::LID-$eye;
		$dx = $site['x']-$car['x']; $dz = $site['z']-$car['z'];
		$distance = hypot($dx,$dz);

		// A few probes usually find the intervening mesa without traversing kilometers of lattice cells.
		$divisions = max(2.,$distance/($distance <= 384. ? 4. : 24.));
		for($n=2; $n<=$divisions; $n*=2)
		{
			for($i=1; $i<$n; $i+=2)
			{
				yield;
				$t = $i/$n;
				if($land->height($car['x']+$t*$dx,$car['z']+$t*$dz) <= $eye+$t*$dy) continue;
				$cover = array_fill(0,count(self::POINTS),$t);
				if(self::blocks($land,$car,$site,$cover)) return $cover;
			}
		}

		// A thin ridge can lie between probes. Each ray stops at its own first terrain hit.
		$cover = [];
		foreach(self::POINTS as [$x,$z])
		{
			$direction = [$dx+$x,$dy+.10,$dz+$z];
			$length = hypot(hypot($direction[0],$direction[2]),$direction[1]);
			$hit = yield from $land->raycastSteps([$car['x'],$eye,$car['z']],$direction,$length);
			if($hit === null) return null;
			$cover[] = $hit['distance']/$length;
		}
		return self::blocks($land,$car,$site,$cover) ? $cover : null;
	}

	/** Recheck the sampled terrain against the driver's current position in one simulation tick. */
	public static function blocks(Landscape $land, array $car, array $site, array $cover): bool
	{
		$eye = $car['y']+self::EYE;
		foreach(self::POINTS as $i=>[$x,$z])
		{
			$t = $cover[$i];
			$h = $land->height($car['x']+$t*($site['x']+$x-$car['x']),$car['z']+$t*($site['z']+$z-$car['z']));
			if($h <= $eye+$t*($site['y']+self::LID-$eye)) return false;
		}
		return true;
	}
}

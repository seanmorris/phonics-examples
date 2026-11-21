<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainPhonics\Recipe;

/** Choose unseen boxes from the depot's connected ground, preferably across a natural obstacle. */
final class PickupSearch
{
	private DriveRegion $region;
	private ?DriveRegion $nearby = null, $local = null;
	private ?\Closure $floor;
	private float $range;
	private ?array $previous = null;

	public function __construct(private Landscape $land, private float $water, private array $depot)
	{
		$r = $land->recipe();
		$this->region = new DriveRegion($land,$water,$depot);
		$this->range = min(DeliveryRun::MAX_LEG,($r['worldSize']/2-6)*.91);
		$this->floor = self::floorTest($land);
	}

	/** Both ends of a canyon delivery use the same seed-specific valley floor, including its strata and detail. */
	public static function floorTest(Landscape $land): ?\Closure
	{
		$r = $land->recipe();
		$wall = $r['wallHeight']*$r['heightScale'];
		if($wall <= 0) return null;
		$allowance = min(24.,.12*$wall);
		$floor = new Recipe(['wallHeight'=>0.] + $r);
		return static fn(float $x, float $z, float $y): bool => $y <= $floor->height($x,$z)+$allowance;
	}

	public function find(array $car, Camera $camera, float $aspect, float $phase): \Generator
	{
		if($this->floor !== null && !($this->floor)($this->depot['x'],$this->depot['z'],$this->depot['y'])) return null;
		if(!(yield from $this->region->containsPosition($car['x'],$car['z']))) return yield from $this->confinedSite($car,$camera,$aspect);
		$attempt = 0;
		$seen = [];
		foreach([1.,.72,.45,.25] as $fraction)
		{
			$open = [];
			for($i=0; $i<($fraction === 1. ? 48 : 24); $i++)
			{
				yield;
				$attempt++;
				$angle = $phase+2.39996323*$attempt;
				$distance = $this->range*$fraction*(.70+.30*fmod($attempt*.75487767,1.));
				$id = $this->region->nearest($car['x']+sin($angle)*$distance,$car['z']+cos($angle)*$distance);
				if($id === null || isset($seen[$id])) continue;
				$seen[$id] = true;
				$point = $this->region->point($id);
				if(abs($point[2]-$this->depot['y'])>min(140.,max(24.,$distance*.12))) continue;
				$site = $this->site($point,$car,$camera,$aspect);
				if($site === null) continue;
				if($this->region->isLevel() || (yield from $this->crossesObstacle($site)))
				{
					if(!(yield from $this->region->connect($id))) continue;
					return $this->previous = $site;
				}
				$open[$id] = $site;
			}
			foreach($open as $id=>$site)
			{
				if(!(yield from $this->region->connect($id))) continue;
				return $this->previous = $site;
			}
		}
		return yield from $this->confinedSite($car,$camera,$aspect);
	}

	private function site(array $point, array $car, Camera $camera, float $aspect, bool $allowPrevious = false): ?array
	{
		[$x,$z,$y] = $point;
		$distance = hypot($x-$car['x'],$z-$car['z']);
		if($distance<12 || $distance>DeliveryRun::MAX_LEG || hypot($x-$this->depot['x'],$z-$this->depot['z'])<12
			|| $y<$this->water+1 || (!$allowPrevious && $this->previous !== null && $this->previous['x']===$x && $this->previous['z']===$z)) return null;
		$site = ['x'=>$x,'z'=>$z,'y'=>$y,'kind'=>'pickup','color'=>DeliveryRun::COLORS['pickup']];
		if(DeliveryRun::visible($site,$camera,$aspect) || ($this->floor !== null && !($this->floor)($x,$z,$y))) return null;
		$s = $this->land->sample($x,$z);
		return hypot($s['dx'],$s['dz']) <= .25 ? $site : null;
	}

	/** A coarse link can miss a turn halfway across its cell. Keep the closer searches at their original scales. */
	private function confinedSite(array $car, Camera $camera, float $aspect): \Generator
	{
		$this->nearby ??= new DriveRegion($this->land,$this->water,$this->depot,96.);
		if(yield from $this->nearby->containsPosition($car['x'],$car['z']))
		{
			$site = yield from $this->nearbySite($this->nearby,min(1200.,$this->range),$car,$camera,$aspect);
			if($site !== null) return $site;
		}
		return yield from $this->localSite($car,$camera,$aspect);
	}

	private function localSite(array $car, Camera $camera, float $aspect): \Generator
	{
		$this->local ??= new DriveRegion($this->land,$this->water,$this->depot,16.);
		if(!(yield from $this->local->containsPosition($car['x'],$car['z']))) return null;
		$site = yield from $this->nearbySite($this->local,min(384.,$this->range),$car,$camera,$aspect);
		if($site !== null || $this->previous === null) return $site;
		// A tiny island can have just one safe, hidden site. It can host a new box after delivery.
		$p = $this->previous;
		return $this->site([$p['x'],$p['z'],$p['y']],$car,$camera,$aspect,true);
	}

	private function nearbySite(DriveRegion $region, float $radius, array $car, Camera $camera, float $aspect): \Generator
	{
		$best = null;
		$bestDistance = 0.;
		foreach($region->nearby($radius) as $point)
		{
			yield;
			if($point === null) continue;
			$distance = hypot($point[0]-$car['x'],$point[1]-$car['z']);
			if($distance <= $bestDistance) continue;
			$site = $this->site($point,$car,$camera,$aspect);
			if($site === null) continue;
			$best = $site; $bestDistance = $distance;
			if($distance >= .8*$radius) break;
		}
		if($best !== null) $this->previous = $best;
		return $best;
	}

	/** This coarse chord only ranks scenery. Both placement paths require the same connected-ground check. */
	private function crossesObstacle(array $site): \Generator
	{
		$dx = $site['x']-$this->depot['x']; $dz = $site['z']-$this->depot['z'];
		$length = hypot($dx,$dz);
		$count = max(1,(int)ceil($length/64.));
		$recipe = $this->land->recipe();
		$spacing = $recipe['spacing'];
		$bound = floor($recipe['worldSize']/(2*$spacing))*$spacing;
		$last = $this->depot['y'];
		for($i=1; $i<=$count; $i++)
		{
			yield;
			$x = round(($this->depot['x']+$dx*$i/$count)/$spacing)*$spacing;
			$z = round(($this->depot['z']+$dz*$i/$count)/$spacing)*$spacing;
			$h = $this->land->height(max(-$bound,min($bound,$x)),max(-$bound,min($bound,$z)));
			if($h < $this->water+1 || abs($h-$last)>.46*($length/$count+$spacing)
				|| $h>max($site['y'],$this->depot['y'])+90) return true;
			$last = $h;
		}
		return false;
	}
}

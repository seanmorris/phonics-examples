<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;
use TerrainPhonics\Recipe;

/** Choose unseen boxes from the depot's connected ground, preferably across a natural obstacle. */
final class PickupSearch
{
	private array $regions = [];
	private ?\Closure $floor;
	private float $range;
	private const GOLDEN_ANGLE = 2.39996323;
	private array $recent = [];

	public function __construct(private Landscape $land, private float $water, private array $depot)
	{
		$r = $land->recipe();
		$this->range = min(DeliveryRun::MAX_LEG,($r['worldSize']/2-6)*.91);
		$this->floor = self::floorTest($land);
	}

	/** Recheck routes on the new surface while retaining the locations of recent deliveries. */
	public function refitted(Landscape $land, float $water, array $depot): self
	{
		$next = new self($land,$water,$depot);
		$next->recent = $this->recent;
		return $next;
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

	/** Only boxes actually published in the world influence the next delivery. */
	public function placed(array $site): void
	{
		$x = $site['x']-$this->depot['x']; $z = $site['z']-$this->depot['z'];
		array_unshift($this->recent,['site'=>$site,'bearing'=>atan2($x,$z),'radius'=>hypot($x,$z)]);
		if(count($this->recent)>3) array_pop($this->recent);
	}

	/** Start in the largest unused arc, then wind around the depot with golden-angle probes. */
	private function searchPhase(float $phase): float
	{
		if(!$this->recent) return $phase;
		$angles = [];
		foreach($this->recent as $p) $angles[] = atan2(sin($p['bearing']-$phase),cos($p['bearing']-$phase));
		sort($angles,SORT_NUMERIC);
		$angles[] = $angles[0]+2*M_PI;
		$largest = 0.; $middle = 0.;
		for($i=1; $i<count($angles); $i++)
		{
			$gap = $angles[$i]-$angles[$i-1];
			if($gap > $largest) { $largest = $gap; $middle = $angles[$i-1]+$gap/2; }
		}
		return $phase+$middle+.15*$largest*sin($phase)-self::GOLDEN_ANGLE;
	}

	/** Full preference needs separation from the last three boxes and a 60-degree turn from the latest. */
	private function spread(array $site): float
	{
		if(!$this->recent) return 1.;
		$x = $site['x']-$this->depot['x']; $z = $site['z']-$this->depot['z'];
		$radius = hypot($x,$z);
		$gap = 1.;
		foreach($this->recent as $p)
		{
			$spacing = min(.30*$this->range,.60*max(24.,$radius,$p['radius']));
			$gap = min($gap,hypot($site['x']-$p['site']['x'],$site['z']-$p['site']['z'])/$spacing);
		}
		$turn = min(1.,2*abs(sin((atan2($x,$z)-$this->recent[0]['bearing'])/2)));
		return $gap*(.5+.5*$turn);
	}

	public function find(array $car, Camera $camera, float $aspect, float $phase): \Generator
	{
		if($this->floor !== null && !($this->floor)($this->depot['x'],$this->depot['z'],$this->depot['y'])) return null;
		// Different caps can round to the same terrain lattice. Check the car once per actual grid.
		$access = []; $alternates = [];
		$phase = $this->searchPhase($phase);
		foreach([192.,96.] as $cap)
		{
			$region = $this->region($cap);
			$key = spl_object_id($region);
			if(isset($access[$key])) continue;
			$access[$key] = yield from $region->containsPosition($car['x'],$car['z']);
			if(!$access[$key]) continue;
			$site = yield from $this->distantSite($region,$car,$camera,$aspect,$phase,$alternates);
			if($site !== null) return $site;
		}
		foreach([[96.,1200.],[16.,384.]] as [$cap,$radius])
		{
			$region = $this->region($cap);
			$key = spl_object_id($region);
			$access[$key] ??= yield from $region->containsPosition($car['x'],$car['z']);
			if(!$access[$key]) continue;
			$radius = min($radius,$this->range);
			$site = yield from $this->choosePoint($region->nearby($radius),$radius,$car,$camera,$aspect,$alternates);
			if($site !== null) return $site;
		}
		// A thin, winding road can fall between radial probes. Its already verified bends are pickup sites too.
		foreach($this->regions as $region)
		{
			if(!$access[spl_object_id($region)]) continue;
			$site = yield from $this->choosePoint($region->remainingPoints(),$this->range,$car,$camera,$aspect,$alternates);
			if($site !== null) return $site;
		}
		// A narrow valley may offer only one heading. Take the best separated reachable alternative.
		usort($alternates,static fn($a,$b) => $b[0] <=> $a[0]);
		foreach($alternates as [$score,$site,$region,$id])
			if($id === null || (yield from $region->connect($id))) return $site;
		return null;
	}

	private function region(float $cap): DriveRegion
	{
		$key = (string)DriveRegion::cellSize($this->land->recipe(),$cap);
		return $this->regions[$key] ??= new DriveRegion($this->land,$this->water,$this->depot,$cap);
	}

	private function distantSite(DriveRegion $region, array $car, Camera $camera, float $aspect, float $phase, array &$alternates): \Generator
	{
		if(!(yield from $region->hasDestinations())) return null;
		$attempt = 0;
		$seen = [];
		foreach([1.,.72,.45,.25] as $fraction)
		{
			$open = [];
			for($i=0; $i<($fraction === 1. ? 48 : 24); $i++)
			{
				yield;
				$attempt++;
				$angle = $phase+self::GOLDEN_ANGLE*$attempt;
				$distance = $this->range*$fraction*(.70+.30*fmod($attempt*.75487767,1.));
				$id = $region->nearest($car['x']+sin($angle)*$distance,$car['z']+cos($angle)*$distance,true);
				if($id === null || isset($seen[$id])) continue;
				$seen[$id] = true;
				$point = $region->point($id);
				if(abs($point[2]-$this->depot['y'])>min(140.,max(24.,$distance*.12))) continue;
				$site = $this->site($point,$car,$camera,$aspect);
				if($site === null) continue;
				$score = $this->spread($site);
				if($score < 1.)
				{
					$alternates[] = [$score,$site,$region,$id];
					continue;
				}
				if($region->isLevel() || (yield from $this->crossesObstacle($site)))
				{
					if(!(yield from $region->connect($id))) continue;
					return $site;
				}
				$open[$id] = $site;
			}
			foreach($open as $id=>$site)
			{
				if(!(yield from $region->connect($id))) continue;
				return $site;
			}
		}
		return null;
	}

	private function site(array $point, array $car, Camera $camera, float $aspect): ?array
	{
		[$x,$z,$y] = $point;
		$distance = hypot($x-$car['x'],$z-$car['z']);
		if($distance<12 || $distance>DeliveryRun::MAX_LEG || hypot($x-$this->depot['x'],$z-$this->depot['z'])<12
			|| $y<$this->water+1) return null;
		$site = ['x'=>$x,'z'=>$z,'y'=>$y,'kind'=>'pickup','color'=>DeliveryRun::COLORS['pickup']];
		if(DeliveryRun::visible($site,$camera,$aspect) || ($this->floor !== null && !($this->floor)($x,$z,$y))) return null;
		$s = $this->land->sample($x,$z);
		return hypot($s['dx'],$s['dz']) <= .25 ? $site : null;
	}

	private function choosePoint(\Generator $points, float $radius, array $car, Camera $camera, float $aspect, array &$alternates): \Generator
	{
		$alternate = null;
		$best = null;
		$bestDistance = 0.;
		foreach($points as $point)
		{
			yield;
			if($point === null) continue;
			$distance = hypot($point[0]-$car['x'],$point[1]-$car['z']);
			if($distance <= $bestDistance) continue;
			$site = $this->site($point,$car,$camera,$aspect);
			if($site === null) continue;
			$score = $this->spread($site);
			if($score < 1.)
			{
				if($alternate === null || $score > $alternate[0]) $alternate = [$score,$site,null,null];
				continue;
			}
			$best = $site; $bestDistance = $distance;
			if($distance >= .8*$radius) break;
		}
		if($alternate !== null) $alternates[] = $alternate;
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

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\Recipe;

/** Choose unseen boxes from the depot's connected ground, preferably across a natural obstacle. */
final class PickupSearch
{
	private array $regions = [];
	private ?\Closure $floor;
	private float $range;
	private float $plateauRise;
	private const GOLDEN_ANGLE = 2.39996323;
	private array $recent = [];
	private array $observer = [], $sightLines = [];

	public function __construct(private Landscape $land, private float $water, private array $depot)
	{
		$r = $land->recipe();
		$this->range = min(DeliveryRun::MAX_LEG,($r['worldSize']/2-6)*.91);
		$this->floor = self::floorTest($land);
		// Tall, steep-sided valley walls can screen an entire plume and force a drive around the mesa.
		$relief = $r['wallHeight']*$r['heightScale'];
		$this->plateauRise = $this->range >= 2000. && $relief >= 250. && $r['wallWidth'] <= $r['floorWidth']
			? max(125.,.30*$relief) : 0.;
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

	public function find(array $car, float $phase): \Generator
	{
		if($this->plateauRise > 0.) {
			$site = yield from $this->search($car,$phase,true);
			if($site !== null) return $site;
		}
		// An enclosed branch may have no route around a plateau. It can still support a local delivery.
		return yield from $this->search($car,$phase,false);
	}

	private function search(array $car, float $phase, bool $mesa): \Generator
	{
		if($this->floor !== null && !($this->floor)($this->depot['x'],$this->depot['z'],$this->depot['y'])) return null;
		// Without elevation relief there is nowhere to hide the crate on any grid.
		if($this->region(192.)->isLevel()) return null;
		$observer = [$car['x'],$car['y'],$car['z']];
		if($observer !== $this->observer)
		{
			$this->observer = $observer;
			$this->sightLines = [];
		}
		// Different caps can round to the same terrain lattice. Check the car once per actual grid.
		$access = []; $alternates = [];
		$phase = $this->searchPhase($phase);

		// Try both route resolutions in the chosen sector before accepting a long leg elsewhere.
		// A coarse grid commonly misses the bend into a narrower branch of the canyon.
		$passes = $mesa ? [[true,[1.]], [false,[1.]]]
			: [[true,[1.,.70,.45]], [true,[.25,.12]], [false,[1.,.72,.45,.25]]];
		foreach($passes as [$sector,$bands])
		{
			$visited = [];
			foreach($mesa && !$sector ? [192.,96.,48.] : [192.,96.] as $cap)
			{
				$region = $this->region($cap);
				$key = spl_object_id($region);
				if(isset($visited[$key])) continue;
				$visited[$key] = true;
				$access[$key] ??= yield from $region->containsPosition($car['x'],$car['z']);
				if(!$access[$key]) continue;
				$site = yield from $this->distantSite($region,$car,$phase,$alternates,$sector,$bands,$mesa);
				if($site !== null) return $site;
				if($mesa && $sector) {
					// The verified route can pass excellent sites between the radial probes.
					$site = yield from $this->choosePoint($region->remainingPoints(),$this->range,$car,$alternates,true,
						$phase+self::GOLDEN_ANGLE);
					if($site !== null) return $site;
				}
			}
		}
		$nearby = $mesa ? [] : [[96.,1200.],[16.,384.]];
		foreach($nearby as [$cap,$radius])
		{
			$region = $this->region($cap);
			$key = spl_object_id($region);
			$access[$key] ??= yield from $region->containsPosition($car['x'],$car['z']);
			if(!$access[$key]) continue;
			$radius = min($radius,$this->range);
			$site = yield from $this->choosePoint($region->nearby($radius),$radius,$car,$alternates,$mesa);
			if($site !== null) return $site;
		}
		// A thin, winding road can fall between radial probes. Its already verified bends are pickup sites too.
		foreach($this->regions as $region)
		{
			if(!($access[spl_object_id($region)] ?? false)) continue;
			$site = yield from $this->choosePoint($region->remainingPoints(),$this->range,$car,$alternates,$mesa);
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

	private function distantSite(DriveRegion $region, array $car, float $phase, array &$alternates, bool $sector, array $bands, bool $mesa): \Generator
	{
		if(!(yield from $region->hasDestinations())) return null;
		$attempt = 0; $approached = false;
		$seen = [];
		foreach($bands as $fraction)
		{
			$open = []; $deferred = [];
			$count = $sector ? 16 : ($fraction === 1. ? 48 : 24);
			for($i=0; $i<$count; $i++)
			{
				yield;
				$attempt++;
				$angle = $sector
					? $phase+self::GOLDEN_ANGLE+1.5*(2*fmod($i*.61803399+.5,1.)-1.)
					: $phase+self::GOLDEN_ANGLE*$attempt;
				$radial = fmod($attempt*.75487767,1.);
				$from = $mesa ? $this->depot : $car;
				$distance = $this->range*$fraction*($mesa ? .77+.21*$radial : .70+.30*$radial);
				$id = $region->nearest($from['x']+sin($angle)*$distance,$from['z']+cos($angle)*$distance,true);
				if($id === null || isset($seen[$id])) continue;
				$seen[$id] = true;
				$point = $region->point($id);
				if(abs($point[2]-$this->depot['y'])>min(140.,max(24.,$distance*.12))) continue;
				$site = yield from $this->site($point,$car,$mesa);
				if($site === null) continue;
				$score = $this->spread($site);
				if($score < 1.)
				{
					if(!$sector) $alternates[] = [$score,$site,$region,$id];
					continue;
				}
				if($sector || $mesa || (yield from $this->crossesObstacle($site)))
				{
					if($mesa && $sector && !$approached) {
						$approached = true;
						if(yield from $region->approach($id,$region === $this->region(96.) ? 256 : 16)) return $site;
					}
					if(yield from $region->connect($id,quick:true)) return $site;
					if(!$sector) $deferred[$id] = $site;
					continue;
				}
				$open[$id] = $site;
			}
			// Retry harder scenic routes with the full budget before leaving this distance band.
			foreach($deferred+$open as $id=>$site)
			{
				if(!(yield from $region->connect($id))) continue;
				return $site;
			}
		}
		return null;
	}

	private function site(array $point, array $car, bool $mesa): \Generator
	{
		[$x,$z,$y] = $point;
		$distance = hypot($x-$car['x'],$z-$car['z']);
		$leg = hypot($x-$this->depot['x'],$z-$this->depot['z']);
		if($mesa && ($leg < .75*$this->range || $leg > $this->range)) return null;
		if($distance<12 || $distance>DeliveryRun::MAX_LEG || $leg<12
			|| $y<$this->water+1) return null;
		$site = ['x'=>$x,'z'=>$z,'y'=>$y,'kind'=>'pickup','color'=>DeliveryRun::COLORS['pickup']];
		if($this->floor !== null && !($this->floor)($x,$z,$y)) return null;
		$s = $this->land->sample($x,$z);
		if(hypot($s['dx'],$s['dz']) > .25) return null;
		$key = "$x:$z:".(int)$mesa;
		if(!array_key_exists($key,$this->sightLines)) {
			if($mesa) {
				$crest = yield from PickupCover::plateau($this->land,$this->depot,$site,$this->plateauRise);
				if($crest !== null && !PickupCover::hidesPlume($this->land,$car,$site,$crest))
					$crest = yield from PickupCover::plateau($this->land,$car,$site,$this->plateauRise);
				$this->sightLines[$key] = $crest === null ? null : array_fill(0,5,$crest);
			} else $this->sightLines[$key] = yield from PickupCover::find($this->land,$car,$site);
		}
		$cover = $this->sightLines[$key];
		if($cover === null) return null;
		if($mesa) $site['plumeCover'] = $cover[0];
		return $site+['cover'=>$cover];
	}

	private function choosePoint(\Generator $points, float $radius, array $car, array &$alternates, bool $mesa, ?float $bearing = null): \Generator
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
			if($bearing !== null && cos(atan2($point[0]-$this->depot['x'],$point[1]-$this->depot['z'])-$bearing)<.35) continue;
			$site = yield from $this->site($point,$car,$mesa);
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

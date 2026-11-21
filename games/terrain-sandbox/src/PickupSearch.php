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
	private ?Recipe $floor = null;
	private float $range, $floorAllowance;
	private ?int $previous = null;

	public function __construct(private Landscape $land, private float $water, private array $depot)
	{
		$r = $land->recipe();
		$this->region = new DriveRegion($land,$water,$depot);
		$this->range = min(DeliveryRun::MAX_LEG,($r['worldSize']/2-6)*.91);
		// The same seed and relief with escarpment height removed gives the local valley datum,
		// including its rolling slope, strata and detail. This also recognizes a wide, level mesa top.
		$wall = $r['wallHeight']*$r['heightScale'];
		$this->floorAllowance = min(24.,.12*$wall);
		if($wall>0) $this->floor = new Recipe(['wallHeight'=>0.] + $r);
	}

	public function find(array $car, Camera $camera, float $aspect, float $phase): \Generator
	{
		if(!(yield from $this->region->containsPosition($car['x'],$car['z']))) return null;
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
				if($id === null || $id === $this->previous || isset($seen[$id])) continue;
				$seen[$id] = true;
				[$x,$z,$y] = $this->region->point($id);
				if(hypot($x-$this->depot['x'],$z-$this->depot['z'])<12 || hypot($x-$car['x'],$z-$car['z'])>DeliveryRun::MAX_LEG
					|| $y<$this->water+1 || abs($y-$this->depot['y'])>min(140.,max(24.,$distance*.12))) continue;
				$site = ['x'=>$x,'z'=>$z,'y'=>$y,'kind'=>'pickup','color'=>DeliveryRun::COLORS['pickup']];
				if(DeliveryRun::visible($site,$camera,$aspect)) continue;
				if($this->floor && $y > $this->floor->height($x,$z)+$this->floorAllowance) continue;
				$s = $this->land->sample($x,$z);
				if(hypot($s['dx'],$s['dz'])>.25) continue;
				if($this->region->isLevel() || (yield from $this->crossesObstacle($site)))
				{
					if(!(yield from $this->region->connect($id))) continue;
					$this->previous = $id;
					return $site;
				}
				$open[$id] = $site;
			}
			foreach($open as $id=>$site)
			{
				if(!(yield from $this->region->connect($id))) continue;
				$this->previous = $id;
				return $site;
			}
		}
		return null;
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

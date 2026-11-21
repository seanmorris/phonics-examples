<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Look for a reachable box across a ridge, with a direct drive as the fallback on open terrain. */
final class PickupSearch
{
	private float $limit, $spacing;

	public function __construct(private Landscape $land, private float $water)
	{
		$this->limit = $land->recipe()['worldSize']/2-6;
		$this->spacing = $land->recipe()['spacing'];
	}

	public function find(array $depot, array $car, Camera $camera, float $aspect, float $phase): \Generator
	{
		$range = min(DeliveryRun::MAX_LEG,$this->limit*.91);
		$flat = $this->land->recipe()['heightScale'] == 0 && $this->land->recipe()['detailAmplitude'] == 0
			&& $this->land->revision() === 0;
		$attempt = 0;
		foreach([1.,.72,.45,.25] as $fraction)
		{
			$fallback = null;
			for($i=0; $i<24; $i++)
			{
				yield;
				$attempt++;
				$angle = $phase + 2.39996323*$attempt;
				$distance = $range*$fraction*(.70+.30*fmod($attempt*.75487767,1.));
				$x = $this->snap($car['x'] + sin($angle)*$distance);
				$z = $this->snap($car['z'] + cos($angle)*$distance);
				if(abs($x)>$this->limit || abs($z)>$this->limit || hypot($x-$depot['x'],$z-$depot['z'])<12) continue;
				$y = $this->land->height($x,$z);
				$site = ['x'=>$x,'y'=>$y,'z'=>$z,'kind'=>'pickup','color'=>DeliveryRun::COLORS['pickup']];
				if($y < $this->water+1 || abs($y-$depot['y']) > min(140.,max(24.,$distance*.12))
					|| DeliveryRun::visible($site,$camera,$aspect)) continue;
				yield;
				$s = $this->land->sample($x,$z);
				if(hypot($s['dx'],$s['dz']) > .25) continue;

				$ceiling = max($y,$depot['y'])+90.;
				if(yield from $this->passage($depot,$x,$z,$ceiling))
				{
					if($flat) return $site;
					$fallback ??= $site;
					continue;
				}
				if(yield from $this->detour($depot,$site,$ceiling)) return $site;
			}
			// Exhaust the distant sites before reducing the radius for small islands and confined valleys.
			if($fallback !== null) return $fallback;
		}
		return null;
	}

	/** Follow connected dry floor around a blocked chord. Expand from the destination to reject isolated ledges early. */
	private function detour(array $depot, array $site, float $ceiling): \Generator
	{
		$dx = $site['x']-$depot['x']; $dz = $site['z']-$depot['z'];
		$distance = hypot($dx,$dz);
		$cell = max($this->spacing,$this->snap(min(192.,$distance/10.)));
		// Orient the grid along the chord. The depot and pickup are exact grid points.
		$columns = max(1,(int)ceil($distance/$cell));
		$ux = $dx/$columns; $uz = $dz/$columns;
		$frontier = new \SplPriorityQueue;
		$frontier->insert([$columns,0,0.],0.);
		$visited = $cost = [];
		while(!$frontier->isEmpty() && count($visited)<650)
		{
			[$a,$b,$g] = $frontier->extract();
			if($a === 0 && $b === 0) return true;
			$key = "$a:$b";
			if(isset($visited[$key])) continue;
			$visited[$key] = true;
			$x = $depot['x']+$a*$ux-$b*$uz; $z = $depot['z']+$a*$uz+$b*$ux;
			$here = ['x'=>$x,'z'=>$z,'y'=>$this->land->height($this->snap($x),$this->snap($z))];
			yield;
			foreach([[-1,0],[0,1],[0,-1],[1,0],[-1,1],[-1,-1],[1,1],[1,-1]] as [$da,$db])
			{
				$na = $a+$da; $nb = $b+$db;
				if($na < -$columns/2 || $na > $columns*1.5 || abs($nb)>$columns*.85) continue;
				$next = "$na:$nb";
				$newCost = $g+($da && $db ? M_SQRT2 : 1.);
				if(isset($visited[$next]) || $newCost >= ($cost[$next] ?? INF)) continue;
				$nx = $x+$da*$ux-$db*$uz; $nz = $z+$da*$uz+$db*$ux;
				if(!(yield from $this->passage($here,$nx,$nz,$ceiling))) continue;
				$cost[$next] = $newCost;
				$frontier->insert([$na,$nb,$newCost],-($newCost+1.4*hypot($na,$nb)));
			}
		}
		return false;
	}

	private function snap(float $v): float { return round($v/$this->spacing)*$this->spacing; }

	/** Sample one grid edge or the entire direct approach at intervals of up to 64 metres. */
	private function passage(array $start, float $endX, float $endZ, float $ceiling): \Generator
	{
		if(abs($endX)>$this->limit || abs($endZ)>$this->limit) return false;
		$dx = $endX-$start['x']; $dz = $endZ-$start['z'];
		$length = hypot($dx,$dz);
		$count = max(1,(int)ceil($length/64.));
		$maxRise = .46*($length/$count+$this->spacing);
		$last = $start['y'];
		for($i=1; $i<=$count; $i++)
		{
			yield;
			$px = $this->snap($start['x']+$dx*$i/$count); $pz = $this->snap($start['z']+$dz*$i/$count);
			if(abs($px)>$this->limit || abs($pz)>$this->limit) return false;
			$y = $this->land->height($px,$pz);
			if($y < $this->water+1 || $y > $ceiling || abs($y-$last)>$maxRise) return false;
			$last = $y;
		}
		return true;
	}
}

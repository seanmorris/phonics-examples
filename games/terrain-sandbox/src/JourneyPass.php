<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{Edits,Recipe};

/** Grade both approaches and the onward pavement as one road network at a mountain confluence. */
final class JourneyPass
{
	private Recipe $ground;
	public function __construct(array $recipe,int $span)
	{
		unset($recipe['join']);
		$this->ground=new Recipe(['worldSize'=>$span]+$recipe);
	}

	/** Match heights at the junction while retaining the elevations at each map entrance and exit. */
	public function grade(array $roads,array $junction): \Generator
	{
		$edges=$paths=$pref=$ends=$starts=[];
		$floor=$this->ground->values()['waterLevel']+6.;
		foreach($roads as $id=>$r) {
			$path=[];
			foreach($r['points'] as $i=>$p) {
				$key=$i===0 || $i===array_key_last($r['points']) ? JourneySigns::node($p) : "$id:$i";
				$pref[$key]=max($floor,$this->ground->height($p[0],$p[2]));
				if($i) {
					$b=$r['points'][$i-1]; $cap=.098*hypot($p[0]-$b[0],$p[2]-$b[2]);
					if(max(hypot($p[0]-$junction[0],$p[2]-$junction[2]),hypot($b[0]-$junction[0],$b[2]-$junction[2]))<5.*$r['width']) $cap=0.;
					$edges[$path[$i-1]][$key]=$edges[$key][$path[$i-1]]=$cap;
				}
				$path[]=$key;
			}
			$paths[$id]=$path;
			$starts[$path[0]]=$r['points'][0][1]; $ends[end($path)]=end($r['points'])[1];
			yield;
		}
		$anchors=array_diff_key($starts,$ends)+array_diff_key($ends,$starts);
		$lo=JourneyRoad::envelope($edges,$pref);
		$hi=JourneyRoad::envelope($edges,array_map(static fn($v)=>-$v,$pref));
		$upper=JourneyRoad::envelope($edges,$anchors);
		$lower=JourneyRoad::envelope($edges,array_map(static fn($v)=>-$v,$anchors));
		foreach($pref as $k=>$v) {
			if(-$lower[$k]>$upper[$k]+.001) return null;
			$pref[$k]=max(-$lower[$k],min($upper[$k],($lo[$k]-$hi[$k])*.5));
		}
		foreach($roads as $id=>&$r) {
			foreach($r['points'] as $i=>&$p) $p[1]=$pref[$paths[$id][$i]];
			unset($p);
			for($i=1;$i<count($r['points']);$i++) {
				$p=$r['points'][$i-1]; $q=$r['points'][$i]; $dx=$q[0]-$p[0]; $dz=$q[2]-$p[2];
				$slope=($q[1]-$p[1])/($dx*$dx+$dz*$dz);
				foreach([[$i-1,'out'],[$i,'in']] as [$j,$side]) {
					$h=Edits::roadHandles($r['points'],$r['handles'] ?? [],$j)[$side];
					$h[1]=$slope*($h[0]*$dx+$h[2]*$dz);
					$r['handles'][$j][$side]=$h;
				}
			}
			yield;
		}
		return $roads;
	}
}

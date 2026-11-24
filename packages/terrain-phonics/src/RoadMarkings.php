<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Paint coordinates for continuous road sections and the shared pavement of forks and merges. */
final class RoadMarkings
{
	public const DASH_METERS = 3.048;

	/** Derive the visible paint paths from committed control points, independent of edit order. */
	public static function connect(array $operations): array
	{
		$starts = $ends = $incident = $next = $previous = $remaining = [];
		foreach($operations as $i=>$op) {
			if($op['type'] !== 'road') continue;
			unset($operations[$i]['_road']['ink']);
			if($op['material'] !== 'road') continue;
			$points = $op['points'];
			foreach([$points[0],$points[array_key_last($points)]] as $end=>$point) $incident[serialize($point)][]=[$i,$end];
			$starts[serialize([$points[0],$op['width']])][] = $i;
			$ends[serialize([$points[array_key_last($points)],$op['width']])][] = $i;
		}
		$junctions=[];
		foreach($incident as $point=>$legs) if(count($legs)>=3) foreach($legs as [$i,$end]) {
			$junctions[$i][$end]=$point;
			$remaining[$i]=true;
		}
		foreach($ends as $key=>$from) {
			$to = $starts[$key] ?? [];
			if(count($from) !== 1 || count($to) !== 1 || isset($junctions[$from[0]][1])) continue;
			$next[$from[0]] = $to[0];
			$previous[$to[0]] = $from[0];
			$remaining[$from[0]] = $remaining[$to[0]] = true;
		}
		$paths=$bearings=$keys=[];
		// Visit open chains from their physical start, then any remaining closed loops.
		foreach([...array_keys(array_diff_key($remaining,$previous)), ...array_keys($remaining)] as $first) {
			if(!isset($remaining[$first])) continue;
			$offsets = []; $length = 0.; $i = $first;
			while(isset($remaining[$i])) {
				unset($remaining[$i]);
				$offsets[$i] = $length;
				$stations = $operations[$i]['_road']['stations'];
				$length += $stations[array_key_last($stations)];
				$i = $next[$i] ?? null;
			}
			$last = array_key_last($offsets);
			$startFork = $junctions[$first][0] ?? null;
			$endFork = $junctions[$last][1] ?? null;
			$lap = $i === $first || ($startFork !== null && $endFork !== null) ? $length : 0.;
			// Center a dash on each fork, regardless of the direction a road was drawn.
			$origin = $startFork !== null || $endFork !== null ? self::DASH_METERS/2 : 0.;
			if($startFork === null && $endFork !== null) $origin -= $length;
			$pivot = $lap > 0. ? self::middleSample($operations,$offsets,$lap/2) : 0.;
			// The encoded phase can reverse at the center of a dash or gap without reversing the painted pattern.
			$halfPattern = max(1.,round($lap/(2*self::DASH_METERS)))*self::DASH_METERS;
			foreach($offsets as $id=>$offset) {
				$operations[$id]['_road']['ink'] = ['offset'=>$offset,'origin'=>$origin,'lap'=>$lap,'pivot'=>$pivot,
					'rising'=>$pivot > 0. ? $halfPattern/$pivot : 0.,
					'falling'=>$lap > $pivot ? $halfPattern/($lap-$pivot) : 0.];
			}
			// All editable sections of one uninterrupted arm share a paint coordinate.
			// Track both ends so short sections inside a fork behave like one long spline.
			if($startFork!==null || $endFork!==null) {
				$paths[$first]=$offsets;
				$ends=[];
				foreach([$first,$last] as $end=>$id) {
					$samples=$operations[$id]['_road']['samples'];
					[$x,$y,$z,$tx,$tz]=$samples[$end ? array_key_last($samples) : 0];
					$direction=$end ? -1 : 1;
					$ends[]=[$x,$y,$z,$direction*$tx,$direction*$tz];
					$point=$end ? $endFork : $startFork;
					if($point===null) continue;
					$bearings[$point][$first]=[$direction*$tx,$direction*$tz,$direction];
				}
				sort($ends);
				$keys[$first]=$ends;
			}
			foreach($offsets as $id=>$_) {
				foreach([0=>$previous[$id] ?? null,1=>$next[$id] ?? null] as $end=>$neighbor) {
					if($neighbor === null) continue;
					$road = $operations[$id]['_road'];
					$segments = [];
					[$x,,$z] = $road['samples'][$end ? array_key_last($road['samples']) : 0];
					$j = $neighbor;
					do {
						$other = $operations[$j]['_road'];
						$further = $end ? ($next[$j] ?? null) : ($previous[$j] ?? null);
						if(!self::continuation($segments,$other,$end,$x,$z,2*$road['gridSize'],$further === null && !isset($junctions[$j][$end]))) break;
						$j = $further;
					} while($j !== null && $j !== $id && $j !== $neighbor);
					$operations[$id]['_road']['ink']['ends'][$end] = $segments;
				}
			}
		}
		$roles=self::stripeRoles($bearings,$keys);
		// Distinct arms of the same junction network can overlap on the ground.
		// Keep unrelated crossings and later brush paint in ordinary edit order.
		while($paths) {
			$root=array_key_first($paths); $stack=[$root];
			while($stack) {
				$arm=array_pop($stack);
				if(!isset($paths[$arm])) continue;
				$sections=$paths[$arm]; unset($paths[$arm]);
				foreach($sections as $id=>$_) {
					$ink=&$operations[$id]['_road']['ink'];
					$ink['network']=$root+1; $ink['arm']=$arm+1; $ink['stripeRole']=$roles[$arm];
					foreach($junctions[$id]??[] as $end=>$point) {
						$ink['forkCaps']=($ink['forkCaps']??0)|(1<<$end);
						foreach($bearings[$point]??[] as $neighbor=>$_) $stack[]=$neighbor;
						unset($bearings[$point]);
					}
					unset($ink);
				}
			}
		}
		return $operations;
	}

	/** Assign interpolation channels and lateral signs from the topology of the visible roads. */
	private static function stripeRoles(array $bearings,array $keys): array
	{
		asort($keys);
		$neighbors=$pairs=[];
		foreach($bearings as $legs) {
			$closest=0.; $siblings=null;
			foreach($legs as $a=>[$ax,$az,$ad]) foreach($legs as $b=>[$bx,$bz,$bd]) {
				if($a===$b) continue;
				$neighbors[$a][$b]=true;
				$dot=$ax*$bx+$az*$bz;
				if(count($legs)===3 && $dot>$closest) {
					$closest=$dot;
					$siblings=[$a,$b,-$ad*$bd];
				}
			}
			// Both sides of the median must have the same sign. The two arms
			// can then share an interpolant even while they are less than a grid cell apart.
			if($siblings!==null) {
				[$a,$b,$sign]=$siblings;
				$pairs[$a][$b]=$pairs[$b][$a]=$sign;
			}
		}
		$roles=[];
		foreach(array_keys($keys) as $root) {
			if(isset($roles[$root])) continue;
			$signs=[$root=>1]; $stack=[$root]; $usage=[0,0,0,0];
			while($stack) {
				$a=array_pop($stack);
				foreach($neighbors[$a]??[] as $b=>$_)
					if(isset($roles[$b])) $usage[$roles[$b]>>1]++;
				foreach($pairs[$a]??[] as $b=>$sign) if(!isset($signs[$b])) {
					$signs[$b]=$signs[$a]*$sign;
					$stack[]=$b;
				}
			}
			$channel=array_search(min($usage),$usage,true);
			foreach($signs as $a=>$sign) $roles[$a]=2*$channel+(int)($sign<0);
		}
		return $roles;
	}

	/** Snap the phase reversal to a real sample so every projected segment stays linear. */
	private static function middleSample(array $operations,array $offsets,float $middle): float
	{
		foreach($offsets as $id=>$offset) {
			$stations = $operations[$id]['_road']['stations'];
			$lo = 0; $hi = array_key_last($stations);
			$wanted = $middle-$offset;
			if($stations[$hi] < $wanted) continue;
			while($lo+1 < $hi) {
				$m = intdiv($lo+$hi,2);
				if($stations[$m] < $wanted) $lo = $m; else $hi = $m;
			}
			return $offset+($wanted-$stations[$lo] < $stations[$hi]-$wanted ? $stations[$lo] : $stations[$hi]);
		}
		return 0.;
	}

	/** Neighboring segments that can beat the shared endpoint as the nearest projection inside its cap. */
	private static function continuation(array &$segments, array $road, int $end, float $x, float $z, float $reach, bool $open): bool
	{
		$samples = $road['samples']; $last = count($samples)-1;
		for($i=$end ? 0 : $last-1; $i>=0 && $i<$last; $i+=$end ? 1 : -1) {
			[$ax,,$az] = $samples[$i];
			[$bx,,$bz] = $samples[$i+1];
			$start = self::station($road,$i);
			$cap = $open && $end && $i === $last-1 ? 2 : (int)($open && !$end && $i === 0);
			$segments[] = [$ax,$az,$bx-$ax,$bz-$az,$start,self::station($road,$i+1)-$start,$cap,
				$road['stations'][$i],$road['stations'][$i+1]-$road['stations'][$i]];
			if(hypot(($end ? $bx : $ax)-$x,($end ? $bz : $az)-$z) > $reach) return false;
		}
		return true;
	}

	/** Distance used to encode dash phase at a sample, including wraparound of a closed lap. */
	public static function station(array $road, int $i): float
	{
		$s = $road['stations'][$i];
		if(!isset($road['ink'])) return $s;
		$ink = $road['ink'];
		$s += $ink['offset'];
		$lap = $ink['lap']; $pivot = $ink['pivot'];
		if($lap <= 0.) return $s+$ink['origin'];
		if($pivot <= 0. || $pivot >= $lap) return self::DASH_METERS/2;
		return self::DASH_METERS/2 + ($s <= $pivot ? $s*$ink['rising'] : ($lap-$s)*$ink['falling']);
	}
}

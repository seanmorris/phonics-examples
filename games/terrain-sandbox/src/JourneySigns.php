<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{JoinedTerrain,Landscape};

/** Highway signs positioned by distance along the committed pavement in each journey landscape. */
final class JourneySigns
{
	public const MILE = 1000. * DisplayUnits::KM_PER_MILE;

	private static function node(array $point): string
	{
		// A square handoff clips and translates the same spline in two coordinate systems.
		return implode(',',array_map(static fn($v)=>(int)round($v*1000.),array_slice($point,0,3)));
	}

	/** Signed panels are scenery. Yield during long road scans so rebuilding a window can share the frame budget. */
	public static function layout(Landscape $land,bool $journeyStage=false): \Generator
	{
		$recipe=$land->recipe(); $join=$recipe['join'] ?? null;
		if(!$join && !$journeyStage) return [];
		$half=($join['span'] ?? $recipe['worldSize'])*.5;
		$squares=$join ? JoinedTerrain::origins($join['span'],count($join['destinations'])) : [[0.,0.]];
		$roads=$land->roads();
		$starts=$ends=$incoming=$outgoing=$inside=[];
		foreach($roads as $i=>$road) {
			$points=$road['points'];
			$a=$starts[$i]=self::node($points[0]);
			$b=$ends[$i]=self::node($points[array_key_last($points)]);
			$outgoing[$a][]=$i; $incoming[$b][]=$i;
			foreach($squares as $square=>[$cx,$cz])
				$inside[$i][$square]=yield from self::intervals($road['_road'],$cx,$cz,$half);
			yield;
		}

		$sites=[];
		// Start a run on each entrance and each outgoing arm. Shared arrivals can feed one
		// fork, but a fork ends the incoming run, so successive splits never multiply routes.
		foreach($starts as $root=>$a) {
			if(isset($incoming[$a]) && count($outgoing[$a])===1) continue;
			$run=$visits=$junctions=[]; $length=0.; $id=$root;
			while(!isset($run[$id])) {
				$road=$roads[$id]['_road'];
				$run[$id]=$length;
				foreach($inside[$id] as $square=>$intervals) foreach($intervals as [$from,$to])
					self::append($visits[$square],$length+$from,$length+$to);
				$length+=$road['stations'][array_key_last($road['stations'])];
				$b=$ends[$id]; $next=$outgoing[$b] ?? [];
				if(count($incoming[$b])>1 || count($next)>1) $junctions[]=$length;
				if(count($next)!==1) break;
				$id=$next[0];
				yield;
			}

			$at=static function(float $distance) use($run,$roads): array {
				foreach(array_reverse($run,true) as $i=>$start)
					if($distance >= $start) return self::pose($roads[$i],$distance-$start);
				return self::pose($roads[array_key_first($run)],0.);
			};
			if(count($next)>1) {
				$sites['fork:'.$b]=['kind'=>'fork']+$at($length);
				$pose=$at(max(0.,$length-self::MILE));
				$sites['exits:'.self::node($pose['position'])]=['kind'=>'exits']+$pose;
			}
			foreach($visits as $square=>$ranges) {
				[$cx,$cz]=$squares[$square];
				$first=$at($ranges[0][0])['position']; $last=$at(end($ranges)[1])['position'];
				// A highway must reach the east exit. Brief excursions into a neighboring landscape or
				// disconnected fragments of a hidden road do not get their own set of quarter marks.
				if(max(abs($first[0]-$cx),abs($first[2]-$cz))<$half-.1
					|| abs($last[0]-($cx+$half))>.1) continue;
				$total=0.;
				foreach($ranges as [$from,$to]) $total+=$to-$from;
				foreach([.25,.5,.75] as $fraction) {
					$offset=$total*$fraction;
					foreach($ranges as [$from,$to]) {
						if($offset<=$to-$from) break;
						$offset-=$to-$from;
					}
					$station=$from+$offset; $goal=end($ranges)[1]; $caption='stage';
					foreach($junctions as $junction) if($junction>$station) {
						$goal=$junction; $caption='junction'; break;
					}
					$pose=$at($station);
					$sites['mile:'.self::node($pose['position'])]=['kind'=>'mile','square'=>$square,
						'fraction'=>$fraction,'remaining'=>$goal-$station,'caption'=>$caption]+$pose;
				}
			}
			yield;
		}
		return array_values($sites);
	}

	private static function append(?array &$ranges,float $from,float $to): void
	{
		if($to-$from<1e-6) return;
		$last=$ranges ? array_key_last($ranges) : null;
		if($last!==null && abs($ranges[$last][1]-$from)<.001) $ranges[$last][1]=$to;
		else $ranges[]=[$from,$to];
	}

	/** Clip sampled centerline stations to the named square. Most road chunks lie wholly inside or outside. */
	private static function intervals(array $road,float $cx,float $cz,float $half): \Generator
	{
		[$west,$north,$east,$south]=$road['bounds'];
		$x0=$cx-$half; $x1=$cx+$half; $z0=$cz-$half; $z1=$cz+$half;
		if($east<$x0 || $west>$x1 || $south<$z0 || $north>$z1) return [];
		$stations=$road['stations'];
		if($west>=$x0 && $east<=$x1 && $north>=$z0 && $south<=$z1)
			return [[0.,$stations[array_key_last($stations)]]];
		$ranges=[]; $samples=$road['samples']; $last=count($stations)-1;
		$i=$checks=0;
		while($i<$last) {
			[$px,,$pz]=$samples[$i];
			$before=$stations[$i];
			$margin=min($px-$x0,$x1-$px,$pz-$z0,$z1-$pz);
			// Road distance bounds displacement along either axis. Skip as far as the nearest
			// possible boundary crossing, even on a winding or non-monotone spline.
			$next=$i+1;
			if($next<$last && $stations[$next+1]-$before<abs($margin))
				$next=self::atOrBefore($stations,$before+abs($margin),$next);
			if($next>$i+1) {
				if($margin>0.) self::append($ranges,$before,$stations[$next]);
			} else {
				[$x,,$z]=$samples[$next];
				$start=0.; $end=1.;
				if(min($px,$x)<=$x1 && max($px,$x)>=$x0 && min($pz,$z)<=$z1 && max($pz,$z)>=$z0) {
					if($x!=$px) {
						$a=($x0-$px)/($x-$px); $b=($x1-$px)/($x-$px);
						$start=max($start,min($a,$b)); $end=min($end,max($a,$b));
					}
					if($z!=$pz) {
						$a=($z0-$pz)/($z-$pz); $b=($z1-$pz)/($z-$pz);
						$start=max($start,min($a,$b)); $end=min($end,max($a,$b));
					}
					$step=$stations[$next]-$before;
					self::append($ranges,$before+$start*$step,$before+$end*$step);
				}
			}
			$i=$next;
			if(++$checks%64===0) yield;
		}
		return $ranges;
	}

	/** Last sample at or before an arc distance, including the endpoint. */
	private static function atOrBefore(array $stations,float $distance,int $lo=0): int
	{
		$hi=count($stations)-1;
		while($lo<$hi) {
			$mid=($lo+$hi+1)>>1;
			if($stations[$mid]<=$distance) $lo=$mid; else $hi=$mid-1;
		}
		return $lo;
	}

	private static function pose(array $road,float $distance): array
	{
		$stations=$road['_road']['stations']; $samples=$road['_road']['samples'];
		$lo=min(count($stations)-2,self::atOrBefore($stations,$distance)); $hi=$lo+1;
		$t=max(0.,min(1.,($distance-$stations[$lo])/max(1e-9,$stations[$hi]-$stations[$lo])));
		$a=$samples[$lo]; $b=$samples[$hi];
		$p=[]; for($i=0;$i<5;$i++) $p[]=$a[$i]+$t*($b[$i]-$a[$i]);
		$norm=max(1e-9,hypot($p[3],$p[4]));
		return ['position'=>array_slice($p,0,3),'heading'=>[$p[3]/$norm,$p[4]/$norm],'width'=>$road['width']];
	}

	public static function legend(float $meters): array
	{
		$miles=$meters/self::MILE;
		$value=$miles<10. ? round($miles,1) : round($miles);
		return [(string)$value,$value==1. ? 'MILE' : 'MILES'];
	}
}

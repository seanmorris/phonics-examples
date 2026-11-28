<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Shape only long, nearly constant headings, before the generator grades and fits the road splines. */
final class RoadSerpentine
{
	private const MILE = 1000. * DisplayUnits::KM_PER_MILE;
	private const MAX_STRAIGHT = 3. * self::MILE;
	private const HEADING_SPREAD = .08726646; // Five degrees across the entire run.

	/** Consecutive sections share their last knot. Fixed sections still count toward straight length. */
	public static function fit(array $sections,array $movable,callable $allows,array $ties=[]): \Generator
	{
		$line=[]; $starts=[];
		foreach($sections as $i=>$section) {
			$starts[$i]=max(0,count($line)-1);
			array_push($line,...($i===0 ? $section : array_slice($section,1)));
		}
		$stations=[0.]; $runs=[]; $start=0; $heading=null; $low=$high=0.;
		for($i=1;$i<count($line);$i++) {
			[$x,$z]=$line[$i]; [$a,$b]=$line[$i-1];
			$length=hypot($x-$a,$z-$b);
			$stations[$i]=$stations[$i-1]+$length;
			if($length<1.e-7) continue;
			$angle=atan2($z-$b,$x-$a);
			$heading ??= $angle;
			$delta=atan2(sin($angle-$heading),cos($angle-$heading));
			if(max($high,$delta)-min($low,$delta)>self::HEADING_SPREAD) {
				if($stations[$i-1]-$stations[$start]>self::MAX_STRAIGHT+1.e-6) $runs[]=[$start,$i-1];
				$start=$i-1; $heading=$angle; $low=$high=0.;
			} else { $low=min($low,$delta); $high=max($high,$delta); }
			if(($i&127)===0) yield;
		}
		$end=count($line)-1;
		if($end>0 && $stations[$end]-$stations[$start]>self::MAX_STRAIGHT+1.e-6) $runs[]=[$start,$end];

		foreach($movable as $section) {
			$first=$starts[$section]; $last=$first+count($sections[$section])-1;
			// Splice from the end so original survey indices and the ties between sections stay valid.
			foreach(array_reverse($runs) as [$a,$b]) {
				$a=max($first,$a); $b=min($last,$b);
				if($a>=$b) continue;
				$cuts=[$a];
				foreach($ties[$section] ?? [] as $k) if($first+$k>$a && $first+$k<$b) $cuts[]=$first+$k;
				$cuts[]=$b;
				sort($cuts,SORT_NUMERIC);
				for($n=count($cuts)-1;$n>0;$n--) {
					[$lo,$hi]=[$cuts[$n-1],$cuts[$n]];
					if($stations[$hi]-$stations[$lo]<.5*self::MILE) continue;
					$part=yield from self::weave($line,$stations,$lo,$hi,$allows,$section);
					array_splice($sections[$section],$lo-$first,$hi-$lo+1,$part);
				}
			}
		}
		return $sections;
	}

	/** Check both verges as well as the center of the road against the surveyed corridor. */
	public static function fitsGround(callable $height,float $halfWidth,float $dry,float $x,float $z,
		float $px,float $pz,float $nx,float $nz): bool
	{
		foreach([0.,-$halfWidth,$halfWidth] as $side) {
			$a=$height($x+$side*$nx,$z+$side*$nz);
			$b=$height($px+$side*$nx,$pz+$side*$nz);
			// Dry ground must stay dry. On a causeway, avoid a deeper crossing.
			if(abs($b-$a)>4. || ($a>=$dry ? $b<$dry : $b<$a-.05)) return false;
		}
		return true;
	}

	private static function weave(array $line,array $stations,int $a,int $b,callable $allows,int $section): \Generator
	{
		$length=$stations[$b]-$stations[$a];
		$lobes=2*max(1,(int)round($length/(2.*self::MILE)));
		$halfPeriod=$length/$lobes;
		$dx=$line[$b][0]-$line[$a][0]; $dz=$line[$b][1]-$line[$a][1];
		$chord=hypot($dx,$dz); $nx=-$dz/$chord; $nz=$dx/$chord;
		$bends=[]; $result=[$line[$a]];
		for($i=$a+1;$i<=$b;$i++) {
			if(($i&63)===0) yield;
			[$x,$z]=$line[$i-1]; [$u,$v]=$line[$i];
			$run=$stations[$i]-$stations[$i-1];
			$steps=max(1,(int)ceil($run/96.));
			for($j=1;$j<=$steps;$j++) {
				$knot=$j===$steps; $t=$j/$steps;
				$base=$knot ? $line[$i] : [$x+$t*($u-$x),$z+$t*($v-$z)];
				$along=($stations[$i-1]-$stations[$a]+$t*$run)/$halfPeriod;
				$lobe=min($lobes-1,(int)floor($along));
				$sine=sin(M_PI*($along-$lobe));
				// A cubed sine meets the original heading with zero added slope and curvature at each tie.
				$offset=$i===$b && $knot ? 0. : ($lobe%2 ? -1. : 1.)*.034*$halfPeriod*$sine**3;
				$bends[$lobe][]=[$base,$offset,$knot];
				if(($j&63)===0) yield;
			}
		}
		foreach($bends as $samples) {
			// Try both orientations, then a smaller excursion where the surveyed corridor is tight.
			$scale=0.;
			foreach([1.,-1.,.5,-.5,.25,-.25] as $trial) {
				foreach($samples as $j=>[[$x,$z],$offset]) {
					if(($j&7)===0) yield;
					$d=$trial*$offset;
					if(abs($d)>.001 && !$allows($x,$z,$x+$nx*$d,$z+$nz*$d,$nx,$nz,$section)) continue 2;
				}
				$scale=$trial; break;
			}
			foreach($samples as [$base,$offset,$knot]) {
				if(!$scale && !$knot) continue;
				$d=$scale*$offset;
				$result[]=$d==0. ? $base : [$base[0]+$nx*$d,$base[1]+$nz*$d];
			}
		}
		return $result;
	}
}

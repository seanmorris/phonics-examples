<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/EmbeddedPlanarGraph.php';

/** Fit connected unions of opposing rectangles inside fixed graph reservations. */
final class CompoundRooms
{
	/** Longest continuous contact shared by both unions along a graph boundary. */
	public static function contact(array $a,array $b,array $boundary): float
	{
		[$p,$q]=$boundary; $dx=$q[0]-$p[0]; $dy=$q[1]-$p[1]; $length=hypot($dx,$dy);
		$spans=[];
		foreach($a as $part) foreach($b as $other) {
			$edge=PolygonGeometry::shared($part,$other);
			if(!$edge || !($edge=PolygonGeometry::shared($edge,$boundary))) continue;
			$ts=array_map(static fn($v)=>(($v[0]-$p[0])*$dx+($v[1]-$p[1])*$dy)/$length,$edge);
			$spans[]=[min($ts),max($ts)];
		}
		sort($spans); $best=0; $lo=$hi=null;
		foreach($spans as [$start,$end]) {
			if($hi===null || $start>$hi+1e-6) { $lo=$start; $hi=$end; }
			else $hi=max($hi,$end);
			$best=max($best,$hi-$lo);
		}
		return $best;
	}

	/** Try nine bar arrangements; retain a simple rectangle when entrance spans cannot fit. */
	public static function fit(array $reservation,array $contacts,SeedSequence $random,int $count): array
	{
		if($count<1 || $count>3) throw new \InvalidArgumentException('Compound rooms use one to three rectangles');
		$base=['rectangles'=>[$reservation],'pieces'=>[$reservation]];
		if($count===1) return $base;
		$c=PolygonGeometry::cell([],$reservation);
		$x=(int)$c['x0']; $y=(int)$c['y0']; $w=(int)($c['x1']-$x); $h=(int)($c['y1']-$y);
		$bw=max(512,(int)ceil($w*2/3/64)*64); $bh=max(512,(int)ceil($h*2/3/64)*64);
		if($bw>=$w || $bh>=$h || $w<=$bh || $h<=$bw) return $base;
		$positions=[]; foreach([0,1,2] as $px) foreach([0,1,2] as $py) $positions[]=[$px,$py];
		foreach($random->shuffle($positions) as [$px,$py]) {
			$vx=$x+(int)floor(($w-$bw)*$px/2/64)*64;
			$hy=$y+(int)floor(($h-$bh)*$py/2/64)*64;
			$rectangles=[EmbeddedPlanarGraph::rectangle($x,$hy,$w,$bh),EmbeddedPlanarGraph::rectangle($vx,$y,$bw,$h)];
			if($count===3) {
				// Extend a bar into an exposed corner, leaving a substantial step.
				$left=$vx-$x; $right=$x+$w-$vx-$bw;
				$bottom=$hy-$y; $top=$y+$h-$hy-$bh;
				$rw=max($left,$right); $rh=max($bottom,$top);
				if($rw<128 || $rh<128) continue;
				$rx=$left>=$right ? $x : $vx+$bw-128;
				$ry=$bottom>=$top ? $y : $hy+$bh-128;
				$rectangles[]=EmbeddedPlanarGraph::rectangle($rx,$ry,$rw+128,$rh+128-64);
			}
			$pieces=PolygonGeometry::union($rectangles);
			if(count($pieces)<2) continue;
			foreach($contacts as $contact)
			if(self::contact($pieces,$contact['pieces'],$contact['boundary'])<384) continue 2;
			return compact('rectangles','pieces');
		}
		return $base;
	}
}

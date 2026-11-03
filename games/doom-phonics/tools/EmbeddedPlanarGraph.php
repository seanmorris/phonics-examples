<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/PolygonGeometry.php';
require_once __DIR__.'/SeedSequence.php';

/** Grow an embedded planar adjacency graph before fitting room outlines.
 * Node reservations prevent crossing edges; independent attachment offsets avoid row/column constraints.
 */
final class EmbeddedPlanarGraph
{
	/** Clockwise rectangle, also used for cuts and reservations. */
	public static function rectangle(int $x,int $y,int $w,int $h): array
	{
		return [[$x,$y],[$x,$y+$h],[$x+$w,$y+$h],[$x+$w,$y]];
	}

	/** Distance to an anchor's boundary, zero within the anchor. */
	public static function distance(array $p,array $anchors): float
	{
		$d=INF;
		foreach($anchors as $polygon) {
			if(PolygonGeometry::contains($polygon,...$p)) return 0;
			foreach($polygon as $i=>$a) {
				$b=$polygon[($i+1)%count($polygon)]; $dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $l=$dx*$dx+$dy*$dy;
				$t=max(0,min(1,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$l));
				$d=min($d,hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy));
			}
		}
		return $d;
	}

	/** A monotonic size rule; variation in aspect ratio never reverses the distance tiers. */
	public static function size(float $distance): int
	{
		return max(384,896-64*(int)floor(max(0,$distance)/256));
	}

	public static function build(string $seed,string $plane,int $budget,int $specialSize): array
	{
		$r=new SeedSequence("organic-v13:$seed:$plane");
		$root=self::rectangle(0,0,$specialSize,$specialSize);
		$partitions=[$root]; $owners=[0]; $anchors=[$root];
		$anchorCount=min(3,max(1,(int)ceil($budget/6)));
		$history=[]; $candidates=[0=>['role'=>'special','tier'=>'anchor','distance'=>0,'size'=>$specialSize,'center'=>[$specialSize/2,$specialSize/2]]];
		for($id=1;$id<$budget;$id++) {
			$isAnchor=$id<$anchorCount; $accepted=false; $best=null;
			for($attempt=0;$attempt<96;$attempt++) {
				$base=$id===1 ? 0 : $r->integer(1,count($partitions)-1);
				$p=$partitions[$base]; $c=PolygonGeometry::cell([],$p);
				$side=$base===0 ? 0 : $r->integer(0,3);
				$distance=self::distance($c['center'],$anchors);
				$size=$isAnchor ? 1024+128*$r->integer(0,4) : self::size($distance);
				if($plane==='ground' && $isAnchor && $id===1) $size=max(1152,$size);
				$w=$size+128; $h=$size+128;
				$offset=64*$r->integer(-2,2);
				$x=(int)(round(($c['center'][0]-$w/2)/64)*64); $y=(int)(round(($c['center'][1]-$h/2)/64)*64);
				match($side) {0=>$x=(int)$c['x1'],1=>$y=(int)$c['y1'],2=>$x=(int)$c['x0']-$w,3=>$y=(int)$c['y0']-$h};
				if($side%2===0) $y+=$offset; else $x+=$offset;
				// Leave the special building and its inter-storey approaches untouched.
				if($x<$specialSize || max(abs($x+$w),abs($y+$h))>12000) continue;
				if(!$isAnchor) for($fit=0;$fit<8;$fit++) {
					$distance=self::distance([$x+$w/2,$y+$h/2],$anchors); $target=self::size($distance);
					if($target===$size) break;
					// Converge from the larger bound, avoiding a tier-boundary oscillation.
					$size=min($size,$target); $w=$h=$size+128;
					$x=(int)(round(($c['center'][0]-$w/2)/64)*64); $y=(int)(round(($c['center'][1]-$h/2)/64)*64);
					match($side) {0=>$x=(int)$c['x1'],1=>$y=(int)$c['y1'],2=>$x=(int)$c['x0']-$w,3=>$y=(int)$c['y0']-$h};
					if($side%2===0) $y+=$offset; else $x+=$offset;
				}
				if($x<$specialSize) continue;
				$polygon=self::rectangle($x,$y,$w,$h);
				foreach($partitions as $q) if(PolygonGeometry::overlaps($polygon,$q)) continue 2;
				$edge=PolygonGeometry::shared($p,$polygon);
				if(!$edge || hypot($edge[1][0]-$edge[0][0],$edge[1][1]-$edge[0][1])<384) continue;
				if($base===0 && !($edge[0][1]<= $specialSize/2 && $edge[1][1]>= $specialSize/2)) {
					$lo=min($edge[0][1],$edge[1][1]); $hi=max($edge[0][1],$edge[1][1]);
					if($lo>$specialSize/2-128 || $hi<$specialSize/2+128) continue;
				}
				$center=PolygonGeometry::cell([],$polygon)['center'];
				if($isAnchor) foreach($anchors as $anchor) {
					$ac=PolygonGeometry::cell([],$anchor)['center'];
					if($center[0]===$ac[0] || $center[1]===$ac[1]) continue 2;
				}
				$contacts=0;
				foreach($partitions as $q) { $contact=PolygonGeometry::shared($polygon,$q); if($contact && hypot($contact[1][0]-$contact[0][0],$contact[1][1]-$contact[0][1])>=384) $contacts++; }
				$score=$contacts*1024-$distance/8+$r->integer(0,128);
				if($best===null || $score>$best['score']) $best=compact('polygon','center','distance','size','score');
			}
			if($best!==null) {
				['polygon'=>$polygon,'center'=>$center,'distance'=>$distance,'size'=>$size]=$best;
				$partitions[]=$polygon; $owners[]=$id;
				if($isAnchor) $anchors[]=$polygon;
				$candidates[$id]=['role'=>'room','tier'=>$isAnchor ? 'anchor' : 'surround','distance'=>$distance,'size'=>$size,'center'=>$center];
				$accepted=true;
			}
			if(!$accepted) $history[]=['kind'=>'placement','candidate'=>$id,'reason'=>'Bounded candidate attempts exhausted; keep the connected arrangement'];
		}
		$edges=[];
		foreach($partitions as $i=>$a) foreach($partitions as $j=>$b) {
			if($j<=$i) continue;
			$boundary=PolygonGeometry::shared($a,$b);
			if(!$boundary || hypot($boundary[1][0]-$boundary[0][0],$boundary[1][1]-$boundary[0][1])<384) continue;
			$edges[]=['id'=>"edge-$i-$j",'from'=>$owners[$i],'to'=>$owners[$j],'boundary'=>$boundary];
		}
		return ['nodes'=>$candidates,'reservations'=>$partitions,'owners'=>$owners,'edges'=>$edges,'anchors'=>$anchors,'history'=>$history,'requested'=>$budget];
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/EmbeddedPlanarGraph.php';

/** Broad upper room terrace with two stair flights recessed into its perimeter. */
final class RaisedLedge
{
	/** Try four sides and two rises, keeping existing entrances on the lower floor. */
	public static function carve(array &$layer,string $name,string $seed): bool
	{
		if(($layer['role']??'room')!=='room' || isset($layer['elevation']) || isset($layer['gallery']) || isset($layer['feature']) || isset($layer['cluster'])) return false;
		foreach($layer['cells'] as $cell) if(isset($cell['verticalPortal'])) return false;
		$core=$layer['cells'][0]; $shell=PolygonGeometry::points($core);
		$width=$core['x1']-$core['x0']; $height=$core['y1']-$core['y0'];
		if(min($width,$height)<896) return false;
		$random=new SeedSequence("ledge:$seed:$name");
		foreach($random->shuffle([0,1,2,3]) as $turn) foreach([128,96] as $rise) foreach([0,128,192,256] as $margin) {
			$w=$turn%2 ? $height : $width; $h=$turn%2 ? $width : $height;
			$run=intdiv($rise,32)*64; $right=$w-$margin; $top=$h-$margin; $start=$top-256-$run;
			if($start<384 || $right-256-$run-256<128) continue;
			$transform=static function(array $p) use ($turn,$width,$height,$core): array {
				return array_map(static function($v) use ($turn,$width,$height,$core) {
					[$x,$y]=match($turn) {0=>$v,1=>[$width-$v[1],$v[0]],2=>[$width-$v[0],$height-$v[1]],3=>[$v[1],$height-$v[0]]};
					return [$x+$core['x0'],$y+$core['y0']];
				},$p);
			};
			$rect=static fn($x,$y,$w,$h)=>$transform(EmbeddedPlanarGraph::rectangle((int)$x,(int)$y,(int)$w,(int)$h));
			// Where doors occupy every side, retain a 128-unit perimeter route
			// behind the broad terrace instead of obstructing those lower entrances.
			$deck=PolygonGeometry::intersect($shell,$rect($margin,$start,$w-2*$margin,$top-$start));
			if(!$deck) continue;
			if($margin) foreach($deck as $vertex) if(!PolygonGeometry::contains($shell,...[...$vertex,128])) continue 2;
			// A raised terrace must not silently turn a lower doorway into a drop.
			foreach($layer['cells'] as $cell) {
				if($cell['id']===$core['id'] || isset($cell['room'])) continue;
				if(PolygonGeometry::shared($deck,PolygonGeometry::points($cell))) continue 2;
			}
			$roof=max($core['ceiling'],$core['floor']+$rise+192);
			if(($layer['plane']??'')==='basement' && $roof>$core['ceiling']) continue;
			$steps=intdiv($rise,32); $route=[];
			$route[]=['lower-landing',$rect($right-192,$start-256,192,256),0];
			for($s=1;$s<=$steps;$s++) $route[]=["up-$s",$rect($right-192,$start+($s-1)*64,192,64),$s*16];
			$route[]=['turn',$rect($right-256,$start+$run,256,256),intdiv($rise,2)];
			for($s=1;$s<=$steps;$s++) $route[]=["across-$s",$rect($right-256-$s*64,$top-192,64,192),intdiv($rise,2)+$s*16];
			$route[]=['top-landing',$rect($right-256-$run-256,$top-192,256,192),$rise];
			foreach($route as [$part,$polygon]) foreach($polygon as $vertex)
			if(!PolygonGeometry::contains($shell,...$vertex) || ($part!=='lower-landing' && !PolygonGeometry::contains($deck,...$vertex))) continue 3;
			$low=PolygonGeometry::surround($shell,$deck); $high=[$deck];
			foreach($route as [,$polygon]) foreach(['low','high'] as $which) {
				$next=[];
				foreach($$which as $piece) {
					if(!PolygonGeometry::overlaps($piece,$polygon)) { $next[]=$piece; continue; }
					array_push($next,...PolygonGeometry::surround($piece,$polygon));
				}
				$$which=$next;
			}
			$cells=[]; $fields=array_replace($core,['ceiling'=>$roof]);
			foreach(PolygonGeometry::mergeConvex($low) as $i=>$polygon) $cells[]=PolygonGeometry::cell(array_replace($fields,['id'=>$i ? "$name.ledge-low-$i" : $core['id']]),$polygon);
			foreach(PolygonGeometry::mergeConvex($high) as $i=>$polygon) $cells[]=PolygonGeometry::cell(array_replace($fields,['id'=>"$name.ledge-$i",'floor'=>$core['floor']+$rise]),$polygon);
			$ids=[];
			foreach($route as [$part,$polygon,$z]) {
				$id="$name.ledge-stair.$part"; $ids[]=$id;
				$cells[]=PolygonGeometry::cell(array_replace($fields,['id'=>$id,'floor'=>$core['floor']+$z,'role'=>'ledge-stair']),$polygon);
			}
			$layer['cells']=array_merge($cells,array_slice($layer['cells'],1)); $layer['ceiling']=$roof;
			$layer['terrace']=['rise'=>$rise,'width'=>192,'landing'=>256,'turnLanding'=>256,'rotation'=>$turn*90,'deck'=>$deck,'route'=>$ids];
			$layer['elevation']=['kind'=>'ledge-stairs','minimumLanding'=>256,'route'=>$ids];
			return true;
		}
		return false;
	}
}

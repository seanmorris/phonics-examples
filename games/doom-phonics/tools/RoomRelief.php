<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';

/**
 * Give one room several navigable elevations without treating it as a clique.
 *
 * A lowered trench or rounded bowl is surrounded by connected high ledges.
 * Three sixteen-unit treads provide a deliberate return route; the remaining
 * sixty-four-unit edges are drops. Runtime collision derives their directions.
 */
final class RoomRelief
{
	/** Carve an ordinary room, retaining every existing entrance and its floor height. */
	public static function carve(array &$layer,string $name,string $kind): bool
	{
		if(!in_array($kind,['trench','bowl'],true)) throw new \InvalidArgumentException('Unknown room relief');
		$old=$layer['cells'][0]; $shell=PolygonGeometry::points($old);
		if(count($shell)!==4 || isset($layer['footprint']['wallSlope'])) return false;
		$cx=round(($old['x0']+$old['x1'])/32)*16; $cy=round(($old['y0']+$old['y1'])/32)*16;
		if(!PolygonGeometry::contains($shell,$cx,$cy,288)) return false;
		if($kind==='bowl') {
			$cut=(int)(floor(min($old['x1']-$old['x0'],$old['y1']-$old['y0'])/64)*16);
			foreach([[1,1,$old['x1']+$old['y1']],[-1,1,-$old['x0']+$old['y1']],
				[-1,-1,-$old['x0']-$old['y0']],[1,-1,$old['x1']-$old['y0']]] as [$nx,$ny,$d])
			$shell=PolygonGeometry::clip($shell,$nx,$ny,$d-$cut);
			// Outer rounding must not trim the already-built doorway or stair cap.
			foreach(array_slice($layer['cells'],1) as $cell) {
				$edge=PolygonGeometry::shared(PolygonGeometry::points($old),PolygonGeometry::points($cell));
				if(!$edge) continue;
				$kept=PolygonGeometry::shared($shell,PolygonGeometry::points($cell));
				if(!$kept || abs(hypot($edge[1][0]-$edge[0][0],$edge[1][1]-$edge[0][1])-hypot($kept[1][0]-$kept[0][0],$kept[1][1]-$kept[0][1]))>1e-6) return false;
			}
			$opening=[[-112,-224],[-224,-112],[-224,112],[-112,224],[112,224],[224,112],[224,-112],[112,-224]];
		} else $opening=[[-256,-128],[-256,64],[256,128],[256,-64]];
		$opening=array_map(static fn($p)=>[$p[0]+$cx,$p[1]+$cy],$opening);
		$pieces=PolygonGeometry::mergeConvex(PolygonGeometry::surround($shell,$opening));
		$cells=$ledges=[];
		foreach($pieces as $i=>$p) {
			$id=$i ? "$name.ledge-$i" : "$name.core"; $ledges[]=$id;
			$cells[]=PolygonGeometry::cell(array_replace($old,['id'=>$id]),$p);
		}
		$x=min(array_column($opening,0));
		$steps=[];
		for($i=0;$i<4;$i++) {
			$p=PolygonGeometry::clip($opening,-1,0,-($x+$i*64));
			if($i<3) $p=PolygonGeometry::clip($p,1,0,$x+($i+1)*64);
			$id=$i<3 ? "$name.relief-step-$i" : "$name.$kind";
			$cells[]=PolygonGeometry::cell(array_replace($old,['id'=>$id,'floor'=>$old['floor']-16*($i+1),
				'floorTexture'=>$i<3 ? 'FLOOR0_1' : 'FLOOR4_8','light'=>$i<3 ? 192 : 160]),$p);
			$steps[]=$id;
		}
		$layer['cells']=array_merge($cells,array_slice($layer['cells'],1));
		$layer['footprint']['polygon']=$shell;
		$layer['terrain']=['kind'=>$kind,'depth'=>64,'ledges'=>$ledges,'steps'=>$steps,'low'=>"$name.$kind",'center'=>[$cx,$cy]];
		return true;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';

/**
 * Carve a vertical portal and its walkable surround from a convex room core.
 *
 * The floor side is recessed into the slab. The matching ceiling aperture keeps
 * a clear landing below it; both sides retain the same shape and dimensions.
 * Rooms remain subdivided traversal regions, so crossing a hole is never
 * mistaken for walking across an uninterrupted floor.
 */
final class PlanePortalSite
{
	/** Reserve a square aperture with ample space to walk around all four sides. */
	public static function carve(array &$layer,string $room,bool $floor): string
	{
		$index=array_search($room.'.core',array_column($layer['cells'],'id'),true);
		if($index===false) throw new \InvalidArgumentException('Portal site needs an ordinary room core');
		$core=$layer['cells'][$index]; $shell=PolygonGeometry::points($core);
		$x=round(($core['x0']+$core['x1'])/16)*8; $y=round(($core['y0']+$core['y1'])/16)*8;
		$opening=[[$x-96,$y-96],[$x-96,$y+96],[$x+96,$y+96],[$x+96,$y-96]];
		foreach($opening as [$px,$py])
		if(!PolygonGeometry::contains($shell,$px,$py,128)) throw new \InvalidArgumentException('Portal site needs a 128-unit walkway around its aperture: '.$room);
		$id=$room.($floor ? '.shaft' : '.arrival');
		$pieces=PolygonGeometry::surround($shell,$opening);
		$layer['cells'][$index]=PolygonGeometry::cell($core,array_shift($pieces));
		foreach($pieces as $i=>$polygon)
		$layer['cells'][]=PolygonGeometry::cell(array_replace($core,['id'=>"$id.rim-$i"]),$polygon);
		$aperture=array_replace($core,['id'=>$id,'verticalPortal'=>$floor ? 'floor' : 'ceiling']);
		if($floor) $aperture['floor']-=32;
		$layer['cells'][]=PolygonGeometry::cell($aperture,$opening);
		if($floor) self::escapeRim($layer,$id);
		return $id;
	}

	/** Split a recessed opening's 32-unit lip into two traversable 16-unit rises. */
	public static function escapeRim(array &$layer,string $id): void
	{
		$aperture=$layer['cells'][array_search($id,array_column($layer['cells'],'id'),true)];
		$x0=$aperture['x0']-64; $y0=$aperture['y0']-64; $x1=$aperture['x1']+64; $y1=$aperture['y1']+64;
		$outer=[[$x0,$y0],[$x0,$y1],[$x1,$y1],[$x1,$y0]]; $cells=[]; $steps=[];
		foreach($layer['cells'] as $cell) {
			$p=PolygonGeometry::points($cell);
			$cut=$cell['id']===$id || $cell['floor']!==$aperture['floor']+32 ? [] : PolygonGeometry::intersect($p,$outer);
			if(!$cut) { $cells[]=$cell; continue; }
			$outside=PolygonGeometry::surround($p,$outer);
			foreach($outside as $i=>$q) $cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$cell['id'].($i ? ".escape-rim-$i" : '')]),$q);
			$stepId=$outside ? $cell['id'].'.escape-step' : $cell['id'];
			$cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$stepId,'floor'=>$aperture['floor']+16]),$cut);
			$steps[]=$stepId;
		}
		$layer['cells']=$cells;
		$layer['portalEscapes'][]=['aperture'=>$id,'steps'=>$steps,'rise'=>16,'treadDepth'=>64,'minimumWidth'=>min($aperture['x1']-$aperture['x0'],$aperture['y1']-$aperture['y0'])];
	}
}

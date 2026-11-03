<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/SpiralStaircase.php';

/**
 * Put a quarter-turn stair in a room corner and continue its top as a long ledge.
 *
 * The lower floor runs straight into the first landing. Every tread turns toward
 * the ledge; the top landing faces along it. Unoccupied space inside the turn
 * remains part of the lower room, while the exterior wall follows the curved
 * stairs. A supplied entrance plan connects the upper ledge to an exit, while
 * the opposite entrance stays below. Standalone callers can keep both at floor
 * level when authoring a ledge without a doorway.
 */
final class CornerStaircase
{
	/** Carve a free corner of a rectangular room, avoiding entrances and reserved features. */
	public static function carve(array &$layer,string $name,?array $connection=null): bool
	{
		if(isset($layer['cluster']) || isset($layer['lift']) || isset($layer['elevation']) || isset($layer['gallery'])
			|| ($layer['joinCandidate']??false) || isset($layer['footprint']['wallSlope'])) return false;
		foreach($layer['cells'] as $c) if(isset($c['verticalPortal'])) return false;
		$core=$layer['cells'][0]; $shell=PolygonGeometry::points($core);
		if(count($shell)!==4 || min($core['x1']-$core['x0'],$core['y1']-$core['y0'])<768) return false;
		$turn=$connection['rotation']??null;
		if($turn===null)
		foreach([['north','east'],['west','north'],['south','west'],['east','south']] as $r=>$sides)
		if(!isset($layer['anchors'][$sides[0]]) && !isset($layer['anchors'][$sides[1]])) { $turn=$r; break; }
		if($turn===null) return false;
		$w=$turn%2 ? $core['y1']-$core['y0'] : $core['x1']-$core['x0'];
		$h=$turn%2 ? $core['x1']-$core['x0'] : $core['y1']-$core['y0'];
		$stair=SpiralStaircase::build(steps:$connection['count']??4,width:160,headroom:192);
		$landing=$stair['landing']; $size=$stair['size']; $inner=$stair['inner']; $outer=$stair['outer']; $ledgeWidth=$outer-$inner;
		$dx=$w-$size; $dy=$h-$size; $floor=$core['floor']; $roof=max($core['ceiling'],$floor+$stair['height']+192);
		$fields=array_replace($core,['ceiling'=>$roof]); $cells=[];
		$rect=static fn($x0,$y0,$x1,$y1)=>[[$x0,$y0],[$x0,$y1],[$x1,$y1],[$x1,$y0]];
		foreach([
			['core',0,0,$w,$dy,0],['corner-floor',0,$dy,$dx,$h-$ledgeWidth,0],
			['ledge',0,$h-$ledgeWidth,$dx,$h,$stair['height']],
			['corner-entry',$dx,$dy,$dx+$landing+$inner,$dy+$landing,0],
			['corner-inside',$dx,$dy+$landing,$dx+$landing,$dy+$landing+$inner,0]
		] as [$id,$x0,$y0,$x1,$y1,$z])
		$cells[]=PolygonGeometry::cell(array_replace($fields,['id'=>"$name.$id",'floor'=>$floor+$z]),$rect($x0,$y0,$x1,$y1));
		$route=[];
		foreach($stair['cells'] as $c) {
			$id="$name.curve.".$c['id']; $route[]=$id;
			$cells[]=PolygonGeometry::cell(array_replace($fields,['id'=>$id,'floor'=>$floor+$c['floor']]),
				array_map(static fn($v)=>[$v[0]+$dx,$v[1]+$dy],$c['polygon']));
			if(!str_starts_with($c['id'],'step-')) continue;
			// Fill the inside with low floor; these seams are not walls or passages.
			$cells[]=PolygonGeometry::cell(array_replace($fields,['id'=>"$id.inside"]),
				[[$dx+$landing,$dy+$landing],[$c['polygon'][1][0]+$dx,$c['polygon'][1][1]+$dy],[$c['polygon'][0][0]+$dx,$c['polygon'][0][1]+$dy]]);
		}
		foreach($cells as &$c) {
			$p=array_map(static function($v) use($turn,$w,$h,$core) {
				[$x,$y]=match($turn) {0=>$v,1=>[$h-$v[1],$v[0]],2=>[$w-$v[0],$h-$v[1]],3=>[$v[1],$w-$v[0]]};
				return [$x+$core['x0'],$y+$core['y0']];
			},$c['polygon']); $c=PolygonGeometry::cell($c,$p);
		}
		unset($c);
		$layer['cells']=array_merge($cells,array_slice($layer['cells'],1));
		$layer['ceiling']=$roof;
		$layer['cornerStair']=['sweep'=>90,'height'=>$stair['height'],'width'=>$stair['width'],'inner'=>$inner,
			'rotation'=>$turn*90,'route'=>$route,'ledge'=>"$name.ledge",'low'=>"$name.core",'steps'=>$stair['steps']]
			+ ($connection ? ['exit'=>$connection['exit']] : []);
		return true;
	}

	/** Fit a quarter-turn ledge against the higher of two opposed entrances. */
	public static function connection(array $footprint,array $dimensions,array $elevation): ?array
	{
		if(count($elevation['floors'])!==2 || $elevation['rise']>64) return null;
		$p=$footprint['polygon']; $x0=min(array_column($p,0)); $y0=min(array_column($p,1));
		$w=max(array_column($p,0))-$x0; $h=max(array_column($p,1))-$y0;
		if(min($w,$h)<768) return null;
		$exit=array_search(max($elevation['floors']),$elevation['floors'],true);
		$rotation=match($exit) {'north'=>0,'west'=>1,'south'=>2,'east'=>3};
		$axis=$rotation%2; $span=$axis ? $h : $w;
		$center=$dimensions[$axis]/2-($axis ? $y0 : $x0);
		if($rotation>=2) $center=$span-$center;
		$stair=SpiralStaircase::build(steps:$elevation['count']);
		// The entire entrance, including player clearance, must meet flat ledge.
		if($center-80<0 || $center+80>$span-$stair['size']+128) return null;
		return compact('rotation','exit')+['kind'=>'corner'];
	}
}

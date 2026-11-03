<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';

/**
 * Build a continuously turning stair from radial, integer-coordinate treads.
 *
 * The sweep is independent of the climb. A quarter turn can lead onto a room's
 * perimeter ledge; a half turn makes a separate stair hall. The inner radius
 * clips away the common origin, avoiding pinched wedge tips on the walking
 * route. Radius grows when necessary to preserve usable inner tread depth.
 */
final class SpiralStaircase
{

	/** Return a curved stair hall with level entrance/exit landings and matching frames. */
	public static function build(int $steps=12,int $rise=16,int $width=160,int $sweep=90,int $innerRadius=0,bool $mirror=false,int $headroom=192,int $treadDepth=48): array
	{
		if($steps<2 || $steps>64 || $rise<1 || $rise>24 || $width<128 || !in_array($sweep,[90,180,270],true)
			|| $innerRadius<0 || $headroom<56 || $treadDepth<40)
		throw new \InvalidArgumentException('Curved stairs need usable tread depth, width and headroom');
		$angle=deg2rad($sweep)/$steps;
		$inner=(int)(ceil(max($innerRadius,$sweep===270 ? 256 : 0,($treadDepth+4)/(2*sin($angle/2)))/16)*16);
		// The chord between radial faces is slightly narrower than its radius difference.
		$outer=$inner+(int)(ceil(($width+4)/cos($angle/2)/8)*8);
		$height=$steps*$rise; $landing=256;
		$fields=['ceiling'=>$height+$headroom,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5','light'=>208,'tag'=>0];
		$innerPoints=$outerPoints=[];
		for($i=0;$i<=$steps;$i++) {
			$t=$angle*$i;
			$innerPoints[]=[(int)round($inner*cos($t)),(int)round($inner*sin($t))];
			$outerPoints[]=[(int)round($outer*cos($t)),(int)round($outer*sin($t))];
		}
		$cells=[PolygonGeometry::cell(['id'=>'landing-in','floor'=>0]+$fields,
			[[$inner,-$landing],[$inner,0],[$outer,0],[$outer,-$landing]])];
		foreach(range(0,$steps-1) as $i)
		$cells[]=PolygonGeometry::cell(['id'=>'step-'.$i,'floor'=>($i+1)*$rise]+$fields,
			[$innerPoints[$i],$innerPoints[$i+1],$outerPoints[$i+1],$outerPoints[$i]]);
		[$dx,$dy]=match($sweep) {90=>[-1,0],180=>[0,-1],270=>[1,0]};
		$a=end($innerPoints); $b=end($outerPoints);
		$cells[]=PolygonGeometry::cell(['id'=>'landing-out','floor'=>$height]+$fields,
			[$a,[$a[0]+$dx*$landing,$a[1]+$dy*$landing],[$b[0]+$dx*$landing,$b[1]+$dy*$landing],$b]);
		$anchors=[
			'in'=>['point'=>[($inner+$outer)/2,-$landing,0],'normal'=>[0,-1,0],'size'=>[$width,$headroom]],
			'out'=>['point'=>[($a[0]+$b[0])/2+$dx*$landing,($a[1]+$b[1])/2+$dy*$landing,$height],'normal'=>[$dx,$dy,0],'size'=>[$width,$headroom]]
		];
		// Portal landings have the same aperture height as the adjoining room.
		$cells[0]['ceiling']=$headroom;
		$ox=-min(array_column($cells,'x0')); $oy=-min(array_column($cells,'y0'));
		$size=max(max(array_column($cells,'x1'))+$ox,max(array_column($cells,'y1'))+$oy);
		foreach($cells as &$cell) {
			$p=array_map(static fn($v)=>[$v[0]+$ox,$v[1]+$oy],$cell['polygon']);
			if($mirror) $p=array_reverse(array_map(static fn($v)=>[$size-$v[0],$v[1]],$p));
			$cell=PolygonGeometry::cell($cell,$p);
		}
		unset($cell);
		foreach($anchors as &$anchor) {
			$anchor['point'][0]=(int)($anchor['point'][0]+$ox); $anchor['point'][1]=(int)($anchor['point'][1]+$oy);
			if($mirror) { $anchor['point'][0]=$size-$anchor['point'][0]; $anchor['normal'][0]*=-1; }
		}
		unset($anchor);
		return compact('cells','anchors','size','height','inner','outer','width','rise','steps','mirror','sweep','treadDepth','landing');
	}
}

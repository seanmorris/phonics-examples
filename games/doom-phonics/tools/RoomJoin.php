<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/RoomOutline.php';

/**
 * Remove a short connector between compatible rectangular room candidates.
 *
 * The joining band spans their overlapping faces, preserving offsets that make
 * an L or stepped outline. It does not fill the pair's bounding rectangle.
 * Size, height and clearance limits keep a join from creating a giant room or
 * swallowing an unrelated approach. Both authored halves retain their IDs.
 */
final class RoomJoin
{
	public const MAX_HALL = 256;
	public const MAX_AREA = 1835008;

	/** Bring compatible rectangular candidates closer inside their reserved bays. */
	public static function approach(array &$footprints,array &$specs,array $pins,array $dimensions,array &$passages): void
	{
		$claimed=[];
		foreach($pins as [$a,$aa,$b,$ba]) {
			if(!isset($specs[$a],$specs[$b]) || isset($claimed[$a]) || isset($claimed[$b])) continue;
			if(str_ends_with($a,'.0') || str_ends_with($b,'.0') || $specs[$a]['plane']!==$specs[$b]['plane']
				|| $specs[$a]['floor']!==$specs[$b]['floor']) continue;
			foreach([$a,$b] as $n) if(isset($specs[$n]['liftTag']) || isset($specs[$n]['gallery']) || isset($footprints[$n]['wallSlope'])) continue 2;
			$axis=in_array($aa,['east','west'],true) ? 0 : 1;
			$sign=in_array($aa,['east','north'],true) ? 1 : -1;
			$shifts=[]; $gap=0;
			foreach([[$a,$aa,$sign],[$b,$ba,-$sign]] as [$n,$port,$d]) {
				$p=$footprints[$n]['polygon']; $lo=min(array_column($p,$axis)); $hi=max(array_column($p,$axis));
				$span=$dimensions[$n][$axis]; $margin=$d>0 ? $span-$hi : $lo; $gap+=$margin;
				$clear=128;
				$maxShift=$d>0 ? $span/2-$clear-$lo : $hi-$span/2-$clear;
				$shifts[$n]=min(max(0,$maxShift),max(0,$margin-128));
			}
			if($gap-array_sum($shifts)>self::MAX_HALL) continue;
			foreach([[$a,$sign],[$b,-$sign]] as [$n,$d]) {
				foreach($footprints[$n]['polygon'] as &$v) $v[$axis]+=$d*$shifts[$n]; unset($v);
				$p=$footprints[$n]['polygon']; [$w,$h]=$dimensions[$n];
				$footprints[$n]['approaches']=['west'=>min(array_column($p,0)),'east'=>$w-max(array_column($p,0)),
					'south'=>min(array_column($p,1)),'north'=>$h-max(array_column($p,1))];
				$specs[$n]['joinCandidate']=true; $claimed[$n]=true;
				foreach($passages[$n] as &$passage) $passage['bend']=0; unset($passage);
			}
		}
	}

	/** Join disjoint eligible pairs, preferring nonrectangular unions and keeping a report. */
	public static function apply(array &$layers,array $pins,string $seed): array
	{
		$joins=$claimed=[];
		foreach($pins as [$a,$aa,$b,$ba]) {
			if(!isset($layers[$a],$layers[$b]) || isset($claimed[$a]) || isset($claimed[$b])) continue;
			$left=$layers[$a]; $right=$layers[$b];
			if($left['plane']!==$right['plane'] || $left['localFloor']!==$right['localFloor'] || $left['ceiling']!==$right['ceiling']) continue;
			foreach([$left,$right] as $layer) {
				if(isset($layer['cluster']) || isset($layer['lift']) || isset($layer['gallery']) || isset($layer['elevation'])
					|| isset($layer['cornerStair']) || isset($layer['footprint']['wallSlope']) || !str_ends_with($layer['cells'][0]['id'],'.core')) continue 2;
				foreach($layer['cells'] as $c) if(isset($c['verticalPortal'])) continue 3;
			}
			$ap=$left['anchors'][$aa]['point']; $bp=$right['anchors'][$ba]['point'];
			$offset=[$ap[0]-$bp[0],$ap[1]-$bp[1]];
			$one=$left['cells'][0]; $two=PolygonGeometry::translate($right['cells'][0],... [...$offset,0]);
			$candidate=self::candidate($one,$two,$aa);
			if(!$candidate) continue;
			$chance=$candidate['rectangular'] ? 20 : 100;
			if((new SeedSequence('short-join:'.$seed.':'.$a.':'.$b))->integer(0,99)>=$chance) continue;
			[$nx,$ny]=$left['anchors'][$aa]['normal'];
			$cut=$nx ? $ap[0] : $ap[1]; $sign=$nx ?: $ny;
			foreach([[$a,$aa,0,0,-1],[$b,$ba,$offset[0],$offset[1],1]] as [$name,$port,$ox,$oy,$side]) {
				$layer=&$layers[$name];
				$old=$layer['corridors'][$port]['cells'];
				$layer['cells']=array_values(array_filter($layer['cells'],static fn($c)=>!in_array($c['id'],$old,true)));
				// The source occupies the negative normal half of the connection.
				if($side===-1) $p=PolygonGeometry::clip($candidate['band'],$nx,$ny,$sign*$cut);
				else $p=PolygonGeometry::clip($candidate['band'],-$nx,-$ny,-$sign*$cut);
				$p=array_map(static fn($v)=>[$v[0]-$ox,$v[1]-$oy],$p);
				$id="$name.$port.join";
				$layer['cells'][]=PolygonGeometry::cell(array_replace($one,['id'=>$id]),$p);
				$layer['corridors'][$port]=['from'=>$one['floor'],'to'=>$one['floor'],'bend'=>0,'length'=>$candidate['gap']/2,
					'width'=>$candidate['width'],'cells'=>[$id],'joined'=>true];
				$center=$candidate['center'];
				$layer['anchors'][$port]['point'][0]=(int)(($nx ? $ap[0] : $center)-$ox);
				$layer['anchors'][$port]['point'][1]=(int)(($ny ? $ap[1] : $center)-$oy);
				$layer['anchors'][$port]['size'][0]=(int)$candidate['width'];
				$layer['merged']=count($joins); unset($layer);
			}
			$claimed[$a]=$claimed[$b]=true;
			$joins[]=['rooms'=>[$a,$b],'direction'=>$aa,'floor'=>$one['floor'],'hallLength'=>$candidate['gap'],
				'width'=>$candidate['width'],'area'=>$candidate['area'],'shape'=>$candidate['rectangular'] ? 'rectangle' : 'offset',
				'outline'=>RoomOutline::edges([$one,$two,PolygonGeometry::cell(['id'=>'join'],$candidate['band'])])];
		}
		return $joins;
	}

	/** Describe a broad union only when the facing room walls leave a genuinely short gap. */
	public static function candidate(array $a,array $b,string $direction): ?array
	{
		foreach([$a,$b] as $c) if(count(PolygonGeometry::points($c))!==4
			|| abs(PolygonGeometry::area(PolygonGeometry::points($c))-($c['x1']-$c['x0'])*($c['y1']-$c['y0']))>1e-6) return null;
		$x=in_array($direction,['east','west'],true); $u=$x ? 'x' : 'y'; $v=$x ? 'y' : 'x';
		$positive=in_array($direction,['east','north'],true);
		$start=$a[$u.($positive ? '1' : '0')]; $end=$b[$u.($positive ? '0' : '1')];
		$gap=($end-$start)*($positive ? 1 : -1);
		$lo=max($a[$v.'0'],$b[$v.'0']); $hi=min($a[$v.'1'],$b[$v.'1']); $width=$hi-$lo;
		$area=PolygonGeometry::area(PolygonGeometry::points($a))+PolygonGeometry::area(PolygonGeometry::points($b))+$gap*$width;
		if($gap<=0 || $gap>self::MAX_HALL || $width<384 || $area>self::MAX_AREA) return null;
		$band=$x ? [[min($start,$end),$lo],[min($start,$end),$hi],[max($start,$end),$hi],[max($start,$end),$lo]]
			: [[$lo,min($start,$end)],[$lo,max($start,$end)],[$hi,max($start,$end)],[$hi,min($start,$end)]];
		$rectangular=$a[$v.'0']===$b[$v.'0'] && $a[$v.'1']===$b[$v.'1']; $center=($lo+$hi)/2;
		return compact('band','gap','width','area','rectangular','center');
	}
}

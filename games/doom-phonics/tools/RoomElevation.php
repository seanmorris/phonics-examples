<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';

/**
 * Carry a route between different entrance elevations through the room itself.
 *
 * Opposing passages supply the end heights. Broad stair treads divide the
 * footprint into two landings, with a generous middle landing for side exits.
 * The outline and floor material remain continuous; there is no isolated pit
 * and no need to leave the room to reach its higher entrances.
 */
final class RoomElevation
{

	/** Select a safe stair axis without changing the shared external doorway frames. */
	public static function plan(array $footprint,array $dimensions,array $passages): ?array
	{
		if(isset($footprint['wallSlope'])) return null;
		$p=$footprint['polygon']; $choices=[];
		foreach([['west','east'],['south','north']] as $axis=>[$a,$b]) {
			if(!isset($passages[$a],$passages[$b])) continue;
			$from=$passages[$a]['height']; $to=$passages[$b]['height']; $rise=abs($to-$from);
			if($rise<32 || $rise>96) continue;
			$count=intdiv($rise,16); $middle=intdiv($count,2); $center=intdiv($dimensions[$axis],2);
			$start=$center-96-($middle-1)*48; $end=$center+96+($count-$middle-1)*48;
			if($start-min(array_column($p,$axis))<256 || max(array_column($p,$axis))-$end<256) continue;
			$sign=$to<=>$from; $floors=[];
			foreach($passages as $port=>$passage) {
				$floor=$port===$a ? $from : ($port===$b ? $to : $from+$sign*16*$middle);
				// Preserve the existing approach reservation and its neighbor's aperture.
				if(abs($floor-$passage['height'])/16*64>$footprint['approaches'][$port]
					|| max($floor,$passage['height'])+192>$passage['ceiling']) continue 2;
				$floors[$port]=$floor;
			}
			$choices[]=compact('axis','from','to','rise','count','middle','center','start','end','floors')
				+['ports'=>[$a,$b],'kind'=>'stairs'];
		}
		usort($choices,static fn($a,$b)=>$b['rise']<=>$a['rise']);
		return $choices[0]??null;
	}

	/** Split the existing footprint into full-width treads and entrance landings. */
	public static function cells(array $body,array $plan): array
	{
		['axis'=>$axis,'from'=>$from,'to'=>$to,'count'=>$count,'middle'=>$middle,'center'=>$center,'start'=>$start,'end'=>$end]=$plan;
		$p=PolygonGeometry::points($body); $edges=[min(array_column($p,$axis)),$start];
		for($i=1;$i<$middle;$i++) $edges[]=$start+$i*48;
		$edges[]=$center+96;
		for($i=1;$i<$count-$middle;$i++) $edges[]=$center+96+$i*48;
		$edges[]=max(array_column($p,$axis));
		$cells=[];
		for($i=0;$i<count($edges)-1;$i++) {
			$polygon=PolygonGeometry::clip($p,$axis===0 ? -1 : 0,$axis===1 ? -1 : 0,-$edges[$i]);
			$polygon=PolygonGeometry::clip($polygon,$axis===0 ? 1 : 0,$axis===1 ? 1 : 0,$edges[$i+1]);
			$id=$i ? substr($body['id'],0,-5).'.rise-'.$i : $body['id'];
			$cells[]=PolygonGeometry::cell(array_replace($body,['id'=>$id,'floor'=>$from+($to<=>$from)*16*$i]),$polygon);
		}
		return $cells;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . "/PolygonGeometry.php";
require_once __DIR__ . "/SeedSequence.php";

/**
 * Size room footprints independently and deform them around clear entrance bands.
 *
 * A minority of rooms share a gentle shear direction within each layer. Independent
 * dimensions provide most variation; neighboring angled walls remain parallel.
 */
final class RoomOutline
{
	/** Resize each side independently; occasionally shear both opposite walls together. */
	public static function footprint(string $name,array $dimensions,int $inset,string $seed,bool $building=false,bool $reserveGallery=false,?string $fixedWall=null): array
	{
		[$width,$height]=$dimensions;
		$random=new SeedSequence("footprint:$seed:$name");
		$margin=[];
		foreach(['west'=>$width,'east'=>$width,'south'=>$height,'north'=>$height] as $side=>$span) {
			$sizeBudget=intdiv($span-2*$inset-768,256);
			$entranceBudget=intdiv((int)($span/2)-$inset-384,128);
			$extra=($building || $reserveGallery) ? 0 : max(0,min(3,$sizeBudget,$entranceBudget));
			$margin[$side]=$inset+128*$random->integer(0,$extra);
		}
		// A large neighboring hub reserves a large bay, not an enormous room.
		$maximum=$name==='basement.0' ? 1280 : ($reserveGallery ? 1152 : 896);
		foreach([['west','east',$width],['south','north',$height]] as [$a,$b,$span]) {
			while($span-$margin[$a]-$margin[$b]>$maximum) {
				$side=$margin[$a]<=$margin[$b] ? $a : $b;
				$margin[$side]+=64;
			}
		}
		if(!$reserveGallery && $name!=='basement.0') foreach([['west','east',$width],['south','north',$height]] as [$a,$b,$span]) {
			$extra=min(2,(int)floor(($span-$margin[$a]-$margin[$b]-768)/64));
			if($extra>0) $margin[$random->integer(0,1) ? $a : $b]+=64*$random->integer(0,$extra);
		}
		$x0=$margin['west']; $x1=$width-$margin['east'];
		$y0=$margin['south']; $y1=$height-$margin['north'];
		$p=[[$x0,$y0],[$x0,$y1],[$x1,$y1],[$x1,$y0]];
		$plane=explode('.',$name)[0];
		$slope=(new SeedSequence("wall-direction:$seed:$plane"))->integer(0,1) ? 1 : -1;
		$angled=!$building && !$reserveGallery && $fixedWall===null && $random->integer(0,99)<35;
		if($angled) {
			// Every angled room in this layer uses the same 1:8 direction. Both
			// faces move together, so the space never pinches into a random wedge.
			$p=array_map(static fn($v)=>[$v[0]+$slope*($v[1]-$height/2)/8,$v[1]],$p);
		}
		return ['polygon'=>$p,'approaches'=>$margin,'dimensions'=>[$x1-$x0,$y1-$y0],'maximumSize'=>$maximum,'wallSlope'=>$angled ? [$slope,8] : null];
	}

	/** Return disjoint regions whose union is the room's irregular floor outline. */
	public static function cells(string $name,int $size,int $inset,int $floor,int $ceiling,int $variant,bool $building = false): array
	{
		$lo = $inset; $hi = $size-$inset;
		$polygon = [[$lo,$lo],[$lo,$hi],[$hi,$hi],[$hi,$lo]];
		if(!$building)
		{
			// A 64-unit bevel preserves the central 384-unit band in a small core.
			$cuts = [64,64,64,64];
			if($inset === 256) $cuts[$variant % 4] = 128;
			foreach([[1,1,2*$hi-$cuts[0]],[-1,1,$hi-$lo-$cuts[1]],
				[-1,-1,-2*$lo-$cuts[2]],[1,-1,$hi-$lo-$cuts[3]]] as [$nx,$ny,$d])
			$polygon = PolygonGeometry::clip($polygon,$nx,$ny,$d);
		}
		$cells = [PolygonGeometry::cell(['id'=>"$name.core"] + compact('floor','ceiling')
			+ ['tag'=>0,'light'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'],$polygon)];
		return $cells;
	}

	/** Trace exterior segments only, removing invisible subdivision edges from the report. */
	public static function edges(array $cells): array
	{
		$result = [];
		foreach($cells as $cell)
		{
			$points = PolygonGeometry::points($cell);
			foreach($points as $i=>$p)
			{
				$q=$points[($i+1)%count($points)]; $dx=$q[0]-$p[0]; $dy=$q[1]-$p[1]; $length=$dx*$dx+$dy*$dy;
				$cuts=[0,1]; $covered=[];
				foreach($cells as $other)
				{
					if($other['id']===$cell['id']) continue;
					$shared=PolygonGeometry::shared([$p,$q],PolygonGeometry::points($other));
					if(!$shared) continue;
					[$a,$b]=array_map(static fn($v)=>(($v[0]-$p[0])*$dx+($v[1]-$p[1])*$dy)/$length,$shared);
					$cuts[]=$a; $cuts[]=$b; $covered[]=[min($a,$b),max($a,$b)];
				}
				$cuts=array_values(array_unique($cuts)); sort($cuts);
				for($j=1;$j<count($cuts);$j++)
				{
					$a=$cuts[$j-1]; $b=$cuts[$j]; $mid=($a+$b)/2;
					foreach($covered as [$start,$end]) if($mid>$start && $mid<$end) continue 2;
					$result[]=[[$p[0]+$dx*$a,$p[1]+$dy*$a],[$p[0]+$dx*$b,$p[1]+$dy*$b]];
				}
			}
		}
		return $result;
	}
}

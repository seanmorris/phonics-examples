<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/RoomOutline.php';

/**
 * Finish outdoor rooms with a solid horizon wall and visible building roofs.
 *
 * A garden reaches the cluster exterior; a courtyard is enclosed by its rooms.
 * Like Doom II MAP01, the garden's far edge uses a collapsed sky sector above
 * a raised floor. It blocks movement while showing sky over the wall instead
 * of stretching a wall texture up to the roof. Open sky sits above adjoining
 * indoor ceilings, leaving room for textured masonry over their openings.
 */
final class OutdoorSpace
{
	/** Visible masonry above the adjacent indoor ceiling. */
	public const ROOF_THICKNESS = 32;

	/** Add a bounded exterior sky strip, keeping the garden floor continuous. */
	public static function garden(array &$cells,array $regions,array $center): array
	{
		$ids=array_fill_keys($regions,true); $edges=RoomOutline::edges($cells);
		$rim=null;
		foreach($cells as $i=>$cell) {
			if(!isset($ids[$cell['id']])) continue;
			$p=$cell['polygon'];
			foreach($edges as [$a,$b]) {
				$dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $length=hypot($dx,$dy);
				if($length<256) continue;
				$full=false;
				foreach($p as $j=>$v) {
					$w=$p[($j+1)%count($p)];
					if(hypot($v[0]-$a[0],$v[1]-$a[1])<1e-6 && hypot($w[0]-$b[0],$w[1]-$b[1])<1e-6) $full=true;
				}
				if(!$full) continue;
				// Leave the four central approach positions available to the caller.
				if(min($a[0],$b[0])<=$center[0]+192 && max($a[0],$b[0])>=$center[0]-192
					|| min($a[1],$b[1])<=$center[1]+192 && max($a[1],$b[1])>=$center[1]-192) continue;
				$gcd=static function(int $a,int $b): int { while($b) [$a,$b]=[$b,$a%$b]; return max(1,$a); };
				$g=$gcd((int)round(abs($dx)),(int)round(abs($dy))); $nx=-$dy/$g; $ny=$dx/$g;
				$d=$nx*$a[0]+$ny*$a[1]; $grid=6*($nx*$nx+$ny*$ny);
				$inset=ceil(48*hypot($nx,$ny)/$grid)*$grid;
				$inside=PolygonGeometry::clip($p,$nx,$ny,$d-$inset);
				$strip=PolygonGeometry::clip($p,-$nx,-$ny,-($d-$inset));
				if(!$inside || !$strip || PolygonGeometry::area($inside)<65536) continue;
				$cells[$i]=PolygonGeometry::cell($cell,$inside);
				$id=$cell['id'].'.sky-wall'; $height=$cell['floor']+80;
				$cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$id,'floor'=>$height,'ceiling'=>$height,
					'traversable'=>false,'wallTexture'=>'STONE2']),$strip);
				$rim=['region'=>$id,'height'=>80,'depth'=>$inset/hypot($nx,$ny)]; break 2;
			}
		}
		return compact('rim');
	}
}

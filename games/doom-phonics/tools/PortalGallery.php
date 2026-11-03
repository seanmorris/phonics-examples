<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/PlanePortalSite.php';

/**
 * Reuse POOL3D's open gallery around a stacked floor/ceiling aperture.
 *
 * A middle aperture can carry both portals so a fall continues through several
 * levels. Optional outer halls surround four solid wall strips with aligned
 * entrances; every rim remains accessible from the ordinary room network.
 */
final class PortalGallery
{
	/** Find a room center with enough space for the aperture, rim and optional halls. */
	public static function center(array $layer,bool $halls): ?array
	{
		if(isset($layer['galleryCenter'])) {
			[$x,$y]=$layer['galleryCenter']; $square=self::square($x,$y,$halls ? 512 : 352); $covered=0;
			foreach($layer['cells'] as $c) $covered+=PolygonGeometry::area(PolygonGeometry::intersect(PolygonGeometry::points($c),$square));
			return abs($covered-PolygonGeometry::area($square))<.01 ? [$x,$y] : null;
		}
		$core=$layer['cells'][0];
		if(!str_ends_with($core['id'],'.core')) return null;
		$x=round(($core['x0']+$core['x1'])/32)*16; $y=round(($core['y0']+$core['y1'])/32)*16;
		$r=$halls ? 512 : 352;
		foreach(self::square($x,$y,$r) as [$px,$py])
		if(!PolygonGeometry::contains(PolygonGeometry::points($core),$px,$py)) return null;
		return [$x,$y];
	}

	/** Replace one core with a gallery and return its shared vertical portal region. */
	public static function carve(array &$layer,string $room,bool $floor,bool $halls): string
	{
		$center=self::center($layer,$halls);
		if(!$center) throw new \InvalidArgumentException('Gallery needs a clear rim and hall reservation');
		[$x,$y]=$center; $core=$layer['cells'][0]; $shell=PolygonGeometry::points($core);
		if(isset($layer['galleryCenter'])) {
			$opening=self::square($x,$y,192); $outer=self::square($x,$y,384); $inner=self::square($x,$y,320);
			$cells=[];
			foreach($layer['cells'] as $cell) {
				$polygon=PolygonGeometry::points($cell); $cut=$halls ? $outer : $opening;
				if(!PolygonGeometry::overlaps($polygon,$cut)) { $cells[]=$cell; continue; }
				$parts=PolygonGeometry::surround($polygon,$cut);
				foreach($parts as $i=>$p) $cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$cell['id'].($i ? ".gallery-$i" : '')]),$p);
			}
			$pieces=$halls ? PolygonGeometry::surround($inner,$opening) : [];
			if($halls) foreach([[1,0],[0,1],[-1,0],[0,-1]] as [$dx,$dy]) {
				$p=[]; foreach([[320,-64],[320,64],[384,64],[384,-64]] as [$n,$t]) $p[]=[$x+$dx*$n-$dy*$t,$y+$dy*$n+$dx*$t]; $pieces[]=$p;
			}
			foreach($pieces as $i=>$p) $cells[]=PolygonGeometry::cell(array_replace($core,['id'=>"$room.gallery-rim-$i"]),$p);
			$id="$room.gallery-aperture";
			$cells[]=PolygonGeometry::cell(array_replace($core,['id'=>$id,'floor'=>$core['floor']-($floor ? 32 : 0),'verticalPortal'=>$floor ? 'floor' : 'ceiling']),$opening);
			$layer['cells']=$cells;
			$layer['gallery']=['aperture'=>$id,'width'=>384,'hallways'=>$halls,'rimWidth'=>$halls ? 128 : null,'wallThickness'=>$halls ? 64 : null,'inserted'=>true];
			if($floor) PlanePortalSite::escapeRim($layer,$id);
			return $id;
		}
		$opening=self::square($x,$y,192); $pieces=[];
		if($halls) {
			$inner=self::square($x,$y,320); $outer=self::square($x,$y,384);
			$pieces=PolygonGeometry::surround($shell,$outer);
			array_push($pieces,...PolygonGeometry::surround($inner,$opening));
			foreach([[1,0],[0,1],[-1,0],[0,-1]] as [$dx,$dy]) {
				$p=[];
				foreach([[320,-64],[320,64],[384,64],[384,-64]] as [$n,$t])
				$p[]=[$x+$dx*$n-$dy*$t,$y+$dy*$n+$dx*$t];
				$pieces[]=$p;
			}
		} else $pieces=PolygonGeometry::surround($shell,$opening);
		$layer['cells'][0]=PolygonGeometry::cell($core,array_shift($pieces));
		foreach($pieces as $i=>$p) $layer['cells'][]=PolygonGeometry::cell(array_replace($core,['id'=>"$room.gallery-rim-$i"]),$p);
		$id="$room.gallery-aperture";
		$aperture=array_replace($core,['id'=>$id,'verticalPortal'=>$floor ? 'floor' : 'ceiling']);
		if($floor) $aperture['floor']-=32;
		$layer['cells'][]=PolygonGeometry::cell($aperture,$opening);
		$layer['gallery']=['aperture'=>$id,'width'=>384,'hallways'=>$halls,'rimWidth'=>$halls ? 128 : null,'wallThickness'=>$halls ? 64 : null];
		if($floor) PlanePortalSite::escapeRim($layer,$id);
		return $id;
	}

	/** Return a clockwise square on the same integer lattice as the room. */
	private static function square(float $x,float $y,int $r): array
	{
		return [[$x-$r,$y-$r],[$x-$r,$y+$r],[$x+$r,$y+$r],[$x+$r,$y-$r]];
	}
}

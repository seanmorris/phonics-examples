<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/RoomIntrusion.php';

/**
 * Partition a footprint around a central octagon and reserve solid shared walls.
 *
 * The eight surrounding rooms fan out from the core's corners. Each dividing
 * line is defined once; equal inward offsets create parallel faces and leave
 * the wall itself as unoccupied map space. Intersections join the strips at
 * corners without overlapping rooms. This models nested architectural contours
 * rather than independently positioned boxes or a square grid of room sites.
 */
final class ConformingRooms
{

	/**
	 * Return convex rooms, original partitions, logical owners and shared wall planes.
	 *
	 * Radius is the core partition's half-extent; corner cuts are radius/2.
	 * Use a radius divisible by 12 and hull coordinates on the six-unit grid
	 * relative to center. Offset distances also respect the integer normal's
	 * squared length, permitting exact rectangular door cuts through every wall.
	 * An optional disjoint convex footprint clips the partitions to a polygon union
	 * before reserving walls. Equal group IDs keep internal seams open, producing
	 * larger chambers from several convex regions. An optional invading polygon subtracts space before reserving walls.
	 * Invalid custom dimensions are rejected; callers must also check room size.
	 */
	public static function radial(array $outline,array $center,int $radius=360,int $wall=128,?array $footprint=null,array $groups=[],?array $intrusion=null): array
	{
		if($radius<12 || $radius%12 || $wall<1 || count($center)!==2)
		throw new \InvalidArgumentException('Radial rooms need a positive wall and a radius divisible by 12');
		[$x,$y]=$center;
		foreach($center as $v) if($v!== (int)$v) throw new \InvalidArgumentException('Room center must use integer coordinates');
		foreach($outline as $p) foreach([0,1] as $axis)
		if(abs(($p[$axis]-$center[$axis])/6-round(($p[$axis]-$center[$axis])/6))>1e-6)
		throw new \InvalidArgumentException('Radial footprint must use the six-unit grid relative to its center');
		$half=intdiv($radius,2);
		$vertices=[[-$half,-$radius],[-$radius,-$half],[-$radius,$half],[-$half,$radius],
			[$half,$radius],[$radius,$half],[$radius,-$half],[$half,-$radius]];
		$core=$outline; $faces=[];
		foreach($vertices as $i=>$a) {
			$b=$vertices[($i+1)%8]; $nx=-($b[1]-$a[1])/$half; $ny=($b[0]-$a[0])/$half;
			$d=$nx*($x+$a[0])+$ny*($y+$a[1]); $faces[]=[$nx,$ny,$d];
			$core=PolygonGeometry::clip($core,$nx,$ny,$d);
		}
		$partitions=[$core];
		foreach($vertices as $i=>$a) {
			$b=$vertices[($i+1)%8]; [$nx,$ny,$d]=$faces[$i];
			$p=PolygonGeometry::clip($outline,-$nx,-$ny,-$d);
			foreach([[-$a[1],$a[0]],[$b[1],-$b[0]]] as [$nx,$ny]) {
				$nx/=$half; $ny/=$half;
				$p=PolygonGeometry::clip($p,$nx,$ny,$nx*$x+$ny*$y);
			}
			$partitions[]=$p;
		}
		if($footprint!==null) foreach($partitions as &$partition) {
			$pieces=[];
			foreach($footprint as $piece) {
				$intersection=PolygonGeometry::intersect($partition,$piece);
				if($intersection) $pieces[]=$intersection;
			}
			$pieces=PolygonGeometry::mergeConvex($pieces);
			if(count($pieces)!==1) throw new \LogicException('Outline partition must remain a connected convex room');
			$partition=$pieces[0];
		}
		unset($partition);

		$owners=$groups ?: array_keys($partitions);
		if($intrusion!==null) {
			$cut=RoomIntrusion::partition($partitions,$owners,$intrusion,$footprint);
			$partitions=$cut['partitions']; $owners=$cut['owners'];
		}
		return self::offset($partitions,$owners,$wall,$intrusion!==null);
	}

	/** Offset shared room boundaries while keeping convex decomposition seams open. */
	public static function offset(array $partitions,array $owners,int $wall,bool $allowEmpty=false): array
	{
		$byOwner=[];
		foreach($partitions as $i=>$p) $byOwner[$owners[$i]][]=$p;
		$partitions=$owners=[];
		foreach($byOwner as $owner=>$pieces)
		foreach(PolygonGeometry::mergeConvex($pieces) as $p) { $partitions[]=$p; $owners[]=$owner; }

		$rooms=$planes=[];
		foreach($partitions as $i=>$p) {
			$planes[$i]=[];
			foreach($partitions as $j=>$q) {
				if($i===$j || ($owners[$i]===$owners[$j]) || !($edge=PolygonGeometry::shared($p,$q))) continue;
				[$a,$b]=$edge; $nx=(int)round(-($b[1]-$a[1])); $ny=(int)round($b[0]-$a[0]);
				$g=self::gcd(abs($nx),abs($ny)); $nx=intdiv($nx,$g); $ny=intdiv($ny,$g);
				$n2=$nx*$nx+$ny*$ny; $grid=intdiv(6*$n2,self::gcd(6,$n2));
				$inset=ceil($wall/2*hypot($nx,$ny)/$grid)*$grid;
				$planes[$i][$j]=[$nx,$ny,$nx*$a[0]+$ny*$a[1],$inset];
			}
			$room=$p;
			foreach($planes[$i] as [$nx,$ny,$d,$inset]) $room=PolygonGeometry::clip($room,$nx,$ny,$d-$inset);
			if(!$room && !$allowEmpty) throw new \InvalidArgumentException('Wall offsets consume a room');
			foreach($room as $v) foreach($v as $coordinate)
			if(abs($coordinate-round($coordinate))>1e-6) throw new \LogicException('Radial wall intersection left the integer grid');
			$rooms[]=$room;
		}
		return compact('rooms','partitions','planes','owners');
	}

	/** Reduce grid directions and compute common offset increments exactly. */
	private static function gcd(int $a,int $b): int
	{
		while($b) [$a,$b]=[$b,$a%$b]; return max(1,$a);
	}
}

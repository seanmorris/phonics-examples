<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/SeedSequence.php';

/**
 * Let a coherent new room subtract space from existing architectural partitions.
 *
 * Subtraction happens before wall offsets and door placement. Convex fragments
 * retain their original owner, so their representation seams remain open. The
 * invading polygon receives its own owner and parallel wall reservations along
 * every contact with the surviving rooms. Exterior cuts can form gardens; fully
 * enclosed cuts can form courtyards using exactly the same operation.
 */
final class RoomIntrusion
{

	/** Cut partitions without losing area, returning their logical room ownership. */
	public static function partition(array $partitions,array $owners,array $polygon,?array $footprint=null): array
	{
		$result=$groups=$inside=[];
		foreach($partitions as $i=>$p) {
			foreach(PolygonGeometry::mergeConvex(PolygonGeometry::surround($p,$polygon)) as $piece) {
				$result[]=$piece; $groups[]=$owners[$i];
			}
			$intersection=PolygonGeometry::intersect($p,$polygon);
			if($intersection) $inside[]=$intersection;
		}
		if($footprint!==null) {
			$inside=[];
			foreach($footprint as $p) { $piece=PolygonGeometry::intersect($p,$polygon); if($piece) $inside[]=$piece; }
		}
		$owner=max($owners)+1;
		foreach(PolygonGeometry::mergeConvex($inside) as $piece) { $result[]=$piece; $groups[]=$owner; }
		return ['partitions'=>$result,'owners'=>$groups,'intruder'=>$owner];
	}

	/** Choose a cardinal or diagonal intrusion that clips the core and surrounding rooms. */
	public static function garden(string $seed,array $center): array
	{
		$r=new SeedSequence('intrusion:'.$seed);
		$extent=$r->integer(0,2);
		$rotation=$r->integer(0,3);
		// The offset diagonal interrupts the core without generating unrelated angles.
		$diagonal=(bool)$r->integer(0,1);
		// Cardinal cuts stay clear of the four centered approach reservations.
		$depth=$diagonal ? $extent*120 : 120+($extent%2)*120;
		$p=$diagonal ? [[$depth-1500,1500],[1500,1500],[1500,$depth-1500]]
			: [[$depth,-1500],[$depth,1500],[1500,1500],[1500,-1500]];
		foreach($p as &$v) {
			for($i=0;$i<$rotation;$i++) $v=[-$v[1],$v[0]];
			$v=[$v[0]+$center[0],$v[1]+$center[1]];
		}
		unset($v);
		return ['polygon'=>$p,'depth'=>$depth,'rotation'=>$rotation*90,'diagonal'=>$diagonal];
	}
}

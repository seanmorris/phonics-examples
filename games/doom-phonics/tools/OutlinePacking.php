<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/RoomOutline.php';

/**
 * Establish a cluster's exterior before subdividing its usable interior.
 *
 * A broad octagonal body and two tapered wings overlap to form a concave union.
 * Wing edges follow the same partition directions used by neighboring rooms.
 * Convex pieces are only a representation of that union, not extra room walls.
 */
final class OutlinePacking
{
	/** Return the source polygons, their exact union and the continuous exterior contour. */
	public static function cluster(int $variant,int $center): array
	{
		$body=[[-1008,-1200],[-1008,1200],[1008,1200],[1008,-1200]];
		$cut=$variant<4 ? 240 : 288;
		foreach([[1,1],[-1,1],[-1,-1],[1,-1]] as [$nx,$ny])
		$body=PolygonGeometry::clip($body,$nx,$ny,2208-$cut);
		$east=[[840,-420],[840,420],[1200,600],[1200,-600]];
		$west=array_map(static fn($p)=>[-$p[0],-$p[1]],$east);
		$rotate=static function(array $p) use($variant,$center): array {
			for($i=0;$i<$variant%4;$i++) $p=[-$p[1],$p[0]];
			return [$p[0]+$center,$p[1]+$center];
		};
		$components=array_map(static fn($p)=>array_map($rotate,$p),[$body,$east,$west]);
		$pieces=PolygonGeometry::union($components);
		$cells=[];
		foreach($pieces as $i=>$p) $cells[]=PolygonGeometry::cell(['id'=>(string)$i],$p);
		$edges=RoomOutline::edges($cells); $edge=array_shift($edges); $outline=[$edge[0]]; $next=$edge[1];
		while(hypot($next[0]-$outline[0][0],$next[1]-$outline[0][1])>1e-6) {
			$outline[]=$next; $found=false;
			foreach($edges as $i=>$e) if(hypot($e[0][0]-$next[0],$e[0][1]-$next[1])<1e-6) {
				$next=$e[1]; unset($edges[$i]); $found=true; break;
			}
			if(!$found) throw new \LogicException('Cluster union has a disconnected outline');
		}
		$outline=PolygonGeometry::clean($outline);
		return compact('components','pieces','outline');
	}
}

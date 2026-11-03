<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Shared convex geometry for generated rooms, wall offsets and traversal regions.
 *
 * Polygons use clockwise map coordinates. Clipping preserves exact line equations;
 * the WAD writer rejects fractional vertices instead of silently bending walls.
 */
final class PolygonGeometry
{
	/** Read a polygon or adapt an existing rectangular generation cell. */
	public static function points(array $cell): array
	{
		return $cell['polygon'] ?? [[$cell['x0'],$cell['y0']],[$cell['x0'],$cell['y1']],
			[$cell['x1'],$cell['y1']],[$cell['x1'],$cell['y0']]];
	}

	/** Return the positive area of either polygon winding. */
	public static function area(array $points): float
	{
		$area = 0;
		foreach($points as $i=>[$x,$y]) { [$a,$b]=$points[($i+1)%count($points)]; $area += $x*$b-$y*$a; }
		return abs($area)/2;
	}

	/** Clip to nx*x + ny*y <= distance, retaining clockwise order. */
	public static function clip(array $points,float $nx,float $ny,float $distance): array
	{
		$out=[];
		foreach($points as $i=>$a)
		{
			$b=$points[($i+1)%count($points)];
			$da=$nx*$a[0]+$ny*$a[1]-$distance; $db=$nx*$b[0]+$ny*$b[1]-$distance;
			if($da <= 1e-7) $out[]=$a;
			if(($da < -1e-7 && $db > 1e-7) || ($da > 1e-7 && $db < -1e-7))
			{ $t=$da/($da-$db); $out[]=[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t]; }
		}
		return self::clean($out);
	}

	/** Remove coincident and redundant straight-line vertices after clipping. */
	public static function clean(array $points): array
	{
		$out=[];
		foreach($points as $p)
		if(!$out || hypot($p[0]-$out[count($out)-1][0],$p[1]-$out[count($out)-1][1])>1e-7) $out[]=$p;
		if(count($out)>1 && hypot($out[0][0]-$out[count($out)-1][0],$out[0][1]-$out[count($out)-1][1])<1e-7) array_pop($out);
		do {
			$changed=false;
			foreach($out as $i=>$p) {
				$a=$out[($i+count($out)-1)%count($out)]; $b=$out[($i+1)%count($out)];
				if(abs(($p[0]-$a[0])*($b[1]-$p[1])-($p[1]-$a[1])*($b[0]-$p[0]))<1e-7)
				{ array_splice($out,$i,1); $changed=true; break; }
			}
		} while($changed && count($out)>2);
		return count($out)>=3 ? $out : [];
	}

	/** Add polygon bounds and an interior witness to a generation cell. */
	public static function cell(array $fields,array $points): array
	{
		if(count($points)<3 || self::area($points)<1e-6) throw new \InvalidArgumentException('Empty polygon region');
		$fields['polygon']=$points;
		$fields['x0']=min(array_column($points,0)); $fields['x1']=max(array_column($points,0));
		$fields['y0']=min(array_column($points,1)); $fields['y1']=max(array_column($points,1));
		$fields['center']=[array_sum(array_column($points,0))/count($points),array_sum(array_column($points,1))/count($points)];
		return $fields;
	}

	/** Test convex containment with optional clearance from every edge. */
	public static function contains(array $points,float $x,float $y,float $clearance=0): bool
	{
		foreach($points as $i=>[$ax,$ay]) {
			[$bx,$by]=$points[($i+1)%count($points)]; $dx=$bx-$ax; $dy=$by-$ay;
			if($dx*($y-$ay)-$dy*($x-$ax)>-$clearance*hypot($dx,$dy)+1e-6) return false;
		}
		return true;
	}

	/** Find a shared straight boundary, excluding point contacts. */
	public static function shared(array $a,array $b): ?array
	{
		foreach($a as $i=>$p) {
			$q=$a[($i+1)%count($a)]; $dx=$q[0]-$p[0]; $dy=$q[1]-$p[1]; $len=$dx*$dx+$dy*$dy;
			foreach($b as $j=>$r) {
				$s=$b[($j+1)%count($b)];
				if(abs($dx*($r[1]-$p[1])-$dy*($r[0]-$p[0]))>1e-6 || abs($dx*($s[1]-$p[1])-$dy*($s[0]-$p[0]))>1e-6) continue;
				$t0=(($r[0]-$p[0])*$dx+($r[1]-$p[1])*$dy)/$len; $t1=(($s[0]-$p[0])*$dx+($s[1]-$p[1])*$dy)/$len;
				$lo=max(0,min($t0,$t1)); $hi=min(1,max($t0,$t1));
				if($hi-$lo>1e-7) return [[$p[0]+$dx*$lo,$p[1]+$dy*$lo],[$p[0]+$dx*$hi,$p[1]+$dy*$hi]];
			}
		}
		return null;
	}

	/** Subtract an interior convex opening, returning convex pieces around its boundary. */
	public static function surround(array $shell,array $opening): array
	{
		$pieces=[];
		foreach($opening as $i=>$p) {
			$q=$opening[($i+1)%count($opening)]; $nx=$p[1]-$q[1]; $ny=$q[0]-$p[0]; $d=$nx*$p[0]+$ny*$p[1];
			$outside=self::clip($shell,-$nx,-$ny,-$d);
			if($outside) $pieces[]=$outside;
			$shell=self::clip($shell,$nx,$ny,$d);
		}
		return $pieces;
	}

	/** Join adjacent clipping pieces when their exact union remains convex. */
	public static function mergeConvex(array $pieces): array
	{
		do {
			$changed=false;
			foreach($pieces as $i=>$a) foreach($pieces as $j=>$b) {
				if($j<=$i || !self::shared($a,$b)) continue;
				$points=array_merge($a,$b);
				usort($points,static fn($p,$q)=>($p[0]<=>$q[0]) ?: ($p[1]<=>$q[1]));
				$points=array_values(array_unique($points,SORT_REGULAR));
				$chains=[];
				foreach([$points,array_reverse($points)] as $sequence) {
					$chain=[];
					foreach($sequence as $p) {
						while(count($chain)>=2) {
							$q=$chain[count($chain)-1]; $r=$chain[count($chain)-2];
							if(($q[0]-$r[0])*($p[1]-$r[1])-($q[1]-$r[1])*($p[0]-$r[0])>1e-7) break;
							array_pop($chain);
						}
						$chain[]=$p;
					}
					array_pop($chain); array_push($chains,...$chain);
				}
				$hull=array_reverse($chains);
				if(abs(self::area($hull)-self::area($a)-self::area($b))>1e-6) continue;
				$pieces[$i]=$hull; array_splice($pieces,$j,1); $changed=true; break 2;
			}
		} while($changed);
		return $pieces;
	}

	/** Intersect two clockwise convex polygons without snapping their wall planes. */
	public static function intersect(array $a,array $b): array
	{
		foreach($b as $i=>$p) {
			$q=$b[($i+1)%count($b)]; $nx=$p[1]-$q[1]; $ny=$q[0]-$p[0];
			$a=self::clip($a,$nx,$ny,$nx*$p[0]+$ny*$p[1]);
			if(!$a) break;
		}
		return $a;
	}

	/** Form a polygon union as disjoint convex pieces, preserving concave exterior bays. */
	public static function union(array $polygons): array
	{
		$pieces=[];
		foreach($polygons as $polygon) {
			$remaining=[$polygon];
			foreach($pieces as $existing) {
				$next=[];
				foreach($remaining as $p) array_push($next,...self::surround($p,$existing));
				$remaining=$next;
			}
			array_push($pieces,...$remaining);
		}
		return self::mergeConvex($pieces);
	}

	/** Test positive-area overlap; touching walls and door edges are allowed. */
	public static function overlaps(array $a,array $b): bool
	{
		foreach($b as $i=>$p) {
			$q=$b[($i+1)%count($b)]; $nx=$p[1]-$q[1]; $ny=$q[0]-$p[0];
			$a=self::clip($a,$nx,$ny,$nx*$p[0]+$ny*$p[1]);
			if(!$a) return false;
		}
		return self::area($a)>1e-6;
	}

	/** Translate both exact polygons and their cached bounds/interior witnesses. */
	public static function translate(array $cell,float $x,float $y,float $z=0): array
	{
		foreach(['x0','x1'] as $k) $cell[$k]+=$x;
		foreach(['y0','y1'] as $k) $cell[$k]+=$y;
		foreach(['floor','ceiling'] as $k) $cell[$k]+=$z;
		if(isset($cell['polygon'])) $cell['polygon']=array_map(static fn($p)=>[$p[0]+$x,$p[1]+$y],$cell['polygon']);
		if(isset($cell['center'])) { $cell['center'][0]+=$x; $cell['center'][1]+=$y; }
		return $cell;
	}
}

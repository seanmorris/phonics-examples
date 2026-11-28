<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Edits;

/** The portion of an editable highway inside a named landscape, in that square's local coordinates. */
final class JourneyPavement
{
	public static function translated(array $road,array $shift): array
	{
		foreach($road['points'] as &$p) foreach($shift as $axis=>$offset) $p[$axis]+=$offset;
		return $road;
	}

	/** Clip Bézier spans at the square boundary while retaining their exact curvature and elevation. */
	public static function square(array $roads,array $origin,float $half,bool $outside=false): \Generator
	{
		$pieces=[];
		foreach($roads as $road) {
			$road=self::translated($road,[-$origin[0],-$origin[1],-$origin[2]]);
			$points=$road['points']; $handles=$road['handles'] ?? [];
			$part=null; $left=Edits::roadHandles($points,$handles,0);
			$banks=$road['banks'] ?? null;
			// Keep a feathered cap outside the square so clipping doesn't fade the road inside it.
			$margin=$outside ? 0. : ($road['endBlend'] ?? 0.); $touches=$margin===0.; $firstPiece=count($pieces);
			for($i=1;$i<count($points);$i++) {
				$right=Edits::roadHandles($points,$handles,$i);
				$a=$points[$i-1]; $d=$points[$i];
				$b=array_map(static fn($v,$h)=>$v+$h,$a,$left['out']);
				$c=array_map(static fn($v,$h)=>$v+$h,$d,$right['in']);
				$left=$right; $curve=[$a,$b,$c,$d];
				if(!$touches && self::inside($curve,$half)) $touches=true;
				$ranges=self::inside($curve,$half+$margin);
				if($outside) {
					$gaps=[]; $at=0.;
					foreach($ranges as [$lo,$hi]) { if($lo>$at) $gaps[]=[$at,$lo]; $at=$hi; }
					if($at<1.) $gaps[]=[$at,1.];
					$ranges=$gaps;
				}
				foreach($ranges as [$t0,$t1]) {
					[$p,$q,$r,$s]=self::portion($curve,$t0,$t1);
					if(hypot($s[0]-$p[0],$s[2]-$p[2])<.001) continue;
					if($part===null || end($part['points'])!==$p) {
						if($part!==null) $pieces[]=$part;
						$part=$road; $part['points']=[$p]; $part['handles']=[[]];
						if($banks!==null) $part['banks']=[$banks[$i-1]+$t0*($banks[$i]-$banks[$i-1])];
					}
					$part['handles'][array_key_last($part['handles'])]['out']=array_map(static fn($v,$w)=>$v-$w,$q,$p);
					$part['points'][]=$s;
					if($banks!==null) $part['banks'][]=$banks[$i-1]+$t1*($banks[$i]-$banks[$i-1]);
					$part['handles'][]=['in'=>array_map(static fn($v,$w)=>$v-$w,$r,$s)];
				}
				if(($i&15)===0) yield;
			}
			if($part!==null) $pieces[]=$part;
			if(!$touches) array_splice($pieces,$firstPiece);
		}
		return $pieces;
	}

	private static function mix(array $a,array $b,float $t): array
	{
		return array_map(static fn($v,$w)=>$v+($w-$v)*$t,$a,$b);
	}

	public static function split(array $curve,float $t): array
	{
		[$a,$b,$c,$d]=$curve;
		$ab=self::mix($a,$b,$t); $bc=self::mix($b,$c,$t); $cd=self::mix($c,$d,$t);
		$abc=self::mix($ab,$bc,$t); $bcd=self::mix($bc,$cd,$t); $p=self::mix($abc,$bcd,$t);
		return [[$a,$ab,$abc,$p],[$p,$bcd,$cd,$d]];
	}

	private static function portion(array $curve,float $start,float $end): array
	{
		if($end<1.) $curve=self::split($curve,$end)[0];
		if($start>0.) $curve=self::split($curve,$start/$end)[1];
		return $curve;
	}

	/** Convex-hull rejection handles almost every span. Bisect only at boundary crossings.
	 * Coalesce adjacent parameter intervals before splitting the original curve. */
	private static function inside(array $curve,float $half): array
	{
		$ranges=[]; $pending=[[$curve,0.,1.]];
		while($pending) {
			[$c,$lo,$hi]=array_pop($pending);
			$px=array_column($c,0); $pz=array_column($c,2);
			if(max($px)<-$half || min($px)>$half || max($pz)<-$half || min($pz)>$half) continue;
			if((min($px)>=-$half && max($px)<=$half && min($pz)>=-$half && max($pz)<=$half) || $hi-$lo<1.e-10) {
				$i=array_key_last($ranges);
				if($i!==null && $ranges[$i][1]===$lo) $ranges[$i][1]=$hi;
				else $ranges[]=[$lo,$hi];
			} else {
				[$left,$right]=self::split($c,.5); $mid=($lo+$hi)*.5;
				$pending[]=[$right,$mid,$hi]; $pending[]=[$left,$lo,$mid];
			}
		}
		// A tangent that merely touches the border has no length inside the square.
		return array_filter($ranges,static fn($range)=>$range[1]-$range[0]>1.e-8);
	}

	/** Identical ramps share one editable record even after a floating-origin / sea-level shift. */
	public static function key(array $road): string
	{
		$properties=$road;
		unset($properties['points'],$properties['handles']);
		$geometry=json_encode($properties,JSON_THROW_ON_ERROR);
		foreach($road['points'] as $i=>$p) {
			$h=Edits::roadHandles($road['points'],$road['handles'] ?? [],$i);
			if($i===0) $h['in']=[0.,0.,0.];
			if($i===array_key_last($road['points'])) $h['out']=[0.,0.,0.];
			$geometry.=pack('l*',...array_map(static fn($v)=>(int)round(1000.*$v),[...$p,...$h['in'],...$h['out']]));
		}
		return hash('sha256',$geometry);
	}
}

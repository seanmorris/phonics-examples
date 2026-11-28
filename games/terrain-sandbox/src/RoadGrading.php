<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Edits;
use TerrainPhonics\Landscape;

/** Fit ground-placed spans to broad terrain relief, baking the result as ordinary editable cubic knots. */
final class RoadGrading
{
	private const STATION = 16.; // Meters between survey samples, before smoothing.
	private const TOLERANCE = .8; // Vertical error relative to the smoothed survey, in meters.

	/** Keep a slot for the next hand-placed split, and another to close an open road into a circuit. */
	public static function availableKnots(array $points): int
	{
		$count = count($points);
		if($count<2) return 0;
		$closed = $points[0] === $points[$count-1];
		return max(0,128-$count-($closed ? 1 : 2));
	}

	/** Evaluate the original cubic and its derivative with respect to its span parameter. */
	private static function pose(array $a, array $b, array $out, array $in, float $t): array
	{
		$p = $v = [];
		foreach($a as $axis=>$start) {
			$d = $b[$axis]-$start;
			$c1 = 3*$out[$axis];
			$c2 = 3*($d-2*$out[$axis]+$in[$axis]);
			$c3 = -2*$d+3*($out[$axis]-$in[$axis]);
			$p[] = $start+$t*($c1+$t*($c2+$t*$c3));
			$v[] = $c1+$t*(2*$c2+3*$t*$c3);
		}
		return [$p,$v];
	}

	/** Queue the worst missed rise or dip between two proposed knots. Slopes use survey index units. */
	private static function deviation(\SplPriorityQueue $queue, array $y, array $slope, int $span, int $a, int $b): void
	{
		$n = $b-$a;
		$start = $slope[$a]*$n;
		$end = $slope[$b]*$n;
		$delta = $y[$b]-$y[$a];
		$c2 = 3*$delta-2*$start-$end;
		$c3 = $start+$end-2*$delta;
		$worst = self::TOLERANCE;
		$split = null;
		for($j=$a+1; $j<$b; $j++) {
			$t = ($j-$a)/$n;
			$error = abs($y[$a]+$t*($start+$t*($c2+$t*$c3))-$y[$j]);
			if($error>$worst) { $worst=$error; $split=$j; }
		}
		if($split!==null) $queue->insert([$span,$a,$split,$b],$worst);
	}

	public static function fit(Landscape $land, array $road): \Generator
	{
		$ground = $road['_ground'] ?? [];
		unset($road['_ground']);
		if(!$ground) return $road;
		$points = $road['points'];
		$last = count($points)-1;
		$room = self::availableKnots($points);
		if(!$room) return $road;
		$recipe = $land->recipe();
		if(!isset($recipe['join']) && $recipe['heightScale']==0. && ($recipe['detailAmplitude']==0. || !$recipe['detailOctaves'])
			&& $land->heightEditIndex()->isEmpty()) return $road;

		$tangents = [];
		foreach($points as $i=>$_) $tangents[] = Edits::roadHandles($points,$road['handles'] ?? [],$i);
		$queue = new \SplPriorityQueue;
		$profiles = $knots = [];
		$half = $recipe['worldSize']/2;
		$probes = 0;
		for($i=0; $i<$last; $i++) {
			if(!isset($ground[$i]['out'],$ground[$i+1]['in'])) continue;
			$a = $points[$i]; $b = $points[$i+1];
			$out = $tangents[$i]['out']; $in = $tangents[$i+1]['in'];
			$polygon = hypot($out[0],$out[2])+hypot($in[0],$in[2])
				+hypot($b[0]+$in[0]-$a[0]-$out[0],$b[2]+$in[2]-$a[2]-$out[2]);
			$n = min(2048,(int)ceil($polygon/self::STATION));
			if($n<2) continue;

			// The correction is relative to the authored spline. Zero endpoint value and slope keep joins smooth.
			$raw = [0.];
			for($j=1; $j<$n; $j++) {
				if((++$probes&3)===0) yield;
				$t = $j/$n;
				[[$x,$y,$z]] = self::pose($a,$b,$out,$in,$t);
				// An off-map curve has no underlying surface to survey.
				if(max(abs($x),abs($z))>$half) continue 2;
				$offset = (1-$t)*$ground[$i]['offset']+$t*$ground[$i+1]['offset'];
				$raw[] = $land->height($x,$z)+$offset-$y;
			}
			$raw[] = 0.;
			$y = $slope = [0.];
			for($j=1; $j<$n; $j++) $y[] = ($raw[$j-1]+2*$raw[$j]+$raw[$j+1])/4;
			$y[] = 0.;
			for($j=1; $j<$n; $j++) {
				$l = $y[$j]-$y[$j-1]; $r = $y[$j+1]-$y[$j];
				$slope[] = $l*$r>0 ? 2*$l*$r/($l+$r) : 0.;
			}
			$slope[] = 0.;
			$profiles[$i] = [$y,$slope];
			self::deviation($queue,$y,$slope,$i,0,$n);
		}
		// Spend the shared edit's spare control points on the largest errors anywhere along the road.
		for(; $room>0 && !$queue->isEmpty(); $room--) {
			[$i,$a,$split,$b] = $queue->extract();
			$knots[$i][] = $split;
			[$y,$slope] = $profiles[$i];
			self::deviation($queue,$y,$slope,$i,$a,$split);
			self::deviation($queue,$y,$slope,$i,$split,$b);
			yield;
		}
		if(!$knots) return $road;

		$new = [$points[0]]; $handles = [[]]; $banks = [$road['banks'][0] ?? 0.];
		for($i=0; $i<$last; $i++) {
			$out = $tangents[$i]['out']; $in = $tangents[$i+1]['in'];
			$cuts = $knots[$i] ?? [];
			sort($cuts,SORT_NUMERIC);
			$n = $cuts ? count($profiles[$i][0])-1 : 1;
			$cuts[] = $n;
			$from = 0;
			$derivative = array_map(static fn($d)=>3*$d,$out);
			foreach($cuts as $j) {
				$t = $j/$n;
				// Keep authored endpoints exact, including the repeated point at a lap's seam.
				if($j===$n) {
					$p = $points[$i+1];
					$v = array_map(static fn($d)=>-3*$d,$in);
				} else {
					[$p,$v] = self::pose($points[$i],$points[$i+1],$out,$in,$t);
					$p[1] += $profiles[$i][0][$j];
					$v[1] += $n*$profiles[$i][1][$j];
				}
				$scale = ($j-$from)/(3*$n);
				$handles[count($new)-1]['out'] = array_map(static fn($d)=>$d*$scale,$derivative);
				$handles[] = ['in'=>array_map(static fn($d)=>-$d*$scale,$v)];
				$new[] = $p;
				if(isset($road['banks'])) $banks[] = (1-$t)*$road['banks'][$i]+$t*$road['banks'][$i+1];
				$from = $j; $derivative = $v;
			}
		}
		$road['points'] = $new; $road['handles'] = $handles;
		if(isset($road['banks'])) $road['banks'] = $banks;
		return $road;
	}
}

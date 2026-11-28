<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Fit editable cubic spans to the generator's evenly spaced, banked lap stations. */
final class CircuitCurves
{
	private const SIDE_ERROR = 3.;     // Meters in the horizontal plane.
	private const HEIGHT_ERROR = .5;   // Meters along the vertical axis.
	private const BANK_ERROR = .025;    // Cross slope, rise over run.

	private array $tangents = [];

	private function __construct(private array $points, private array $banks)
	{
		$n = count($points);
		foreach($points as $i => $_) {
			$this->tangents[] = array_map(static fn($next,$prev) => ($next-$prev)/2.,
				$points[($i+1)%$n],$points[($i+$n-1)%$n]);
		}
	}

	/** Keep enough spare controls in each record for the author to split and reshape bends. */
	public static function sections(array $points, array $banks): \Generator
	{
		$fit = new self($points,$banks);
		$n = count($points);
		$knots = [0];
		for($quarter=1; $quarter<=4; $quarter++) {
			yield from $fit->extend($knots,intdiv($quarter*$n,4));
		}
		if(count($knots)<=96) return [$fit->record($knots)];

		// Each road has a rounded grading footprint. Join sections on locally planar
		// pavement so a cap meeting the next section carries the same grade and bank.
		array_pop($knots);
		$spans = count($knots);
		$count = (int)ceil($spans/64);
		$joints = [];
		$window = intdiv(intdiv($spans,$count),4);
		for($i=0; $i<$count; $i++) {
			$nominal = intdiv($i*$spans,$count);
			$best = INF;
			for($j=$nominal-$window; $j<=$nominal+$window; $j++) {
				$k = ($j+$spans)%$spans;
				$error = $fit->jointError($knots[$k]);
				if($error < $best) { $best = $error; $joint = $k; }
			}
			$joints[] = $joint;
		}
		sort($joints);
		$sections = [];
		foreach($joints as $i=>$start) {
			$end = $joints[$i+1] ?? $joints[0]+$spans;
			$ids = [];
			for($j=$start; $j<=$end; $j++) $ids[] = $knots[$j%$spans]+($j >= $spans ? $n : 0);
			$sections[] = $fit->record($ids);
		}
		return $sections;
	}

	/** Worst deviation of nearby lane heights from the junction's tangent plane. */
	private function jointError(int $j): float
	{
		$n = count($this->points);
		[$x,$y,$z] = $this->points[$j];
		[$dx,$dy,$dz] = $this->tangents[$j];
		$run = max(.001,hypot($dx,$dz));
		$tx = $dx/$run; $tz = $dz/$run;
		$grade = $dy/$run; $bank = $this->banks[$j];
		$gx = $grade*$tx-$bank*$tz; $gz = $grade*$tz+$bank*$tx;
		$error = 0.;
		foreach([-2,-1,1,2] as $offset) {
			$i = ($j+$n+$offset)%$n;
			[$px,$py,$pz] = $this->points[$i];
			[$vx,,$vz] = $this->tangents[$i];
			$speed = max(.001,hypot($vx,$vz));
			$heightError = $py-$y-$gx*($px-$x)-$gz*($pz-$z);
			$bankError = $this->banks[$i]-(-$gx*$vz+$gz*$vx)/$speed;
			$error = max($error,(abs($heightError)+(CircuitRoad::WIDTH/2)*abs($bankError))/abs($offset));
		}
		return $error;
	}

	/** Shortest path over accepted spans. Trying different endpoints keeps bends from being split needlessly. */
	private function extend(array &$knots, int $end): \Generator
	{
		$start = end($knots);
		$cost = [$end=>0]; $next = [];
		for($from=$end-1; $from>=$start; $from--) {
			if(($end-$from)%4===0) yield;
			$next[$from] = $from+1; $cost[$from] = 1+$cost[$from+1];
			for($to=min($end,$from+64); $to>$from+1; $to--) {
				if($cost[$to]+1 >= $cost[$from] || !$this->fits($from,$to)) continue;
				$cost[$from] = $cost[$to]+1;
				$next[$from] = $to;
			}
		}
		while($start<$end) $knots[] = $start = $next[$start];
	}

	private function fits(int $start, int $end): bool
	{
		$n = count($this->points); $length = $end-$start;
		$a = $this->points[$start]; $z = $end%$n;
		// Coefficients of the candidate Hermite cubic in increasing powers of t.
		$b = $c = $d = [];
		foreach($a as $axis=>$v) {
			$b[] = $this->tangents[$start][$axis]*$length;
			$last = $this->tangents[$z][$axis]*$length;
			$delta = $this->points[$z][$axis]-$v;
			$c[] = 3*$delta-2*$b[$axis]-$last;
			$d[] = $b[$axis]+$last-2*$delta;
		}
		for($i=$start; $i<=$end; $i++) {
			$j = $i%$n; $t = ($i-$start)/$length;
			$bank = $this->banks[$start]+($this->banks[$z]-$this->banks[$start])*$t;
			if(abs($bank-$this->banks[$j])>self::BANK_ERROR) return false;
			// On each original span the difference is itself a cubic Bézier.
			// Its four control vectors bound the entire difference curve by convexity.
			foreach($a as $axis=>$v) {
				$error[$axis] = $v+$t*($b[$axis]+$t*($c[$axis]+$t*$d[$axis]))-$this->points[$j][$axis];
				$handle[$axis] = (($b[$axis]+$t*(2*$c[$axis]+3*$t*$d[$axis]))/$length-$this->tangents[$j][$axis])/3.;
			}
			if(!self::within($error)) return false;
			$incoming = $outgoing = [];
			foreach($error as $axis=>$v) {
				$incoming[] = $v-$handle[$axis];
				$outgoing[] = $v+$handle[$axis];
			}
			if($i>$start && (!self::within($previousOut) || !self::within($incoming))) {
				// Subdivision tightens the convex hull at the corners without relaxing the limits.
				$ab = $bc = $cd = $abc = $bcd = [];
				foreach($error as $axis=>$v) {
					$ab[] = .5*($previous[$axis]+$previousOut[$axis]);
					$bc[] = .5*($previousOut[$axis]+$incoming[$axis]);
					$cd[] = .5*($incoming[$axis]+$v);
					$abc[] = .5*($ab[$axis]+$bc[$axis]);
					$bcd[] = .5*($bc[$axis]+$cd[$axis]);
				}
				if(!self::within($ab) || !self::within($abc) || !self::within($bcd) || !self::within($cd)) return false;
			}
			$previous = $error;
			$previousOut = $outgoing;
		}
		return true;
	}

	private static function within(array $v): bool
	{
		return abs($v[1])<=self::HEIGHT_ERROR && $v[0]*$v[0]+$v[2]*$v[2]<=self::SIDE_ERROR**2;
	}

	private function record(array $knots): array
	{
		$n = count($this->points);
		$points = $banks = $handles = [];
		foreach($knots as $i=>$k) {
			$j = ($k+$n)%$n;
			$points[] = $this->points[$j];
			$banks[] = $this->banks[$j];
			$pair = [];
			foreach(['in'=>$i-1,'out'=>$i+1] as $side=>$adjacent) {
				if(isset($knots[$adjacent])) {
					$scale = ($knots[$adjacent]-$k)/3.;
					$pair[$side] = array_map(static fn($v) => $v*$scale,$this->tangents[$j]);
				}
			}
			$handles[] = $pair;
		}
		return compact('points','banks','handles');
	}
}

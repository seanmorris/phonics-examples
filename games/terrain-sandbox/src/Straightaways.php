<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Find forward lines that pass through consecutive bends inside the same paved strip. */
final class Straightaways
{
	public const CLEARANCE = Car::BODY_HALF_WIDTH + .6;

	public static function fit(array $points, array $stations, array $normals, array $bounds, Landscape $land): array
	{
		$count = count($points);
		$portals = $leftTurn = $rightTurn = [];
		$left = $right = 0.;
		for($i = 0; $i < 2*$count; $i++) {
			$j = $i%$count;
			[$x, $z, $angle] = $stations[$j];
			if($i < $count) {
				[$nx, $nz] = $normals[$j];
				[$lower, $upper] = $bounds[$j];
				$portals[] = [$x+$lower*$nx, $z+$lower*$nz, $x+$upper*$nx, $z+$upper*$nz];
			}
			$change = $angle-$stations[($j+$count-1)%$count][2];
			$change = atan2(sin($change), cos($change));
			$left += max(0., -$change);
			$right += max(0., $change);
			$leftTurn[] = $left;
			$rightTurn[] = $right;
		}
		// Overlapping windows find long straights first, including ones spanning the timing line.
		$result = $points;
		$used = array_fill(0, $count, false);
		for($span = intdiv($count, 2); $span >= 8; $span = intdiv($span, 2)) {
			$candidates = [];
			for($first = 0; $first < $count; $first+=max(1, intdiv($span, 4))) {
				$last = $first+$span;
				if($leftTurn[$last]-$leftTurn[$first] < .10 || $rightTurn[$last]-$rightTurn[$first] < .10) continue;
				for($j = $first; $j <= $last; $j++) if($used[$j%$count]) continue 2;
				$lane = self::lane($portals, $first, $last);
				if($lane === null) continue;
				[$nx, $nz, $offset] = $lane;
				// Extend a verified heading in both directions until an edge or an earlier straight constrains it.
				$begin = $first + $count;
				$end = $last + $count;
				// Limit retries around an obstruction as the search moves to smaller windows.
				$limit = min(intdiv($count, 2), 3*$span);
				while($end-$begin < $limit && !$used[($begin-1)%$count] && self::admits($portals[($begin-1)%$count], $nx, $nz, $offset)) $begin--;
				while($end-$begin < $limit && !$used[($end+1)%$count] && self::admits($portals[($end+1)%$count], $nx, $nz, $offset)) $end++;
				$candidates[] = [$end-$begin, $begin, $end, $nx, $nz, $offset];
			}
			rsort($candidates);
			foreach($candidates as [, $first, $last, $nx, $nz, $offset]) {
				for($j = $first; $j <= $last; $j++) if($used[$j%$count]) continue 2;
				$path = self::path($points, $portals, $first, $last, $nx, $nz, $offset);
				if(!$path || !self::holdsPace($path, $points, $first) || !self::paved($path, $land)) continue;
				foreach($path as $k => $position) {
					$i = ($first+$k)%$count;
					$result[$i] = $position;
					$used[$i] = true;
				}
			}
		}
		return $result;
	}

	private static function admits(array $edges, float $nx, float $nz, float $offset): bool
	{
		return $edges[0]*$nx+$edges[1]*$nz <= $offset && $edges[2]*$nx+$edges[3]*$nz >= $offset;
	}

	/** Choose the heading and lateral offset with the widest common opening across all cross sections. */
	private static function lane(array $portals, int $first, int $last): ?array
	{
		$count = count($portals);
		$a = $portals[$first%$count];
		$b = $portals[$last%$count];
		$dx = .5*($b[0]+$b[2]-$a[0]-$a[2]);
		$dz = .5*($b[1]+$b[3]-$a[1]-$a[3]);
		$length = hypot($dx, $dz);
		if($length < 45.) return null;
		$nx = $dz/$length;
		$nz = -$dx/$length;
		// The end openings bound how far the heading can turn from their center-to-center bearing.
		$bound = min(.5, (hypot($a[2]-$a[0], $a[3]-$a[1])+hypot($b[2]-$b[0], $b[3]-$b[1]))/$length);
		$lower = -$bound;
		$upper = $bound;
		$slope = 0.;
		$best = -INF;
		$lane = null;
		for($pass = 0; $pass < 10; $pass++) {
			$x = $nx+$slope*$nz;
			$z = $nz-$slope*$nx;
			$left = -INF;
			$right = INF;
			for($i = $first; $i <= $last; $i++) {
				$portal = $portals[$i%$count];
				$leftEdge = $portal[0]*$x+$portal[1]*$z;
				$rightEdge = $portal[2]*$x+$portal[3]*$z;
				if($leftEdge > $left) {
					$left = $leftEdge;
					$leftAlong = -$portal[0]*$nz+$portal[1]*$nx;
				}
				if($rightEdge < $right) {
					$right = $rightEdge;
					$rightAlong = -$portal[2]*$nz+$portal[3]*$nx;
				}
			}
			$width = $right-$left;
			if($width >= 0. && $width > $best) {
				$scale = hypot($x, $z);
				$lane = [$x/$scale, $z/$scale, .5*($left+$right)/$scale];
				$best = $width;
			}
			// min(right) - max(left) is concave in slope. Its active edges give its derivative.
			$possibleGain = ($leftAlong-$rightAlong) * ($leftAlong > $rightAlong ? $upper-$slope : $lower-$slope);
			if($width+$possibleGain < 0. || ($lane !== null && $width+$possibleGain <= $best+.02) || abs($leftAlong-$rightAlong) < .001) break;
			if($leftAlong > $rightAlong) $lower = $slope; else $upper = $slope;
			$slope = .5*($lower+$upper);
		}
		return $lane;
	}

	private static function path(array $points, array $portals, int $first, int $last, float $nx, float $nz, float $offset): array
	{
		$count = count($points);
		$targets = [];
		$station = [];
		for($i = $first; $i <= $last; $i++) {
			[$lx, $lz, $rx, $rz] = $portals[$i%$count];
			$across = ($rx-$lx)*$nx+($rz-$lz)*$nz;
			$t = $across > 1e-6 ? ($offset-$lx*$nx-$lz*$nz)/$across : 0.;
			$x = $lx+$t*($rx-$lx);
			$z = $lz+$t*($rz-$lz);
			$along = -$x*$nz+$z*$nx;
			// A shortcut must cross every section forward, including near parallel legs of a hairpin.
			if($station && $along <= $station[count($station)-1]) return [];
			$station[] = $along;
			$targets[] = [$x, $z];
		}
		// Join near a point where the existing racing line already approaches this heading.
		// This leaves the high-speed entry and exit of an actual corner intact.
		$lastPoint = count($station)-1;
		$length = $station[$lastPoint]-$station[0];
		$reach = min(360., .20*$length);
		$begin = $finish = 0.;
		$bestBegin = $bestEnd = INF;
		foreach($targets as $j => [$x, $z]) {
			$i = ($first+$j)%$count;
			[$px, $pz] = $points[$i];
			[$qx, $qz] = $points[($i+1)%$count];
			$angle = abs(($qx-$px)*$nx+($qz-$pz)*$nz)/max(.01, hypot($qx-$px, $qz-$pz));
			$cost = hypot($px-$x, $pz-$z)+40.*$angle;
			if($station[$j]-$station[0] <= $reach && $cost < $bestBegin) {
				$begin = $station[$j];
				$bestBegin = $cost;
			}
			if($station[$lastPoint]-$station[$j] <= $reach && $cost < $bestEnd) {
				$finish = $station[$j];
				$bestEnd = $cost;
			}
		}
		$transition = min(200., .23*($finish-$begin));
		foreach($targets as $j => [$x, $z]) {
			$u = max(0., min(1., min($station[$j]-$begin, $finish-$station[$j])/$transition));
			$blend = $u**3*(10.+$u*(-15.+6.*$u));
			[$px, $pz] = $points[($first+$j)%$count];
			$targets[$j] = [$px+$blend*($x-$px), $pz+$blend*($z-$pz)];
		}
		return $targets;
	}

	/** A straight that adds a sharp turn on entry or exit can require more braking than the original bends. */
	private static function holdsPace(array $path, array $points, int $start): bool
	{
		$count = count($points);
		$original = [];
		for($k = -2; $k < count($path)+2; $k++) $original[] = $points[($start+$k+$count)%$count];
		$joined = [$original[0], $original[1], ...$path, ...array_slice($original, -2)];
		// Prefer holding one heading when both estimates are within one percent.
		return self::travelTime($joined) <= 1.01 * self::travelTime($original);
	}

	private static function travelTime(array $path): float
	{
		$speed = [];
		$lengths = [];
		$count = count($path);
		for($i = 1; $i < $count-1; $i++) {
			[$ax, $az] = $path[$i-1];
			[$x, $z] = $path[$i];
			[$bx, $bz] = $path[$i+1];
			$dx = $bx-$x;
			$dz = $bz-$z;
			$distance = hypot($dx, $dz);
			$lengths[] = $distance;
			$area = abs(($x-$ax)*$dz-($z-$az)*$dx);
			$radius = hypot($x-$ax, $z-$az)*$distance*hypot($bx-$ax, $bz-$az)/max(.00001, 2.*$area);
			$speed[] = min(Car::TOP_SPEED_MPH*Car::MPS_PER_MPH, sqrt(9.2*$radius));
		}
		// Propagate braking backward at 12 m/s², then acceleration forward at 7 m/s².
		for($i = count($speed)-2; $i >= 0; $i--) {
			$speed[$i] = min($speed[$i], sqrt($speed[$i+1]**2+24.*$lengths[$i]));
		}
		$time = 0.;
		foreach($lengths as $i => $distance) {
			if($i) $speed[$i] = min($speed[$i], sqrt($speed[$i-1]**2+14.*$lengths[$i-1]));
			$time+=$distance/max(1., $speed[$i]);
		}
		return $time;
	}

	/** Test the swept line between the portals, including both tire tracks across surface edits. */
	private static function paved(array $path, Landscape $land): bool
	{
		$clear = self::CLEARANCE;
		$extent = $land->recipe()['worldSize']/2;
		for($i = 1, $end = count($path); $i < $end; $i++) {
			[$x, $z] = $path[$i-1];
			[$bx, $bz] = $path[$i];
			$dx = $bx-$x;
			$dz = $bz-$z;
			$length = hypot($dx, $dz);
			if($length < .001) continue;
			$nx = $clear*$dz/$length;
			$nz = -$clear*$dx/$length;
			$steps = (int)ceil($length/2.);
			for($k = 0; $k <= $steps; $k++) {
				$t = $k/$steps;
				$px = $x+$t*$dx;
				$pz = $z+$t*$dz;
				if(abs($px)+abs($nx) > $extent || abs($pz)+abs($nz) > $extent || RacingLine::edgeDistance($land, $px, $pz) < $clear
					|| RacingLine::edgeDistance($land, $px+$nx, $pz+$nz) <= 0. || RacingLine::edgeDistance($land, $px-$nx, $pz-$nz) <= 0.) return false;
			}
		}
		return true;
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Locate an off-road car on the approach it is currently racing. Used for stick-click recovery and flooded engines. */
final class RoadRecovery
{
	private const GATE_CLEARANCE = 10.;

	public static function site(Car $car, Landscape $land, float $water, Course $course, Race $race, ?Circuit $circuit,
		bool $flooded = false): ?array
	{
		$pose = $car->state();
		$x = $pose['x']; $z = $pose['z'];
		if(!$flooded && $land->material($x,$z,$offset,$mark) === 'road' && ($mark === null ? $offset === null : abs($mark[0]) <= $mark[2]))
			return null;

		$next = $race->nextGate($course);
		$previous = $race->previousGate($course);
		if($previous === null || $next['kind'] !== 'road') return null;
		$previous['halfWidth'] = $course->measure($previous)['window']['halfWidth'];
		$next['halfWidth'] = $course->measure($next)['window']['halfWidth'];

		$candidates = [];
		$paths = [];
		foreach($land->roads() as $road) {
			$line = [];
			foreach($road['_road']['samples'] as [$px,,$pz]) $line[] = [$px,$pz];
			if(!$flooded && $road['material'] !== 'road' && self::withinLane($line,$x,$z,$road['width']/2)) return null;
			// Generated circuits are stored in contiguous spline sections. Separate roads stay separate.
			$last = array_key_last($paths);
			if($last !== null && self::joined(end($paths[$last]),$line[0]))
				array_push($paths[$last], ...array_slice($line,1));
			else $paths[] = $line;
		}
		foreach($paths as $line) self::approach($line,$previous,$next,$x,$z,$candidates);
		if(!$candidates && $circuit !== null && (!$paths || $circuit->featureArea === null)) {
			// Unpaved natural circuits have a driving corridor as wide as the gate.
			$line = [...$circuit->points,$circuit->points[0]];
			if(!$flooded && self::withinLane($line,$x,$z,$next['halfWidth'])) return null;
			self::approach($line,$previous,$next,$x,$z,$candidates);
		}
		sort($candidates);
		$posts = $course->posts();
		foreach($candidates as [,$px,$pz,$yaw]) {
			$site = $car->checkedSite($land,$water,$px,$pz,$yaw,$posts);
			if($site !== null) return $site;
		}
		return null;
	}

	private static function withinLane(array $line, float $x, float $z, float $halfWidth): bool
	{
		for($i = 1; $i < count($line); $i++) {
			[$ax,$az] = $line[$i-1]; [$bx,$bz] = $line[$i];
			$dx = $bx-$ax; $dz = $bz-$az;
			$t = max(0.,min(1.,(($x-$ax)*$dx+($z-$az)*$dz)/max(1e-12,$dx*$dx+$dz*$dz)));
			if(hypot($x-$ax-$t*$dx,$z-$az-$t*$dz) <= $halfWidth) return true;
		}
		return false;
	}

	private static function joined(array $a, array $b): bool
	{
		return hypot($a[0]-$b[0],$a[1]-$b[1]) < .01;
	}

	/** Distance along the spline where it crosses the opening, and which way to drive through it. */
	private static function crossing(array $line, array $stations, array $gate): ?array
	{
		$s = sin($gate['yaw']); $c = cos($gate['yaw']);
		$best = null; $nearest = INF;
		foreach($line as $i => [$x,$z]) {
			if(!isset($line[$i+1])) break;
			[$bx,$bz] = $line[$i+1];
			$a = ($x-$gate['x'])*$s + ($z-$gate['z'])*$c;
			$b = ($bx-$gate['x'])*$s + ($bz-$gate['z'])*$c;
			if(abs($b-$a)<1e-8 || $a*$b > 0.) continue;
			$t = $a/($a-$b);
			$lateral = abs(($x+$t*($bx-$x)-$gate['x'])*$c - ($z+$t*($bz-$z)-$gate['z'])*$s);
			if($lateral > $gate['halfWidth'] || $lateral >= $nearest) continue;
			$nearest = $lateral;
			$best = [$stations[$i]+$t*($stations[$i+1]-$stations[$i]), $b>$a ? 1 : -1];
		}
		return $best;
	}

	/** Clip each road segment to the traveled leg, then project the car onto its eligible portion. */
	private static function approach(array $line, array $previous, array $next, float $x, float $z, array &$candidates): void
	{
		$stations = [0.]; $length = 0.;
		for($i = 1; $i < count($line); $i++) {
			$length += hypot($line[$i][0]-$line[$i-1][0],$line[$i][1]-$line[$i-1][1]);
			$stations[] = $length;
		}
		$end = self::crossing($line,$stations,$next);
		if($end === null) return;
		[$at,$dir] = $end;
		$closed = self::joined($line[0],end($line));
		$start = self::crossing($line,$stations,$previous);
		$leg = $closed ? $length : ($dir > 0 ? $at : $length-$at);
		if($start !== null && $start[1] === $dir) {
			$leg = ($at-$start[0])*$dir;
			if($leg <= 0. && $closed) $leg += $length;
		}
		// If this leg crosses the lap seam it occupies two intervals in the stored spline.
		if($leg < 0.) return;
		// Very close gates can put the ten-meter line just before the previous checkpoint.
		$a = $at-$dir*max(self::GATE_CLEARANCE,$leg);
		$b = $at-$dir*self::GATE_CLEARANCE;
		$lo = min($a,$b); $hi = max($a,$b);
		$ranges = [[$lo,$hi]];
		if($lo < 0. && $closed) $ranges[] = [$lo+$length,$hi+$length];
		if($hi > $length && $closed) $ranges[] = [$lo-$length,$hi-$length];
		foreach($ranges as [$lo,$hi]) {
			for($i = 1; $i<count($line); $i++) {
				$from = max($lo,$stations[$i-1]); $to = min($hi,$stations[$i]);
				$span = $stations[$i]-$stations[$i-1];
				if($from > $to || $span < 1e-8) continue;
				[$ax,$az] = $line[$i-1]; [$bx,$bz] = $line[$i];
				$ux = ($bx-$ax)/$span; $uz = ($bz-$az)/$span;
				$d = max($from-$stations[$i-1],min($to-$stations[$i-1],($x-$ax)*$ux+($z-$az)*$uz));
				$px = $ax+$d*$ux; $pz = $az+$d*$uz;
				$candidates[] = [($x-$px)**2+($z-$pz)**2,$px,$pz,atan2($dir*$ux,$dir*$uz)];
			}
		}
	}
}

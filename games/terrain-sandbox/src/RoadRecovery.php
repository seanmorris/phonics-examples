<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Find a safe restart on the active race leg or the current journey highway. */
final class RoadRecovery
{
	private const GATE_CLEARANCE = 10.;

	public static function site(Car $car, Landscape $land, float $water, Course $course, Race $race, ?Circuit $circuit,
		bool $flooded = false): ?array
	{
		$pose = $car->state();
		$x = $pose['x']; $z = $pose['z'];
		if(!$flooded && Car::onPavement($land,$x,$z)) return null;

		$next = $race->nextGate($course);
		$previous = $race->previousGate($course);
		if($previous === null || $next === null) return null;
		$previous['halfWidth'] = $course->measure($previous)['window']['halfWidth'];
		$next['halfWidth'] = $course->measure($next)['window']['halfWidth'];

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
		$groups = ['roads'=>$paths];
		if($circuit !== null) $groups['circuit'] = [[...$circuit->points,$circuit->points[0]]];
		$posts = $course->posts();
		$passed = null;
		foreach($groups as $group => $paths) {
			// The stored circuit can also describe a natural, unpaved course with no matching authored road.
			if($group === 'circuit' && !$flooded && self::withinLane($paths[0],$x,$z,$next['halfWidth'])) return null;
			$stations = $candidates = [];
			$matched = false;
			foreach($paths as $i => $line) {
				$stations[$i] = self::stations($line);
				if(self::approach($line,$stations[$i],$previous,$next,$x,$z,$candidates)) $matched = true;
			}
			$site = self::drySite($candidates,$car,$land,$water,$posts);
			if($site !== null) return $site;

			// A flooded or disconnected leg may have usable road behind an earlier crossing.
			$passed ??= iterator_to_array($race->passedGates($course),false);
			foreach($passed as $gate) {
				if(!$paths) break;
				$candidates = [];
				$gate['halfWidth'] = $course->measure($gate)['window']['halfWidth'];
				foreach($paths as $i => $line) {
					if(self::approach($line,$stations[$i],null,$gate,$gate['x'],$gate['z'],$candidates)) {
						$matched = true;
						// Rewinding from this gate checks the whole loop once.
						if(self::joined($line[0],end($line))) unset($paths[$i]);
					}
				}
				$site = self::drySite($candidates,$car,$land,$water,$posts,$next);
				if($site !== null) return $site;
			}
			// A dry chord of the saved racing line can cut across dirt beside a flooded spline.
			if($matched) return null;
		}
		if(!$flooded) return null;

		// Hand-placed gates can define a course without a spline. Restart in a cleared gate's approach.
		foreach($passed as $gate) {
			$candidates = [];
			foreach([0.,self::GATE_CLEARANCE,2*self::GATE_CLEARANCE] as $back)
				$candidates[] = [$back,$gate['x']-$back*sin($gate['yaw']),$gate['z']-$back*cos($gate['yaw']),$gate['yaw']];
			$site = self::drySite($candidates,$car,$land,$water,$posts,$next);
			if($site !== null) return $site;
		}
		return null;
	}

	/** Journey splines run toward their destination. Search their separate sections by distance
	 * so a click beside a fork can use either branch without scanning the whole highway first. */
	public static function journeySite(Car $car, Landscape $land, float $water, array $posts): ?array
	{
		$pose = $car->state();
		$x = $pose['x']; $z = $pose['z'];
		if(Car::onPavement($land,$x,$z) || $land->bounds()['maxY'] <= $water) return null;

		$half = $land->recipe()['worldSize']/2;
		$roads = $land->roads();
		$queue = new \SplPriorityQueue;
		foreach($roads as $i => $road) {
			if($road['material'] !== 'road') continue;
			[$left,$top,$right,$bottom] = $road['_road']['bounds'];
			$dx = max($left-$x,0.,$x-$right); $dz = max($top-$z,0.,$z-$bottom);
			$queue->insert($i,-($dx*$dx+$dz*$dz));
		}
		while(!$queue->isEmpty()) {
			$item = $queue->extract();
			if(is_int($item)) {
				$samples = $roads[$item]['_road']['samples'];
				[$ax,,$az] = $samples[0];
				for($i=1;$i<count($samples);$i++) {
					[$bx,,$bz] = $samples[$i];
					$dx = $bx-$ax; $dz = $bz-$az;
					$span = $dx*$dx+$dz*$dz;
					if($span > 0.) {
						$t = max(0.,min(1.,(($x-$ax)*$dx+($z-$az)*$dz)/$span));
						$px = $ax+$t*$dx; $pz = $az+$t*$dz;
						$queue->insert([$px,$pz,$dx,$dz],-(($px-$x)**2+($pz-$z)**2));
					}
					$ax = $bx; $az = $bz;
				}
				continue;
			}
			[$px,$pz,$dx,$dz] = $item;
			if(max(abs($px),abs($pz)) > $half || $land->height($px,$pz) <= $water) continue;
			$site = $car->checkedSite($land,$water,$px,$pz,atan2($dx,$dz),$posts);
			if($site !== null && Car::onPavement($land,$px,$pz)) return $site;
		}
		return null;
	}

	private static function drySite(array $candidates, Car $car, Landscape $land, float $water, array $posts,
		?array $pending = null): ?array
	{
		sort($candidates);
		$half = $land->recipe()['worldSize']/2;
		if($pending !== null) { $s = sin($pending['yaw']); $c = cos($pending['yaw']); }
		foreach($candidates as [,$px,$pz,$yaw]) {
			if($pending !== null) {
				// Older legs can pass close to the open gate on a hairpin or a short lap.
				$dx = $px-$pending['x']; $dz = $pz-$pending['z'];
				$side = max(0.,abs($dx*$c-$dz*$s)-$pending['halfWidth']);
				if(($dx*$s+$dz*$c)**2+$side**2 < self::GATE_CLEARANCE**2-1e-8) continue;
			}
			if(max(abs($px),abs($pz)) > $half || $land->height($px,$pz) <= $water) continue;
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

	private static function stations(array $line): array
	{
		$stations = [0.];
		for($i = 1; $i < count($line); $i++)
			$stations[] = $stations[$i-1]+hypot($line[$i][0]-$line[$i-1][0],$line[$i][1]-$line[$i-1][1]);
		return $stations;
	}

	/** Project onto the active leg, or search upstream in road order when rewinding from a cleared gate. */
	private static function approach(array $line, array $stations, ?array $previous, array $next, float $x, float $z,
		array &$candidates): bool
	{
		$end = self::crossing($line,$stations,$next);
		$length = end($stations);
		$closed = self::joined($line[0],end($line));
		$start = $previous === null ? null : self::crossing($line,$stations,$previous);
		$partial = $end === null;
		if($partial) {
			// A hand-laid road can leave the cleared gate and end short of the next one.
			if($start === null || $closed) return false;
			$dir = $start[1]; $at = $dir > 0 ? $length : 0.;
			$s = sin($next['yaw']); $c = cos($next['yaw']);
		} else [$at,$dir] = $end;
		$leg = $closed ? $length : ($dir > 0 ? $at : $length-$at);
		if($start !== null && $start[1] === $dir) {
			$leg = ($at-$start[0])*$dir;
			if($leg <= 0. && $closed) $leg += $length;
		}
		// If this leg crosses the lap seam it occupies two intervals in the stored spline.
		if($leg < 0.) return true;
		// Very close gates can put the ten-meter line just before the previous checkpoint.
		$clearance = $previous === null || $partial ? 0. : self::GATE_CLEARANCE;
		$a = $at-$dir*max($clearance,$leg);
		$b = $at-$dir*$clearance;
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
				$yaw = atan2($dir*$ux,$dir*$uz);
				$projected = $stations[$i-1]+($x-$ax)*$ux+($z-$az)*$uz;
				// An unpaved circuit can have a dry stretch between distant vertices.
				$pieces = max(1,(int)ceil(($to-$from)/4.));
				for($j = 0; $j < $pieces; $j++) {
					$low = $from+($to-$from)*$j/$pieces;
					$high = $from+($to-$from)*($j+1)/$pieces;
					$station = $previous !== null ? max($low,min($high,$projected)) : ($dir > 0 ? $high : $low);
					$d = $station-$stations[$i-1];
					$px = $ax+$d*$ux; $pz = $az+$d*$uz;
					if($partial && ($px-$next['x'])*$s+($pz-$next['z'])*$c > -self::GATE_CLEARANCE) continue;
					$rank = ($x-$px)**2+($z-$pz)**2;
					if($previous === null) {
						$rank = $dir*($at-$station);
						if($rank < 0.) $rank += $length;
					}
					$candidates[] = [$rank,$px,$pz,$yaw];
				}
			}
		}
		return true;
	}
}

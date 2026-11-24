<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Fit a smooth closed driving line inside the painted road and the openings of its gates. */
final class RacingLine
{
	// Leave room for the body swinging out under handbrake and for steering error.
	private const CLEARANCE = Car::BODY_HALF_WIDTH + 1.8;

	/** The returned points stay in circuit order, including across the start/finish seam. */
	public static function fit(array $stations, float $spacing, Landscape $land, Course $course): array
	{
		$count = count($stations);
		$extent = $land->recipe()['worldSize'] / 2;
		$edits = $land->editIndex(false);
		$normals = $bounds = $straightBounds = $points = [];
		foreach($stations as $i => [$x,$z,$yaw]) {
			[$px,$pz] = $stations[($i+$count-1)%$count];
			[$qx,$qz] = $stations[($i+1)%$count];
			$length = hypot($qx-$px,$qz-$pz);
			[$nx,$nz] = $length > .001 ? [($qz-$pz)/$length,($px-$qx)/$length] : [cos($yaw),-sin($yaw)];
			$normals[] = [$nx,$nz];
			$points[] = [$x,$z];
			$low = $high = $straightLow = $straightHigh = 0.;
			if($land->material($x,$z,$offset,$mark) === 'road' && $mark !== null && $offset !== null) {
				// A disc around the nearest point on the painted spline fits even on a skew crossing.
				// The shoulder contributes to grading, but ends outside the asphalt half-width in mark[2].
				$across = $offset[0]*$nx + $offset[1]*$nz;
				$along = $offset[0]*$nz - $offset[1]*$nx;
				$room = max(0., $mark[2] - self::CLEARANCE);
				$half = sqrt(max(0., $room*$room - $along*$along));
				$low = -$across-$half; $high = -$across+$half;
				// A car holding one heading needs less side clearance than one sliding into a bend.
				$room = max(0.,$mark[2]-Straightaways::CLEARANCE);
				$half = sqrt(max(0.,$room*$room-$along*$along));
				$straightLow = -$across-$half; $straightHigh = -$across+$half;
				// Prevent a wide inside lane from folding back across the center of a tight hairpin.
				$tx = $qx-$px; $tz = $qz-$pz;
				$bend = ($x-$px)*$tz - ($z-$pz)*$tx;
				if(abs($bend) > .01) {
					$radius = hypot($x-$px,$z-$pz) * hypot($qx-$x,$qz-$z) * $length / (2.*abs($bend));
					if($bend < 0.) $high = min($high,.75*$radius);
					else $low = max($low,-.75*$radius);
				}
				foreach([[$x,$nx],[$z,$nz]] as [$pos,$axis]) {
					if(abs($axis) < .0001) continue;
					$a = (-$extent-$pos)/$axis; $b = ($extent-$pos)/$axis;
					$min = min($a,$b); $max = max($a,$b);
					$low = max($min,min($max,$low)); $high = max($min,min($max,$high));
					$straightLow = max($min,min($max,$straightLow)); $straightHigh = max($min,min($max,$straightHigh));
				}
				// A later brush may cut a hole inside the nominal asphalt. Keep the opening connected to the route.
				$reach = max(abs($straightLow),abs($straightHigh)) + $spacing/2 + self::CLEARANCE;
				if(!$edits->isEmpty() && $edits->intersect([$x-$reach,$z-$reach,$x+$reach,$z+$reach])) {
					foreach([-1,1] as $side) {
						$edge = $side < 0 ? -$straightLow : $straightHigh;
						$limit = self::paintedEdge($land,$x,$z,$nx,$nz,$side,$edge,$spacing,$extent);
						if($limit === null) continue;
						$cornerRoom = max(0.,$limit-self::CLEARANCE);
						$straightRoom = max(0.,$limit-Straightaways::CLEARANCE);
						if($side < 0) { $low = max($low,-$cornerRoom); $straightLow = max($straightLow,-$straightRoom); }
						else { $high = min($high,$cornerRoom); $straightHigh = min($straightHigh,$straightRoom); }
					}
				}
			}
			$bounds[] = [$low,$high];
			$straightBounds[] = [$straightLow,$straightHigh];
		}
		self::fitGates($stations,$normals,$bounds,$straightBounds,$course,$spacing);

		// Minimize changes of heading by moving only sideways. Broad spans set up the entry and exit,
		// then shorter spans smooth the apex and transitions where the road narrows.
		$spans = array_map(static fn($meters) => max(1,min(intdiv($count,4),(int)round($meters/$spacing))), [84,48,24,12,0]);
		foreach(array_unique($spans) as $span) {
			for($pass = 0; $pass < 6; $pass++) {
				$next = [];
				foreach($points as $i => [$x,$z]) {
					[$cx,$cz] = $stations[$i];
					[$nx,$nz] = $normals[$i];
					[$low,$high] = $bounds[$i];
					[$ax,$az] = $points[($i+$count-$span)%$count]; [$bx,$bz] = $points[($i+$span)%$count];
					[$px,$pz] = $points[($i+$count-2*$span)%$count]; [$qx,$qz] = $points[($i+2*$span)%$count];
					// The fourth difference vanishes on a locally smooth line.
					$dx = (4*($ax+$bx)-$px-$qx)/6 - $x; $dz = (4*($az+$bz)-$pz-$qz)/6 - $z;
					$lateral = (($x-$cx)+.48*$dx)*$nx + (($z-$cz)+.48*$dz)*$nz;
					$lateral = max($low,min($high,$lateral));
					$next[] = [$cx+$lateral*$nx,$cz+$lateral*$nz];
				}
				$points = $next;
			}
		}
		// At section joins a later, narrower spline or surface edit can cover the nominal edge.
		// If that happens, clip the proposed shortcut back toward the known route.
		foreach($points as $i => [$x,$z]) {
			[$cx,$cz] = $stations[$i];
			if(abs($x-$cx)+abs($z-$cz) < .01 || self::edgeDistance($land,$x,$z) >= self::CLEARANCE - .01) continue;
			$required = min(self::CLEARANCE,self::edgeDistance($land,$cx,$cz));
			if($required <= 0.) { $points[$i] = [$cx,$cz]; continue; }
			$inside = 0.; $outside = 1.;
			for($j = 0; $j < 7; $j++) {
				$t = .5*($inside+$outside);
				if(self::edgeDistance($land,$cx+$t*($x-$cx),$cz+$t*($z-$cz)) >= $required) $inside = $t;
				else $outside = $t;
			}
			// At a painted boundary the material can change abruptly, with no spline-edge distance to warn the driver.
			$inside = max(0.,$inside - Car::BODY_HALF_WIDTH/hypot($x-$cx,$z-$cz));
			$points[$i] = [$cx+$inside*($x-$cx),$cz+$inside*($z-$cz)];
		}
		return Straightaways::fit($points,$stations,$normals,$straightBounds,$land);
	}

	/** Walk outward from the route over the area represented by this cross section. */
	private static function paintedEdge(Landscape $land, float $x, float $z, float $nx, float $nz,
		int $side, float $edge, float $spacing, float $extent): ?float
	{
		for($d = 0.; $d <= $edge+self::CLEARANCE; $d += 1.) {
			foreach([-.5,0.,.5] as $step) {
				$sx = $x + $side*$d*$nx - $step*$spacing*$nz;
				$sz = $z + $side*$d*$nz + $step*$spacing*$nx;
				if(max(abs($sx),abs($sz)) > $extent || $land->material($sx,$sz) !== 'road') return max(0.,$d-1.);
			}
		}
		return null;
	}

	/** Remaining distance inside the asphalt, excluding the graded shoulder. */
	public static function edgeDistance(Landscape $land, float $x, float $z): float
	{
		if($land->material($x,$z,$offset,$mark) !== 'road' || $mark === null) return 0.;
		return max(0.,$mark[2]-abs($mark[0]));
	}

	/** Narrow the allowed strip through an authored gate whose posts stand inside the road. */
	private static function fitGates(array $stations, array $normals, array &$bounds, array &$straightBounds, Course $course, float $spacing): void
	{
		$count = count($stations);
		foreach($course->gates(false) as $gate) {
			if($gate['kind'] !== 'road') continue;
			$gx = cos($gate['yaw']); $gz = -sin($gate['yaw']);
			$half = max(0.,$course->measure($gate)['window']['halfWidth'] - self::CLEARANCE);
			$nearest = INF; $crossing = 0;
			foreach($stations as $i => [$x,$z]) {
				$d = ($x-$gate['x'])**2 + ($z-$gate['z'])**2;
				if($d < $nearest) { $nearest = $d; $crossing = $i; }
			}
			$span = max(1,(int)ceil(12./$spacing));
			for($j = -$span; $j <= $span; $j++) {
				$i = ($crossing+$j+$count)%$count;
				[$x,$z] = $stations[$i]; [$nx,$nz] = $normals[$i];
				$dot = $gx*$nx + $gz*$nz;
				if(abs($dot) < .1) continue;
				$offset = ($x-$gate['x'])*$gx + ($z-$gate['z'])*$gz;
				$a = (-$half-$offset)/$dot; $b = ($half-$offset)/$dot;
				[$low,$high] = $bounds[$i];
				$bounds[$i] = [max($low,min($high,min($a,$b))),min($high,max($low,max($a,$b)))];
				[$low,$high] = $straightBounds[$i];
				$straightBounds[$i] = [max($low,min($high,min($a,$b))),min($high,max($low,max($a,$b)))];
			}
		}
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Drives a closed circuit with the same four controls available to a player. */
final class CircuitDriver
{
	private const PLANNED_BRAKING = 14.; // m/s², below the car's full service braking on dry pavement.
	private array $line, $speedLimit = [], $sight = [];
	private array $bends = [], $nextBend = [];
	private int $count, $segment;
	private ?int $activeBend = null;
	private float $spacing, $phaseTime = 0.;
	public string $phase = 'Driving';
	public readonly int $revision;

	public function __construct(public readonly Circuit $circuit, public readonly Course $course, ?Landscape $landscape = null)
	{
		$this->revision = $course->revision;
		// Equal-distance samples bound the work per decision even on endurance circuits.
		$this->count = min(8192, max(32, (int)ceil($circuit->length / 6.)));
		$this->spacing = $circuit->length / $this->count;
		$distances = array_map(fn($i) => $i * $this->spacing, range(0, $this->count - 1));
		$this->line = $circuit->stations($distances);
		$hasPavement = $landscape !== null && $landscape->roads();
		if($hasPavement) {
			$path = new Circuit(RacingLine::fit($this->line,$this->spacing,$landscape,$course),$circuit->dryLevel,null);
			// The inside line changes the distance around the lap. Braking and lookahead both use driven meters.
			$this->spacing = $path->length / $this->count;
			$this->line = $path->stations(array_map(fn($i) => $i*$this->spacing, range(0,$this->count-1)));
		}
		$this->segment = 0;

		// Measure the change in heading over about 10 meters on either side of each bend.
		$span = max(1, (int)round(10. / $this->spacing));
		$curvatures = [];
		foreach($this->line as $i => [$x, $z]) {
			[$ax, $az] = $this->line[$this->wrap($i - $span)];
			[$bx, $bz] = $this->line[$this->wrap($i + $span)];
			$angle = atan2($bx - $x, $bz - $z) - atan2($x - $ax, $z - $az);
			$curvature = self::angle($angle) / ($span * $this->spacing);
			$curvatures[] = $curvature;
			$margin = $hasPavement ? max(.25,RacingLine::edgeDistance($landscape,$x,$z) - Car::BODY_HALF_WIDTH - .8) : INF;
			// Limit the shortcut made by steering at a point ahead: chord sagitta is about curvature * length² / 8.
			$k = max(.0001,abs($curvature));
			$this->sight[] = max(4.,min(36.,sqrt(6.*$margin/$k)));
			$grip = min(9.2,7.2+.65*$margin);
			$this->speedLimit[] = min(Car::TOP_SPEED_MPH * Car::MPS_PER_MPH, sqrt($grip/$k));
		}
		$this->planBends($curvatures);
		// Work back from each bend at the planned deceleration. The second pass covers the lap seam.
		for($i = 2 * $this->count - 1; $i >= 0; $i--) {
			$j = $this->wrap($i);
			$brakingSpeed = sqrt($this->speedLimit[$this->wrap($i + 1)] ** 2 + 2. * self::PLANNED_BRAKING * $this->spacing);
			$this->speedLimit[$j] = min($this->speedLimit[$j], $brakingSpeed);
		}
	}

	private function wrap(int $i): int { return ($i % $this->count + $this->count) % $this->count; }
	private static function angle(float $a): float { return atan2(sin($a), cos($a)); }

	/** Group same-direction turns and put the apex halfway through their accumulated heading change. */
	private function planBends(array $curvatures): void
	{
		$direction = array_map(static fn(float $k) => abs($k) < .005 ? 0 : $k <=> 0., $curvatures);
		// Start at a transition so a corner spanning the timing line remains a single bend.
		for($start = 0; $start < $this->count; $start++)
			if($direction[$start] !== $direction[$this->wrap($start - 1)]) break;
		if($start === $this->count) return;

		for($i = $start; $i < $start + $this->count;) {
			$entry = $this->wrap($i);
			$side = $direction[$entry];
			if(!$side) { $i++; continue; }
			$total = $peak = 0.;
			$first = $i;
			do {
				$k = abs($curvatures[$this->wrap($i++)]);
				$total += $k;
				$peak = max($peak, $k);
			} while($i < $start + $this->count && $direction[$this->wrap($i)] === $side);
			if($peak < .012 || $total * $this->spacing < .20) continue;
			$angle = 0.;
			for($j = $first; $j < $i; $j++) {
				$k = abs($curvatures[$this->wrap($j)]);
				if($angle + $k >= .5 * $total) break;
				$angle += $k;
			}
			$apex = ($j - $first + (.5 * $total - $angle) / $k - .5) * $this->spacing;
			$this->bends[$entry] = ['side'=>$side, 'apex'=>$apex, 'length'=>($i-$first)*$this->spacing];
			for($j = $first; $j < $i; $j++) $this->nextBend[$this->wrap($j)] = $entry;
		}
		if(!$this->bends) return;
		$next = min(array_keys($this->bends));
		for($i = $this->count-1; $i >= 0; $i--) {
			$next = $this->nextBend[$i] ?? $next;
			$this->nextBend[$i] = $next;
		}
	}

	/** Project onto the nearby leg so adjacent hairpins cannot steal the cursor. Only relocations scan the full lap. */
	private function locate(float $x, float $z, bool $relocated = false): float
	{
		$first = $relocated ? 0 : $this->segment - 5;
		$count = $relocated ? $this->count : 11;
		$best = INF;
		$progress = 0.;
		for($n = 0; $n < $count; $n++) {
			$i = $this->wrap($first + $n);
			[$ax, $az] = $this->line[$i];
			[$bx, $bz] = $this->line[$this->wrap($i + 1)];
			$dx = $bx - $ax; $dz = $bz - $az;
			$along = (($x - $ax) * $dx + ($z - $az) * $dz) / max(1e-9, $dx*$dx + $dz*$dz);
			$t = max(0., min(1., $along));
			$distance = ($x - $ax - $t*$dx)**2 + ($z - $az - $t*$dz)**2;
			if($distance < $best) {
				$best = $distance;
				$this->segment = $i;
				$progress = $i + $t;
			}
		}
		return $progress;
	}

	public function restart(float $x, float $z): void
	{
		$this->locate($x, $z, true);
		$this->activeBend = null;
		$this->phaseTime = 0.;
		$this->phase = 'Driving';
	}

	/** Interpolate the braking envelope in distance, where speed squared varies linearly under constant braking. */
	private function limitAt(float $station): float
	{
		$i = (int)floor($station);
		$a = $this->speedLimit[$this->wrap($i)];
		$b = $this->speedLimit[$this->wrap($i + 1)];
		return sqrt($a*$a + ($station - $i)*($b*$b - $a*$a));
	}

	/** Choose the corner controls and return the target speed for the available grip. */
	private function corner(array $car, float $dt, float $at, float $limit, float $error, float $noseError): float
	{
		$speed = $car['speed'];
		$charge = $car['nitrous'];
		$this->phaseTime += $dt;
		$entry = $this->nextBend[$this->segment] ?? null;
		$near = false;
		$toApex = INF;
		$side = 0;
		if($entry !== null) {
			$bend = $this->bends[$entry];
			$side = $bend['side'];
			$along = fmod($at - $entry + $this->count, $this->count) * $this->spacing;
			if($along >= $bend['length']) $along -= $this->spacing * $this->count;
			$near = $along >= -max(7., min(32., .65*$speed));
			$toApex = ($bend['apex']-$along)/max(1.,$speed);
			if($near && $toApex > 0. && $toApex <= .46 && $this->activeBend !== $entry
				&& $speed > 7. && $error*$side > .035) {
				$this->activeBend = $entry;
				$this->phase = 'Handbraking';
				$this->phaseTime = 0.;
			}
		}
		// Check both the immediate exit and the following braking zone before committing the bottle.
		$travel = max(16.,$speed)/$this->spacing;
		$exit = $this->limitAt($at+$travel);
		$opening = $exit > $speed+3. && $this->limitAt($at+.45*$travel) > $speed-1.;
		$unwinding = $this->limitAt($at+.25*$travel) >= $limit-.25;
		// Nitrous pulls the velocity toward the nose. It should reduce the error to the exit heading.
		$travelError = self::angle($noseError + $car['drift']);
		$noseCloser = abs($noseError) < abs($travelError);
		$aligned = abs($noseError) < .17 && ($noseCloser || abs($car['drift']) <= .008);
		$inSlideTurn = $near && $entry === $this->activeBend;
		// Carry a little extra speed into an open apex when there is enough charge to regain grip there.
		$turnLimit = $limit;
		if($inSlideTurn && $toApex <= .46 && $charge > .16 && $exit > $limit+6. && $this->sight[$this->segment] > 9.) {
			$turnLimit = min(1.08*$limit, $exit-3.);
		}
		$catch = $inSlideTurn && $toApex <= .16 && $car['drift']*$side > .008
			&& $aligned && $charge > .06 && $opening && $unwinding && $speed < 1.12*$limit;
		if($this->phase === 'Handbraking' && (!$inSlideTurn || $catch || $toApex <= -.22 || $this->phaseTime >= .68)) {
			$this->phase = 'Cornering';
		}
		if($this->phase === 'Cornering' && (!$inSlideTurn || $catch || $toApex <= 0.)) {
			$this->phase = 'Corner exit';
			$this->phaseTime = 0.;
		}
		if(!$near) $this->activeBend = null;

		if($this->phase === 'Corner exit' && $this->phaseTime > 1.4) $this->phase = 'Driving';
		if($this->phase === 'Boosting') {
			// Nitrous adds tire load while catching a slide. Return to the ordinary limit as the exit closes.
			$boostLimit = $limit * ($inSlideTurn && $unwinding ? 1.20 : 1.03);
			if($exit <= $speed || $speed >= $boostLimit || abs($noseError) > .42 || $charge < .005 || $this->phaseTime >= 2.4) $this->phase = 'Driving';
			return $turnLimit;
		}
		$onExit = $this->phase === 'Corner exit' && $aligned && $charge > .04 && ($catch || $speed < $limit);
		$onStraight = $this->phase === 'Driving' && !$near && $charge > .85 && $limit > 38.
			&& $speed + 8. < $limit && abs($error) < .10 && $aligned;
		if($opening && ($onExit || $onStraight)) {
			$this->phase = 'Boosting';
			$this->phaseTime = 0.;
		}
		return $turnLimit;
	}

	/** @return array{float,float,bool,bool} Pedal, steering, handbrake and nitrous. */
	public function controls(array $car, float $dt, bool $finished): array
	{
		$speed = $car['speed'];
		if($finished || $car['motion'] !== 'driving' || $car['roadHold'] > 0.) {
			$this->phase = $finished ? 'Finished' : 'Coasting';
			$this->activeBend = null;
			return [$finished && $speed > .3 ? -1. : 0., 0., false, false];
		}
		if($this->phase === 'Coasting') $this->phase = 'Driving';
		$at = $this->locate($car['x'], $car['z']);
		$look = max(6.,min(36.,6. + .58*$speed));
		$probe = $this->wrap((int)round($at + .5*$look/$this->spacing));
		$look = min($look,$this->sight[$this->segment],$this->sight[$probe]);
		$ahead = $at + $look / $this->spacing;
		$i = (int)floor($ahead);
		[$ax, $az] = $this->line[$this->wrap($i)];
		[$bx, $bz] = $this->line[$this->wrap($i + 1)];
		$t = $ahead - $i;
		$dx = $ax + $t*($bx - $ax) - $car['x'];
		$dz = $az + $t*($bz - $az) - $car['z'];
		// Pure pursuit from the travel direction, which can differ from the nose during a slide.
		$bearing = atan2($dx,$dz);
		$error = self::angle($bearing - ($car['yaw'] - $car['drift']));
		$wheel = atan2(2 * Car::WHEELBASE * sin($error), max(1., hypot($dx, $dz)));
		$steer = max(-1., min(1., -$wheel / Car::steeringLimit($speed)));
		$limit = $this->limitAt($at);
		// Point the sliding chassis into the outgoing line. Keep the correction tied to the
		// pursuit point so a car wide of its line still steers back toward pavement.
		$outgoing = self::angle(atan2($bx-$ax,$bz-$az) - $bearing);
		$exitHeading = $bearing + max(-.30,min(.30,.70*$outgoing));
		$noseError = self::angle($exitHeading - $car['yaw']);
		$limit = $this->corner($car, $dt, $at, $limit, $error, $noseError);
		$pedal = max(-1., min(1., .8 + ($limit - $speed)*.50));
		if($this->phase === 'Handbraking') {
			// Turn the nose toward the exit while the rear wheels are locked, easing off as it lines up.
			if($error*$noseError > 0.) {
				$turnIn = max(-.42, min(.42, 2.4*$noseError));
				$steer = max(-1., min(1., $steer - $turnIn));
			}
		}
		$boost = $this->phase === 'Boosting';
		return [$boost ? 1. : $pedal, $steer, $this->phase === 'Handbraking', $boost];
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Drives a closed circuit with the same four controls available to a player. */
final class CircuitDriver
{
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
		// Work back from each bend using 12 m/s² of service braking. The second pass covers the lap seam.
		for($i = 2 * $this->count - 1; $i >= 0; $i--) {
			$j = $this->wrap($i);
			$brakingSpeed = sqrt($this->speedLimit[$this->wrap($i + 1)] ** 2 + 24. * $this->spacing);
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

	/** Slide into a tight bend, then boost as the steering unwinds and the braking plan allows. */
	private function corner(float $dt, float $at, float $speed, float $limit, float $error, float $charge): void
	{
		$this->phaseTime += $dt;
		$entry = $this->nextBend[$this->segment] ?? null;
		$near = $pastApex = false;
		if($entry !== null) {
			$bend = $this->bends[$entry];
			$along = fmod($at - $entry + $this->count, $this->count) * $this->spacing;
			if($along >= $bend['length']) $along -= $this->spacing * $this->count;
			$near = $along >= -max(7., min(32., .65*$speed));
			$pastApex = $along >= $bend['apex'];
			if($near && !$pastApex && $this->activeBend !== $entry && $speed > 7. && $error*$bend['side'] > .045) {
				$this->activeBend = $entry;
				$this->phase = 'Handbraking';
				$this->phaseTime = 0.;
			}
		}
		if($this->phase === 'Handbraking' && ($this->phaseTime >= .42 || $pastApex)) $this->phase = 'Cornering';
		if($this->phase === 'Cornering' && ($pastApex || $entry !== $this->activeBend || !$near)) {
			$this->phase = 'Corner exit';
			$this->phaseTime = 0.;
		}
		if(!$near) $this->activeBend = null;

		// Look a second down the exit. An immediate second bend can use all the available braking room.
		$exitStation = $this->wrap((int)round($at + max(16., $speed) / $this->spacing));
		$room = $this->speedLimit[$exitStation] > $speed + 3.;
		if($this->phase === 'Corner exit' && $this->phaseTime > 1.4) $this->phase = 'Driving';
		$onExit = $this->phase === 'Corner exit' && abs($error) < .32 && $charge > .04;
		$onStraight = $this->phase === 'Driving' && !$near && $charge > .85 && $limit > 38.
			&& $speed + 8. < $limit && abs($error) < .10;
		if($room && $speed < $limit && ($onExit || $onStraight)) {
			$this->phase = 'Boosting';
			$this->phaseTime = 0.;
		}
		if($this->phase === 'Boosting' && (!$room || $this->phaseTime > 2.4 || $speed >= $limit
			|| abs($error) > .42 || $charge < .005)) $this->phase = 'Driving';
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
		$error = self::angle(atan2($dx, $dz) - ($car['yaw'] - $car['drift']));
		$wheel = atan2(2 * Car::WHEELBASE * sin($error), max(1., hypot($dx, $dz)));
		$steer = max(-1., min(1., -$wheel / Car::steeringLimit($speed)));
		$limit = min($this->speedLimit[$this->segment], $this->speedLimit[$this->wrap($this->segment + 1)]);
		$pedal = max(-1., min(1., .8 + ($limit - $speed)*.50));
		$this->corner($dt, $at, $speed, $limit, $error, $car['nitrous']);
		$boost = $this->phase === 'Boosting';
		return [$boost ? 1. : $pedal, $steer, $this->phase === 'Handbraking', $boost];
	}
}

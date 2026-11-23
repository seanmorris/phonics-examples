<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Drives a closed circuit with the same four controls available to a player. */
final class CircuitDriver
{
	private array $line, $curvature = [], $speedLimit = [];
	private int $count, $segment, $turnSide = 0;
	private float $spacing, $phaseTime = 0.;
	public string $phase = 'Driving';
	public readonly int $revision;

	public function __construct(public readonly Circuit $circuit, public readonly Course $course)
	{
		$this->revision = $course->revision;
		// Equal-distance samples bound the work per decision even on endurance circuits.
		$this->count = min(8192, max(32, (int)ceil($circuit->length / 6.)));
		$this->spacing = $circuit->length / $this->count;
		$distances = array_map(fn($i) => $i * $this->spacing, range(0, $this->count - 1));
		$this->line = $circuit->stations($distances);
		$this->segment = 0;

		// Measure the change in heading over about 10 meters on either side of each bend.
		$span = max(1, (int)round(10. / $this->spacing));
		foreach($this->line as $i => [$x, $z]) {
			[$ax, $az] = $this->line[$this->wrap($i - $span)];
			[$bx, $bz] = $this->line[$this->wrap($i + $span)];
			$angle = atan2($bx - $x, $bz - $z) - atan2($x - $ax, $z - $az);
			$curvature = self::angle($angle) / ($span * $this->spacing);
			$this->curvature[] = $curvature;
			// Aim for 6 m/s² of lateral acceleration and reserve grip for corrections and banks.
			$this->speedLimit[] = min(46., sqrt(6. / max(.001, abs($curvature))));
		}
		// Work back from each bend using 7 m/s² of service braking. The second pass covers the lap seam.
		for($i = 2 * $this->count - 1; $i >= 0; $i--) {
			$j = $this->wrap($i);
			$brakingSpeed = sqrt($this->speedLimit[$this->wrap($i + 1)] ** 2 + 14. * $this->spacing);
			$this->speedLimit[$j] = min($this->speedLimit[$j], $brakingSpeed);
		}
	}

	private function wrap(int $i): int { return ($i % $this->count + $this->count) % $this->count; }
	private static function angle(float $a): float { return atan2(sin($a), cos($a)); }

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
		$this->turnSide = 0;
		$this->phaseTime = 0.;
		$this->phase = 'Driving';
	}

	/** Lock the rears briefly on entry. Boost on unwind only with space and charge for the exit. */
	private function corner(float $dt, float $speed, float $limit, float $error, float $curve, float $charge): void
	{
		$side = $curve <=> 0.;
		$this->phaseTime += $dt;
		if($this->turnSide && ($side !== $this->turnSide || abs($curve) < .005)) {
			$this->turnSide = 0;
			$this->phase = 'Corner exit';
			$this->phaseTime = 0.;
		}
		if(!$this->turnSide && abs($curve) > .012 && $speed > 7. && abs($error) > .045 && $error*$curve > 0.) {
			$this->turnSide = $side;
			$this->phase = 'Handbraking';
			$this->phaseTime = 0.;
		}
		if($this->phase === 'Handbraking' && $this->phaseTime > .34) $this->phase = 'Cornering';
		if($this->phase === 'Corner exit') {
			if($this->phaseTime > 1.) $this->phase = 'Driving';
			elseif(abs($error) < .23 && $speed < $limit && $charge > .04) {
				$this->phase = 'Boosting';
				$this->phaseTime = 0.;
			}
		}
		if($this->phase === 'Boosting' && ($this->phaseTime > .95 || $speed >= $limit || abs($error) > .32 || $charge < .005))
			$this->phase = 'Driving';
	}

	/** @return array{float,float,bool,bool} Pedal, steering, handbrake and nitrous. */
	public function controls(array $car, float $dt, bool $finished): array
	{
		$speed = $car['speed'];
		if($finished || $car['motion'] !== 'driving' || $car['roadHold'] > 0.) {
			$this->phase = $finished ? 'Finished' : 'Coasting';
			$this->turnSide = 0;
			return [$finished && $speed > .3 ? -1. : 0., 0., false, false];
		}
		if($this->phase === 'Coasting') $this->phase = 'Driving';
		$at = $this->locate($car['x'], $car['z']);
		$look = max(6., min(24., 6. + .48*$speed));
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
		$pedal = max(-1., min(1., .55 + ($limit - $speed)*.35));
		$this->corner($dt, $speed, $limit, $error, $this->curvature[$this->wrap((int)round($ahead))], $car['nitrous']);
		$boost = $this->phase === 'Boosting';
		return [$boost ? 1. : $pedal, $steer, $this->phase === 'Handbraking', $boost];
	}
}

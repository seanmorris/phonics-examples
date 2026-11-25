<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/**
 * Drives a closed circuit with the same four controls available to a player.
 *
 * The tires alone hold about one g, which at 240 mph needs a bend more than a kilometer in radius. The driver gets
 * round tighter bends the way a player does: it locks the rear wheels and steers in, which swings the nose toward the
 * exit, and adds nitrous, which pulls the car's path round after the nose and presses the tires down. A plan made
 * once for the lap sets the speed that move allows through each bend, where to brake, which crests the car may fly
 * and which it must stay down over, and how much of the bottle to keep for the bends ahead.
 */
final class CircuitDriver
{
	use DriverControls;

	private const TOP_SPEED = Car::TOP_SPEED_MPH * Car::MPS_PER_MPH;
	/**
	 * With the rear wheels locked and the wheel hard over, the tail swings toward 0.6 radians in about a third of
	 * a second, while nitrous pulls the car's path after its nose at three times the tail's angle each second. The two
	 * settle with the path turning at SLIDE_RATE radians a second. SLIDE_GRIP is the lateral acceleration in m/s² the
	 * front tires add meanwhile, with the boost pressing them down.
	 */
	private const SLIDE_RATE = .878, SLIDE_GRIP = Session::DRIVING_GRAVITY + Car::BOOST_DOWNFORCE;
	/** Share of that turn rate the speed plan relies on. The rest is left for corrections. */
	private const SLIDE_SHARE = .9;
	/** Bottle spent on each radian of a slide, with a margin. */
	private const BURN_PER_RADIAN = .36;
	/** Corners of the fitted line tighter than this many meters are rounded where the pavement has room. */
	private const TIGHTEST = 16.;
	private array $line, $speedLimit = [], $sight = [];
	private array $curve = [], $reserve = [], $aloft = [], $room = [], $press = [];
	private int $count, $segment;
	private int $slide = 0;
	private bool $boosting = false;
	private float $spacing;
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
		$heights = [];
		if($hasPavement) {
			$fitted = RacingLine::fit($this->line,$this->spacing,$landscape,$course);
			$path = new Circuit($this->roundCorners($fitted,$landscape,$course),$circuit->dryLevel,null);
			// The inside line changes the distance around the lap. Braking and lookahead both use driven meters.
			$this->spacing = $path->length / $this->count;
			$this->line = $path->stations(array_map(fn($i) => $i*$this->spacing, range(0,$this->count-1)));
			$heights = $this->profile($landscape);
		}
		$this->segment = 0;
		$braking = [];
		// A short change of heading and a sustained turn have different demands on the tires.
		$span = max(1, (int)round(12. / $this->spacing));
		foreach($this->line as $i => [$x, $z]) {
			[$ax, $az] = $this->line[$this->wrap($i - $span)];
			[$bx, $bz] = $this->line[$this->wrap($i + $span)];
			$angle = atan2($bx - $x, $bz - $z) - atan2($x - $ax, $z - $az);
			$curvature = self::angle($angle) / ($span * $this->spacing);
			$this->curve[] = $curvature;
			$halfWidth = 0.;
			$margin = INF;
			if($hasPavement) $margin = max(.25,RacingLine::edgeDistance($landscape,$x,$z,$halfWidth) - Car::BODY_HALF_WIDTH - .8);
			// Chord sagitta is about curvature * lookahead² / 8. Keep the pursuit point inside the pavement.
			$k = max(.0001,abs($curvature));
			$this->sight[] = max(4.,min(36.,sqrt(6.*$margin/$k)));
			$this->room[] = $margin;
			$widthAllowance = max(0., min(1., ($halfWidth - 6.) / 6.));
			$grip = min(9.2,7.2+.65*$margin);
			// A slide wanders more than a gripping car, so a narrow road gets less of it.
			$share = self::SLIDE_SHARE*($hasPavement ? max(.5,min(1.,.5 + ($halfWidth-4.5)/9.)) : 1.);
			// A very tight bend is over before the tail has swung out, so the slide gains less there.
			$slide = self::slideSpeed($k,$share*max(0.,min(1.,(1./$k - 6.)/16.)));
			// Below 14 m/s a locked rear axle swings the tail less, so the tires alone corner there.
			$this->speedLimit[] = min(self::TOP_SPEED, max(min(sqrt($grip/$k),self::lockSpeed($k)), $slide >= 14. ? $slide : 0.));
			// The brakes and the road's resistance slow the car, and the grade adds to that or takes from it.
			// Plan on a little less than all of it, and less again on a narrow entry.
			$grade = $heights ? ($heights[$this->wrap($i + 1)] - $heights[$i])/$this->spacing : 0.;
			$firm = .88 + .05*$widthAllowance;
			$braking[] = [$firm*Car::SERVICE_BRAKE + Session::DRIVING_GRAVITY*$grade,$firm];
		}
		// A car thrown off a crest can neither brake nor turn until it lands. Let it fly where the line beneath
		// stays straight enough to land on, and elsewhere cross the crest slowly enough to stay down. Slowing for
		// one crest changes the speed over the next, so repeat until every flight left is a clear one.
		$this->aloft = $this->press = array_fill(0,$this->count,false);
		$this->brake($braking);
		$crests = $heights ? $this->crests($heights) : [];
		for($round = 0; $crests && $round < 8; $round++) {
			if($round) $this->brake($braking);
			$slowed = false;
			foreach($this->flightsOver($heights,$crests) as [$from,$to]) {
				$i = $this->wrap($from);
				$v = $this->speedLimit[$i];
				$clear = $this->clearFlight($i,$to - $from,$v);
				if($clear !== null) {
					// Nothing slows a car in the air, and braking as it left would pitch it nose down.
					for($n = $clear[0]; $n <= $to - $from; $n++) {
						$j = $this->wrap($from + $n);
						if($braking[$j][0] > 0.) { $braking[$j] = [0.,0.]; $slowed = true; }
					}
					continue;
				}
				// Find the fastest crossing that stays down, or whose shorter flight is a clear one. The boost
				// presses the tires down, so holding it over the crest allows more speed. It also speeds the car
				// up and keeps it off the brakes, which only pays where the road beyond has room for that.
				$half = intdiv($to - $from,2);
				$plain = $this->crestSpeed($heights,$from,$to,$v,null);
				$held = $this->crestSpeed($heights,$from,$to,$v,$from - 2);
				$exit = sqrt($held*$held + 2.*Car::BOOST_ACCEL*($half + 2)*$this->spacing);
				$hold = $held > $plain && $exit <= $this->speedLimit[$this->wrap($from + $half + 1)];
				// The road keeps falling away past the first station the wheels leave.
				for($n = -2; $n <= $half; $n++) {
					$j = $this->wrap($from + $n);
					$pace = $hold ? sqrt($held*$held + 2.*Car::BOOST_ACCEL*($n + 2)*$this->spacing) : $plain;
					$this->speedLimit[$j] = min($this->speedLimit[$j],$pace);
					if($hold) {
						$this->press[$j] = true;
						$braking[$j] = [-Car::BOOST_ACCEL,0.];
					}
				}
				$slowed = true;
			}
			if(!$slowed) break;
		}
		$this->brake($braking);
		foreach($this->flightsOver($heights,$crests) as [$from,$to]) {
			$speed = $this->speedLimit[$this->wrap($from)];
			[$first,$last] = $this->clearFlight($this->wrap($from),$to - $from,$speed)
				?? [-(int)ceil(.5*$speed/$this->spacing),$to - $from];
			for($n = $first; $n <= $last; $n++) $this->aloft[$this->wrap($from + $n)] = true;
		}
		// Keep enough of the bottle for the slides ahead. Driving without the boost refills it between them.
		$this->reserve = array_fill(0,$this->count,0.);
		$reserve = 0.;
		for($i = 2 * $this->count - 1; $i >= 0; $i--) {
			$j = $this->wrap($i);
			$k = abs($this->curve[$j]);
			$sliding = $this->speedLimit[$j]**2*$k > 9.5;
			// A crest held down spends the bottle by the time spent on it and a slide by the angle turned. A gentle
			// bend is slid in short pulses, and the bottle refills between them as it does on a straight.
			$pulsed = $sliding ? min(1.,$this->speedLimit[$j]*$k) : 0.;
			$burn = $this->press[$j] ? 1./(Car::BOOST_SECONDS*$this->speedLimit[$j])
				: $pulsed*self::BURN_PER_RADIAN*$k - (1. - $pulsed)/Car::REFILL_DISTANCE;
			$reserve = max(0.,min(.9,$reserve + $burn*$this->spacing));
			$this->reserve[$j] = $reserve;
		}
	}

	/**
	 * Round the corners the fitted line left too sharp to drive at more than a crawl. The fit moves each point only
	 * across the road, so it cannot cut a corner by more than the corner's own radius. Here a point near such a
	 * corner moves toward the middle of its neighbors for as long as it keeps the fit's clearance from the pavement
	 * edge. Points beside a gate stay where the fit put them, between its posts.
	 */
	private function roundCorners(array $points, Landscape $land, Course $course): array
	{
		$count = count($points);
		$clear = Car::BODY_HALF_WIDTH + 1.8;
		$held = $edge = [];
		foreach($course->gates(false) as $gate) {
			if($gate['kind'] !== 'road') continue;
			foreach($points as $i => [$x, $z]) if(hypot($x-$gate['x'],$z-$gate['z']) < 14.) $held[$i] = true;
		}
		for($pass = 0; $pass < 60; $pass++) {
			$sharp = [];
			foreach($points as $i => [$x, $z]) {
				[$ax, $az] = $points[($i + $count - 2) % $count];
				[$bx, $bz] = $points[($i + 2) % $count];
				$bend = abs(($x-$ax)*($bz-$z) - ($z-$az)*($bx-$x));
				$radius = hypot($x-$ax,$z-$az)*hypot($bx-$x,$bz-$z)*hypot($bx-$ax,$bz-$az)/max(1e-9,2.*$bend);
				if($radius >= self::TIGHTEST) continue;
				for($n = -3; $n <= 3; $n++) $sharp[($i + $n + $count) % $count] = true;
			}
			$moved = false;
			foreach($sharp as $i => $_) {
				if(isset($held[$i])) continue;
				[$x, $z] = $points[$i];
				[$ax, $az] = $points[($i + $count - 1) % $count];
				[$bx, $bz] = $points[($i + 1) % $count];
				$nx = .5*$x + .25*($ax+$bx);
				$nz = .5*$z + .25*($az+$bz);
				if(abs($nx-$x) + abs($nz-$z) < .005) continue;
				$edge[$i] ??= RacingLine::edgeDistance($land,$x,$z);
				$room = RacingLine::edgeDistance($land,$nx,$nz);
				if($room < min($clear,$edge[$i])) continue;
				$points[$i] = [$nx,$nz];
				$edge[$i] = $room;
				$moved = true;
			}
			if(!$moved) break;
		}
		return $points;
	}

	/**
	 * The fastest speed at which full steering lock still turns the car as tightly as this curvature. The lock
	 * opens up as the car slows, so a very sharp corner has to be taken at a crawl.
	 */
	private static function lockSpeed(float $k): float
	{
		if(tan(Car::steeringLimit(self::TOP_SPEED)) >= Car::WHEELBASE*$k) return self::TOP_SPEED;
		$slow = 2.5;
		$fast = self::TOP_SPEED;
		for($pass = 0; $pass < 12; $pass++) {
			$try = .5*($slow+$fast);
			if(tan(Car::steeringLimit($try)) >= Car::WHEELBASE*$k) $slow = $try; else $fast = $try;
		}
		return $slow;
	}

	/** The speed at which a slide still turns the car as tightly as this curvature, using a share of its turn rate. */
	private static function slideSpeed(float $k, float $share): float
	{
		$rate = self::SLIDE_RATE*$share;
		return ($rate + sqrt($rate*$rate + 4.*$k*self::SLIDE_GRIP*$share))/(2.*$k);
	}

	/**
	 * Road height at every station. A height on the terrain's own lattice costs a single sample where one between
	 * lattice points costs a whole tile, so read the nearest lattice point and carry it back along the road's grade.
	 */
	private function profile(Landscape $land): array
	{
		$step = $land->recipe()['spacing'];
		$raw = $shift = $heights = [];
		foreach($this->line as $i => [$x, $z]) {
			[$nx, $nz] = $this->line[$this->wrap($i + 1)];
			$rx = round($x/$step)*$step;
			$rz = round($z/$step)*$step;
			$raw[] = $land->height($rx, $rz);
			$shift[] = (($rx-$x)*($nx-$x) + ($rz-$z)*($nz-$z))/max(.001,hypot($nx-$x,$nz-$z));
		}
		foreach($raw as $i => $height) {
			$before = $this->wrap($i - 1);
			$after = $this->wrap($i + 1);
			$grade = ($raw[$after]-$raw[$before])/max(1.,2.*$this->spacing + $shift[$after] - $shift[$before]);
			$heights[] = $height - $grade*$shift[$i];
		}
		return $heights;
	}

	/**
	 * Work back from each bend at the planned deceleration. Where the boost is held the car gains speed instead, so
	 * it must arrive slower still. The second pass covers the lap seam.
	 * @param list<array{float,float}> $braking Per station, the deceleration in m/s² before resistance and the share
	 * of the road's resistance to add to it.
	 */
	private function brake(array $braking): void
	{
		for($i = 2 * $this->count - 1; $i >= 0; $i--) {
			$j = $this->wrap($i);
			$next = $this->speedLimit[$this->wrap($i + 1)];
			[$slowing,$resisted] = $braking[$j];
			$slowing = max($resisted > 0. ? 6. : -INF,$slowing + $resisted*Car::drag($next));
			$brakingSpeed = sqrt(max(1.,$next*$next + 2.*$slowing*$this->spacing));
			$this->speedLimit[$j] = min($this->speedLimit[$j], $brakingSpeed);
		}
	}

	/**
	 * Stretches of the line where, at the planned speed, the road falls away fast enough to lighten the car. Only these
	 * need the suspension ridden over them. Planning only ever lowers a speed, so the first survey covers every round.
	 * @return list<array{int,int}> First and last station of each stretch, with room to settle before and land after.
	 */
	private function crests(array $heights): array
	{
		$crests = [];
		$first = $last = null;
		$scale = 1./($this->spacing*$this->spacing);
		for($i = 0; $i < $this->count; $i++) {
			$height = 2.*$heights[$i];
			$near = $height - $heights[$this->wrap($i - 1)] - $heights[$this->wrap($i + 1)];
			$wide = ($height - $heights[$this->wrap($i - 3)] - $heights[$this->wrap($i + 3)])/9.;
			if(max($near,$wide)*$scale*$this->speedLimit[$i]**2 > 3.) {
				if($first !== null && $i - $last > 22) {
					$crests[] = [$first - 10,$last + 12];
					$first = null;
				}
				$first ??= $i;
				$last = $i;
			}
		}
		if($first !== null) $crests[] = [$first - 10,$last + 12];
		return $crests;
	}

	/** Every flight the planned speeds would cause, from riding the suspension over each crest in turn. */
	private function flightsOver(array $heights, array $crests): array
	{
		$flights = [];
		foreach($crests as [$first,$last]) array_push($flights,...$this->flights($heights,$first,$last));
		return $flights;
	}

	/** The fastest speed up to a limit at which a crest's flights are all clear ones, or the car stays down. */
	private function crestSpeed(array $heights, int $from, int $to, float $fast, ?int $pressFrom): float
	{
		$slow = 1.;
		for($pass = 0; $pass < 7; $pass++) {
			$try = .5*($slow+$fast);
			$safe = true;
			foreach($this->flights($heights,$from - 10,$to + 2,$try,$pressFrom) as [$left,$landed]) {
				if($this->clearFlight($this->wrap($left),$landed - $left,$try) === null) { $safe = false; break; }
			}
			if($safe) $slow = $try; else $fast = $try;
		}
		return $slow;
	}

	/**
	 * Ride the suspension along the line and report where the road falls away faster than the wheels can follow.
	 * @param array $heights Road height at every station.
	 * @param int $first Station to start from, settled on the road.
	 * @param int $last Station to stop at. It can lie past the lap seam.
	 * @param ?float $pace A single speed in m/s to hold, or null for the planned speed at each station.
	 * @param ?int $pressFrom With a single speed, the station from which the boost presses the car down.
	 * @return list<array{int,int}> For each flight, the station where the wheels leave and the one where they land.
	 */
	private function flights(array $heights, int $first, int $last, ?float $pace = null, ?int $pressFrom = null): array
	{
		$road = function(float $s) use ($heights): float {
			$i = (int)floor($s);
			$f = $s - $i;
			$a = $heights[$this->wrap($i - 1)]; $b = $heights[$this->wrap($i)];
			$c = $heights[$this->wrap($i + 1)]; $d = $heights[$this->wrap($i + 2)];
			return $b + .5*$f*($c - $a + $f*(2.*$a - 5.*$b + 4.*$c - $d + $f*(3.*($b - $c) + $d - $a)));
		};
		$dt = 1/120;
		$s = (float)$first;
		$here = $road($s);
		$y = $here + .02;
		$speed = $pace ?? $this->speedLimit[$this->wrap($first)];
		$vertical = ($road($s + .05) - $here)/.05*$speed/$this->spacing;
		$landing = 0.;
		$flights = [];
		$left = null;
		$airtime = 0.;
		// Ride on past the end until a flight in progress lands.
		while($s < $last || ($left !== null && $s < $last + 96)) {
			$station = $this->wrap((int)$s);
			$speed = max(5.,$pace ?? $this->speedLimit[$station]);
			// With a single pace the boost comes on at one station and speeds the car up from there.
			$pressing = $pace === null ? $this->press[$station] : $pressFrom !== null && $s >= $pressFrom;
			if($pressing && $pace !== null) $speed = sqrt($pace*$pace + 2.*Car::BOOST_ACCEL*($s - $pressFrom)*$this->spacing);
			$next = $road($s + $speed*$dt/$this->spacing);
			if(CarSuspension::heave($dt,$y,$vertical,$here,$next,$landing,$pressing ? Car::BOOST_DOWNFORCE : 0.)) {
				// A hop shorter than three frames does not interrupt the driver.
				if($left !== null && $airtime > .05) $flights[] = [$left,(int)$s];
				$left = null;
				$airtime = 0.;
			} else {
				$left ??= (int)$s;
				$airtime += $dt;
			}
			$s += $speed*$dt/$this->spacing;
			$here = $next;
		}
		if($left !== null && $airtime > .05) $flights[] = [$left,(int)$s];
		return $flights;
	}

	/**
	 * Whether a car can fly from this station and land on the line. It must leave the ground rolling straight, since
	 * a cornering car keeps rolling over in the air, and the line under the flight and for a quarter of a second after
	 * it must stay close to the car's straight path.
	 * @return list<int>|null First and last offsets, in stations, of the stretch to drive without a slide.
	 */
	private function clearFlight(int $i, int $flown, float $speed): ?array
	{
		if($flown*$this->spacing > 1.2*$speed) return null;
		$approach = (int)ceil(.5*$speed/$this->spacing);
		for($n = -$approach; $n <= 0; $n++) {
			if($speed*$speed*abs($this->curve[$this->wrap($i + $n)]) > 3.) return null;
		}
		$last = $flown + (int)ceil(.25*$speed/$this->spacing);
		$heading = $aside = 0.;
		for($n = 0; $n <= $last; $n++) {
			$j = $this->wrap($i + $n);
			$heading += $this->curve[$j]*$this->spacing;
			$aside += $heading*$this->spacing;
			// The car may land beside the line where the pavement has the room, as long as a short slide can
			// bring its heading back.
			if(abs($heading) > .10 || abs($aside) > max(1.5,$this->room[$j] - 1.5)) return null;
		}
		return [-$approach,$last];
	}

	private function wrap(int $i): int { return ($i % $this->count + $this->count) % $this->count; }

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
		$this->slide = 0;
		$this->boosting = false;
		$this->phase = 'Driving';
		$this->serviceBraking = false;
	}

	/** Interpolate the braking envelope in distance, where speed squared varies linearly under constant braking. */
	private function limitAt(float $station): float
	{
		$i = (int)floor($station);
		$a = $this->speedLimit[$this->wrap($i)];
		$b = $this->speedLimit[$this->wrap($i + 1)];
		return sqrt($a*$a + ($station - $i)*($b*$b - $a*$a));
	}

	/** Hold the planned speed, braking as hard as the plan four tenths of a second ahead requires. */
	private function pace(float $speed, float $at, float $limit, bool $boost = false): float
	{
		if($boost) {
			$this->serviceBraking = false;
			return 1.;
		}
		$reach = max(6.,.4*$speed);
		$ahead = $this->limitAt($at + $reach/$this->spacing);
		$need = ($speed*$speed - $ahead*$ahead)/(2.*$reach);
		// Stay on the brakes until the car is back under the plan, so they do not pulse at every station.
		if($need > 7. || $speed > $limit + 3.1) $this->serviceBraking = true;
		elseif($need < 1. && $speed < $limit + .9) $this->serviceBraking = false;
		if($this->serviceBraking) return -max(.1,min(1.,max($need/14.,.5*($speed - $limit))));
		return max(0.,min(1.,.8 + .5*($limit - $speed)));
	}

	/** The line's position at a fractional station. */
	private function pointAt(float $station): array
	{
		$i = (int)floor($station);
		$t = $station - $i;
		[$ax, $az] = $this->line[$this->wrap($i)];
		[$bx, $bz] = $this->line[$this->wrap($i + 1)];
		return [$ax + $t*($bx - $ax), $az + $t*($bz - $az)];
	}

	/** Signed curvature of the line at a fractional station. Positive turns the heading up. */
	private function curveAt(float $station): float
	{
		$i = (int)floor($station);
		$a = $this->curve[$this->wrap($i)];
		return $a + ($station - $i)*($this->curve[$this->wrap($i + 1)] - $a);
	}

	/** @return array{float,float,bool,bool} Pedal, steering, handbrake and nitrous. */
	public function controls(array $car, float $dt, bool $finished): array
	{
		$speed = $car['speed'];
		// Keep the route cursor under the car during a jump so landing resumes on the correct bend.
		if(!$finished && $car['motion'] === 'airborne') $this->locate($car['x'],$car['z']);
		if($finished || $car['motion'] !== 'driving' || $car['roadHold'] > 0.) {
			$this->phase = $finished ? 'Finished' : 'Coasting';
			$this->serviceBraking = $this->boosting = false;
			$this->slide = 0;
			return [$finished && $speed > .3 ? -1. : 0., 0., false, false];
		}
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
		[$steer,,$error] = self::pursuit($car,$dx,$dz);
		// Pursuit steers less and less once the point is behind the car. Turn hard toward it instead.
		if(abs($error) > 1.5) $steer = $error > 0. ? -1. : 1.;
		$limit = $this->limitAt($at);
		$charge = $car['nitrous'];
		$drift = $car['drift'];
		// Once the rear tires roll again the car's path swings round to its nose, so the nose is where the car
		// will soon be going. Compare it with the line a quarter of a second ahead, where the path will have
		// caught up, and lean that toward the line by however far the car sits to one side of it.
		[$px, $pz] = $this->pointAt($at);
		[$qx, $qz] = $this->pointAt($at + .5);
		$along = hypot($qx-$px,$qz-$pz);
		$aside = (($car['x']-$px)*($qz-$pz) - ($car['z']-$pz)*($qx-$px))/max(.001,$along);
		$preview = $at + .25*$speed/$this->spacing;
		[$ax, $az] = $this->pointAt($preview - .5);
		[$bx, $bz] = $this->pointAt($preview + .5);
		$heading = atan2($bx-$ax,$bz-$az) - atan2($aside,max(25.,.8*$speed));
		$noseError = self::angle($heading - $car['yaw']);
		// A car pointing well away from where it should go has left its line. The slower it drives, the
		// tighter it turns back, where at speed it would only circle.
		$astray = max(abs($noseError),abs($error));
		if($astray > .6) $limit = min($limit,max(4.,7./$astray));
		// A gentle bend keeps turning while the path swings round to the nose, so carry the nose a little past
		// its heading and one slide lasts longer. In a tight bend the slide is held throughout and needs no lead.
		$turn = $speed*$this->curveAt($at + .15*$speed/$this->spacing);
		$aim = $noseError + .3*$turn*max(0.,1. - abs($turn)/.6);
		// Over a crest that would otherwise throw the car, the boost holds the tires on the road.
		$held = $this->press[$this->segment] || $this->press[$this->wrap($this->segment + 1)];
		// Radians a second the front tires can turn the car on their own. Leave them the small corrections.
		$tires = 8.5/max(1.,$speed);
		// A car that leaves a crest sideways rolls over in the air, so hold no slide around a planned flight.
		if($speed < 9. || $astray > 1.2 || $speed > $limit + 8. || $this->aloft[$this->segment]) $this->slide = 0;
		elseif(!$this->slide && abs($aim) > .04 + .35*$tires) $this->slide = $aim <=> 0.;
		elseif($aim*$this->slide <= 0.) $this->slide = 0;
		// The boost adds speed and pulls the path round faster. Spend it where the car is below its pace or
		// the bend is too tight for the slide alone, and let a gentle slide settle by itself.
		$spend = $speed < $limit - 1. || abs($turn) > .3;
		if($this->slide) {
			// Lock the rear wheels and steer in until the nose points where the car should go. The wheel sets
			// how far the tail swings.
			$wheel = max(.25,min(1.,2.5*($drift*$this->slide + abs($aim))));
			// The boost joins once the tail is out. It needs the throttle, so leave it off while slowing.
			$boost = $charge > .02 && ($held || $spend && $drift*$this->slide > .02 && $speed < $limit + 4.);
			$this->boosting = false;
			$this->phase = 'Drifting';
			return [$this->pace($speed,$at,$limit,$boost), -$this->slide*$wheel, true, $boost];
		}
		// Nitrous pulls the travel direction toward the nose. Use it to catch a slide whose nose points down the exit.
		$travelError = self::angle($noseError + $drift);
		$catch = abs($drift) > .012 && abs($noseError) < abs($travelError) && ($spend || abs($drift) > .1);
		if($charge > .02 && ($held || $catch && $speed < $limit + 2.)) {
			$this->boosting = false;
			$this->phase = 'Boosting';
			return [1., $steer, false, true];
		}
		// On the open road spend only what the slides ahead can spare.
		$travel = max(16.,$speed)/$this->spacing;
		$exit = $this->limitAt($at+$travel);
		$nearExit = $this->limitAt($at+.45*$travel);
		$opening = $exit > $speed+1. && $nearExit >= $speed-.5;
		$spare = $charge - $this->reserve[$this->segment];
		if($this->boosting) {
			$this->boosting = $opening && $speed < $limit && abs($noseError) < .42 && $spare > .012
				&& $speed < .985*self::TOP_SPEED;
		} else {
			$this->boosting = $opening && $spare > .075 && $speed+2. < $limit && $speed+4. < min($exit,$nearExit)
				&& abs($error) < .14 && abs($noseError) < .17 && $speed < .96*self::TOP_SPEED;
		}
		// Feed in throttle as the bend opens during the next fraction of a second.
		$limit += max(0., min(1.6, $this->limitAt($at + .18*$speed/$this->spacing) - $limit));
		$pedal = $this->pace($speed,$at,$limit,$this->boosting);
		$this->phase = $this->boosting ? 'Boosting' : ($pedal < 0. ? 'Braking' : 'Driving');
		return [$pedal, $steer, false, $this->boosting];
	}
}

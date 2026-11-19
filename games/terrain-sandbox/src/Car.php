<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\Water;

/** Tire-supported bicycle steering and free chassis motion on the committed landscape. */
final class Car
{
	// Metres, measured from the baked Testarossa. Its origin is midway between the axles.
	public const WHEELBASE = 2.515, FRONT_TRACK = 1.57, REAR_TRACK = 1.644, TIRE_RADIUS = .297;
	private float $x = 0, $y = 0, $z = 0, $yaw = 0, $pitch = 0, $roll = 0;
	private float $speed = 0, $steering = 0, $spin = 0;
	/** Angle in radians from the direction of travel to the way the body points, while the tail slides out. */
	private float $drift = 0;
	/** Deceleration in metres a second squared from locked rear tires carrying all the rear load. */
	private const HANDBRAKE = 6.5;
	/**
	 * Largest drift the handbrake swings the tail to, in radians, and the time constant of that swing, in seconds.
	 * Once the rear tires roll again the drift dies away with its own time constant and a steady rate in radians a
	 * second, so it settles on zero.
	 */
	private const DRIFT = .6, DRIFT_IN = .35, DRIFT_OUT = .3, DRIFT_SETTLE = .3;
	private const RECOVERY_DELAY = 1.5;
	/**
	 * The body's footprint and height in metres, measured from the baked Testarossa: half its width, and how far it
	 * reaches ahead of and behind the midpoint between the axles. Gate posts collide with this box.
	 */
	public const BODY_HALF_WIDTH = .97, BODY_FRONT = 2.37, BODY_REAR = 2.11, BODY_HEIGHT = 1.13;
	/**
	 * Share of the speed into a post that bounces back out, and the friction between the body and the post's face,
	 * which takes speed along the face in proportion to the speed into it.
	 */
	private const POST_BOUNCE = .25, POST_FRICTION = .5;
	private CarSuspension $suspension;
	private float $roadPitch = 0, $roadRoll = 0;
	private ?CarTumble $tumble = null;
	private float $recoveryTime = 0;
	private array $lastSafe = [];
	private int $recoveries = 0;
	private int $revision = -1;
	private float $waterLevel = 0;

	public function __construct() { $this->suspension = new CarSuspension; }

	/**
	 * Search the camera position first, then progressively wider rings. Each yield permits a frame boundary.
	 * @param array $posts Solid posts as Course::posts() lists them. A site whose body would stand within half a
	 * metre of one is passed over.
	 */
	public function findSite(Landscape $land, Water $water, array $eye, float $yaw, array $posts = []): \Generator
	{
		$half = $land->recipe()['worldSize'] / 2;
		$spacing = $land->recipe()['spacing'];
		$areaScale = min(1.0, ($half - 2.6) / 12);
		foreach([0,4,12,32,64,128,256,512,900,1400,2200] as $radius)
		{
			$count = match($radius) { 0 => 1, 4 => 4, 12 => 8, default => 16 };
			for($i = 0; $i < $count; $i++)
			{
				$a = $yaw + $i * 2 * M_PI / $count;
				$x = $eye[0] + $radius * sin($a); $z = $eye[2] + $radius * cos($a);
				// Lattice heights take one sample even on cold terrain. Preserve in-place boarding exactly.
				if($radius && $spacing <= 4)
				{
					$x = round($x/$spacing)*$spacing; $z = round($z/$spacing)*$spacing;
				}
				if(abs($x) + 2.6 > $half || abs($z) + 2.6 > $half) continue;
				yield;
				$dry = $water->heightRange()[1] + .12;
				if($land->height($x, $z) <= $dry) continue;
				$center = $land->sample($x, $z);
				if(hypot($center['dx'], $center['dz']) > .20) continue;
				// Try the pilot's heading first, then other approaches for a road or a narrow terrace.
				foreach([0, M_PI/2, -M_PI/2, M_PI] as $offset)
				{
					$heading = $yaw + $offset; $sin = sin($heading); $cos = cos($heading);
					foreach([[-3,-4],[3,-4],[-3,0],[3,0],[-3,10],[0,10],[3,10]] as [$side,$ahead])
					{
						$px = $x + ($cos*$side + $sin*$ahead)*$areaScale;
						$pz = $z + (-$sin*$side + $cos*$ahead)*$areaScale;
						if($spacing <= 2)
						{
							$px = round($px/$spacing)*$spacing; $pz = round($pz/$spacing)*$spacing;
						}
						if(abs($px) > $half || abs($pz) > $half) continue 2;
						yield;
						$h = $land->height($px, $pz);
						$plane = $center['height'] + $center['dx']*($px-$x) + $center['dz']*($pz-$z);
						if($h <= $dry || abs($h - $plane) > .40) continue 2;
					}
					yield;
					$support = $this->support($land, $water->heightRange()[1], $x, $z, $heading);
					if($support !== null && self::safe($support)
						&& !self::nearPost($posts, $x, $z, $heading, $support[0])) return [$x,$z,$heading,$support];
				}
			}
		}
		return null;
	}

	/** Adopt a fully checked site only when the search succeeds. */
	public function spawn(array $site, Landscape $land, float $waterLevel): void
	{
		[$this->x,$this->z,$this->yaw,$support] = $site;
		$this->restOnWheels($support);
		$this->speed = $this->steering = $this->spin = $this->drift = 0;
		$this->tumble = null; $this->recoveryTime = 0; $this->recoveries = 0;
		$this->lastSafe = [$this->x,$this->z,$this->yaw];
		$this->revision = $land->revision();
		$this->waterLevel = $waterLevel;
	}

	/** Return false if a water-level change or published terrain edit removes support at the current pose. */
	/**
	 * Drive one fixed step. The keyboard gives whole pedal and steering values and a gamepad anything between.
	 * @param float $dt Fixed step in seconds.
	 * @param float $throttle -1 to 1. Positive drives forward. Negative brakes while rolling forward, then reverses.
	 * @param float $turn -1 to 1. Positive steers right.
	 * @param Landscape $land Ground under the wheels.
	 * @param float $waterLevel Water the car may not drive into.
	 * @param bool $handbrake Lock the rear wheels.
	 * @param array $posts Solid posts as Course::posts() lists them. The body cannot pass through one.
	 * @return bool False when the ground under the car changed and no support remains.
	 */
	public function step(float $dt, float $throttle, float $turn, Landscape $land, float $waterLevel,
		bool $handbrake = false, array $posts = []): bool
	{
		if($dt <= 0) return true;
		if($this->revision !== $land->revision() || $this->waterLevel !== $waterLevel)
		{
			$support = $this->support($land, $waterLevel, $this->x, $this->z, $this->yaw);
			if($support === null) return false;
			if($this->tumble === null && $this->revision !== $land->revision())
				$this->suspension->changeGround($support[4]);
			elseif($this->tumble !== null && $this->revision !== $land->revision())
			{
				$this->tumble->wake();
				$this->recoveryTime = 0;
			}
			$this->revision = $land->revision(); $this->waterLevel = $waterLevel;
		}
		if($this->tumble !== null) return $this->stepTumble($dt,$land,$waterLevel,$posts);
		// Steering tightens at parking speeds. Swapping pedals brakes before engaging reverse.
		$target = -$turn * .52 / (1 + .035 * abs($this->speed));
		$this->steering += max(-2.5*$dt, min(2.5*$dt, $target - $this->steering));
		$gripFraction = $this->suspension->contacts/4;
		$grade = sin($this->roadPitch);
		if($gripFraction > 0 && $throttle == 0)
		{
			if($this->speed != 0 || abs($grade) > .18) $this->speed -= 9.81*$grade*$dt*$gripFraction;
			$drag = (1.7 + .006*$this->speed**2)*$dt;
			$this->speed = ($this->speed <=> 0) * max(0.0, abs($this->speed) - $drag*$gripFraction);
		}
		elseif($gripFraction > 0)
		{
			$power = $throttle*$this->speed < 0 ? 13 : ($throttle > 0 ? 8 : 4);
			$rearGrip = ($this->suspension->contacts-$this->suspension->frontContacts)/2;
			$traction = $throttle*$this->speed < 0 ? $gripFraction : $rearGrip;
			$this->speed += ($throttle*$power*$traction - (.006*$this->speed*abs($this->speed)+9.81*$grade)*$gripFraction)*$dt;
			$pedal = abs($throttle);
			// A light pedal leaves some of the rolling resistance a released one would meet.
			if($pedal < 1)
				$this->speed = ($this->speed <=> 0)*max(0.0, abs($this->speed) - 1.7*(1 - $pedal)*$gripFraction*$dt);
			$this->speed = min(36, max(-8, $this->speed));
		}
		$rear = ($this->suspension->contacts - $this->suspension->frontContacts)/2;
		if($handbrake && $rear > 0 && $this->speed != 0)
			$this->speed = ($this->speed <=> 0)*max(0.0, abs($this->speed) - self::HANDBRAKE*$rear*$dt);
		if($this->drift != 0 && $gripFraction > 0)
		{
			// Tires sliding sideways scrub speed.
			$scrub = 3.0*abs(sin($this->drift))*$gripFraction*$dt;
			$this->speed = ($this->speed <=> 0)*max(0.0, abs($this->speed) - $scrub);
		}
		if($this->speed == 0) $this->drift = 0;
		if($this->speed == 0 && $this->suspension->resting) return true;
		$distance = $this->speed * $dt;
		// An inward bank supplies some centripetal acceleration. Only the remainder needs tire grip.
		$gravitySide = 9.81*sin($this->roadRoll);
		$grip = 9.81*cos($this->roadRoll)*cos($this->roadPitch);
		$demand = $this->speed**2*tan($this->steering)/self::WHEELBASE;
		$direction = $demand <=> 0;
		$lateral = $direction*min(abs($demand), max(0.0, $grip+$direction*$gravitySide))*$this->suspension->frontContacts/2;
		$rotation = $this->speed == 0 ? 0.0 : $lateral*$dt/$this->speed;
		// Locked rear tires stop holding the tail on line, so it swings out into the turn. Once they roll again
		// the car keeps pointing where it was and its path swings round to meet it.
		$slide = $handbrake && $rear > 0 && $this->speed > 2
			? -max(-1.0, min(1.0, $turn))*self::DRIFT*min(1.0, $this->speed/14) : 0.0;
		if($slide == 0 && $this->drift == 0)
		{
			$heading = $this->yaw + .5*$rotation;
			$yaw = $this->yaw + $rotation;
		}
		else
		{
			$path = $this->yaw - $this->drift;
			$drift = $slide != 0 ? $this->drift + ($slide - $this->drift)*(1 - exp(-$dt/self::DRIFT_IN))
				: ($this->drift <=> 0)*max(0.0, abs($this->drift)*exp(-$dt/self::DRIFT_OUT) - self::DRIFT_SETTLE*$dt);
			$yaw = $slide != 0 ? $path + $rotation + $drift : $this->yaw + $rotation;
			$heading = .5*($path + $yaw - $drift);
			$this->drift = $drift;
		}
		$x = $this->x + sin($heading)*$distance*cos($this->roadPitch);
		$z = $this->z + cos($heading)*$distance*cos($this->roadPitch);
		if($posts && ($blocked = $this->avoidPosts($posts, $x, $z, $yaw)) !== null)
		{
			[$x,$z,$yaw,$normalX,$normalZ] = $blocked;
			// The tires keep the car on its heading, so only the speed along it changes. Its part into the post
			// bounces back and its part along the post's face loses what friction takes.
			$into = sin($heading)*$normalX + cos($heading)*$normalZ;
			if($this->speed*$into < 0)
			{
				$along = sqrt(max(0.0, 1 - $into*$into));
				$slide = max(0.0, $along - self::POST_FRICTION*(1 + self::POST_BOUNCE)*abs($into));
				$this->speed *= $slide*$along - self::POST_BOUNCE*$into*$into;
			}
		}
		$support = $this->support($land, $waterLevel, $x, $z, $yaw);
		if($support === null)
		{
			$this->speed = $distance = 0;
			$x = $this->x; $z = $this->z; $yaw = $this->yaw;
			$support = $this->support($land,$waterLevel,$x,$z,$yaw);
			if($support === null) return false;
		}
		[,$pitch,$roll] = $support;
		// Downhill gravity and outward cornering load act together on an adverse camber.
		$sideLoad = 9.81*sin($roll) - $lateral*cos($roll);
		$normalLoad = 9.81*cos($pitch)*cos($roll) + $lateral*sin($roll);
		$tipSide = abs($roll) > .40 && abs($sideLoad) > 1.32*max(0,$normalLoad);
		$tipEnd = abs($pitch) > 1.02;
		$springs = $this->suspension;
		$this->spin = fmod($this->spin + $distance/self::TIRE_RADIUS, 2*M_PI);
		// A steep face below a flying car cannot apply torque until a tire actually touches it.
		$bodyTipped = abs($this->pitch) > 1.22 || abs($this->roll) > 1.22;
		if($bodyTipped || $gripFraction > 0 && ($tipSide || $tipEnd))
		{
			$pitchRate = max(-3.5,min(3.5,$springs->pitchRate)); $rollRate = max(-4.5,min(4.5,$springs->rollRate));
			if($tipEnd && $gripFraction > 0) $pitchRate += ($pitch <=> 0)*1.65;
			if($tipSide && $gripFraction > 0) $rollRate += ($sideLoad <=> 0)*2.1;
			$this->drift = 0;
			$this->tumble = new CarTumble($this->x,$this->y,$this->z,$this->yaw,$this->pitch,$this->roll,
				$this->speed*cos($this->roadPitch),$springs->verticalSpeed,$pitchRate,$rollRate);
			return $this->stepTumble($dt,$land,$waterLevel,$posts);
		}
		$this->x = $x; $this->z = $z; $this->yaw = atan2(sin($yaw), cos($yaw));
		$springs->step($dt,$support[4],$distance != 0);
		$this->y = $springs->y; $this->pitch = $springs->pitch; $this->roll = $springs->roll;
		if($springs->contacts > 0)
		{
			if($gripFraction == 0) $this->speed *= cos($this->roadPitch)/cos($pitch);
			$this->roadPitch = $pitch; $this->roadRoll = $roll;
			if($springs->contacts === 4 && self::safe($support)) $this->lastSafe = [$x,$z,$this->yaw];
		}
		return true;
	}

	/** Grounded, drivable locations retained as a fallback if a wreck settles on a cliff. */
	private static function safe(array $support): bool
	{
		return abs($support[1]) < .40 && abs($support[2]) < .40 && $support[3] < .38;
	}

	private function stepTumble(float $dt, Landscape $land, float $water, array $posts = []): bool
	{
		$body = $this->tumble;
		if($this->recoveryTime > 0)
		{
			$this->recoveryTime = max(0, $this->recoveryTime-$dt);
			if($this->recoveryTime > 1e-8) return true;
			return $this->rightCar($land,$water);
		}

		$before = [$this->x, $this->z];
		$body->advance($dt,$land,$water);
		[$this->x,$this->y,$this->z,$this->pitch,$this->roll,$this->speed] = $body->pose();
		if($posts)
		{
			[$x,$z] = [$this->x, $this->z];
			[$this->x,$this->z] = $before;
			$blocked = $this->avoidPosts($posts, $x, $z, $this->yaw);
			[$this->x,$this->z] = [$x, $z];
			if($blocked !== null)
			{
				$body->push($blocked[0] - $x, $blocked[1] - $z, $blocked[3], $blocked[4], self::POST_BOUNCE);
				[$this->x,$this->y,$this->z,$this->pitch,$this->roll,$this->speed] = $body->pose();
			}
		}
		if(!$body->onWheels && !$body->settled) return true;
		if($body->settled && $body->roofDown)
		{
			$this->speed = 0;
			$this->recoveryTime = self::RECOVERY_DELAY;
			return true;
		}
		// A half somersault plus a half barrel roll lands facing the other way.
		$reversed = cos($this->pitch) < 0;
		$heading = $reversed ? $this->yaw + M_PI : $this->yaw;
		$support = $this->support($land,$water,$this->x,$this->z,$heading);
		// Leave a margin below the tipping load and let sideways momentum dissipate before gripping.
		if($support !== null && abs($support[1]) < .98 && abs(tan($support[2])) < 1.22*cos($support[1]))
		{
			$this->speed /= cos($support[1]);
			if($reversed) $this->speed = -$this->speed;
			$this->yaw = atan2(sin($heading),cos($heading));
			$this->restOnWheels($support,$this->speed*sin($support[1]));
			$this->tumble = null;
		}
		elseif($body->settled) return $this->rightCar($land,$water);
		return true;
	}

	private function rightCar(Landscape $land, float $water): bool
	{
		$support = $this->support($land,$water,$this->x,$this->z,$this->yaw);
		if($support === null || !self::safe($support))
		{
			[$this->x,$this->z,$this->yaw] = $this->lastSafe;
			$support = $this->support($land,$water,$this->x,$this->z,$this->yaw);
			if($support === null || !self::safe($support)) return false;
		}
		$this->restOnWheels($support);
		$this->speed = $this->steering = $this->recoveryTime = 0;
		$this->tumble = null;
		$this->recoveries++;
		return true;
	}

	private function restOnWheels(array $support, float $verticalSpeed = 0): void
	{
		[$this->y,$this->pitch,$this->roll] = $support;
		$this->roadPitch = $this->pitch; $this->roadRoll = $this->roll;
		$this->suspension->reset($support,$verticalSpeed);
	}

	/**
	 * Keep the body out of every post near one step's travel. The step is checked every quarter metre from the
	 * current pose, so even at 36 m/s it cannot pass through a post between two checks. Between the last clear check
	 * and the first touching one, halving the gap finds the moment of contact to a millimetre, so the face the body
	 * met is known: a bumper corner that clips a post stops on it however the checks fell. The rest of the step then
	 * slides along that face, keeping only its travel along it.
	 * @param array $posts Solid posts as Course::posts() lists them.
	 * @param float $x World X the step would reach.
	 * @param float $z World Z the step would reach.
	 * @param float $yaw Heading the step would reach.
	 * @return array|null [x, z, yaw, normal X, normal Z] at the end of the step, clear of every post, with the outward
	 * normal of the face the body met, or null when the step touches no post.
	 */
	private function avoidPosts(array $posts, float $x, float $z, float $yaw): ?array
	{
		$travel = hypot($x - $this->x, $z - $this->z);
		$reach = self::BODY_FRONT + self::BODY_HALF_WIDTH + $travel;
		$near = [];
		foreach($posts as $post)
		{
			$range = $reach + 1.5*$post[2];
			if(abs($post[0] - $x) < $range && abs($post[1] - $z) < $range
				&& $this->y + self::BODY_HEIGHT > $post[5] && $this->y < $post[6]) $near[] = $post;
		}
		if(!$near) return null;
		$pose = fn(float $along): array => [$this->x + ($x - $this->x)*$along, $this->z + ($z - $this->z)*$along,
			$this->yaw + ($yaw - $this->yaw)*$along];
		$deepest = static function(array $at) use ($near): ?array {
			$deepest = null;
			foreach($near as $post)
			{
				$overlap = self::penetration($at[0], $at[1], $at[2], $post);
				if($overlap !== null && ($deepest === null || $overlap[0] > $deepest[0])) $deepest = $overlap;
			}
			return $deepest;
		};
		$checks = max(1, (int)ceil($travel/.25));
		$clear = 0.0;
		for($i = 1; $i <= $checks; $i++)
		{
			$touch = $i/$checks;
			if($deepest($pose($touch)) === null) { $clear = $touch; continue; }
			for($halving = 0; $halving < 8; $halving++)
			{
				$middle = ($clear + $touch)/2;
				if($deepest($pose($middle)) === null) $clear = $middle; else $touch = $middle;
			}
			$contact = $pose($touch);
			[, $normalX, $normalZ] = $deepest($contact);
			$restX = $x - $contact[0]; $restZ = $z - $contact[1];
			$inward = $restX*$normalX + $restZ*$normalZ;
			if($inward < 0) { $restX -= $inward*$normalX; $restZ -= $inward*$normalZ; }
			$px = $contact[0] + $restX; $pz = $contact[1] + $restZ;
			// Turning can still press the body into the face, and a push out of one post can press it into another,
			// so push out of the deepest overlap until clear.
			for($pass = 0; $pass < 4 && ($overlap = $deepest([$px,$pz,$yaw])) !== null; $pass++)
			{
				$px += $overlap[1]*($overlap[0] + 1e-4);
				$pz += $overlap[2]*($overlap[0] + 1e-4);
			}
			return [$px, $pz, $yaw, $normalX, $normalZ];
		}
		return null;
	}

	/**
	 * Measure how far the body overlaps one post, by separating axes between two rectangles seen from above.
	 * @param float $x World X of the midpoint between the axles.
	 * @param float $z World Z of the midpoint between the axles.
	 * @param float $yaw Heading of the body.
	 * @param array $post [x, z, half size, cos yaw, sin yaw, bottom, top] of a square post.
	 * @return array|null [depth, normal X, normal Z] of the shortest push that separates them, pointing from the post
	 * to the body, or null when they do not overlap.
	 */
	public static function penetration(float $x, float $z, float $yaw, array $post): ?array
	{
		$sin = sin($yaw); $cos = cos($yaw);
		$length = (self::BODY_FRONT + self::BODY_REAR)/2;
		$ahead = (self::BODY_FRONT - self::BODY_REAR)/2;
		[$px,$pz,$half,$postCos,$postSin] = $post;
		$dx = $x + $sin*$ahead - $px; $dz = $z + $cos*$ahead - $pz;
		$best = null;
		foreach([[$sin,$cos],[$cos,-$sin],[$postSin,$postCos],[$postCos,-$postSin]] as [$ux,$uz])
		{
			$body = $length*abs($sin*$ux + $cos*$uz) + self::BODY_HALF_WIDTH*abs($cos*$ux - $sin*$uz);
			$box = $half*(abs($postSin*$ux + $postCos*$uz) + abs($postCos*$ux - $postSin*$uz));
			$gap = $dx*$ux + $dz*$uz;
			$depth = $body + $box - abs($gap);
			if($depth <= 0) return null;
			if($best === null || $depth < $best[0]) $best = $gap < 0 ? [$depth,-$ux,-$uz] : [$depth,$ux,$uz];
		}
		return $best;
	}

	/** Whether a body standing at a pose would come within half a metre of any post. */
	private static function nearPost(array $posts, float $x, float $z, float $yaw, float $y): bool
	{
		foreach($posts as $post)
		{
			if($y + self::BODY_HEIGHT <= $post[5] || $y >= $post[6]) continue;
			$post[2] += .5;
			if(self::penetration($x, $z, $yaw, $post) !== null) return true;
		}
		return false;
	}

	/** Fit a plane through both axles. Water and the edge of the heightfield bound travel. */
	private function support(Landscape $land, float $waterLevel, float $x, float $z, float $yaw): ?array
	{
		$limit = $land->recipe()['worldSize'] / 2 - 2.6;
		if(abs($x) > $limit || abs($z) > $limit) return null;
		$sin = sin($yaw); $cos = cos($yaw);
		$heights = [];
		foreach([[self::FRONT_TRACK,1],[self::REAR_TRACK,-1]] as [$track,$axle])
		{
			$along = $axle * self::WHEELBASE / 2;
			foreach([-.5,.5] as $side)
			{
				$across = $side * $track;
				$h = $land->height($x + $along*$sin + $across*$cos, $z + $along*$cos - $across*$sin);
				if($h <= $waterLevel + .12) return null;
				$heights[] = $h;
			}
		}
		[$fl,$fr,$rl,$rr] = $heights;
		$rise = (($fl+$fr) - ($rl+$rr)) / (2*self::WHEELBASE);
		$frontBank = ($fr-$fl) / self::FRONT_TRACK;
		$rearBank = ($rr-$rl) / self::REAR_TRACK;
		$bank = ($frontBank+$rearBank) / 2;
		$y = array_sum($heights) / 4;
		$center = $land->height($x, $z);
		if($center <= $waterLevel + .12) return null;
		return [max($y,$center-.14) + .02,atan($rise),-atan($bank/sqrt(1+$rise*$rise)),max(abs($frontBank-$rearBank),abs($center-$y)),$heights];
	}

	public function state(): array
	{
		return ['x'=>$this->x,'y'=>$this->y,'z'=>$this->z,'yaw'=>$this->yaw,'pitch'=>$this->pitch,'roll'=>$this->roll,
			'speed'=>$this->speed,'steering'=>$this->steering,'drift'=>$this->drift,'wheelSpin'=>$this->spin,'terrainRevision'=>$this->revision,
			'wheelTravel'=>$this->tumble === null ? $this->suspension->travel : [0.0,0.0,0.0,0.0],
			'wheelContacts'=>$this->tumble === null ? $this->suspension->contacts : 0,
			'motion'=>$this->tumble === null ? ($this->suspension->contacts ? 'driving' : 'airborne') : ($this->recoveryTime > 0 ? 'overturned' : 'tumbling'),
			'recoveries'=>$this->recoveries];
	}
}

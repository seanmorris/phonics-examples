<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\Water;

/** Tire-supported bicycle steering and free chassis motion on the committed landscape. */
final class Car
{
	// Meters, measured from the baked Testarossa. Its origin is midway between the axles.
	public const WHEELBASE = 2.515, FRONT_TRACK = 1.57, REAR_TRACK = 1.644, TIRE_RADIUS = .297;
	/** Height of the simulated center of mass above the model's tire-sole origin, in meters. */
	public const CENTER_OF_MASS_HEIGHT = .50;
	public const TOP_SPEED_MPH = 240, MPS_PER_MPH = .44704;
	private const TOP_SPEED = self::TOP_SPEED_MPH * self::MPS_PER_MPH;
	// Keep the low-speed resistance while allowing a long straight to reach the speed governor.
	private const ROAD_DRAG = .0059, ROAD_DRAG_LIMIT = 30, AERO_DRAG = .0001;
	/** Full pedal braking in m/s² with all four tires on the ground. */
	private const SERVICE_BRAKE = 15.0;
	/** Share of downhill acceleration that pulls the steering downhill on a bank. */
	private const BANK_PULL = .16;
	private const BOOST_SECONDS = 4., REFILL_DISTANCE = 1320 * DisplayUnits::FOOT, RESTART_CHARGE = .03;
	private const BOOST_ACCEL = 13., BOOST_DOWNFORCE = 7.5; // m/s² at full flow.
	private float $nitrous = 1.;
	private bool $nitrousDepleted = false;
	private float $x = 0, $y = 0, $z = 0, $yaw = 0, $pitch = 0, $roll = 0;
	private float $speed = 0, $steering = 0, $spin = 0, $rearSpin = 0;
	private CarGearbox $gearbox;
	private float $squeal = 0.;
	/** Dry, loose tire contacts this step: world x/y/z, outward side, emission strength. */
	public array $dustContacts = [];
	/** Angle in radians from the direction of travel to the way the body points, while the tail slides out. */
	private float $drift = 0;
	/** Deceleration in meters a second squared from locked rear tires carrying all the rear load. */
	private const HANDBRAKE = 6.5;
	/**
	 * Largest drift the handbrake swings the tail to, in radians, and the time constant of that swing, in seconds.
	 * Once the rear tires roll again the drift dies away with its own time constant and a steady rate in radians a
	 * second, so it settles on zero.
	 */
	private const DRIFT = .6, DRIFT_IN = .35, DRIFT_OUT = .3, DRIFT_SETTLE = .3;
	private const RECOVERY_DELAY = 1.5;
	// Lateral load in multiples of tire normal load, and fore-aft slope in radians at tip-over.
	private const SIDE_TIP_LOAD = 1.45, END_TIP_PITCH = 1.02;
	// Approximate Testarossa intake, two feet above the tire soles and aft of the axle midpoint.
	private const INTAKE_HEIGHT = 2 * DisplayUnits::FOOT, INTAKE_AFT = .8;
	// Horizontal drag rate per meter of mean tire immersion.
	public const WATER_RESISTANCE = 2.4;
	/**
	 * The body's footprint and height in meters, measured from the baked Testarossa: half its width, and how far it
	 * reaches ahead of and behind the midpoint between the axles. Gate posts collide with this box.
	 */
	public const BODY_HALF_WIDTH = .97, BODY_FRONT = 2.37, BODY_REAR = 2.11, BODY_HEIGHT = 1.13;
	/**
	 * Share of the speed into a post that bounces back out, and the friction between the body and the post's face,
	 * which takes speed along the face in proportion to the speed into it.
	 */
	private const POST_BOUNCE = .25, POST_FRICTION = .5;
	/**
	 * The sharpest upward bend in meters a second squared, about 6 g, that the suspension gives the car's path while the
	 * ground turns up under it. Where the ground turns up faster, the car crashes into the new slope: it loses its speed
	 * into the slope and FACE_LOSS times that from its speed along it, for crushing and friction. FACE_SPEED is the old
	 * 36 m/s top speed. Up to it the car still rides up a steep face and rolls over as it always did; only speed beyond
	 * it is lost in the crash. At 240 mph a 30 m raise now throws the car about 47 m up instead of about 400 m.
	 */
	private const BEND_LIMIT = 60.0, FACE_LOSS = 1.5, FACE_SPEED = 36.0;
	private CarSuspension $suspension;
	private float $roadPitch = 0, $roadRoll = 0;
	private ?CarTumble $tumble = null;
	private float $recoveryTime = 0, $roadHold = 0;
	private array $lastSafe = [];
	private int $recoveries = 0;
	private bool $floodedHold = false;
	private int $revision = -1;
	private float $waterLevel = 0;
	/** Whether the brake lamps and the reversing lamps are switched on after the last step. */
	private bool $braking = false, $reversing = false;

	public function __construct() { $this->suspension = new CarSuspension; $this->gearbox = new CarGearbox; }

	/**
	 * Search the camera position first, then progressively wider rings. Each yield permits a frame boundary.
	 * @param array $posts Solid posts as Course::posts() lists them. A site whose body would stand within half a
	 * meter of one is passed over.
	 */
	public function findSite(Landscape $land, Water $water, array $eye, float $yaw, array $posts = [],
		bool $circuitStart = false, ?\Closure $floor = null): \Generator
	{
		if($circuitStart)
		{
			$site = $this->checkedSite($land, $water->heightRange()[1], $eye[0], $eye[2], $yaw, $posts);
			if($site !== null && ($floor === null || $floor($eye[0],$eye[2],$land->height($eye[0],$eye[2])))) return $site;
		}
		$half = $land->recipe()['worldSize'] / 2;
		$spacing = $land->recipe()['spacing'];
		$areaScale = min(1.0, ($half - 2.6) / 12);
		$radii = [0,4,12,32,64,128,256,512,900,1400,2200];
		if($floor !== null) array_push($radii,3000,4000,5000,6000);
		foreach($radii as $radius)
		{
			$count = match($radius) { 0 => 1, 4 => 4, 12 => 8, default => $floor !== null && $radius >= 512 ? 48 : 16 };
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
				$y = $land->height($x,$z);
				if($y <= $dry || ($floor !== null && !$floor($x,$z,$y))) continue;
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
					$site = $this->checkedSite($land, $water->heightRange()[1], $x, $z, $heading, $posts);
					if($site !== null) return $site;
				}
			}
		}
		return null;
	}

	/** Change the local map origin while retaining speed, drivetrain and contact forces. */
	public function translate(array $shift,Landscape $land,float $water): void
	{
		[$dx,$dy,$dz]=$shift;
		$this->x+=$dx; $this->y+=$dy; $this->z+=$dz;
		if($this->lastSafe) { $this->lastSafe[0]+=$dx; $this->lastSafe[1]+=$dz; }
		$this->suspension->translate($dy);
		$this->tumble?->translate($dx,$dy,$dz);
		$this->dustContacts=[];
		$this->revision=$land->revision(); $this->waterLevel=$water;
	}

	/** Check tire support, clearances and stability at a precise boarding pose. */
	public function checkedSite(Landscape $land, float $highWater, float $x, float $z, float $yaw, array $posts): ?array
	{
		$support = $this->support($land, $x, $z, $yaw);
		return $support !== null && $support[5] > $highWater + .12 && self::safe($support) && !self::nearPost($posts, $x, $z, $yaw, $support[0])
			? [$x,$z,$yaw,$support] : null;
	}

	/** Adopt a fully checked site only when the search succeeds. */
	public function spawn(array $site, Landscape $land, float $waterLevel): void
	{
		$this->place($site,$land,$waterLevel);
		$this->nitrous = 1.;
		$this->nitrousDepleted = false;
		$this->recoveries = 0;
		$this->roadHold = 0.;
	}

	/** Put the same car back in the race, retaining its bottle and charging the delay to the race clock. */
	public function recoverToRoad(array $site, Landscape $land, float $water, bool $flooded = false): void
	{
		$this->place($site,$land,$water);
		$this->recoveries++;
		$this->roadHold = self::RECOVERY_DELAY;
		$this->floodedHold = $flooded;
	}

	/** The last grounded, dry pose on the actual approach, including a reverse entry or skid. */
	public function shoreSite(Landscape $land, float $water, array $posts): ?array
	{
		[$x,$z,$yaw] = $this->lastSafe;
		return $this->checkedSite($land,$water,$x,$z,$yaw,$posts);
	}

	public function engineFlooded(float $water): bool
	{
		if($this->y > $water + self::INTAKE_HEIGHT + self::INTAKE_AFT) return false;
		$intake = $this->y + self::INTAKE_HEIGHT*cos($this->pitch)*cos($this->roll) - self::INTAKE_AFT*sin($this->pitch);
		return $water >= $intake;
	}

	/** Mean submerged tire depth in meters, including partially wet axles and free suspension travel. */
	private function immersion(float $water): float
	{
		if($this->y > $water + 2.) return 0.;
		$cp = cos($this->pitch);
		$bank = -$cp*sin($this->roll);
		$axle = .5*self::WHEELBASE*sin($this->pitch);
		$up = $cp*cos($this->roll);
		$travel = $this->suspension->travel;
		$front = $water - $this->y - $axle;
		$rear = $water - $this->y + $axle;
		return .25 * (max(0., $front + .5*self::FRONT_TRACK*$bank - $travel[0]*$up)
			+ max(0., $front - .5*self::FRONT_TRACK*$bank - $travel[1]*$up)
			+ max(0., $rear + .5*self::REAR_TRACK*$bank - $travel[2]*$up)
			+ max(0., $rear - .5*self::REAR_TRACK*$bank - $travel[3]*$up));
	}

	private function place(array $site, Landscape $land, float $waterLevel): void
	{
		[$this->x,$this->z,$this->yaw,$support] = $site;
		$this->restOnWheels($support);
		$this->speed = $this->steering = $this->spin = $this->rearSpin = $this->drift = 0;
		$this->gearbox = new CarGearbox;
		$this->squeal = 0.;
		$this->dustContacts = [];
		$this->tumble = null; $this->recoveryTime = 0;
		$this->braking = $this->reversing = false;
		$this->lastSafe = [$this->x,$this->z,$this->yaw];
		$this->revision = $land->revision();
		$this->waterLevel = $waterLevel;
	}

	/**
	 * Drive one fixed step. The keyboard gives whole pedal and steering values and a gamepad anything between.
	 * @param float $dt Fixed step in seconds.
	 * @param float $throttle -1 to 1. Positive drives forward. Negative brakes while rolling forward, then reverses.
	 * @param float $turn -1 to 1. Positive steers right.
	 * @param Landscape $land Ground under the wheels.
	 * @param float $waterLevel Water surface elevation in meters.
	 * @param bool $handbrake Lock the rear wheels.
	 * @param array $posts Solid posts as Course::posts() lists them. The body cannot pass through one.
	 * @param bool $nitrousHeld Hold to boost while driving forward.
	 * @return bool False when the engine floods or the ground no longer supports the car.
	 */
	public function step(float $dt, float $throttle, float $turn, Landscape $land, float $waterLevel,
		bool $handbrake = false, array $posts = [], bool $nitrousHeld = false): bool
	{
		if($this->engineFlooded($waterLevel)) return false;
		$supported = $this->drive($dt, $throttle, $turn, $land, $waterLevel, $handbrake, $posts, $nitrousHeld);
		if($this->engineFlooded($waterLevel)) return false;
		if($this->tumble !== null)
		{
			// A tumbling or overturned wreck rocks about zero speed, so the rule below would swap its rear lamps.
			// Its brake lamps follow the brake pedal and the handbrake alone, and its reversing lamps stay dark.
			$this->braking = $handbrake || $throttle < 0;
			$this->reversing = false;
			return $supported;
		}
		// The brake lamps follow an opposing pedal or the handbrake, even when the tires lose grip.
		$this->braking = $handbrake || $throttle * $this->speed < 0;
		// Only the reverse pedal can start the white lamps. Keep them on through the same backward coast.
		$coastingInReverse = $this->reversing && $this->speed < 0;
		$this->reversing = $this->gearbox->gear < 0 && ($coastingInReverse || $throttle < 0 && $this->speed <= 0);
		return $supported;
	}

	/** Move the car one fixed step for step(), which documents the parameters. */
	private function drive(float $dt, float $throttle, float $turn, Landscape $land, float $waterLevel,
		bool $handbrake, array $posts, bool $nitrousHeld): bool
	{
		if($dt <= 0) return true;
		$this->squeal = 0.;
		$this->dustContacts = [];
		if($this->revision !== $land->revision() || $this->waterLevel !== $waterLevel)
		{
			$support = $this->support($land, $this->x, $this->z, $this->yaw);
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
		if($this->roadHold > 0.) {
			$this->roadHold = max(0., $this->roadHold-$dt);
			if($this->roadHold < 1e-8) $this->roadHold = 0.;
			return true;
		}
		if($this->tumble !== null) return $this->stepTumble($dt,$land,$waterLevel,$posts);
		$boost = 0.;
		if($nitrousHeld && !$this->nitrousDepleted && $throttle >= 0. && $this->speed >= 0.
			&& $this->suspension->clearance < CarSuspension::BOOST_REACH)
		{
			$burn = min($this->nitrous, $dt/self::BOOST_SECONDS);
			$this->nitrous -= $burn;
			$boost = $burn*self::BOOST_SECONDS/$dt;
			if($this->nitrous < 1e-12)
			{
				$this->nitrous = 0.;
				$this->nitrousDepleted = true;
			}
		}
		$downforce = self::BOOST_DOWNFORCE*$boost;
		// Steering tightens at parking speeds. Swapping pedals brakes before engaging reverse.
		$speed = abs($this->speed);
		$target = -$turn * self::steeringLimit($speed);
		$gravitySide = Session::DRIVING_GRAVITY*sin($this->roadRoll);
		// Approximate steering pull as a small downhill curvature. Fade it in while rolling and out when the
		// front axle loses contact. The speed term keeps the lateral acceleration bounded at highway speeds.
		if($this->suspension->frontContacts > 0)
			$target += self::BANK_PULL*$gravitySide*self::WHEELBASE*min(1.0,$speed/4)/($speed**2 + 36.0);
		$this->steering += max(-2.5*$dt, min(2.5*$dt, $target - $this->steering));
		$gripFraction = $this->suspension->contacts/4;
		$rear = ($this->suspension->contacts - $this->suspension->frontContacts)/2;
		$driveAccel = $this->gearbox->step($dt,$this->speed,$throttle,$rear > 0.);
		$braking = $throttle*$this->speed < 0;
		if($gripFraction > 0)
		{
			$grade = sin($this->roadPitch);
			if($throttle == 0 && ($this->speed != 0 || abs($grade) > .18))
				$this->speed -= Session::DRIVING_GRAVITY*$grade*$dt*$gripFraction;
			$speed = abs($this->speed);
			// The low-speed handling curve gives way to aerodynamic drag on a long straight.
			$rolling = min($speed,self::ROAD_DRAG_LIMIT)*min(1.,40./max(1.,$speed));
			$drag = self::AERO_DRAG*$speed**2 + self::ROAD_DRAG*$rolling**2;
			if($throttle == 0)
				$this->speed = ($this->speed <=> 0)*max(0.0, $speed - (1.7 + $drag)*$gripFraction*$dt);
			else
			{
				// A landing can send the car backward faster than reverse gear can drive it.
				$reverseLimit = min(-8.0, $this->speed);
				$accel = $braking ? $throttle*self::SERVICE_BRAKE*$gripFraction : $driveAccel*$rear;
				// Reverse gear drives up to 8 m/s backward. Faster than that the pedal adds nothing, so drag and grade
				// slow the car instead of the clamp below holding its speed.
				if(!$braking && $throttle < 0 && $this->speed <= -8.0) $accel = 0.;
				$this->speed += ($accel - (($this->speed <=> 0)*$drag+Session::DRIVING_GRAVITY*$grade)*$gripFraction)*$dt;
				$pedal = abs($throttle);
				// A light pedal leaves some of the rolling resistance a released one would meet.
				if($pedal < 1)
					$this->speed = ($this->speed <=> 0)*max(0.0, abs($this->speed) - 1.7*(1 - $pedal)*$gripFraction*$dt);
				$this->speed = max($reverseLimit, $this->speed);
			}
			$this->speed = min(self::TOP_SPEED, $this->speed + self::BOOST_ACCEL*$boost*$gripFraction*$dt);
		}
		if($handbrake && $rear > 0 && $this->speed != 0)
			$this->speed = ($this->speed <=> 0)*max(0.0, abs($this->speed) - self::HANDBRAKE*$rear*$dt);
		if($this->drift != 0 && $gripFraction > 0)
		{
			// Tires sliding sideways scrub speed.
			$scrub = 3.0*abs(sin($this->drift))*$gripFraction*$dt;
			$this->speed = ($this->speed <=> 0)*max(0.0, abs($this->speed) - $scrub);
		}
		$depth = $this->immersion($waterLevel);
		if($depth > 0.) $this->speed /= 1. + self::WATER_RESISTANCE*$depth*$dt;
		if($this->speed == 0) $this->drift = 0;
		if($this->speed == 0 && $this->suspension->resting) return true;
		$distance = $this->speed * $dt;
		// An inward bank supplies some centripetal acceleration. Only the remainder needs tire grip.
		$grip = (Session::DRIVING_GRAVITY+$downforce)*cos($this->roadRoll)*cos($this->roadPitch);
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
		if($boost > 0. && $gripFraction > 0.)
		{
			// Ease the travel direction toward the front wheels. Take up sideslip first and let
			// a smaller share of the steering angle turn both the body and the path together.
			$align = 1. - exp(-3.*$dt*$boost*$gripFraction);
			$slipCorrection = $align*$this->drift;
			$steerCorrection = .30*$align*$this->steering*min(1.,$this->speed/8.);
			$this->drift -= $slipCorrection;
			$yaw += $steerCorrection;
			$heading += .5*($slipCorrection+$steerCorrection);
		}
		$x = $this->x + sin($heading)*$distance*cos($this->roadPitch);
		$z = $this->z + cos($heading)*$distance*cos($this->roadPitch);
		$struck = false;
		if($posts && ($blocked = $this->avoidPosts($posts, $x, $z, $yaw)) !== null)
		{
			$struck = true;
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
		$support = $this->support($land, $x, $z, $yaw);
		if($support === null)
		{
			$this->speed = $distance = 0;
			$x = $this->x; $z = $this->z; $yaw = $this->yaw;
			$support = $this->support($land,$x,$z,$yaw);
			if($support === null) return false;
		}
		[,$pitch,$roll] = $support;
		// The suspension bends the car's path upward by at most BEND_LIMIT. Where the ground turns up more sharply at
		// this speed, as at the foot of a steep face, the rest of the turn is a crash for the speed above FACE_SPEED:
		// it loses its part into the new slope, and FACE_LOSS times that from its part along it. The car then moves only as far as the slowed speed takes it, so the suspension meets only the rise it
		// really makes. Before this, a car at 240 mph kept all its speed up a steep face and flew about 400 m high.
		$turn = ($pitch - $this->roadPitch)*($this->speed <=> 0);
		$allowed = self::BEND_LIMIT*$dt/max(1.0, abs($this->speed));
		if($gripFraction > 0 && !$struck && $turn > $allowed && abs($this->speed) > self::FACE_SPEED)
		{
			$excess = min(M_PI/2, $turn - $allowed);
			$kept = max(0.0, cos($excess) - self::FACE_LOSS*sin($excess));
			$factor = (self::FACE_SPEED + (abs($this->speed) - self::FACE_SPEED)*$kept)/abs($this->speed);
			$this->speed *= $factor;
			$distance *= $factor;
			$x = $this->x + sin($heading)*$distance*cos($this->roadPitch);
			$z = $this->z + cos($heading)*$distance*cos($this->roadPitch);
			if(($slowed = $this->support($land, $x, $z, $yaw)) !== null) [,$pitch,$roll] = $support = $slowed;
		}
		// Downhill gravity and outward cornering load act together on an adverse camber.
		$sideLoad = Session::DRIVING_GRAVITY*sin($roll) - $lateral*cos($roll);
		$normalLoad = (Session::DRIVING_GRAVITY+$downforce)*cos($pitch)*cos($roll) + $lateral*sin($roll);
		$tipSide = abs($roll) > .40 && abs($sideLoad) > self::SIDE_TIP_LOAD*max(0,$normalLoad);
		$tipEnd = abs($pitch) > self::END_TIP_PITCH;
		$springs = $this->suspension;
		// Under heavy service braking a rolling tire runs slightly slower than the road.
		// Use the same lost tread travel for the visible wheel rotation and the contact sound.
		$brakeRatio = $braking && $gripFraction > 0. ? .4*max(0.,1.-$grip/(abs($throttle)*self::SERVICE_BRAKE)) : 0.;
		$treadTravel = $distance*(1.-$brakeRatio);
		$this->spin = fmod($this->spin + $treadTravel/self::TIRE_RADIUS, 2*M_PI);
		$spinSlip = ($throttle <=> 0)*$this->gearbox->wheelSlip;
		if(!$handbrake) $this->rearSpin = fmod($this->rearSpin + ($treadTravel + $spinSlip*$dt)/self::TIRE_RADIUS, 2*M_PI);
		// A steep face below a flying car cannot apply torque until a tire actually touches it.
		$bodyTipped = abs($this->pitch) > 1.22 || abs($this->roll) > 1.22;
		if($bodyTipped || $gripFraction > 0 && ($tipSide || $tipEnd))
		{
			$pitchRate = max(-3.5,min(3.5,$springs->pitchRate)); $rollRate = max(-4.5,min(4.5,$springs->rollRate));
			if($tipEnd && $gripFraction > 0) $pitchRate += ($pitch <=> 0)*1.65;
			if($tipSide && $gripFraction > 0) $rollRate += ($sideLoad <=> 0)*2.1;
			$horizontal = $this->speed*cos($this->roadPitch);
			$slip = $heading - $this->yaw;
			$this->tumble = new CarTumble($this->x,$this->y,$this->z,$this->yaw,$this->pitch,$this->roll,
				$horizontal*cos($slip),$springs->verticalSpeed,$pitchRate,$rollRate,$horizontal*sin($slip));
			$this->gearbox = new CarGearbox;
			$this->drift = 0;
			return $this->stepTumble($dt,$land,$waterLevel,$posts);
		}
		if(!$nitrousHeld && $gripFraction > 0. && $distance > 0. && $this->nitrous < 1.)
		{
			$travel = $distance;
			if($struck)
			{
				// A post can stop the step early or push the body aside. Count only forward progress.
				$forward = ($x-$this->x)*sin($heading) + ($z-$this->z)*cos($heading);
				$travel = max(0., min($distance, $forward/cos($this->roadPitch)));
			}
			$this->nitrous = min(1., $this->nitrous + $travel/self::REFILL_DISTANCE);
			if($this->nitrous >= self::RESTART_CHARGE) $this->nitrousDepleted = false;
		}
		$this->x = $x; $this->z = $z; $this->yaw = atan2(sin($yaw), cos($yaw));
		// The slope already sets the resting chassis attitude. Apply the unbalanced side load as the tires corner.
		$cornerLoad = $sideLoad*min(1.,abs($lateral)/2.);
		$springs->step($dt,$support[4],$distance != 0,$downforce,$cornerLoad);
		$this->y = $springs->y; $this->pitch = $springs->pitch; $this->roll = $springs->roll;
		if($springs->contacts > 0)
		{
			if($gripFraction == 0) $this->speed *= cos($this->roadPitch)/cos($pitch);
			$this->roadPitch = $pitch; $this->roadRoll = $roll;
			if($springs->contacts === 4 && $support[5] > $waterLevel + .12 && self::safe($support)
				&& !self::nearPost($posts,$x,$z,$this->yaw,$support[0])) $this->lastSafe = [$x,$z,$this->yaw];
			// Ground velocity across the rear axle and across the steered front wheels (m/s).
			// In a gripping bicycle turn, yaw rate supplies exactly the sideways travel the steering calls for.
			$along = $this->speed*cos($this->drift);
			$side = -$this->speed*sin($this->drift);
			$frontSide = ($side + $rotation/$dt*self::WHEELBASE)*cos($this->steering) - $along*sin($this->steering);
			$brakeSlip = $this->speed*$brakeRatio;
			$this->tireEffects($land,$waterLevel,$support[4],hypot($frontSide,$brakeSlip),
				hypot($side,$handbrake ? $along : $spinSlip-$brakeSlip),$boost);
		}
		return true;
	}

	private function tireEffects(Landscape $land, float $water, array $heights, float $front, float $rear, float $boost): void
	{
		if(max($front,$rear) < .16 && $boost < .05) return;
		$sin = sin($this->yaw); $cos = cos($this->yaw);
		$power = 0.;
		foreach($heights as $i => $height)
		{
			if($height <= $water || $this->suspension->travel[$i] <= -CarSuspension::SAG) continue;
			$slip = $i < 2 ? $front : $rear;
			$dust = min(1.,max($slip/4.,$i < 2 ? 0. : .8*$boost));
			if($dust < .04) continue;
			$along = ($i < 2 ? .5 : -.5)*self::WHEELBASE;
			$side = $i % 2 ? 1 : -1;
			$across = .5*$side*($i < 2 ? self::FRONT_TRACK : self::REAR_TRACK);
			$x = $this->x + $along*$sin + $across*$cos;
			$z = $this->z + $along*$cos - $across*$sin;
			if(self::onPavement($land,$x,$z))
			{
				// Quiet tire scrub below 1 m/s; a fast locked wheel reaches full voice at 17 m/s.
				$t = max(0.,min(1.,($slip-1.)/16.));
				$level = $t*$t*(3.-2.*$t);
				$power += $level*$level;
			}
			else $this->dustContacts[] = [$x,$height,$z,$side,$dust];
		}
		$this->squeal = sqrt($power/4.);
	}

	/** A road brush paints its whole footprint. A graded spline paints only its pavement width. */
	public static function onPavement(Landscape $land, float $x, float $z): bool
	{
		return $land->material($x,$z,$offset,$mark) === 'road'
			&& ($mark === null ? $offset === null : abs($mark[0]) <= $mark[2]);
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
		[$this->x,$this->y,$this->z,$this->pitch,$this->roll,$this->speed,$side] = $body->pose();
		if($posts)
		{
			[$x,$z] = [$this->x, $this->z];
			[$this->x,$this->z] = $before;
			$blocked = $this->avoidPosts($posts, $x, $z, $this->yaw);
			[$this->x,$this->z] = [$x, $z];
			if($blocked !== null)
			{
				$body->push($blocked[0] - $x, $blocked[1] - $z, $blocked[3], $blocked[4], self::POST_BOUNCE);
				[$this->x,$this->y,$this->z,$this->pitch,$this->roll,$this->speed,$side] = $body->pose();
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
		$support = $this->support($land,$this->x,$this->z,$heading);
		// Leave a margin below the tipping load so the same bank cannot immediately start another tumble.
		if($support !== null && abs($support[1]) < self::END_TIP_PITCH-.04
			&& abs(tan($support[2])) < (self::SIDE_TIP_LOAD-.10)*cos($support[1]))
		{
			$forward = $reversed ? -$this->speed : $this->speed;
			if($reversed) $side = -$side;
			// The tire model travels along yaw minus drift, with signed speed for a backward skid.
			$sign = $forward < 0 ? -1 : 1;
			$this->drift = atan2(-$sign*$side,abs($forward));
			$this->speed = $sign*hypot($forward,$side)/cos($support[1]);
			$this->yaw = atan2(sin($heading),cos($heading));
			$verticalSpeed = ($forward*sin($support[1]) - $side*tan($support[2]))/cos($support[1]);
			$this->restOnWheels($support,$verticalSpeed);
			$this->tumble = null;
		}
		elseif($body->settled) return $this->rightCar($land,$water);
		return true;
	}

	private function rightCar(Landscape $land, float $water): bool
	{
		$support = $this->support($land,$this->x,$this->z,$this->yaw);
		if($support === null || !self::safe($support))
		{
			[$this->x,$this->z,$this->yaw] = $this->lastSafe;
			$support = $this->support($land,$this->x,$this->z,$this->yaw);
			if($support === null || !self::safe($support)) return false;
		}
		$this->restOnWheels($support);
		$this->speed = $this->steering = $this->recoveryTime = 0;
		$this->gearbox = new CarGearbox;
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
	 * Keep the body out of every post near one step's travel. The step is checked every quarter meter from the
	 * current pose, so even at top speed it cannot pass through a post between two checks. Between the last clear check
	 * and the first touching one, halving the gap finds the moment of contact to a millimeter, so the face the body
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

	/** Whether a body standing at a pose would come within half a meter of any post. */
	private static function nearPost(array $posts, float $x, float $z, float $yaw, float $y): bool
	{
		foreach($posts as $post)
		{
			$reach = self::BODY_FRONT + self::BODY_HALF_WIDTH + 1.5*($post[2]+.5);
			if(abs($x-$post[0]) > $reach || abs($z-$post[1]) > $reach
				|| $y + self::BODY_HEIGHT <= $post[5] || $y >= $post[6]) continue;
			$post[2] += .5;
			if(self::penetration($x, $z, $yaw, $post) !== null) return true;
		}
		return false;
	}

	/** Fit a plane through both axles and retain the lowest footprint sample for dry recovery sites. */
	private function support(Landscape $land, float $x, float $z, float $yaw): ?array
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
		return [max($y,$center-.14) + .02,atan($rise),-atan($bank/sqrt(1+$rise*$rise)),max(abs($frontBank-$rearBank),abs($center-$y)),$heights,min($center,$fl,$fr,$rl,$rr)];
	}

	/** Ackermann angles at the front -X and +X wheels for the simulated centerline steering angle. */
	public static function frontWheelAngles(float $steering): array
	{
		$tangent = tan($steering);
		$offset = $tangent*self::FRONT_TRACK/(2*self::WHEELBASE);
		return [atan($tangent/(1 + $offset)), atan($tangent/(1 - $offset))];
	}

	/** Airborne roll speed halves in 3.5 seconds. Pitch carries longer through a jump. */
	public static function dampAirborneSpin(float &$pitchRate, float &$rollRate, float $dt): void
	{
		$pitchRate *= 2 ** (-$dt/28.);
		$rollRate *= 2 ** (-$dt/3.5);
	}

	/** World-space offset from the tire soles to the center of mass at this attitude. */
	public static function massOffset(float $yaw, float $pitch, float $roll): array
	{
		$sinYaw = sin($yaw); $cosYaw = cos($yaw); $cosRoll = cos($roll);
		$side = self::CENTER_OF_MASS_HEIGHT*sin($roll);
		$forward = -self::CENTER_OF_MASS_HEIGHT*sin($pitch)*$cosRoll;
		return [$cosYaw*$side + $sinYaw*$forward, self::CENTER_OF_MASS_HEIGHT*cos($pitch)*$cosRoll,
			-$sinYaw*$side + $cosYaw*$forward];
	}

	public static function centerOfMass(array $pose): array
	{
		[$dx,$dy,$dz] = self::massOffset($pose['yaw'], $pose['pitch'], $pose['roll']);
		return [$pose['x'] + $dx, $pose['y'] + $dy, $pose['z'] + $dz];
	}

	/** Aim terrain detail three quarters of a second along the car's signed path, including a skid or reverse. */
	public static function terrainFocus(array $pose): array
	{
		$heading = $pose['yaw'] - $pose['drift'];
		$lead = in_array($pose['motion'], ['driving','airborne'], true) ? .75 * $pose['speed'] * cos($pose['pitch']) : 0.;
		return [$pose['x'] + $lead*sin($heading), $pose['z'] + $lead*cos($heading)];
	}

	public static function steeringLimit(float $speed): float { return .52 / (1 + .035 * abs($speed)); }

	/** Rates and timers that a picture of the car cannot describe, for a controllable demo branch. */
	public function motion(): array
	{
		return [$this->roadPitch,$this->roadRoll,$this->recoveryTime,$this->roadHold,(int)$this->floodedHold,
			$this->recoveries,(int)$this->nitrousDepleted,(int)$this->braking,(int)$this->reversing,
			...($this->lastSafe ?: [$this->x,$this->z,$this->yaw]),...$this->gearbox->motion(),
			...$this->suspension->motion(),...($this->tumble?->motion() ?? [])];
	}

	public function resume(array $p,array $motion,Landscape $land,float $water): void
	{
		$support=$this->support($land,$p['x'],$p['z'],$p['yaw']);
		if($support===null) throw new \InvalidArgumentException('Recorded car is outside this landscape.');
		foreach(['x','y','z','yaw','pitch','roll','speed','steering','drift','nitrous'] as $k) $this->$k=$p[$k];
		$this->spin=$p['wheelSpin']; $this->rearSpin=$p['rearWheelSpin']; $this->squeal=$p['tireSqueal'];
		$motion = $motion ?: [$p['pitch'],$p['roll'],0,0,0,0,0,0,0,$p['x'],$p['z'],$p['yaw'],1,0,0,0,0,0,0,0];
		[$this->roadPitch,$this->roadRoll,$this->recoveryTime,$this->roadHold,$flooded,$recoveries,$depleted,$brake,$reverse]=$motion;
		$this->floodedHold=(bool)$flooded; $this->recoveries=(int)$recoveries; $this->nitrousDepleted=(bool)$depleted;
		$this->braking=(bool)$brake; $this->reversing=(bool)$reverse;
		$this->lastSafe=array_slice($motion,9,3);
		$this->gearbox->resume(array_slice($motion,12,3),$p['rpm'],$p['engineLoad']);
		$this->suspension->resume($p,$support,array_slice($motion,15,5));
		$this->tumble=count($motion)===26 ? CarTumble::resume($p,array_slice($motion,20)) : null;
		$this->dustContacts=[]; $this->revision=$land->revision(); $this->waterLevel=$water;
	}

	public function state(): array
	{
		return ['x'=>$this->x,'y'=>$this->y,'z'=>$this->z,'yaw'=>$this->yaw,'pitch'=>$this->pitch,'roll'=>$this->roll,
			'speed'=>$this->speed,'nitrous'=>$this->nitrous,'steering'=>$this->steering,'drift'=>$this->drift,'wheelSpin'=>$this->spin,'terrainRevision'=>$this->revision,
			'rearWheelSpin'=>$this->rearSpin,'gear'=>$this->gearbox->gear,'rpm'=>$this->gearbox->rpm,
			'engineLoad'=>$this->gearbox->load,'tireSqueal'=>$this->squeal,
			'wheelTravel'=>$this->tumble === null ? $this->suspension->travel : [0.0,0.0,0.0,0.0],
			'wheelContacts'=>$this->tumble === null ? $this->suspension->contacts : 0,
			'motion'=>$this->tumble === null ? ($this->suspension->contacts ? 'driving' : 'airborne') : ($this->recoveryTime > 0 ? 'overturned' : 'tumbling'),
			'roadHold'=>$this->roadHold,'floodedHold'=>$this->roadHold > 0 && $this->floodedHold,'recoveries'=>$this->recoveries,'braking'=>$this->braking,'reversing'=>$this->reversing];
	}
}

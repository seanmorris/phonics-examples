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
	private const RECOVERY_DELAY = 1.5;
	private CarSuspension $suspension;
	private float $roadPitch = 0, $roadRoll = 0;
	private ?CarTumble $tumble = null;
	private float $recoveryTime = 0;
	private array $lastSafe = [];
	private int $recoveries = 0;
	private int $revision = -1;
	private float $waterLevel = 0;

	public function __construct() { $this->suspension = new CarSuspension; }

	/** Search the camera position first, then progressively wider rings. Each yield permits a frame boundary. */
	public function findSite(Landscape $land, Water $water, array $eye, float $yaw): \Generator
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
					if($support !== null && self::safe($support)) return [$x,$z,$heading,$support];
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
		$this->speed = $this->steering = $this->spin = 0;
		$this->tumble = null; $this->recoveryTime = 0; $this->recoveries = 0;
		$this->lastSafe = [$this->x,$this->z,$this->yaw];
		$this->revision = $land->revision();
		$this->waterLevel = $waterLevel;
	}

	/** Return false if a water-level change or published terrain edit removes support at the current pose. */
	public function step(float $dt, int $throttle, int $turn, Landscape $land, float $waterLevel): bool
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
		if($this->tumble !== null) return $this->stepTumble($dt,$land,$waterLevel);
		// Steering tightens at parking speeds. Swapping pedals brakes before engaging reverse.
		$target = -$turn * .52 / (1 + .035 * abs($this->speed));
		$this->steering += max(-2.5*$dt, min(2.5*$dt, $target - $this->steering));
		$gripFraction = $this->suspension->contacts/4;
		$grade = sin($this->roadPitch);
		if($gripFraction > 0 && $throttle === 0)
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
			$this->speed = min(36, max(-8, $this->speed));
		}
		if($this->speed == 0 && $this->suspension->resting) return true;
		$distance = $this->speed * $dt;
		// An inward bank supplies some centripetal acceleration. Only the remainder needs tire grip.
		$gravitySide = 9.81*sin($this->roadRoll);
		$grip = 9.81*cos($this->roadRoll)*cos($this->roadPitch);
		$demand = $this->speed**2*tan($this->steering)/self::WHEELBASE;
		$direction = $demand <=> 0;
		$lateral = $direction*min(abs($demand), max(0.0, $grip+$direction*$gravitySide))*$this->suspension->frontContacts/2;
		$rotation = $this->speed == 0 ? 0.0 : $lateral*$dt/$this->speed;
		$heading = $this->yaw + .5*$rotation;
		$x = $this->x + sin($heading)*$distance*cos($this->roadPitch);
		$z = $this->z + cos($heading)*$distance*cos($this->roadPitch);
		$yaw = $this->yaw + $rotation;
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
			$this->tumble = new CarTumble($this->x,$this->y,$this->z,$this->yaw,$this->pitch,$this->roll,
				$this->speed*cos($this->roadPitch),$springs->verticalSpeed,$pitchRate,$rollRate);
			return $this->stepTumble($dt,$land,$waterLevel);
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

	private function stepTumble(float $dt, Landscape $land, float $water): bool
	{
		$body = $this->tumble;
		if($this->recoveryTime > 0)
		{
			$this->recoveryTime = max(0, $this->recoveryTime-$dt);
			if($this->recoveryTime > 1e-8) return true;
			return $this->rightCar($land,$water);
		}

		$body->advance($dt,$land,$water);
		[$this->x,$this->y,$this->z,$this->pitch,$this->roll,$this->speed] = $body->pose();
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
			'speed'=>$this->speed,'steering'=>$this->steering,'wheelSpin'=>$this->spin,'terrainRevision'=>$this->revision,
			'wheelTravel'=>$this->tumble === null ? $this->suspension->travel : [0.0,0.0,0.0,0.0],
			'wheelContacts'=>$this->tumble === null ? $this->suspension->contacts : 0,
			'motion'=>$this->tumble === null ? ($this->suspension->contacts ? 'driving' : 'airborne') : ($this->recoveryTime > 0 ? 'overturned' : 'tumbling'),
			'recoveries'=>$this->recoveries];
	}
}

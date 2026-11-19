<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\Water;

/** Kinematic bicycle steering and four tire contacts on the committed landscape. */
final class Car
{
	// Metres, measured from the baked Testarossa. Its origin is midway between the axles.
	public const WHEELBASE = 2.515, FRONT_TRACK = 1.57, REAR_TRACK = 1.644, TIRE_RADIUS = .297;
	private float $x = 0, $y = 0, $z = 0, $yaw = 0, $pitch = 0, $roll = 0;
	private float $speed = 0, $steering = 0, $spin = 0;
	private int $revision = -1;
	private float $waterLevel = 0;

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
					if($support !== null) return [$x,$z,$heading,$support];
				}
			}
		}
		return null;
	}

	/** Adopt a fully checked site only when the search succeeds. */
	public function spawn(array $site, Landscape $land, float $waterLevel): void
	{
		[$this->x,$this->z,$this->yaw,$support] = $site;
		[$this->y,$this->pitch,$this->roll] = $support;
		$this->speed = $this->steering = $this->spin = 0;
		$this->revision = $land->revision();
		$this->waterLevel = $waterLevel;
	}

	/** Return false if a water-level change or published terrain edit removes support at the current pose. */
	public function step(float $dt, int $throttle, int $turn, Landscape $land, float $waterLevel): bool
	{
		if($this->revision !== $land->revision() || $this->waterLevel !== $waterLevel)
		{
			$support = $this->support($land, $waterLevel, $this->x, $this->z, $this->yaw);
			if($support === null) return false;
			[$this->y,$this->pitch,$this->roll] = $support;
			$this->revision = $land->revision(); $this->waterLevel = $waterLevel;
		}
		// Steering tightens at parking speeds. Swapping pedals brakes before engaging reverse.
		$target = -$turn * .52 / (1 + .035 * abs($this->speed));
		$this->steering += max(-2.5*$dt, min(2.5*$dt, $target - $this->steering));
		if($throttle === 0)
		{
			$drag = (1.7 + .006*$this->speed**2)*$dt;
			$this->speed = ($this->speed <=> 0) * max(0.0, abs($this->speed) - $drag);
		}
		else
		{
			$power = $throttle*$this->speed < 0 ? 13 : ($throttle > 0 ? 8 : 4);
			$this->speed += ($throttle*$power - .006*$this->speed*abs($this->speed) - 9.81*sin($this->pitch))*$dt;
			$this->speed = min(36, max(-8, $this->speed));
		}
		if($this->speed == 0) return true;
		$distance = $this->speed * $dt;
		$rotation = $distance * tan($this->steering) / self::WHEELBASE;
		$heading = $this->yaw + .5*$rotation;
		$x = $this->x + sin($heading)*$distance*cos($this->pitch);
		$z = $this->z + cos($heading)*$distance*cos($this->pitch);
		$yaw = $this->yaw + $rotation;
		$support = $this->support($land, $waterLevel, $x, $z, $yaw);
		if($support === null || abs($support[0] - $this->y) > .12 + abs($distance)*.58)
		{
			$this->speed = 0;
			return true;
		}
		$this->x = $x; $this->z = $z; $this->yaw = atan2(sin($yaw), cos($yaw));
		[$this->y,$this->pitch,$this->roll] = $support;
		$this->spin = fmod($this->spin + $distance/self::TIRE_RADIUS, 2*M_PI);
		return true;
	}

	/** Fit a support plane through the two axles and reject submerged tires, ledges and high-centering. */
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
		if(hypot($rise,$bank) > .58 || abs($frontBank-$rearBank) > .38
			|| $center <= $waterLevel + .12 || abs($center-$y) > .22) return null;
		return [$y + .02,atan($rise),-atan($bank)];
	}

	public function state(): array
	{
		return ['x'=>$this->x,'y'=>$this->y,'z'=>$this->z,'yaw'=>$this->yaw,'pitch'=>$this->pitch,'roll'=>$this->roll,
			'speed'=>$this->speed,'steering'=>$this->steering,'wheelSpin'=>$this->spin,'terrainRevision'=>$this->revision];
	}
}

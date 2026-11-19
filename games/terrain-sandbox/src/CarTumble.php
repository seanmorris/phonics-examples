<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Free translation, pitch and roll about the centre of mass after the tires lose support. */
final class CarTumble
{
	private const CG = .50;
	// Ground contacts at the tires, bumpers and roof, in model coordinates measured from the tire soles.
	private const CONTACTS = [
		[-.80,0,1.26],[.80,0,1.26],[-.82,0,-1.26],[.82,0,-1.26],
		[-.91,.23,2.04],[.91,.23,2.04],[-.91,.23,-2.04],[.91,.23,-2.04],
		[-.65,1.12,.73],[.65,1.12,.73],[-.65,1.12,-.77],[.65,1.12,-.77],
	];
	// Inverse moments per unit mass about the transverse and longitudinal axes, in m⁻².
	private const INV_PITCH = .64, INV_ROLL = 2.7;
	private float $sinYaw, $cosYaw;
	private float $x, $y, $z, $side = 0, $ahead, $up;
	private float $quiet = 0;
	public bool $onWheels = false, $settled = false, $roofDown = false;

	public function __construct(float $x, float $y, float $z, float $yaw,
		public float $pitch, public float $roll, float $speed, float $verticalSpeed,
		private float $pitchRate, private float $rollRate)
	{
		$this->sinYaw = sin($yaw); $this->cosYaw = cos($yaw);
		[$ox,$oy,$oz] = $this->offsetToCG();
		$this->x = $x + $ox; $this->y = $y + $oy; $this->z = $z + $oz;
		$this->ahead = $speed*cos($pitch); $this->up = $verticalSpeed;
	}

	private function offsetToCG(): array
	{
		$side = self::CG*sin($this->roll);
		$forward = -self::CG*sin($this->pitch)*cos($this->roll);
		return [$this->cosYaw*$side + $this->sinYaw*$forward, self::CG*cos($this->pitch)*cos($this->roll),
			-$this->sinYaw*$side + $this->cosYaw*$forward];
	}

	/** Render origin stays at the tire soles even when the centre of mass is below it. */
	public function pose(): array
	{
		[$ox,$oy,$oz] = $this->offsetToCG();
		return [$this->x-$ox,$this->y-$oy,$this->z-$oz,$this->pitch,$this->roll,$this->ahead];
	}

	public function wake(): void
	{
		$this->quiet = 0;
		$this->settled = $this->onWheels = false;
	}

	public function advance(float $dt, Landscape $land, float $water): void
	{
		$this->onWheels = false;
		$steps = max(1, (int)ceil($dt*120));
		for($i = 0; $i < $steps && !$this->settled; $i++)
			$this->integrate($dt/$steps, $land, $water);
	}

	private function integrate(float $dt, Landscape $land, float $water): void
	{
		$this->up -= 9.81*$dt;
		$vx = $this->cosYaw*$this->side + $this->sinYaw*$this->ahead;
		$vz = -$this->sinYaw*$this->side + $this->cosYaw*$this->ahead;
		$edge = $land->recipe()['worldSize']/2 - 2.6;
		$x = max(-$edge, min($edge, $this->x+$dt*$vx));
		$z = max(-$edge, min($edge, $this->z+$dt*$vz));
		if($land->height($x,$z) <= $water+.12)
		{
			$this->side = $this->ahead = 0;
		}
		else
		{
			if(abs($x) === $edge) $vx = 0;
			if(abs($z) === $edge) $vz = 0;
			$this->side = $this->cosYaw*$vx - $this->sinYaw*$vz;
			$this->ahead = $this->sinYaw*$vx + $this->cosYaw*$vz;
			$this->x = $x; $this->z = $z;
		}
		$this->y += $dt*$this->up;
		$this->pitch += $dt*$this->pitchRate; $this->roll += $dt*$this->rollRate;
		$cp = cos($this->pitch); $sp = sin($this->pitch);
		$cr = cos($this->roll); $sr = sin($this->roll);
		$contacts = []; $wheels = 0; $upDot = 0.0;
		foreach(self::CONTACTS as $index => [$x,$y,$z])
		{
			$y -= self::CG;
			$side = $cr*$x + $sr*$y; $above = -$sr*$x + $cr*$y;
			$ahead = $cp*$z - $sp*$above; $up = $cp*$above + $sp*$z;
			$px = $this->x + $this->cosYaw*$side + $this->sinYaw*$ahead;
			$pz = $this->z - $this->sinYaw*$side + $this->cosYaw*$ahead;
			$surface = $land->sample($px,$pz);
			$depth = $surface['height'] - ($this->y + $up);
			if($depth < -.012) continue;
			[$nx,$ny,$nz] = $surface['normal'];
			$normal = [$this->cosYaw*$nx - $this->sinYaw*$nz,$ny,$this->sinYaw*$nx + $this->cosYaw*$nz];
			$upDot += $sr*$normal[0] + $cp*$cr*$normal[1] - $sp*$cr*$normal[2];
			// Derivatives of the contact position with respect to pitch and roll.
			$contacts[] = [$normal,[0,$ahead,-$up],[$above,-$cp*$side,$sp*$side],
				min(3.0, max(0.0, $depth*$ny-.004)*.22/$dt)];
			if($index < 4) $wheels++;
		}
		// Reuse the sampled contacts for both passes so simultaneous roof/tire contacts share the load.
		for($i = 0; $i < 2; $i++)
		{
			foreach($contacts as [$normal,$pitchArm,$rollArm,$bias])
			{
				[$ns,$nu,$na] = $normal;
				$v = $this->contactVelocity($pitchArm,$rollArm);
				$vn = $v[0]*$ns + $v[1]*$nu + $v[2]*$na;
				if($vn >= $bias) continue;
				$normalImpulse = $this->impulse($normal,$pitchArm,$rollArm,$bias-$vn,INF);
				$v = $this->contactVelocity($pitchArm,$rollArm);
				$vn = $v[0]*$ns + $v[1]*$nu + $v[2]*$na;
				$side = $v[0]-$vn*$ns; $up = $v[1]-$vn*$nu; $ahead = $v[2]-$vn*$na;
				$slip = sqrt($side*$side+$up*$up+$ahead*$ahead);
				if($slip > .0001)
					$this->impulse([-$side/$slip,-$up/$slip,-$ahead/$slip],$pitchArm,$rollArm,$slip,.72*$normalImpulse);
			}
		}
		$angular = hypot($this->pitchRate,$this->rollRate);
		$this->roofDown = $contacts && $upDot < .1*count($contacts);
		$this->onWheels = $wheels >= 3 && !$this->roofDown && $angular < 1.1 && abs($this->side) < 1.8;
		$slow = $contacts && $angular < .27 && hypot($this->side,$this->ahead) < .24 && abs($this->up) < .22;
		$this->quiet = $slow ? $this->quiet + $dt : 0;
		$this->settled = $this->quiet >= .25;
	}

	private function contactVelocity(array $p, array $r): array
	{
		return [$this->side + $this->rollRate*$r[0],
			$this->up + $this->pitchRate*$p[1] + $this->rollRate*$r[1],
			$this->ahead + $this->pitchRate*$p[2] + $this->rollRate*$r[2]];
	}

	private function impulse(array $axis, array $p, array $r, float $delta, float $limit): float
	{
		[$s,$u,$a] = $axis;
		$pitch = $u*$p[1] + $a*$p[2];
		$roll = $s*$r[0] + $u*$r[1] + $a*$r[2];
		$j = min($limit, $delta/(1 + self::INV_PITCH*$pitch**2 + self::INV_ROLL*$roll**2));
		$this->side += $j*$s; $this->up += $j*$u; $this->ahead += $j*$a;
		$this->pitchRate += $j*self::INV_PITCH*$pitch;
		$this->rollRate += $j*self::INV_ROLL*$roll;
		return $j;
	}
}

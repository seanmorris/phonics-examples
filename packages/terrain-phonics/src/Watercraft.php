<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Four-probe arcade hull with fixed substeps, buoyancy, planing, drag, and heightfield shore contacts. */
final class Watercraft
{
	/** Hull half extents and keel depth, in metres. */
	private const WIDTH = .65, LENGTH = 1.25, KEEL = .32;
	/** Full-throttle acceleration in m/s² with the jet intake in the water. */
	private const FORWARD_THRUST = 16.0, REVERSE_THRUST = 8.0;
	/** World position, heading, hull angles, and their velocities. */
	private float $x, $y, $z, $yaw;
	private float $pitch = 0, $roll = 0, $vx = 0, $vy = 0, $vz = 0;
	private float $pitchVelocity = 0, $rollVelocity = 0, $wakeClock = 0;
	/** Last sampled terrain revision and contact count for diagnostics. */
	private int $terrainRevision = -1, $contacts = 0;

	/**
	 * Create a stationary craft. Use spawn() to place it on a sampled water surface.
	 * @param float $x World X in metres.
	 * @param float $z World Z in metres.
	 * @param float $yaw Heading in radians; zero faces positive Z.
	 * @param float $y Hull centre height in metres.
	 * @return void Creates a craft with zero linear and angular velocity.
	 */
	public function __construct(float $x = 0, float $z = 0, float $yaw = 0, float $y = .232)
	{
		foreach([$x,$y,$z,$yaw] as $value)
		if(!is_finite($value)) throw new \InvalidArgumentException('Craft pose must be finite');

		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->yaw = $yaw;
	}

	/**
	 * Reset the craft above both the current water and terrain surfaces.
	 * @param float $x World X in metres.
	 * @param float $z World Z in metres.
	 * @param float $yaw Heading in radians.
	 * @param Water $water Authoritative water surface.
	 * @param TerrainSurface $terrain Authoritative collision surface, independent of visual LOD.
	 * @param float $time Simulation time in seconds.
	 * @return void Clears velocities and contacts; out-of-world positions are rejected by the terrain.
	 */
	public function spawn(float $x, float $z, float $yaw, Water $water, TerrainSurface $terrain, float $time): void
	{
		if(!is_finite($yaw)) throw new \InvalidArgumentException('Craft heading must be finite');

		$height = max($water->sample($x, $z, $time)['height'] + .232, $terrain->height($x, $z) + self::KEEL);

		foreach($this->probes() as [$px,$pz])
		$height = max(
			$height
			, $terrain->height(
				$x + $px * cos($yaw) + $pz * sin($yaw)
				, $z - $px * sin($yaw) + $pz * cos($yaw)
			) + self::KEEL
		);

		$this->x = $x;
		$this->z = $z;
		$this->y = $height;
		$this->yaw = $yaw;
		$this->vx = $this->vy = $this->vz = $this->pitchVelocity = $this->rollVelocity = 0;
		$this->pitch = $this->roll = $this->wakeClock = 0;
		$this->contacts = 0;
		$this->terrainRevision = $terrain->revision();
	}

	/**
	 * Advance bounded fixed substeps with longitudinal thrust and speed-dependent steering.
	 * @param float $seconds Elapsed seconds, from zero through one; long pauses belong to the engine clock.
	 * @param float $throttle Forward/reverse control clamped to minus one through one.
	 * @param float $steer Handlebar input from -1 (left) to +1 (right).
	 * @param Water $water Shared analytic water field; moving craft append bounded wake packets.
	 * @param TerrainSurface $terrain Full-resolution heightfield queried on every substep after edits.
	 * @param float $time Time at the beginning of this update, in seconds.
	 * @return void Updates physical state without depending on rendered meshes or their LOD.
	 */
	public function step(
		float $seconds
		, float $throttle
		, float $steer
		, Water $water
		, TerrainSurface $terrain
		, float $time
	): void {
		foreach([$seconds,$throttle,$steer,$time] as $value)
		if(!is_finite($value)) throw new \InvalidArgumentException('Craft controls and time must be finite');

		if($seconds < 0 || $seconds > 1) throw new \InvalidArgumentException('Craft step must be between zero and one');

		if($seconds === 0.0) return;

		$throttle = max(-1.0, min(1.0, $throttle));
		$steer = max(-1.0, min(1.0, $steer));
		$steps = max(1, (int)ceil($seconds * max(120, hypot($this->vx, $this->vz) / .2)));
		$dt = $seconds / $steps;
		$this->contacts = 0;
		$this->terrainRevision = $terrain->revision();

		for($i = 0; $i < $steps; $i++) $this->integrate($dt, $throttle, $steer, $water, $terrain, $time + $i * $dt);
	}

	/**
	 * Integrate one small step and resolve the hull against terrain support samples.
	 * @param float $dt Substep duration.
	 * @param float $throttle Clamped propulsion input.
	 * @param float $steer Clamped steering input.
	 * @param Water $water Shared water field.
	 * @param TerrainSurface $terrain Authoritative terrain field.
	 * @param float $time Substep start time.
	 * @return void Applies forces, positional contacts, and a bounded wake emission rate.
	 */
	private function integrate(
		float $dt
		, float $throttle
		, float $steer
		, Water $water
		, TerrainSurface $terrain
		, float $time
	): void {
		$sin = sin($this->yaw);
		$cos = cos($this->yaw);
		$forward = $this->vx * $sin + $this->vz * $cos;
		$lateral = $this->vx * $cos - $this->vz * $sin;
		$planing = min(1.0, max(0.0, (abs($forward) - 5) / 10));
		$lift = $pitchForce = $rollForce = $wet = 0.0;

		foreach($this->probes() as [$px,$pz])
		{
			$sample = $water->sample($this->x + $px * $cos + $pz * $sin, $this->z - $px * $sin + $pz * $cos, $time);
			$probeY = $this->y + sin($this->pitch) * $pz - sin($this->roll) * $px;
			$submerged = max(0.0, min(1.0, ($sample['height'] - $probeY + .5) / .5));
			$relative = $this->vy + $this->pitchVelocity * $pz - $this->rollVelocity * $px - $sample['velocity'];
			$force = max(0.0, min(50.0, 45 * ($sample['height'] + .45 + .1 * $planing - $probeY) - 9 * $relative));
			$force *= min(1.0, $submerged * 2);
			$lift += $force / 4;
			$pitchForce += $force * $pz / 4;
			$rollForce -= $force * $px / 4;
			$wet += $submerged / 4;
		}

		$this->vy += ($lift - 9.81) * $dt;
		$this->y += $this->vy * $dt;
		$this->pitchVelocity += ($pitchForce * .8 - $this->pitch * 2 + .4 * $planing * $throttle) * $dt;
		$this->rollVelocity += ($rollForce * 1.5 - $this->roll * 3 - $steer * abs($forward) * .05) * $dt;
		$this->pitchVelocity *= exp(-3 * $dt);
		$this->rollVelocity *= exp(-3 * $dt);
		$this->pitch = max(-.55, min(.55, $this->pitch + $this->pitchVelocity * $dt));
		$this->roll = max(-.65, min(.65, $this->roll + $this->rollVelocity * $dt));
		$thrust = $throttle >= 0 ? self::FORWARD_THRUST : self::REVERSE_THRUST;
		$forward += $throttle * $thrust * min(1.0, $wet * 2) * $dt;
		$forward *= exp(-(.25 + .018 * abs($forward)) * (1 - .35 * $planing) * $dt);
		$lateral *= exp(-(1 + 3 * $wet) * $dt);
		$previousYaw = $this->yaw;
		// The bow faces local +Z, so turning right decreases yaw when travelling forward.
		$this->yaw -= $steer * .85 * $forward / (2 + abs($forward)) * $dt;
		$this->yaw = atan2(sin($this->yaw), cos($this->yaw));
		$this->vx = sin($this->yaw) * $forward + cos($this->yaw) * $lateral;
		$this->vz = cos($this->yaw) * $forward - sin($this->yaw) * $lateral;
		$nextX = $this->x + $this->vx * $dt;
		$nextZ = $this->z + $this->vz * $dt;

		try
		{
			$ground = $this->support($terrain, $nextX, $nextZ);
			$surface = $water->sample($nextX, $nextZ, $time)['height'];

			if($ground > $this->y - self::KEEL + .08 || $ground > $surface - .12)
			{
				$this->vx *= .35;
				$this->vz *= .35;
				$ground = null;
				$this->contacts++;
			}
			else
			{
				$this->x = $nextX;
				$this->z = $nextZ;
			}
		}
		catch(\OutOfBoundsException)
		{
			$ground = null;
			$this->yaw = $previousYaw;
			$this->vx = $this->vz = 0;
			$this->contacts++;
		}

		// An accepted move already sampled support at the final hull pose.
		$ground ??= $this->support($terrain, $this->x, $this->z);

		if($this->y < $ground + self::KEEL)
		{
			$this->y = $ground + self::KEEL;
			$this->vy = max(0.0, $this->vy);
			$this->contacts++;
		}

		$this->wakeClock += $dt;

		if($this->wakeClock >= .2 && abs($forward) > 2 && $wet > .1)
		{
			$water->wake(
				$this->x - sin($this->yaw) * self::LENGTH
				, $this->z - cos($this->yaw) * self::LENGTH
				, min(.18, abs($forward) * .008)
				, $time
				, $this->vx, $this->vz
			);

			$this->wakeClock = 0;
		}
	}

	/**
	 * Describe the four buoyancy probes.
	 * @return array Local hull corners as [right,forward] coordinates.
	 */
	private function probes(): array
	{
		return [
			[-self::WIDTH,-self::LENGTH],[self::WIDTH,-self::LENGTH]
			, [-self::WIDTH,self::LENGTH],[self::WIDTH,self::LENGTH]
		];
	}

	/**
	 * Find the highest terrain support under the hull, including its centre.
	 * @param TerrainSurface $terrain Full-resolution collision surface.
	 * @param float $x Hull centre X.
	 * @param float $z Hull centre Z.
	 * @return float Minimum safe centre height minus keel depth.
	 */
	private function support(TerrainSurface $terrain, float $x, float $z): float
	{
		$height = $terrain->height($x, $z);

		foreach($this->probes() as [$px,$pz])
		{
			$ground = $terrain->height(
				$x + $px * cos($this->yaw) + $pz * sin($this->yaw)
				, $z - $px * sin($this->yaw) + $pz * cos($this->yaw)
			);

			$height = max($height, $ground - sin($this->pitch) * $pz + sin($this->roll) * $px);
		}

		return $height;
	}

	/**
	 * Read simulation state for rendering.
	 * @return array Pose, speed, velocity, and collision diagnostics.
	 */
	public function state(): array
	{
		return [
			'x' => $this->x
			, 'y' => $this->y
			, 'z' => $this->z
			, 'yaw' => $this->yaw
			, 'pitch' => $this->pitch
			, 'roll' => $this->roll
			, 'speed' => hypot($this->vx, $this->vz)
			, 'velocity' => [$this->vx,$this->vy,$this->vz]
			, 'contacts' => $this->contacts
			, 'terrainRevision' => $this->terrainRevision
		];
	}
}

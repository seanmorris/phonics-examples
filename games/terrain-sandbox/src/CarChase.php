<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** A world-up chase camera, with matching previous poses for rendering between fixed updates. */
final class CarChase
{
	private bool $settling = false;
	private ?Camera $previousEye = null;
	private array $previousCar = [];
	private array $previousCenter = [], $currentCenter = [];
	private const DISTANCE = 6.7;
	private const LOOK_AHEAD = 2.0;
	private const LOWER_FROM = 40 * Car::MPS_PER_MPH, LOWER_BY = 120 * Car::MPS_PER_MPH;
	private const MAX_BOOM_DROP_ANGLE = 4.5 * M_PI / 180;
	private const MAX_FOV_WIDENING = 30 * M_PI / 180;
	private const FOV_HALF_LIFE = .35;
	private float $fovWidening = 0.;
	/**
	 * Angle in radians the driver looks away from straight ahead; positive looks right and half a turn looks back.
	 * The boom swings round to it through the same damping that follows the car's heading.
	 */
	public float $look = 0.0;
	/** Look angle the camera last followed, so a parked car still answers a new one. */
	private float $looked = 0.0;

	public function board(Camera $camera, array $pose, Landscape $land): void
	{
		$this->previousEye = null;
		$this->fovWidening = 0.;
		$this->follow($camera, $pose, Car::centerOfMass($pose), $land, 1.0, 1.0, 1.0, 0.0);
	}

	public function advance(Camera $camera, array $from, array $to, Landscape $land, float $dt): bool
	{
		// A cubic ease-out puts most of the widening into road speeds and levels off at the governor.
		$speedFraction = min(1., abs($to['speed']) / (Car::TOP_SPEED_MPH * Car::MPS_PER_MPH));
		$targetWidening = self::MAX_FOV_WIDENING * (1. - (1. - $speedFraction)**3);
		if($from === $to && !$this->settling && $this->look === $this->looked && $this->fovWidening === $targetWidening)
		{
			$this->previousEye = null;
			return false;
		}
		// Reset both ends of the boom if recovery had to relocate the vehicle to dry, stable ground.
		$recovered = $from['recoveries'] !== $to['recoveries'];
		if($recovered && hypot($to['x']-$from['x'],$to['z']-$from['z']) > 1)
		{
			$this->board($camera,$to,$land);
			return true;
		}
		$this->previousEye = clone $camera;
		$this->previousEye->fov = $this->fieldOfView($camera);
		if($this->fovWidening !== $targetWidening)
		{
			$remaining = ($targetWidening - $this->fovWidening) * 2**(-$dt/self::FOV_HALF_LIFE);
			$this->fovWidening = abs($remaining) < .0002 ? $targetWidening : $targetWidening - $remaining;
		}
		$landed = $to['motion'] === 'driving' && in_array($from['motion'],['overturned','tumbling'],true);
		$this->previousCar = $landed ? $to : $from;
		$fromCenter = Car::centerOfMass($from);
		$this->currentCenter = Car::centerOfMass($to);
		$this->previousCenter = $landed ? $this->currentCenter : $fromCenter;
		$inAir = in_array($from['motion'],['airborne','tumbling'],true) || in_array($to['motion'],['airborne','tumbling'],true);
		$this->follow($camera, $to, $this->currentCenter, $land, 1 - 2**(-$dt/.12), 1 - 2**(-$dt/.17), 1 - 2**(-$dt/.24),
			$dt, $inAir ? $this->currentCenter[1] - $fromCenter[1] : null);
		return true;
	}

	private function follow(Camera $camera, array $pose, array $center, Landscape $land, float $turn, float $lift,
		float $tilt, float $dt, ?float $airTravel = null): void
	{
		$heading = $this->look === 0.0 ? M_PI - $pose['yaw'] : M_PI - $pose['yaw'] + $this->look;
		$this->looked = $this->look;
		$remaining = self::angle($heading - $camera->yaw) * (1 - $turn);
		$yaw = $heading - (abs($remaining) > .0001 ? $remaining : 0);
		$x = $center[0] - self::DISTANCE*sin($yaw);
		$z = $center[2] + self::DISTANCE*cos($yaw);
		$half = $land->recipe()['worldSize'] / 2;
		$ground = $land->height(max(-$half,min($half,$x)), max(-$half,min($half,$z)));
		// Measure the grade over the whole camera boom. Small changes in tire pitch should stay on the chassis.
		$heightAtCar = $center[1] - Car::CENTER_OF_MASS_HEIGHT;
		$grade = max(-.5, min(.5, ($heightAtCar - $ground) / self::DISTANCE));
		$floor = $ground + 1.35;
		$desiredHeight = max($floor, $heightAtCar + 2.55 - .40*self::DISTANCE*$grade);
		$focusHeight = $heightAtCar + .65;
		$lookHeight = $heightAtCar + .7 + 1.5*$grade;
		$speed = abs($pose['speed']);
		if($speed > self::LOWER_FROM && $desiredHeight > $floor)
		{
			$fraction = min(1., ($speed - self::LOWER_FROM) / (self::LOWER_BY - self::LOWER_FROM));
			$drop = self::MAX_BOOM_DROP_ANGLE * $fraction*$fraction*(3 - 2*$fraction);
			$elevation = atan2($desiredHeight - $focusHeight, self::DISTANCE);
			$lowered = max($floor, $focusHeight + self::DISTANCE*tan($elevation - $drop));
			// Tilt the sightline around the chassis by the drop the terrain actually permits.
			$lookHeight += ($desiredHeight - $lowered) * self::LOOK_AHEAD / self::DISTANCE;
			$desiredHeight = $lowered;
		}
		// Follow the car's vertical travel in the air. On the ground the grade keeps tire bumps out of the eye.
		$predicted = $camera->position[1] + ($airTravel ?? $dt*$pose['speed']*$grade);
		$height = max($floor, $predicted + $lift*($desiredHeight - $predicted));
		if(abs($desiredHeight - $height) <= .001) $height = $desiredHeight;
		$pitchHeight = $airTravel === null ? $desiredHeight : $height;
		$desiredPitch = atan2($lookHeight - $pitchHeight, self::DISTANCE + self::LOOK_AHEAD);
		// The ground can hold the eye above a ledge while the car falls. Allow it to tilt ahead of the damper
		// in that case, keeping the car's center within the middle half of the frame.
		$focus = atan2($focusHeight - $height, self::DISTANCE);
		$margin = atan(.5*tan($camera->fov / 2));
		$minPitch = $focus - $margin; $maxPitch = $focus + $margin;
		$desiredPitch = max($minPitch, min($maxPitch, $desiredPitch));
		$pitch = $camera->pitch + $tilt*($desiredPitch - $camera->pitch);
		if(abs($desiredPitch - $pitch) <= .0001) $pitch = $desiredPitch;
		$pitch = max($minPitch, min($maxPitch, $pitch));
		$camera->position = [$x,$height,$z];
		$camera->yaw = $yaw;
		$camera->pitch = $pitch;
		$this->settling = abs($remaining) > .0001 || $height !== $desiredHeight || $pitch !== $desiredPitch;
	}

	/** Pause and boarding are exact boundaries. Never blend from an earlier mode or paused frame. */
	public function resetInterpolation(): void { $this->previousEye = null; }

	/** Vertical field of view for the car at the latest fixed step, in radians. */
	public function fieldOfView(Camera $camera): float { return $camera->fov + $this->fovWidening; }

	public function view(Camera $camera, array $pose, float $alpha): array
	{
		$old = $this->previousEye;
		$interpolate = $old !== null && $alpha < 1.;
		if(!$interpolate && $this->fovWidening === 0.) return [$camera,$pose];
		$eye = clone $camera;
		$eye->fov = $this->fieldOfView($camera);
		if(!$interpolate) return [$eye,$pose];
		$eye->fov = $old->fov + $alpha*($eye->fov - $old->fov);
		foreach($old->position as $i => $value) $eye->position[$i] = $value + $alpha*($camera->position[$i] - $value);
		$eye->yaw = $old->yaw + $alpha*self::angle($camera->yaw - $old->yaw);
		$eye->pitch = $old->pitch + $alpha*($camera->pitch - $old->pitch);
		foreach(['pitch','roll','steering','yaw','wheelSpin','rearWheelSpin','speed','nitrous'] as $key)
		{
			$previous = $this->previousCar[$key];
			$delta = $pose[$key] - $previous;
			if(in_array($key,['yaw','pitch','roll','wheelSpin','rearWheelSpin'],true)) $delta = self::angle($delta);
			$pose[$key] = $previous + $alpha*$delta;
		}
		// Carry the mass along its path between ticks. The tire-sole origin swings around it as the car rotates.
		$offset = Car::massOffset($pose['yaw'], $pose['pitch'], $pose['roll']);
		foreach(['x','y','z'] as $i => $axis)
		{
			$from = $this->previousCenter[$i];
			$pose[$axis] = $from + $alpha*($this->currentCenter[$i] - $from) - $offset[$i];
		}
		foreach($pose['wheelTravel'] as $i => $travel)
		{
			$previous = $this->previousCar['wheelTravel'][$i];
			$pose['wheelTravel'][$i] = $previous + $alpha*($travel-$previous);
		}
		return [$eye,$pose];
	}

	private static function angle(float $radians): float { return atan2(sin($radians),cos($radians)); }
}

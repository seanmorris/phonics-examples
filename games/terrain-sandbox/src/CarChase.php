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
	private const DISTANCE = 6.7;

	public function board(Camera $camera, array $pose, Landscape $land): void
	{
		$this->previousEye = null;
		$this->follow($camera, $pose, $land, 1.0, 1.0, 1.0, 0.0);
	}

	public function advance(Camera $camera, array $from, array $to, Landscape $land, float $dt): bool
	{
		if($from === $to && !$this->settling)
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
		$landed = $to['motion'] === 'driving' && in_array($from['motion'],['overturned','tumbling'],true);
		$this->previousCar = $landed ? $to : $from;
		$this->follow($camera, $to, $land, 1 - 2**(-$dt/.12), 1 - 2**(-$dt/.17), 1 - 2**(-$dt/.24), $dt);
		return true;
	}

	private function follow(Camera $camera, array $pose, Landscape $land, float $turn, float $lift, float $tilt, float $dt): void
	{
		$heading = M_PI - $pose['yaw'];
		$remaining = self::angle($heading - $camera->yaw) * (1 - $turn);
		$yaw = $heading - (abs($remaining) > .0001 ? $remaining : 0);
		$x = $pose['x'] - self::DISTANCE*sin($yaw);
		$z = $pose['z'] + self::DISTANCE*cos($yaw);
		$half = $land->recipe()['worldSize'] / 2;
		$ground = $land->height(max(-$half,min($half,$x)), max(-$half,min($half,$z)));
		// Measure the grade over the whole camera boom. Small changes in tire pitch should stay on the chassis.
		$heightAtCar = $pose['y'];
		if(in_array($pose['motion'],['overturned','tumbling'],true))
			$heightAtCar += .5*(cos($pose['pitch'])*cos($pose['roll']) - 1);
		$grade = max(-.5, min(.5, ($heightAtCar - $ground) / self::DISTANCE));
		$floor = $ground + 1.35;
		$desiredHeight = max($floor, $heightAtCar + 2.55 - .40*self::DISTANCE*$grade);
		// Carry sustained uphill/downhill motion through the damper without feeding individual wheel bumps into it.
		$predicted = $camera->position[1] + $dt*$pose['speed']*$grade;
		$height = max($floor, $predicted + $lift*($desiredHeight - $predicted));
		if(abs($desiredHeight - $height) <= .001) $height = $desiredHeight;
		$desiredPitch = atan2($heightAtCar + .7 + 1.5*$grade - $desiredHeight, self::DISTANCE + 2);
		$pitch = $camera->pitch + $tilt*($desiredPitch - $camera->pitch);
		if(abs($desiredPitch - $pitch) <= .0001) $pitch = $desiredPitch;
		$camera->position = [$x,$height,$z];
		$camera->yaw = $yaw;
		$camera->pitch = $pitch;
		$this->settling = abs($remaining) > .0001 || $height !== $desiredHeight || $pitch !== $desiredPitch;
	}

	/** Pause and boarding are exact boundaries. Never blend from an earlier mode or paused frame. */
	public function resetInterpolation(): void { $this->previousEye = null; }

	public function view(Camera $camera, array $pose, float $alpha): array
	{
		$old = $this->previousEye;
		if($old === null || $alpha >= 1) return [$camera,$pose];
		$eye = clone $camera;
		foreach($old->position as $i => $value) $eye->position[$i] = $value + $alpha*($camera->position[$i] - $value);
		$eye->yaw = $old->yaw + $alpha*self::angle($camera->yaw - $old->yaw);
		$eye->pitch = $old->pitch + $alpha*($camera->pitch - $old->pitch);
		foreach(['x','y','z','pitch','roll','steering','yaw','wheelSpin'] as $key)
		{
			$previous = $this->previousCar[$key];
			$delta = $pose[$key] - $previous;
			if(in_array($key,['yaw','pitch','roll','wheelSpin'],true)) $delta = self::angle($delta);
			$pose[$key] = $previous + $alpha*$delta;
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

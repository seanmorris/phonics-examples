<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Pursue a point on the pavement using the same steering lock, throttle and brakes as the player. */
trait DriverControls
{
	private bool $serviceBraking=false;

	private static function angle(float $a): float { return atan2(sin($a),cos($a)); }

	private static function pursuit(array $car,float $dx,float $dz): array
	{
		$bearing=atan2($dx,$dz);
		$error=self::angle($bearing-($car['yaw']-$car['drift']));
		$wheel=atan2(2.*Car::WHEELBASE*sin($error),max(1.,hypot($dx,$dz)));
		$steer=max(-1.,min(1.,-$wheel/Car::steeringLimit($car['speed'])));
		return [$steer,$bearing,$error];
	}

	private function pedal(float $speed,float $limit,bool $boost=false): float
	{
		if($boost || $speed<$limit+.9) $this->serviceBraking=false;
		elseif($speed>$limit+3.1) $this->serviceBraking=true;
		$pedal=max(-1.,min(1.,.8+($limit-$speed)*.50));
		return $boost ? 1. : ($this->serviceBraking ? $pedal : max(0.,$pedal));
	}
}

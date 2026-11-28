<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Pursue a point on the pavement using the same steering lock, throttle and brakes as the player. */
trait DriverControls
{
	private bool $serviceBraking=false;
	private bool $catchingSlide=false;
	private int $slideSide=0;
	private float $slideTime=0.;

	private static function angle(float $a): float { return atan2(sin($a),cos($a)); }

	private static function pursuit(array $car,float $dx,float $dz): array
	{
		$bearing=atan2($dx,$dz);
		$error=self::angle($bearing-($car['yaw']-$car['drift']));
		$wheel=atan2(2.*Car::WHEELBASE*sin($error),max(1.,hypot($dx,$dz)));
		$steer=max(-1.,min(1.,-$wheel/Car::steeringLimit($car['speed'])));
		return [$steer,$bearing,$error];
	}

	private function resetSlide(): void
	{
		$this->slideSide=0;
		$this->slideTime=0.;
		$this->catchingSlide=false;
	}

	/** Rotate the body toward the outgoing line, then use the bottle while the tires still have sideslip. */
	private function slideControls(array $car,float $dt,float $steer,float $error,float $outgoing,float $limit,bool $room): ?array
	{
		$speed=$car['speed']; $slip=$car['drift'];
		$side=$error <=> 0.;
		$this->slideTime=max(0.,$this->slideTime-$dt);
		if(!$room || $speed<24. || abs($error)>.80 || $speed>$limit+8.) {
			if($this->slideSide) $this->slideTime=.30;
			$this->slideSide=0; $this->catchingSlide=false;
			return null;
		}
		if($this->catchingSlide) {
			$this->catchingSlide=$this->slideTime>.07 && abs($slip)>.012 && abs($outgoing-$slip)<abs($outgoing)
				&& $car['nitrous']>.015 && $speed<$limit+2.;
			if($this->catchingSlide) return [1.,$steer,false,true];
		}
		// Begin a pulse only when steering alone would ask the tires for substantial lateral acceleration.
		if(!$this->slideSide && $this->slideTime<=0. && abs($error)>.04 && $outgoing*$side>.075
			&& $steer*$side<0. && $car['nitrous']>.065
			&& $speed*$speed*tan(abs($steer)*Car::steeringLimit($speed))/Car::WHEELBASE>7.) {
			$this->slideSide=$side; $this->slideTime=.5;
		}
		if(!$this->slideSide) return null;
		$side=$this->slideSide;
		$turned=$slip*$side;
		$wanted=min(.45,max(.035,$outgoing*$side));
		if($turned<$wanted-.015 && $this->slideTime>0. && $side*$error>.025) {
			$wheel=min(1.,max(abs($steer),2.4*$wanted+.20));
			return [$this->pedal($speed,$limit),-$side*$wheel,true,false];
		}
		$this->slideSide=0;
		$this->slideTime=.30;
		$this->catchingSlide=$turned>.035 && abs($outgoing-$slip)<abs($outgoing) && $car['nitrous']>.015 && $speed<$limit+2.;
		if($this->catchingSlide) return [1.,$steer,false,true];
		return [$this->pedal($speed,$limit),$steer,false,false];
	}

	private function pedal(float $speed,float $limit,bool $boost=false): float
	{
		if($boost || $speed<$limit+.9) $this->serviceBraking=false;
		elseif($speed>$limit+3.1) $this->serviceBraking=true;
		$pedal=max(-1.,min(1.,.8+($limit-$speed)*.50));
		return $boost ? 1. : ($this->serviceBraking ? $pedal : max(0.,$pedal));
	}
}

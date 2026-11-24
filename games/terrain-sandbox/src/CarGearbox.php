<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Five forward ratios and reverse, with a slipping launch clutch and a torque cut during shifts. */
final class CarGearbox
{
	public const IDLE = 950., REDLINE = 8000.;
	private const RATIOS = [-1 => 3.2, 1 => 2.75, 2 => 2.10, 3 => 1.55, 4 => 1.12, 5 => .86];
	private const FINAL_DRIVE = 2.5, EFFICIENCY = .90, MASS = 1450., PEAK_TORQUE = 900.;
	private const SHIFT_TIME = .24, TIRE_GRIP = 9., REVERSE_ACCEL = 4.;
	public int $gear = 1;
	public float $rpm = self::IDLE, $load = 0., $wheelSlip = 0.;
	private float $shiftLeft = 0.;

	public function motion(): array { return [$this->gear,$this->wheelSlip,$this->shiftLeft]; }

	public function resume(array $state, float $rpm, float $load): void
	{
		[$gear,$this->wheelSlip,$this->shiftLeft] = $state;
		$this->gear = isset(self::RATIOS[(int)$gear]) ? (int)$gear : 1;
		$this->rpm = $rpm; $this->load = $load;
	}

	/** Automatic shift map. Use wheel speed so flaring the engine cannot cause an upshift. */
	private function select(float $wheelRpm, float $pedal): int
	{
		$rpm = $wheelRpm*self::RATIOS[$this->gear]*self::FINAL_DRIVE;
		if($this->gear < 5 && $rpm > 3900. + 3450.*$pedal) return $this->gear + 1;
		if($this->gear > 1 && $rpm < 1550. + 1950.*$pedal) return $this->gear - 1;
		return $this->gear;
	}

	/** Torque at the crank rises from idle, peaks in the midrange and falls away near redline. */
	private function torque(): float
	{
		$peakDistance = ($this->rpm - 5100.) / ($this->rpm < 5100. ? 4150. : 2900.);
		return self::PEAK_TORQUE*(1. - .30*$peakDistance**2);
	}

	/** Return signed acceleration at full rear contact, before resistance, braking and nitrous. */
	public function step(float $dt, float $speed, float $throttle, bool $grounded): float
	{
		$pedal = $speed*$throttle < 0. ? 0. : abs($throttle);
		$wheelRpm = abs($speed)*60./(2.*M_PI*Car::TIRE_RADIUS);
		// The opposing pedal is the service brake until the car stops or crosses zero.
		if($grounded && $pedal > 0. && ($throttle < 0.) !== ($this->gear < 0))
		{
			$this->gear = $throttle < 0. ? -1 : 1;
			$this->shiftLeft = 0.;
		}
		if($grounded && $this->gear > 0 && $this->shiftLeft <= 0.)
		{
			$next = $this->select($wheelRpm,$pedal);
			if($next !== $this->gear)
			{
				$this->gear = $next;
				$this->shiftLeft = self::SHIFT_TIME;
			}
		}
		$clutch = max(0., 1. - $this->shiftLeft/(self::SHIFT_TIME*.65));
		$this->shiftLeft = max(0., $this->shiftLeft - $dt);
		$this->load = $pedal*$clutch;
		$ratio = self::RATIOS[$this->gear]*self::FINAL_DRIVE;
		$coupled = $wheelRpm*$ratio;
		$target = $grounded ? max(self::IDLE + 1550.*$this->load, $coupled)
			: self::IDLE + (self::REDLINE-self::IDLE)*$pedal;
		$target = min(self::REDLINE,$target);
		$this->rpm += ($target-$this->rpm)*(1.-exp(-$dt/($grounded ? .075 : .21)));
		if(abs($this->rpm - $target) < 1.) $this->rpm = $target;
		$accel = $this->torque()*$ratio*self::EFFICIENCY/(self::MASS*Car::TIRE_RADIUS);
		$limit = $this->gear < 0 ? self::REVERSE_ACCEL : self::TIRE_GRIP;
		// Excess drive torque spins up the driven tires during launch. Slip speed is in m/s.
		$launch = max(0.,1.-abs($speed)/($this->gear < 0 ? 6. : 19.));
		$this->wheelSlip = $grounded ? max(0.,$accel*$this->load - self::TIRE_GRIP)*$launch : 0.;
		return $grounded ? ($this->gear <=> 0)*$this->load*min($limit,$accel) : 0.;
	}
}

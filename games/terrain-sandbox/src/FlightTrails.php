<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Short-lived world-space paths laid down by the F5 and its game projectiles. */
final class FlightTrails
{
	// Two vec4 endpoints (position and birth time), followed by kind, lifetime and opacity.
	public const STRIDE = 44;
	private const SMOKE_LIFE = 1.85, VAPOR_LIFE = .78, BURST_LIFE = .95;
	private array $smoke = [], $vapor = [], $bursts = [];
	private float $now = 0.0, $stepStart = 0.0, $density = 0.0;
	private int $tick = 0;
	public int $revision = 0;

	public function active(): bool { return (bool)($this->vapor || $this->smoke || $this->bursts); }

	public function instances(): string
	{
		return implode('',array_column($this->smoke,1)) . implode('',array_column($this->vapor,1))
			. implode('',array_column($this->bursts,1));
	}

	public function timeAt(float $alpha): float
	{
		return $this->stepStart + $alpha * ($this->now - $this->stepStart);
	}

	private function expire(array &$frames, float $life): void
	{
		while(($key = array_key_first($frames)) !== null && $this->stepStart - $frames[$key][0] >= $life)
		{
			unset($frames[$key]);
			$this->revision++;
		}
	}

	public function step(float $dt, array $before, array $after): void
	{
		if($dt <= 0) return;
		$this->stepStart = $this->now;
		$this->now += $dt;
		$this->tick++;
		$this->expire($this->vapor,self::VAPOR_LIFE);
		$this->expire($this->smoke,self::SMOKE_LIFE);
		$this->expire($this->bursts,self::BURST_LIFE);

		// The vertical component of the span stays well behaved through inverted flight and loops.
		$bank = abs(sin($after['roll']) * cos($after['pitch']));
		if($bank < .15 && $this->density === 0.0) return;
		$oldAxes = VehicleModel::basis($before);
		$axes = VehicleModel::basis($after);
		$bend = 0.0;
		for($i = 6; $i < 9; $i++) $bend += ($axes[$i] - $oldAxes[$i]) ** 2;
		// Visual load follows the change in flight direction at the current airspeed.
		$turnLoad = sqrt($bend) * $after['speed'] / $dt;
		$demand = min(1.0,max(0.0,($bank - .15) / .65)) * min(1.0,max(0.0,($turnLoad - 2.0) / 24.0));
		$this->density += ($demand - $this->density) * (1 - exp(-$dt / .13));
		if($this->density < .012) { if($demand === 0.0) $this->density = 0.0; return; }

		$bytes = '';
		foreach([-1,1] as $side)
		{
			$a = self::wingtip($before,$oldAxes,$side);
			$b = self::wingtip($after,$axes,$side);
			$bytes .= pack('g*',$a[0],$a[1],$a[2],$this->stepStart, $b[0],$b[1],$b[2],$this->now,
				0.0,self::VAPOR_LIFE,$this->density);
		}
		$this->vapor[$this->tick] = [$this->now,$bytes];
		$this->revision++;
	}

	/** Aft end of each wingtip rail in the baked gear-up mesh, in its balance-point frame. */
	private static function wingtip(array $pose, array $axes, int $side): array
	{
		$tip = [$pose['x'],$pose['y'],$pose['z']];
		for($i = 0; $i < 3; $i++) $tip[$i] += $side*4.17*$axes[$i] - .48*$axes[3+$i] - 2.16*$axes[6+$i];
		return $tip;
	}

	public function exhaust(array $a, array $b, array $oldDirection, array $direction, float $duration): void
	{
		// The exhaust leaves the back of the four-meter game model.
		for($i = 0; $i < 3; $i++)
		{
			$a[$i] -= 2.0 * $oldDirection[$i];
			$b[$i] -= 2.0 * $direction[$i];
		}
		$this->smoke[$this->tick] ??= [$this->now,''];
		$this->smoke[$this->tick][1] .= pack('g*',$a[0],$a[1],$a[2],$this->stepStart,
			$b[0],$b[1],$b[2],min($this->now,$this->stepStart+$duration),1.0,self::SMOKE_LIFE,1.0);
		$this->revision++;
	}

	/** A world-space flash and smoke bloom born at the instant of contact within this step. */
	public function burst(array $point, float $duration): void
	{
		[$x,$y,$z] = $point;
		$birth = min($this->now,$this->stepStart+$duration);
		$this->bursts[$this->tick] ??= [$this->now,''];
		$this->bursts[$this->tick][1] .= pack('g*',$x,$y,$z,$birth,$x,$y,$z,$birth,2.0,self::BURST_LIFE,1.0);
		$this->revision++;
	}
}

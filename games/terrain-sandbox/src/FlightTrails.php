<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Short-lived world-space paths laid down by the F5 and its game projectiles. */
final class FlightTrails
{
	// Two vec4 endpoints (position and birth time), followed by kind, lifetime and opacity.
	public const STRIDE = 44;
	public const SMOKE_LIFE = 1.85;
	private const VAPOR_LIFE = .78, BURST_LIFE = .95;
	private array $smoke = [], $vapor = [], $bursts = [];
	private float $now = 0.0, $stepStart = 0.0, $density = 0.0;
	private int $tick = 0;
	private const FLASH_HOLD = .080;
	public const LODGED_LIFE = self::BURST_LIFE - .080;

	public function showsLodgedSeeker(): bool { return $this->carFlash!==null; }

	/** Birth tick, birth time and last seated nose position of the car flash. */
	private ?array $carFlash = null;
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

	private function advance(float $dt): void
	{
		$this->stepStart = $this->now;
		$this->now += $dt;
		$this->tick++;
		$this->expire($this->vapor,self::VAPOR_LIFE);
		$this->expire($this->smoke,self::SMOKE_LIFE);
		$this->expire($this->bursts,self::BURST_LIFE);

	}

	/** Build the same exhaust segments from the arcade prop's visible poses, in live play or a replay. */
	public function seeker(float $dt, ?array $before, ?array $after, ?array $impact, ?array $mount, array $car, float $impactAge=0., ?array $previousCar=null,
		?array $previousPlane=null, ?array $plane=null): void
	{
		if($dt<=0.) return;
		$this->step($dt,$previousPlane,$plane);
		if($after!==null) {
			$before ??= $after;
			$this->exhaust([$before['x'],$before['y'],$before['z']],[$after['x'],$after['y'],$after['z']],
				array_slice(VehicleModel::basis($before),6),array_slice(VehicleModel::basis($after),6),$dt);
		}
		if($impact!==null) {
			$age=min($dt,max(0.,$impactAge));
			$point=$impact;
			if($mount!==null) {
				$point=SeekerMount::point($mount,$car);
				if($age>0. && $previousCar!==null) {
					$old=SeekerMount::point($mount,$previousCar);
					foreach($old as $i=>$v) $point[$i]+=($v-$point[$i])*$age/$dt;
				}
				$this->carFlash=[$this->tick,$this->now-$age,$point];
			}
			$this->burst($point,$dt-$age);
		}
		if($mount===null) $this->carFlash=null;
		elseif($this->carFlash!==null) $this->carryFlash(SeekerMount::point($mount,$car));
	}

	/** Move the existing burst, then freeze its center at the release instant, even midway through a tick. */
	private function carryFlash(array $nose): void
	{
		[$tick,$birth,$from]=$this->carFlash;
		$start=max($birth,$this->stepStart);
		$end=min($this->now,$birth+self::FLASH_HOLD);
		$center=$from;
		if($end>$start) {
			$t=($end-$start)/($this->now-$start);
			foreach($nose as $i=>$v) $center[$i]+=$t*($v-$from[$i]);
			$this->bursts[$tick][1]=pack('g*',$from[0],$from[1],$from[2],$birth,$center[0],$center[1],$center[2],$end,2.,self::BURST_LIFE,$start);
			$this->revision++;
		}
		// At speed the driver can leave the fireball well before its last puff fades.
		$outside=($nose[0]-$center[0])**2+($nose[1]-$center[1])**2+($nose[2]-$center[2])**2>8.**2;
		$this->carFlash=$outside || $this->now-$birth>=self::LODGED_LIFE ? null : [$tick,$birth,$center];
	}

	public function step(float $dt, ?array $before, ?array $after): void
	{
		if($dt<=0.) return;
		$this->advance($dt);
		if($before===null || $after===null) { $this->density=0.; return; }
		// The vertical component of the span stays well behaved through inverted flight and loops.
		$bank = abs(sin($after['roll']) * cos($after['pitch']));
		if($bank < .15 && $this->density === 0.0) return;
		$oldAxes = VehicleModel::basis($before);
		$axes = VehicleModel::basis($after);
		$bend = 0.0;
		for($i = 6; $i < 9; $i++) $bend += ($axes[$i] - $oldAxes[$i]) ** 2;
		// Visual load follows the change in flight direction at the current airspeed.
		// Early patrol recordings stored only the aircraft pose.
		$speed = $after['speed'] ?? hypot(hypot($after['x']-$before['x'],$after['z']-$before['z']),$after['y']-$before['y'])/$dt;
		$turnLoad = sqrt($bend) * $speed / $dt;
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

	/** A short ember streak from the dead exhaust, with individual sooty puffs left along its path. */
	public function damagedExhaust(array $before,array $after,float $glideAge,float $dt): void
	{
		$ends=[];
		foreach([$before,$after] as $pose) {
			$axes=VehicleModel::basis($pose);
			$point=[$pose['x'],$pose['y'],$pose['z']];
			foreach($point as $i=>$v) $point[$i]=$v-.23*$axes[3+$i]-6.30*$axes[6+$i];
			$ends[]=$point;
		}
		[$a,$b]=$ends;
		$bytes=pack('g*',$a[0],$a[1],$a[2],$this->stepStart,$b[0],$b[1],$b[2],$this->now,4.,.14,.65);
		$interval=.46;
		$puffTime=floor(($glideAge+1.e-7)/$interval)*$interval;
		if($puffTime>0. && $puffTime>$glideAge-$dt+1.e-7) {
			$f=min(1.,max(0.,1.-($glideAge-$puffTime)/$dt));
			foreach($a as $i=>$v) $a[$i]=$v+$f*($b[$i]-$v);
			$birth=$this->stepStart+$f*$dt;
			$bytes.=pack('g*',$a[0],$a[1],$a[2],$birth,$a[0],$a[1],$a[2],$birth,5.,1.35,.70);
		}
		$this->smoke[$this->tick] ??= [$this->now,''];
		$this->smoke[$this->tick][1].=$bytes;
		$this->revision++;
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
		$this->bursts[$this->tick][1] .= pack('g*',$x,$y,$z,$birth,$x,$y,$z,$birth,2.0,self::BURST_LIFE,$birth);
		$this->revision++;
	}
}

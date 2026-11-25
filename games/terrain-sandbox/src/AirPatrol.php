<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** A fly-by choreographed around the car, independent of the player's jet and the race. */
final class AirPatrol
{
	private const ARRIVAL = 3.4;
	private const AXES = ['x','y','z','yaw','pitch','roll'];
	private string $phase = 'idle';
	private float $elapsed = 0., $slow = 0., $heading = 0., $side = 1., $from = 0., $lane = 0.;
	private float $probeIn = 0., $aheadFloor = 0., $exitSpeed = 0.;
	private ?array $pose = null, $previous = null;

	private static function angle(float $v): float { return atan2(sin($v),cos($v)); }
	private static function smooth(float $v): float {
		$v = max(0., min(1., $v));
		return $v*$v*(3.-2.*$v);
	}

	/** Sample a nearby terrain lattice node. Air clearance is deliberately wider than a tire's. */
	private static function floor(Landscape $land, float $water, float $x, float $z): float
	{
		$r = $land->recipe(); $h = $r['worldSize']/2; $s = $r['spacing'];
		return max($water,$land->height(max(-$h,min($h,round($x/$s)*$s)),max(-$h,min($h,round($z/$s)*$s))));
	}

	private static function visible(array $p, Camera $eye, float $aspect): bool
	{
		// Bound the whole 15.6 m model, including its wingtips at full bank.
		return Camera::inView($eye->clipPlanes(max(1,(int)round($aspect*1000)),1000),$eye->position,
			['x'=>$p['x']-9.,'z'=>$p['z']-9.,'size'=>18.],$p['y']-9.,$p['y']+9.);
	}

	private function arrive(array $car, Camera $eye, float $aspect, Landscape $land, float $water): bool
	{
		$this->heading = $car['yaw']-$car['drift'];
		$f = sin($this->heading); $g = cos($this->heading);
		$left = self::floor($land,$water,$car['x']+32*$f-21*$g,$car['z']+32*$g+21*$f);
		$right = self::floor($land,$water,$car['x']+32*$f+21*$g,$car['z']+32*$g-21*$f);
		if(min($left,$right)>$car['y']+18.) return false;
		$preferred = abs($left-$right)>2. ? ($right<$left ? 1. : -1.)
			: (cos(.017*$car['x']+.009*$car['z'])>=0. ? 1. : -1.);
		foreach([[-112.,$preferred],[-112.,-$preferred],[70.,$preferred],[70.,-$preferred]] as [$along,$side]) {
			$x = $car['x']+$along*$f+64*$side*$g; $z = $car['z']+$along*$g-64*$side*$f;
			$floor = self::floor($land,$water,$x,$z);
			if($floor>$car['y']+30.) continue;
			$p = ['x'=>$x,'y'=>max($car['y']+22.,$floor+10.),'z'=>$z,'yaw'=>$this->heading,'pitch'=>0.,'roll'=>0.];
			if(self::visible($p,$eye,$aspect)) continue;
			$this->phase='arrival'; $this->elapsed=$this->slow=$this->probeIn=0.;
			$this->from=$along; $this->side=$side; $this->lane=1.; $this->pose=$this->previous=$p;
			return true;
		}
		return false;
	}

	/** True while the aircraft or its departure needs a new frame. */
	public function step(float $dt,array $car,callable $eye,float $aspect,Landscape $land,float $water): bool
	{
		if($this->phase==='idle') {
			$this->elapsed=max(0.,$this->elapsed-$dt);
			if($this->elapsed>0. || $car['speed']<=60.*Car::MPS_PER_MPH) return false;
			if($this->arrive($car,$eye(),$aspect,$land,$water)) return true;
			$this->elapsed=2.; // Recheck an obstructed approach after the car has moved on.
			return false;
		}
		$this->elapsed += $dt;
		$this->previous = $this->pose;
		$p = $this->pose;
		if($this->phase==='departure') {
			$p['pitch'] += (.68-$p['pitch'])*(1.-exp(-2.*$dt));
			$p['roll'] += (.70*$this->side-$p['roll'])*(1.-exp(-2.*$dt));
			$p['yaw'] += $this->side*.25*$dt;
			$this->exitSpeed = min(180.,$this->exitSpeed+30.*$dt);
			$run = $this->exitSpeed*$dt*cos($p['pitch']);
			$p['x'] += $run*sin($p['yaw']); $p['z'] += $run*cos($p['yaw']);
			$p['y'] = max($p['y']+$this->exitSpeed*$dt*sin($p['pitch']), self::floor($land,$water,$p['x'],$p['z'])+9.);
			$this->pose=$p;
			if($this->elapsed>2. && (!self::visible($p,$eye(),$aspect) || $p['y']>$car['y']+4000.)) {
				$this->phase='idle'; $this->elapsed=12.; $this->pose=$this->previous=null;
			}
			return true;
		}

		$turn = self::angle($car['yaw']-$car['drift']-$this->heading);
		$this->heading += max(-1.,min(1.,$turn*2.))*$dt;
		$f = sin($this->heading); $g = cos($this->heading);
		// Fly further to the side on straight stretches, then tuck overhead as the car turns.
		$choice = self::smooth((cos(.42*max(0.,$this->elapsed-self::ARRIVAL))+.35)/.95)
			*(1.-min(1.,abs($turn)*2.8));
		$this->lane += ($choice-$this->lane)*(1.-exp(-1.2*$dt));
		$u = min(1.,$this->elapsed/self::ARRIVAL);
		$along = 34.+($this->from-34.)*(1.-$u)**2;
		$across = $this->side*(64.+(14.*$this->lane-64.)*self::smooth(($u-.30)/.70));
		$x = $car['x']+$along*$f+$across*$g; $z = $car['z']+$along*$g-$across*$f;
		$floor = self::floor($land,$water,$x,$z);
		$this->probeIn -= $dt;
		if($this->probeIn<=0.) {
			$this->probeIn=.16;
			$look = 12.+.45*max(0.,$car['speed']);
			$this->aheadFloor=self::floor($land,$water,$x+$look*$f,$z+$look*$g);
		}
		$this->slow = $car['speed']<55.*Car::MPS_PER_MPH ? $this->slow+$dt : 0.;
		if(max($floor,$this->aheadFloor)>$car['y']+23. || $this->slow>=2.5) {
			$this->phase='departure'; $this->elapsed=0.;
			$this->exitSpeed=max(70.,$car['speed']+16.);
			// Climb toward the lower shoulder of the pass when one side is boxed in.
			$heights=[];
			foreach([-1,1] as $side) {
				$yaw=$p['yaw']+$side*.65;
				$heights[$side]=self::floor($land,$water,$p['x']+75.*sin($yaw),$p['z']+75.*cos($yaw));
			}
			if(abs($heights[-1]-$heights[1])>4.) $this->side=$heights[-1]<$heights[1] ? -1. : 1.;
			return true;
		}
		if($u>=1.) $this->phase='escort';
		$height = $car['y']+12.+4.*(1.-$this->lane)+10.*(1.-$u);
		$height = max($height,$this->aheadFloor+10.);
		$y = max($floor+9.,$p['y']+($height-$p['y'])*(1.-exp(-3.*$dt)));
		$dx=$x-$p['x']; $dz=$z-$p['z']; $run=hypot($dx,$dz);
		$yaw = $run>.01 ? atan2($dx,$dz) : $this->heading;
		$pitch = max(-.45,min(.55,atan2($y-$p['y'],max(.01,$run))));
		// Roll into the sweep while the wingtip comes into frame, then unwind onto the car's course.
		$bank = -.98*$this->side*sin(M_PI*self::smooth(($u-.32)/.68)) + max(-.65,min(.65,1.8*$turn));
		$blend = 1.-exp(-6.*$dt);
		$this->pose=['x'=>$x,'y'=>$y,'z'=>$z,'yaw'=>$p['yaw']+self::angle($yaw-$p['yaw'])*$blend,
			'pitch'=>$p['pitch']+($pitch-$p['pitch'])*$blend,'roll'=>$p['roll']+($bank-$p['roll'])*$blend];
		return true;
	}

	public function pose(float $alpha=1.): ?array
	{
		$p=$this->pose;
		if($p===null || $alpha>=1.) return $p;
		return self::interpolate($this->previous,$p,$alpha);
	}

	public static function interpolate(array $from, array $p, float $alpha): array
	{
		foreach(self::AXES as $i=>$key) {
			$a=$from[$key]; $d=$p[$key]-$a;
			$p[$key]=$a+$alpha*($i>=3 ? self::angle($d) : $d);
		}
		return $p;
	}

	/** Keep enough pilot state to resume a recorded fly-by at any point. */
	public function snapshot(): ?array
	{
		if($this->phase==='idle' && $this->elapsed===0.) return null;
		return ['phase'=>$this->phase,'flight'=>[$this->elapsed,$this->slow,$this->heading,$this->side,$this->from,
			$this->lane,$this->probeIn,$this->aheadFloor,$this->exitSpeed],'pose'=>$this->pose];
	}

	public function resume(?array $s): void
	{
		if($s===null) return;
		$this->phase=$s['phase'];
		[$this->elapsed,$this->slow,$this->heading,$this->side,$this->from,$this->lane,$this->probeIn,$this->aheadFloor,$this->exitSpeed]=$s['flight'];
		$this->pose=$this->previous=$s['pose'];
	}
}

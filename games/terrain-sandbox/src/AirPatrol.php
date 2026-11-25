<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** A pilot for the same F5 airframe the player flies. The car provides a course and a formation station. */
final class AirPatrol
{
	private string $phase = 'idle';
	private float $elapsed = 0., $slow = 0., $side = 1., $circleRemaining = 0.;
	private float $probeIn = 0., $aheadFloor = 0.;
	public ?FlightTrails $smoke=null;
	public ?array $carImpact=null;
	private ?Jet $jet = null;
	private ?SpeedSeeker $seeker = null;
	private ?array $previous = null;

	private static function angle(float $v): float { return atan2(sin($v),cos($v)); }

	private function launch(array $pose, float $speed): void
	{
		$this->jet = new Jet;
		$this->seeker = new SpeedSeeker;
		$horizontal = $speed*cos($pose['pitch']);
		$power = $speed<=Jet::CRUISE ? Jet::CRUISE_POWER*($speed-Jet::SLOWEST)/(Jet::CRUISE-Jet::SLOWEST)
			: Jet::CRUISE_POWER+(1.-Jet::CRUISE_POWER)*($speed-Jet::CRUISE)/(Jet::FASTEST-Jet::CRUISE);
		$this->jet->resumeFlight($pose+['speed'=>$speed,'power'=>$power,'vx'=>$horizontal*sin($pose['yaw']),
			'vy'=>$speed*sin($pose['pitch']),'vz'=>$horizontal*cos($pose['yaw']),'aileron'=>0.,'stabilator'=>0.,'rudder'=>0.]);
		$this->previous=$this->jet->state();
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
		$heading = $car['yaw']-$car['drift'];
		$f = sin($heading); $g = cos($heading);
		$left = self::floor($land,$water,$car['x']+32*$f-21*$g,$car['z']+32*$g+21*$f);
		$right = self::floor($land,$water,$car['x']+32*$f+21*$g,$car['z']+32*$g-21*$f);
		if(min($left,$right)>$car['y']+18.) return false;
		$preferred = abs($left-$right)>2. ? ($right<$left ? 1. : -1.)
			: (cos(.017*$car['x']+.009*$car['z'])>=0. ? 1. : -1.);
		foreach([[-112.,$preferred],[-112.,-$preferred],[70.,$preferred],[70.,-$preferred]] as [$along,$side]) {
			$x = $car['x']+$along*$f+64*$side*$g; $z = $car['z']+$along*$g-64*$side*$f;
			$floor = self::floor($land,$water,$x,$z);
			if($floor>$car['y']+30.) continue;
			$p = ['x'=>$x,'y'=>max($car['y']+22.,$floor+10.),'z'=>$z,'yaw'=>$heading,'pitch'=>0.,'roll'=>0.];
			if(self::visible($p,$eye,$aspect)) continue;
			$this->phase='arrival'; $this->elapsed=$this->slow=$this->probeIn=0.;
			$this->side=$side; $this->circleRemaining=0.;
			$this->launch($p,min(Jet::FASTEST,$car['speed']+75.));
			return true;
		}
		return false;
	}

	/** True while the aircraft or its departure needs a new frame. */
	public function step(float $dt,array $car,callable $eye,float $aspect,Landscape $land,float $water,?array $beforeCar=null): bool
	{
		$this->carImpact=null;
		if($this->phase==='idle') {
			$fading=$this->seeker!==null || $this->smoke!==null;
			$this->advanceSeeker($dt,$car,null,'departure',$land,$water,$beforeCar);
			if(!$this->smoke && !isset($this->seeker?->state()['pose'])) $this->seeker=null;
			$this->elapsed=max(0.,$this->elapsed-$dt);
			if($this->elapsed>0. || $car['speed']<=60.*Car::MPS_PER_MPH) return $fading;
			if($this->arrive($car,$eye(),$aspect,$land,$water)) return true;
			$this->elapsed=2.; // Recheck an obstructed approach after the car has moved on.
			return $fading;
		}
		$this->elapsed += $dt;
		$p = $this->previous = $this->jet->state();
		$course = $car['yaw']-$car['drift'];
		$f = sin($course); $g = cos($course);
		$dx = $p['x']-$car['x']; $dz = $p['z']-$car['z'];
		$along = $dx*$f+$dz*$g;
		$across = $dx*$g-$dz*$f;
		$this->slow = $car['speed']<55.*Car::MPS_PER_MPH ? $this->slow+$dt : 0.;
		if($this->phase!=='departure') {
			$this->probeIn -= $dt;
			if($this->probeIn<=0.) {
				$this->probeIn=.18;
				// Look down the aircraft's actual flight path, including the turn its bank is starting.
				$this->aheadFloor=$water;
				$track=atan2($p['vx'],$p['vz']);
				foreach([.45,.95,1.6] as $seconds) {
					$yaw=$track+.5*$seconds*.68*sin($p['roll']);
					$range=$seconds*$p['speed'];
					$this->aheadFloor=max($this->aheadFloor,self::floor($land,$water,
						$p['x']+$range*sin($yaw),$p['z']+$range*cos($yaw)));
				}
			}
			if($this->aheadFloor>max($car['y']+23.,$p['y']+8.) || $this->slow>=45.-1e-9) {
				$this->phase='departure'; $this->elapsed=0.;
				$heights=[];
				foreach([-1,1] as $side) {
					$yaw=$p['yaw']+$side*.65;
					$heights[$side]=self::floor($land,$water,$p['x']+$p['speed']*sin($yaw),$p['z']+$p['speed']*cos($yaw));
				}
				if(abs($heights[-1]-$heights[1])>4.) $this->side=$heights[-1]<$heights[1] ? -1. : 1.;
			}
		}
		if($this->phase==='departure') {
			$bank=-.72*$this->side; $climb=.95; $throttle=.65;
		} else {
			// Use a forward sight point on the desired course to intercept the shoulder and then fly parallel.
			// The pilot can change the engine and control surfaces only. It cannot place the airframe.
			$headingError=self::angle($course-$p['yaw']);
			$joining=$this->phase==='arrival';
			$station=$joining ? 72. : 42.;
			$lane=$this->side*(13.+13.*cos(.43*$this->elapsed))*max(0.,1.-1.6*abs($headingError));
			if($joining && $along<18.) $lane=64.*$this->side;
			$look=$joining ? 32. : max(42.,.82*$p['speed']);
			$desired=$course+atan2($lane-$across,$look+max(0.,$station-$along));
			$speed=$car['speed']+.62*($station-$along);
			// A slow car cannot be paced at the F5's minimum airspeed. Fly a banked orbit until
			// the car moves out ahead, then intercept again. Keep the turn direction through the reversal.
			if($this->circleRemaining<=0. && $along>100. && $car['speed']<Jet::SLOWEST+5. && abs($headingError)<.95)
				$this->circleRemaining=2.*M_PI;
			if($this->circleRemaining>0.) {
				$desired=$p['yaw']+1.45*$this->side;
				$speed=Jet::SLOWEST;
				if($this->circleRemaining<M_PI && $along < -45.) $this->circleRemaining=0.;
			}
			$turn=self::angle($desired-$p['yaw']);
			$bank=max(-1.,min(1.,-2.8*$turn));
			if($speed>Jet::SLOWEST) $speed=Jet::SLOWEST+min(150.,$speed-Jet::SLOWEST)*max(0.,cos($turn))**2;
			$height=max($this->aheadFloor+12.,$car['y']+16.+6.*max(0.,1.-abs($across)/20.));
			$climb=max(-.65,min(.95,atan2($height-$p['y'],max(45.,$p['speed']*.88))/.78));
			$throttle=max(-1.,min(1.,($speed-Jet::CRUISE)/($speed<Jet::CRUISE ?
				Jet::CRUISE-Jet::SLOWEST : Jet::FASTEST-Jet::CRUISE)));
			if($this->phase==='arrival' && $along>=32. && abs($across)<25.) $this->phase='escort';
		}
		$flying=$this->jet->fly($dt,$throttle,$bank,$climb,$land,$water);
		$next=$this->jet->state();
		$this->advanceSeeker($dt,$car,$next,$flying ? $this->phase : 'departure',$land,$water,$beforeCar);
		if($this->circleRemaining>0.) $this->circleRemaining=max(0.,$this->circleRemaining-
			$this->side*self::angle($next['yaw']-$p['yaw']));
		if(!$flying || ($this->phase==='departure' && $this->elapsed>2. && !self::visible($next,$eye(),$aspect))) {
			$this->phase='idle'; $this->elapsed=12.; $this->jet=$this->previous=null;
			if(!$this->smoke && $this->seeker->state()['pose']===null) $this->seeker=null;
		}
		return true;
	}

	public function pose(float $alpha=1.): ?array
	{
		$p=$this->jet?->state();
		return $p===null || $alpha>=1. ? $p : self::interpolate($this->previous,$p,$alpha);
	}

	public static function interpolate(array $from, array $p, float $alpha): array
	{
		foreach($from as $key=>$a) {
			if(!isset($p[$key])) continue;
			$d=$p[$key]-$a;
			$p[$key]=$a+$alpha*(in_array($key,['yaw','pitch','roll'],true) ? self::angle($d) : $d);
		}
		return $p;
	}

	private function advanceSeeker(float $dt,array $car,?array $plane,string $phase,Landscape $land,float $water,?array $beforeCar): void
	{
		$before=$this->seeker?->state();
		$hit=$this->seeker?->step($dt,$car,$plane,$phase,$land,$water,$beforeCar);
		$this->stepSmoke($dt,$before,$this->seeker?->state());
		if(($hit['kind'] ?? null)==='car') $this->carImpact=$hit['point'];
	}

	public function stepSmoke(float $dt,?array $from,?array $to): void
	{
		$impact=$to['impact'] ?? null;
		if($impact===($from['impact'] ?? null)) $impact=null;
		if(!isset($to['pose']) && $impact===null && !$this->smoke) return;
		($this->smoke ??= new FlightTrails)->seeker($dt,$from['pose'] ?? null,$to['pose'] ?? null,$impact);
		if(!$this->smoke->active()) $this->smoke=null;
	}

	public function seeker(float $alpha=1.): ?array { return $this->seeker?->state($alpha); }

	public function snapshot(): ?array
	{
		if($this->phase==='idle' && $this->elapsed===0.) return null;
		return ['phase'=>$this->phase,'flight'=>[$this->elapsed,$this->slow,$this->side,$this->circleRemaining,$this->probeIn,$this->aheadFloor],
			'direction'=>$this->jet ? array_slice($this->jet->motion(),4,3) : null,'pose'=>$this->jet?->state(),'seeker'=>$this->seeker()];
	}

	public function resume(?array $s): void
	{
		$this->jet=$this->previous=$this->seeker=null;
		$this->smoke=$this->carImpact=null;
		$this->phase='idle'; $this->elapsed=$this->slow=$this->circleRemaining=$this->probeIn=$this->aheadFloor=0.;
		if($s===null) return;
		$this->phase=$s['phase'];
		if(count($s['flight'])===9) {
			// Older demos have a visual pose but no velocity. Take over flying from their recorded attitude.
			[$this->elapsed,$this->slow,,$this->side]=$s['flight'];
			if($s['pose']!==null) $this->launch($s['pose'],Jet::CRUISE);
			return;
		}
		[$this->elapsed,$this->slow,$this->side,$this->circleRemaining,$this->probeIn,$this->aheadFloor]=$s['flight'];
		if(isset($s['pose']) || isset($s['seeker'])) $this->seeker=new SpeedSeeker;
		if(isset($s['seeker'])) $this->seeker->resume($s['seeker']);
		if($s['pose']!==null) {
			$this->jet=new Jet;
			$d=$s['direction'];
			$this->jet->resumeFlight($s['pose'],[0,0.,0.,0.,...$d,...$d]);
			$this->previous=$this->jet->state();
		}
	}
}

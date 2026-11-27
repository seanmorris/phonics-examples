<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** A pilot for the same F5 airframe the player flies. The car provides a course and a formation station. */
final class AirPatrol
{
	private string $phase = 'idle';
	private bool $repelled=false;
	private const WRECK_CLEAR_DISTANCE=500., PEEK_SECONDS=.50, ARRIVAL_LEAD=.32;
	private ?float $arrivalPeek=null;
	private float $elapsed = 0., $slow = 0., $side = 1., $circleRemaining = 0.;
	private float $probeIn = 0., $aheadFloor = 0.;
	public ?FlightTrails $smoke=null;
	public ?array $carImpact=null;
	private ?Jet $jet = null;
	private ?SpeedSeeker $seeker = null;
	private ?array $previous = null;
	private const NEUTRAL_GLANCE = [0.,0.];
	private array $glance=self::NEUTRAL_GLANCE, $previousGlance=self::NEUTRAL_GLANCE;

	private static function angle(float $v): float { return atan2(sin($v),cos($v)); }

	private function launch(array $pose, float $speed): void
	{
		$this->arrivalPeek=null;
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
			$this->advanceSeeker($dt,$car,null,'departure',$land,$water,$beforeCar,$eye,$aspect);
			if(!$this->smoke && !isset($this->seeker?->state()['pose'])) $this->seeker=null;
			$this->elapsed=max(0.,$this->elapsed-$dt);
			if($this->repelled && $car['speed']<55.*Car::MPS_PER_MPH) $this->repelled=false;
			if($this->repelled || $this->elapsed>0. || $car['speed']<=60.*Car::MPS_PER_MPH) return $fading;
			if($this->arrive($car,$eye(),$aspect,$land,$water)) return true;
			$this->elapsed=2.; // Recheck an obstructed approach after the car has moved on.
			return $fading;
		}
		if($this->phase==='glide' || $this->phase==='slide') {
			$p=$this->previous=$this->jet->state();
			$moving=$this->phase==='glide' || $p['speed']>0.;
			if($moving) {
				$this->elapsed+=$dt;
				if($this->jet->landUnpowered($dt,$land,$water,$this->phase==='slide') && $this->phase==='glide') {
					$this->phase='slide'; $this->elapsed=0.;
				}
			}
			$next=$this->jet->state();
			$fading=$this->smoke?->active() ?? false;
			if($moving || $fading) $this->advanceSeeker($dt,$car,$next,$this->phase,$land,$water,$beforeCar,$eye,$aspect);
			if($this->phase==='slide' && $next['speed']===0.
				&& hypot($next['x']-$car['x'],$next['z']-$car['z'])>self::WRECK_CLEAR_DISTANCE && !self::visible($next,$eye(),$aspect)) {
				$this->phase='idle'; $this->elapsed=12.; $this->jet=$this->previous=$this->seeker=$this->smoke=null;
				return true;
			}
			return $moving || $fading;
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
			if($this->slow>=45.-1e-9) {
				$this->phase='departure'; $this->elapsed=0.;
				$heights=[];
				foreach([-1,1] as $side) {
					$yaw=$p['yaw']+$side*.65;
					$heights[$side]=self::floor($land,$water,$p['x']+$p['speed']*sin($yaw),$p['z']+$p['speed']*cos($yaw));
				}
				if(abs($heights[-1]-$heights[1])>4.) $this->side=$heights[-1]<$heights[1] ? -1. : 1.;
			}
		}
		$idleSpeed=Jet::SLOWEST;
		if($this->phase==='departure') {
			$bank=-.72*$this->side; $climb=.95; $throttle=.65;
		} else {
			// Join from the shoulder, then bank across the road ahead of the driver.
			$headingError=self::angle($course-$p['yaw']);
			$joining=$this->phase==='arrival';
			$station=$joining ? 72. : 36.;
			$look=$joining ? 32. : max(42.,.82*$p['speed']);
			if($joining) {
				$lane=$this->side*($along<18. ? 64. : (13.+13.*cos(.43*$this->elapsed))*max(0.,1.-1.6*abs($headingError)));
			} else {
				// Let the arcade escort pace a driver just over the limit between crossings.
				if($this->circleRemaining<=0. && $car['speed']>=55.*Car::MPS_PER_MPH)
					$idleSpeed=min(Jet::SLOWEST,$car['speed']-8.);
				// Continue the entry bank toward the opposite side. tan(60°) places the outer marks at 10 and 2.
				$lane=-$this->side*$station*sqrt(3.)*sin(.60*$this->elapsed);
			}
			$desired=$course+atan2($lane-$across,$look+max(0.,$station-$along));
			$speed=$car['speed']+.62*($station-$along);
			if(!$joining) $speed/=max(.6,cos($headingError));
			// A slow car cannot be paced at the F5's minimum airspeed. Fly a banked orbit until
			// the car moves out ahead, then intercept again. Keep the turn direction through the reversal.
			if($this->circleRemaining<=0. && $along>100. && $car['speed']<$idleSpeed+5. && abs($headingError)<.95)
				$this->circleRemaining=2.*M_PI;
			if($this->circleRemaining>0.) {
				$desired=$p['yaw']+1.45*$this->side;
				$speed=Jet::SLOWEST;
				if($this->circleRemaining<M_PI && $along < -45.) $this->circleRemaining=0.;
			}
			$turn=self::angle($desired-$p['yaw']);
			$bank=max(-1.,min(1.,-2.8*$turn));
			if($speed>$idleSpeed) $speed=$idleSpeed+min(150.,$speed-$idleSpeed)*max(0.,cos($turn))**2;
			// Terrain raises the formation altitude. Past the crest the station comes back down to the driver.
			$height=max($this->aheadFloor+12.,$car['y']+16.+6.*max(0.,1.-abs($across)/20.));
			$climb=max(-.65,min(.95,atan2($height-$p['y'],max(45.,$p['speed']*.88))/.78));
			$throttle=max(-1.,min(1.,($speed-Jet::CRUISE)/($speed<Jet::CRUISE ?
				Jet::CRUISE-$idleSpeed : Jet::FASTEST-Jet::CRUISE)));
			if($this->phase==='arrival' && $along>=32. && abs($across)<25.) {
				$this->phase='escort'; $this->elapsed=0.;
			}
		}
		$flying=$this->jet->fly($dt,$throttle,$bank,$climb,$land,$water,idleSpeed:$idleSpeed);
		$next=$this->jet->state();
		$this->advanceSeeker($dt,$car,$next,$flying ? $this->phase : 'departure',$land,$water,$beforeCar,$eye,$aspect);
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

	private function advanceSeeker(float $dt,array $car,?array $plane,string $phase,Landscape $land,float $water,?array $beforeCar,callable $eye,float $aspect): void
	{
		$before=$this->seeker?->state();
		$hit=$this->seeker?->step($dt,$car,$plane,$phase,$land,$water,$beforeCar,$eye,$aspect);
		$this->stepSmoke($dt,$before,$this->seeker?->state(),$car,$beforeCar,$this->previous,$plane,$phase==='glide' ? $this->elapsed : null);
		if(($hit['kind'] ?? null)==='plane') {
			$this->jet->cutEngine();
			$this->repelled=true; $this->phase='glide'; $this->elapsed=0.;
		}
		if(($hit['kind'] ?? null)==='car') $this->carImpact=$hit['point'];
	}

	public function stepSmoke(float $dt,?array $from,?array $to,array $car,?array $previousCar,?array $previousPlane,?array $plane,?float $glideAge=null): void
	{
		$impact=$to['impact'] ?? null;
		if($impact===($from['impact'] ?? null)) $impact=null;
		if($plane===null && !isset($to['pose']) && $impact===null && !$this->smoke) return;
		($this->smoke ??= new FlightTrails)->seeker($dt,$from['pose'] ?? null,$to['pose'] ?? null,$impact,$to['mount'] ?? null,$car,$to['clock'] ?? 0.,$previousCar,$previousPlane,$plane);
		if($glideAge!==null && $previousPlane!==null && $plane!==null && $dt>0.)
			$this->smoke->damagedExhaust($previousPlane,$plane,$glideAge,$dt);
		if($plane===null && !$this->smoke->active()) $this->smoke=null;
	}

	public function seeker(float $alpha=1.): ?array { return $this->seeker?->state($alpha); }

	/** Angular offset from the unassisted chase camera to a point in the scene. */
	private static function lookOffset(Camera $eye,float $x,float $y,float $z): array
	{
		$x-=$eye->position[0]; $y-=$eye->position[1]; $z-=$eye->position[2];
		return [self::angle(atan2($x,-$z)-$eye->yaw),atan2($y,hypot($x,$z))-$eye->pitch];
	}

	public static function interpolateGlance(array $from,array $to,float $t): array
	{
		return [self::angle($from[0]+$t*self::angle($to[0]-$from[0])), $from[1]+$t*($to[1]-$from[1])];
	}

	/** Pan the driver's view without moving the boom or changing steering. */
	public function advanceGlance(callable $getEye,array $car,bool $manual,float $dt,float $aspect): bool
	{
		$this->previousGlance=$this->glance;
		$peek=$this->arrivalPeek;
		if($peek===null && $this->phase==='arrival') {
			// Turn toward the approaching sound just before the aircraft would enter the driver's unassisted view.
			$p=$this->pose();
			$course=$car['yaw']-$car['drift'];
			$p['x']+=self::ARRIVAL_LEAD*($p['vx']-$car['speed']*sin($course));
			$p['z']+=self::ARRIVAL_LEAD*($p['vz']-$car['speed']*cos($course));
			$p['y']+=self::ARRIVAL_LEAD*$p['vy'];
			if(self::visible($p,$getEye(),$aspect)) $peek=0.;
		}
		if($peek!==null) $this->arrivalPeek=min(self::PEEK_SECONDS,$peek+$dt);
		if($manual) {
			$changed=$this->glance!==self::NEUTRAL_GLANCE;
			$this->previousGlance=$this->glance=self::NEUTRAL_GLANCE;
			return $changed;
		}
		$shot=$this->seeker();
		$point=$shot['pose'] ?? null;
		$goal=self::NEUTRAL_GLANCE;
		$halfLife=$this->repelled ? .15 : .12;
		if(($shot['phase'] ?? null)==='return' && ($jet=$this->pose())!==null) {
			$eye=$getEye();
			// Lead with the visible prop, then widen the composition to include the plane.
			$from=self::lookOffset($eye,$point['x'],$point['y'],$point['z']);
			$to=self::lookOffset($eye,$jet['x'],$jet['y'],$jet['z']);
			$goal=self::interpolateGlance($from,$to,.5*min(1.,$shot['clock']/.16));
			$halfLife=.050;
		} elseif($this->repelled && isset($shot['impact']) && $shot['clock']<.22) {
			// The flash stays at the contact point as the jet moves on.
			$goal=self::lookOffset($getEye(),...$shot['impact']);
			$halfLife=.050;
		} elseif($peek!==null && $peek<self::PEEK_SECONDS && ($jet=$this->pose())!==null) {
			// The last part of the half-second peek returns exactly to the driving view.
			if($this->arrivalPeek>.30) {
				$t=max(0.,(self::PEEK_SECONDS-$this->arrivalPeek)/(self::PEEK_SECONDS-max(.30,$peek)));
				$this->glance=array_map(static fn($v)=>$v*$t,$this->glance);
				return true;
			}
			[$pan,$tilt]=self::lookOffset($getEye(),$jet['x'],$jet['y'],$jet['z']);
			$scale=min(1.,deg2rad(30.)/max(.00001,hypot($pan,$tilt)));
			$goal=[$scale*$pan,$scale*$tilt]; $halfLife=.050;
		} elseif($point!==null && ($shot['phase']!=='missed' || isset($shot['rearPassGap']))) {
			[$pan,$tilt]=self::lookOffset($getEye(),$point['x'],$point['y'],$point['z']);
			$weight=max(0.,min(1.,(M_PI/2-abs($pan))/.35));
			if(isset($shot['pose'])) {
				$range=hypot(hypot($point['x']-$car['x'],$point['z']-$car['z']),$point['y']-$car['y']);
				$weight*=max(0.,min(1.,(38.-$range)/30.));
			}
			$goal=[$weight*max(-deg2rad(5.),min(deg2rad(5.),.18*$pan)),
				$weight*max(-deg2rad(3.),min(deg2rad(3.),.18*$tilt))];
		}
		if($goal===$this->glance) return false;
		$this->glance=self::interpolateGlance($this->glance,$goal,1.-2**(-$dt/$halfLife));
		if(abs(self::angle($this->glance[0]-$goal[0]))<.00002 && abs($this->glance[1]-$goal[1])<.00002)
			$this->glance=$goal;
		return true;
	}

	public function glanceView(Camera $eye,float $alpha): Camera
	{
		if($this->glance===self::NEUTRAL_GLANCE && $this->previousGlance===self::NEUTRAL_GLANCE) return $eye;
		[$pan,$tilt]=self::interpolateGlance($this->previousGlance,$this->glance,$alpha);
		$view=clone $eye; $view->yaw+=$pan; $view->pitch+=$tilt;
		return $view;
	}

	public function snapshot(): ?array
	{
		if($this->phase==='idle' && $this->elapsed===0. && !$this->repelled) return null;
		return ['phase'=>$this->phase,'flight'=>[$this->elapsed,$this->slow,$this->side,$this->circleRemaining,$this->probeIn,$this->aheadFloor],
			'direction'=>$this->jet ? array_slice($this->jet->motion(),4,3) : null,'pose'=>$this->jet?->state(),'seeker'=>$this->seeker()]
			+($this->arrivalPeek!==null ? ['arrivalPeek'=>$this->arrivalPeek] : [])
			+($this->glance!==self::NEUTRAL_GLANCE ? ['glance'=>$this->glance] : [])+($this->repelled ? ['repelled'=>true] : []);
	}

	public function resume(?array $s): void
	{
		$this->arrivalPeek=$s['arrivalPeek'] ?? null;
		$this->repelled=$s['repelled'] ?? false;
		$this->previousGlance=$this->glance=$s['glance'] ?? self::NEUTRAL_GLANCE;
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

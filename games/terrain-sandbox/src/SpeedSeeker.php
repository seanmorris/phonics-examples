<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Arcade chase prop. A wing fly-out and a sweeping return lead into a short, escapable bumper chase. */
final class SpeedSeeker
{
	private const SLIP = .18, FLY_AHEAD = .42, DROP_SECONDS = 1.95, CLOSE_SECONDS = 3.8, RELOAD_SECONDS = 12.;
	private string $phase = 'mounted';
	private float $clock = 0., $passSpeed = 0.;
	private ?array $velocity = null, $impact = null;
	/** Start of the drop, expressed in the car's frame so the animation works on winding roads. */
	private array $start = [];
	/** Two smoothed copies of the driver's animation frame, retained when branching a demo mid-pass. */
	private array $carFrames = [];
	private ?array $pose = null, $previous = null, $mount = null;

	public function step(float $dt,array $car,?array $plane,string $patrol,Landscape $land,float $water,?array $beforeCar=null,?callable $eye=null,float $aspect=1.): ?array
	{
		$this->previous=$this->pose;
		$this->clock+=$dt;
		$drifting=abs($car['drift'])>=self::SLIP && ($car['motion'] ?? 'driving')==='driving';
		if($this->phase==='reload') {
			if($this->clock>=1.5) $this->mount=null;
			if($this->clock>=self::RELOAD_SECONDS) { $this->phase='mounted'; $this->clock=0.; $this->impact=null; }
			return null;
		}
		if($this->phase==='mounted') {
			if($plane===null || $patrol!=='escort' || $car['speed']<=60.*Car::MPS_PER_MPH || $drifting ||
				hypot($plane['x']-$car['x'],$plane['z']-$car['z'])>80. || !$eye || !self::inShot($plane,$camera=$eye(),$aspect)) { $this->clock=0.; return null; }
			if($this->clock<1.5-1e-9) return null;
			$wing=Jet::projectileMount($plane,0);
			if($wing['y']<SeekerContact::floor($wing,$land,$water)+1.25) return null;
			$sight=[$wing['x']-$camera->position[0],$wing['y']-$camera->position[1],$wing['z']-$camera->position[2]];
			$range=hypot(hypot($sight[0],$sight[2]),$sight[1]);
			if($land->raycast($camera->position,$sight,$range)!==null) { $this->clock=0.; return null; }
			$this->carFrames=[];
			$course=$car['yaw']-$car['drift']; $f=sin($course); $g=cos($course);
			$x=$wing['x']-$car['x']; $z=$wing['z']-$car['z'];
			$this->start=[$x*$g-$z*$f,$x*$f+$z*$g,$wing['y']-$car['y'],
				atan2(sin($wing['yaw']-$course),cos($wing['yaw']-$course)),$wing['pitch'],$wing['roll']];
			$this->velocity=[$plane['vx'],$plane['vy'],$plane['vz']];
			$this->phase='drop'; $this->clock=0.;
		}
		if($this->phase!=='missed' && ($drifting || $patrol==='departure')) {
			$this->phase='missed'; $this->clock=0.; $this->carFrames=[];
			// Only old recordings lack a measured velocity at release.
			$this->velocity ??= self::motionAlongPose($this->pose,$car['speed']);
			$this->passSpeed=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$this->velocity)));
		}
		if($this->phase==='missed') {
			// Coast with the last world-space displacement, even if the car doubles back.
			foreach(['x','y','z'] as $i=>$axis) $this->pose[$axis]+=$this->velocity[$i]*$dt;
			$hit=SeekerContact::first($this->previous,$this->pose,$beforeCar ?? $car,$car,true,$land,$water);
			if($this->clock>=6. || $hit!==null) {
				$this->impact=$hit['point'] ?? null; $this->mount=$hit['mount'] ?? null;
				$this->phase='reload'; $this->clock=0.; $this->start=$this->carFrames=[]; $this->pose=$this->velocity=null;
			}
			return $hit;
		}
		$drop=$this->phase==='drop';
		$carFrame=$this->animatedCar($car,$dt);
		$body=VehicleModel::basis($carFrame);
		$closing=$drop ? 0. : min(1.,$this->clock/self::CLOSE_SECONDS);
		$shoulder=$this->start[0]>=0. ? 1. : -1.;
		// Move the mark from above one rear corner into the bumper. The last dip is the driver's drift cue.
		$mark=[$shoulder*(1.34*(1.-$closing)+.8*sin(2.*M_PI*$closing)*(1.-$closing)),
			Car::BODY_HEIGHT+1.32-1.48*$closing**1.8, -Car::BODY_REAR-.9+1.08*$closing];
		$p=$carFrame;
		foreach(['x','y','z'] as $i=>$axis) $p[$axis]+=$body[$i]*$mark[0]+$body[$i+3]*$mark[1]+$body[$i+6]*$mark[2];
		if($drop) {
			// The first beat uses the moving wing as its reference. The next beat arcs around the rear quarter.
			$wing=Jet::projectileMount($plane,0);
			$nose=array_slice(VehicleModel::basis($plane),6);
			$ahead=14.*self::smooth($this->clock/self::FLY_AHEAD);
			$t=max(0.,($this->clock-self::FLY_AHEAD)/(self::DROP_SECONDS-self::FLY_AHEAD));
			$across=self::smooth($t/.86); $down=self::smooth(($t-.12)/.88);
			$arc=9.*$shoulder*sin(M_PI*$across);
			foreach(['x','y','z'] as $i=>$axis) {
				$from=$wing[$axis]+$ahead*$nose[$i];
				$p[$axis]=$from+($p[$axis]-$from)*($axis==='y' ? $down : $across)+$body[$i]*$arc;
			}
			foreach(['yaw','pitch','roll'] as $axis) {
				$turn=$carFrame[$axis]-$wing[$axis];
				$p[$axis]=$wing[$axis]+$across*atan2(sin($turn),cos($turn));
			}
			$p['roll']+=.66*$shoulder*sin(2.*M_PI*$across);
		}
		if($drop && $this->clock>self::FLY_AHEAD && $this->previous!==null && $dt>0.) {
			$dx=$p['x']-$this->previous['x']; $dz=$p['z']-$this->previous['z'];
			$run=hypot($dx,$dz);
			if($run>$dt) {
				$turn=atan2($dx,$dz)-$p['yaw'];
				$bankIntoArc=sin(M_PI*min(1.,$t));
				$p['yaw']+=$bankIntoArc*atan2(sin($turn),cos($turn));
				$p['pitch']+=$bankIntoArc*(atan2($p['y']-$this->previous['y'],$run)-$p['pitch']);
			}
		}
		$p['y']=max($p['y'],SeekerContact::floor($p,$land,$water)+1.25-.85*$closing);
		if($this->previous!==null && $dt>0.) {
			$this->velocity=[($p['x']-$this->previous['x'])/$dt,($p['y']-$this->previous['y'])/$dt,
				($p['z']-$this->previous['z'])/$dt];
		}
		if($this->previous!==null && ($hit=SeekerContact::first($this->previous,$p,$beforeCar ?? $car,$car,false,$land,$water))!==null) {
			$this->impact=$hit['point']; $this->mount=$hit['mount'] ?? null; $this->phase='reload'; $this->clock=0.; $this->start=$this->carFrames=[]; $this->pose=$this->velocity=null;
			return $hit;
		}
		$this->pose=$p;
		if($drop && $this->clock>=self::DROP_SECONDS-1e-9) { $this->phase='chase'; $this->clock=0.; }
		return null;
	}

	/** Leave space around the fuselage and the releasing wing in the actual chase camera. */
	private static function inShot(array $plane,Camera $camera,float $aspect): bool
	{
		[$front,$right,$up]=$camera->basis();
		$wing=Jet::projectileMount($plane,0);
		foreach([$plane,$wing] as $p) {
			$x=$p['x']-$camera->position[0]; $y=$p['y']-$camera->position[1]; $z=$p['z']-$camera->position[2];
			$depth=$x*$front[0]+$y*$front[1]+$z*$front[2];
			$half=$depth*tan($camera->fov/2);
			if($depth<=8. || abs($x*$right[0]+$y*$right[1]+$z*$right[2])+3.>.90*$half*$aspect ||
				abs($x*$up[0]+$y*$up[1]+$z*$up[2])+3.>.90*$half) return false;
		}
		return true;
	}

	/** Animate the reference car with follow-through. Constant travel stays in time with the driver,
	 * while steering reversals and braking carry the old motion briefly across the new line. */
	private function animatedCar(array $car,float $dt): array
	{
		$keys=['x','y','z','yaw','pitch','roll'];
		if(!$this->carFrames) {
			$initial=array_map(static fn($key)=>$car[$key] ?? 0.,$keys);
			$this->carFrames=[...$initial,...$initial];
		}
		$pose=[];
		$blend=1.-exp(-$dt/.19);
		foreach($keys as $i=>$key) {
			$v=$car[$key] ?? 0.;
			$first=$this->carFrames[$i]; $second=$this->carFrames[6+$i];
			$delta=$v-$first;
			$first+=$blend*($i>=3 ? atan2(sin($delta),cos($delta)) : $delta);
			$second+=$blend*($first-$second);
			$this->carFrames[$i]=$first; $this->carFrames[6+$i]=$second;
			$pose[$key]=$first+($first-$second);
		}
		return $pose;
	}

	private static function smooth(float $t): float
	{
		$t=max(0.,min(1.,$t));
		return $t*$t*(3.-2.*$t);
	}

	private static function motionAlongPose(array $p,float $speed): array
	{
		$run=$speed*cos($p['pitch']);
		return [sin($p['yaw'])*$run,sin($p['pitch'])*$speed,cos($p['yaw'])*$run];
	}

	public function state(float $alpha=1.): array
	{
		$p=$this->pose;
		if($alpha<1. && $this->previous!==null && $p!==null) $p=AirPatrol::interpolate($this->previous,$p,$alpha);
		return ['phase'=>$this->phase,'clock'=>$this->clock,'start'=>$this->start,'passSpeed'=>$this->passSpeed,'velocity'=>$this->velocity,'impact'=>$this->impact,'pose'=>$p]+($this->mount ? ['mount'=>$this->mount] : [])+($this->carFrames ? ['carFrames'=>$this->carFrames] : []);
	}

	public function resume(array $s): void
	{
		$this->phase=$s['phase']; $this->clock=$s['clock']; $this->start=$s['start']; $this->passSpeed=$s['passSpeed'];
		$this->pose=$this->previous=$s['pose'];
		$this->carFrames=$s['carFrames'] ?? [];
		if(!$this->carFrames) {
			// Earlier demos held their rear-bumper pose indefinitely with the drop clock still running.
			if($this->phase==='chase') $this->clock=0.;
			if($this->phase==='drop') $this->clock*=self::DROP_SECONDS/2.4;
		}
		$this->impact=$s['impact'] ?? null; $this->mount=$s['mount'] ?? null;
		$this->velocity=$s['velocity'] ?? ($this->phase==='missed' ? self::motionAlongPose($this->pose,$this->passSpeed) : null);
	}
}

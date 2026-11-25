<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Arcade chase prop. Its drop and tailing animation play relative to the car until a drift breaks the spell. */
final class SpeedSeeker
{
	private const SLIP = .18, DROP_SECONDS = 2.4, RELOAD_SECONDS = 12.;
	private string $phase = 'mounted';
	private float $clock = 0., $passSpeed = 0.;
	private ?array $velocity = null, $impact = null;
	/** Start of the drop, expressed in the car's frame so the animation works on winding roads. */
	private array $start = [];
	private ?array $pose = null, $previous = null;

	public function step(float $dt,array $car,?array $plane,string $patrol,Landscape $land,float $water,?array $beforeCar=null): ?array
	{
		$this->previous=$this->pose;
		$this->clock+=$dt;
		$drifting=abs($car['drift'])>=self::SLIP && ($car['motion'] ?? 'driving')==='driving';
		$course=$car['yaw']-$car['drift'];
		$f=sin($course); $g=cos($course);
		if($this->phase==='reload') {
			if($this->clock>=self::RELOAD_SECONDS) { $this->phase='mounted'; $this->clock=0.; $this->impact=null; }
			return null;
		}
		if($this->phase==='mounted') {
			if($patrol!=='escort' || $car['speed']<=60.*Car::MPS_PER_MPH || $drifting ||
				hypot($plane['x']-$car['x'],$plane['z']-$car['z'])>80.) { $this->clock=0.; return null; }
			if($this->clock<1.5) return null;
			$wing=Jet::projectileMount($plane,0);
			if($wing['y']<SeekerContact::floor($wing,$land,$water)+1.25) return null;
			$x=$wing['x']-$car['x']; $z=$wing['z']-$car['z'];
			$this->start=[$x*$g-$z*$f,$x*$f+$z*$g,$wing['y']-$car['y'],
				atan2(sin($wing['yaw']-$course),cos($wing['yaw']-$course)),$wing['pitch'],$wing['roll']];
			$this->velocity=[$plane['vx'],$plane['vy'],$plane['vz']];
			$this->phase='drop'; $this->clock=0.;
		}
		if($this->phase!=='missed' && ($drifting || $patrol==='departure')) {
			$this->phase='missed'; $this->clock=0.;
			// Only old recordings lack a measured velocity at release.
			$this->velocity ??= self::motionAlongPose($this->pose,$car['speed']);
			$this->passSpeed=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$this->velocity)));
		}
		if($this->phase==='missed') {
			// Coast with the last world-space displacement, even if the car doubles back.
			foreach(['x','y','z'] as $i=>$axis) $this->pose[$axis]+=$this->velocity[$i]*$dt;
			$hit=SeekerContact::first($this->previous,$this->pose,$beforeCar ?? $car,$car,true,$land,$water);
			if($this->clock>=6. || $hit!==null) {
				$this->impact=$hit['point'] ?? null;
				$this->phase='reload'; $this->clock=0.; $this->start=[]; $this->pose=$this->velocity=null;
			}
			return $hit;
		}
		$t=$this->phase==='drop' ? min(1.,$this->clock/self::DROP_SECONDS) : 1.;
		// Cross to the rear of the car while still high, then settle down over the bumper.
		$across=self::smooth($t/.80);
		$down=self::smooth(($t-.20)/.80);
		[$side,$along,$height,$yaw,$pitch,$roll]=$this->start;
		$shoulder=$side>=0. ? 1. : -1.;
		$target=[.54*$shoulder, Car::BODY_HEIGHT+1.23+.06*sin(3.*$this->clock), -Car::BODY_REAR-.66];
		$body=VehicleModel::basis($car+['pitch'=>0.,'roll'=>0.]);
		$offset=[$g*$side+$f*$along, $height, -$f*$side+$g*$along];
		$p=[];
		foreach(['x','y','z'] as $i=>$axis) {
			$bumper=$body[$i]*$target[0]+$body[$i+3]*$target[1]+$body[$i+6]*$target[2];
			$p[$axis]=$car[$axis]+$offset[$i]+($bumper-$offset[$i])*($axis==='y' ? $down : $across);
		}
		$sweep=4.*$shoulder*sin(M_PI*$t);
		$p['x']+=$g*$sweep; $p['z']-=$f*$sweep;
		$p['yaw']=$course+$yaw*(1.-$across)+$car['drift']*$across;
		$p['pitch']=$pitch*(1.-$down)+($car['pitch'] ?? 0.)*$down-.18*sin(M_PI*$t);
		$p['roll']=$roll*(1.-$across)+$shoulder*.28*sin(M_PI*$t);
		$p['y']=max($p['y'],SeekerContact::floor($p,$land,$water)+1.25);
		if($this->previous!==null && $dt>0.) {
			$this->velocity=[($p['x']-$this->previous['x'])/$dt,($p['y']-$this->previous['y'])/$dt,
				($p['z']-$this->previous['z'])/$dt];
		}
		if($this->previous!==null && ($hit=SeekerContact::first($this->previous,$p,$beforeCar ?? $car,$car,false,$land,$water))!==null) {
			$this->impact=$hit['point']; $this->phase='reload'; $this->clock=0.; $this->start=[]; $this->pose=$this->velocity=null;
			return $hit;
		}
		$this->pose=$p;
		if($t>=1.) $this->phase='chase';
		return null;
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
		return ['phase'=>$this->phase,'clock'=>$this->clock,'start'=>$this->start,'passSpeed'=>$this->passSpeed,'velocity'=>$this->velocity,'impact'=>$this->impact,'pose'=>$p];
	}

	public function resume(array $s): void
	{
		$this->phase=$s['phase']; $this->clock=$s['clock']; $this->start=$s['start']; $this->passSpeed=$s['passSpeed'];
		$this->pose=$this->previous=$s['pose'];
		$this->impact=$s['impact'] ?? null;
		$this->velocity=$s['velocity'] ?? ($this->phase==='missed' ? self::motionAlongPose($this->pose,$this->passSpeed) : null);
	}
}

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
	/** Start of the drop, expressed in the car's frame so the animation works on winding roads. */
	private array $start = [];
	private ?array $pose = null, $previous = null;

	public function step(float $dt,array $car,array $plane,string $patrol,Landscape $land,float $water): void
	{
		$this->previous=$this->pose;
		$this->clock+=$dt;
		$drifting=abs($car['drift'])>=self::SLIP && ($car['motion'] ?? 'driving')==='driving';
		$course=$car['yaw']-$car['drift'];
		$f=sin($course); $g=cos($course);
		if($this->phase==='reload') {
			if($this->clock>=self::RELOAD_SECONDS) { $this->phase='mounted'; $this->clock=0.; }
			return;
		}
		if($this->phase==='mounted') {
			if($patrol!=='escort' || $car['speed']<=60.*Car::MPS_PER_MPH || $drifting ||
				hypot($plane['x']-$car['x'],$plane['z']-$car['z'])>80.) { $this->clock=0.; return; }
			if($this->clock<1.5) return;
			$wing=Jet::projectileMount($plane,0);
			$x=$wing['x']-$car['x']; $z=$wing['z']-$car['z'];
			$this->start=[$x*$g-$z*$f,$x*$f+$z*$g,$wing['y']-$car['y'],
				atan2(sin($wing['yaw']-$course),cos($wing['yaw']-$course)),$wing['pitch'],$wing['roll']];
			$this->phase='drop'; $this->clock=0.;
		}
		if($this->phase!=='missed' && ($drifting || $patrol==='departure')) {
			$this->phase='missed'; $this->clock=0.;
			$this->passSpeed=max(40.,$car['speed']+24.);
		}
		if($this->phase==='missed') {
			// Commit to the last on-screen heading. The player can turn back without bringing this prop back.
			$this->pose['x']+=sin($this->pose['yaw'])*$this->passSpeed*$dt;
			$this->pose['z']+=cos($this->pose['yaw'])*$this->passSpeed*$dt;
			$this->pose['pitch']=min(.62,$this->pose['pitch']+.55*$dt);
			$this->pose['y']+=sin($this->pose['pitch'])*$this->passSpeed*$dt;
			if($this->clock>=1.6) {
				$this->phase='reload'; $this->clock=0.; $this->start=[]; $this->pose=null;
			}
			return;
		}
		$t=$this->phase==='drop' ? min(1.,$this->clock/self::DROP_SECONDS) : 1.;
		$blend=$t*$t*(3.-2.*$t);
		[$side,$along,$height,$yaw,$pitch,$roll]=$this->start;
		$shoulder=$side>=0. ? 1. : -1.;
		// Sweep past the side of the car and settle in the rear quarter visible from the chase camera.
		$side+=(2.15*$shoulder-$side)*$blend + 4.*sin(M_PI*$t)*$shoulder;
		$along+=(-2.5-$along)*$blend;
		$height+=(1.25-.14*cos(3.*$this->clock)-$height)*$blend;
		$x=$car['x']+$f*$along+$g*$side; $z=$car['z']+$g*$along-$f*$side;
		$half=$land->recipe()['worldSize']/2.;
		$ground=$land->height(max(-$half,min($half,$x)),max(-$half,min($half,$z)));
		$this->pose=['x'=>$x,'y'=>max($car['y']+$height,$water+.5,$ground+.5),'z'=>$z,
			'yaw'=>$course+$yaw*(1.-$blend),'pitch'=>$pitch*(1.-$blend)-.22*sin(M_PI*$t),
			'roll'=>$roll*(1.-$blend)+$shoulder*.28*sin(M_PI*$t)];
		if($t>=1.) $this->phase='chase';
	}

	public function state(float $alpha=1.): array
	{
		$p=$this->pose;
		if($alpha<1. && $this->previous!==null && $p!==null) $p=AirPatrol::interpolate($this->previous,$p,$alpha);
		return ['phase'=>$this->phase,'clock'=>$this->clock,'start'=>$this->start,'passSpeed'=>$this->passSpeed,'pose'=>$p];
	}

	public function resume(array $s): void
	{
		$this->phase=$s['phase']; $this->clock=$s['clock']; $this->start=$s['start']; $this->passSpeed=$s['passSpeed'];
		$this->pose=$this->previous=$s['pose'];
	}
}

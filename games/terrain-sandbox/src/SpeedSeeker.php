<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Arcade chase prop. A wing fly-out and a sweeping return lead into a short, escapable bumper chase. */
final class SpeedSeeker
{
	private const SLIP = .18, FLY_AHEAD = .42, DROP_SECONDS = 1.95, CLOSE_SECONDS = 3.8, RELOAD_SECONDS = 10.;
	private const LOOM_ABOVE_ROOF = 2.12;
	/** Meters forward of mesh center on the four-meter prop (60 cm behind the nose). */
	private const CENTER_OF_GRAVITY = 1.40;
	// The loose pass crosses the driving view in one beat, then bursts at the escort's usual lead.
	private const PASS_SECONDS = .78, BURST_MARGIN = .82, HANDOFF_SECONDS = .10, AIM_ANGLE = 22.;
	private const BOOST_RAMP = .24;
	private bool $driftPass=false;
	private ?float $rearPassGap=null;
	private string $phase = 'mounted';
	private int $slot = 0;
	private float $clock = 0., $passSpeed = 0.;
	/** Animation time since wing release, continuous across the changes in flight phase. */
	private ?float $airtime=null;
	private float $previousAirtime=0.;
	private ?array $velocity = null, $impact = null;
	/** Drop poses end with the loom clearance above the roof. Older recorded drops used 1.32 m. */
	private array $start = [];
	/** Two smoothed copies of the driver's animation frame, retained when branching a demo mid-pass. */
	private array $carFrames = [];
	private ?array $pose = null, $previous = null, $mount = null;

	public function step(float $dt,array $car,?array $plane,string $patrol,Landscape $land,float $water,?array $beforeCar=null,?callable $eye=null,float $aspect=1.): ?array
	{
		$this->previous=$this->pose;
		$this->previousAirtime=$this->airtime ?? 0.;
		if($this->airtime!==null) $this->airtime+=$dt;
		$this->clock+=$dt;
		$drifting=abs($car['drift'])>=self::SLIP && ($car['motion'] ?? 'driving')==='driving';
		if($this->phase==='reload') {
			if($this->clock>=FlightTrails::LODGED_LIFE) $this->mount=null;
			if($patrol==='glide' || $patrol==='slide') { $this->clock=min($this->clock,self::RELOAD_SECONDS); return null; }
			if($this->clock>=self::RELOAD_SECONDS) {
				$this->phase='mounted'; $this->clock=0.; $this->impact=null;
				$this->slot=1-$this->slot;
			}
			return null;
		}
		if($this->phase==='mounted') {
			if($plane===null || $patrol!=='escort' || $car['speed']<=60.*Car::MPS_PER_MPH || $drifting ||
				hypot($plane['x']-$car['x'],$plane['z']-$car['z'])>80. || !$eye || !$this->inShot($plane,$camera=$eye(),$aspect)) { $this->clock=0.; return null; }
			if($this->clock<1.5-1e-9) return null;
			$wing=Jet::projectileMount($plane,$this->slot);
			if($wing['y']<SeekerContact::floor($wing,$land,$water)+1.25) return null;
			if(!self::clearSight($wing,$camera,$land)) { $this->clock=0.; return null; }
			$this->carFrames=[];
			$course=$car['yaw']-$car['drift']; $f=sin($course); $g=cos($course);
			$x=$wing['x']-$car['x']; $z=$wing['z']-$car['z'];
			$this->start=[$x*$g-$z*$f,$x*$f+$z*$g,$wing['y']-$car['y'],
				atan2(sin($wing['yaw']-$course),cos($wing['yaw']-$course)),$wing['pitch'],$wing['roll'],self::LOOM_ABOVE_ROOF];
			$this->velocity=[$plane['vx'],$plane['vy'],$plane['vz']];
			$this->phase='drop'; $this->clock=0.; $this->airtime=0.;
		}
		if($this->phase==='return' && $plane!==null && $patrol==='escort') {
			if($this->driftPass) return $this->boostedReturn($dt,$plane,$beforeCar ?? $car,$car,$land,$water);
			return $this->recordedReturn($dt,$plane,$beforeCar ?? $car,$car,$land,$water);
		}
		$rearDrift=$drifting && $this->phase==='chase' && $this->behindBumper($beforeCar ?? $car);
		if($this->phase!=='missed' && ($rearDrift || $patrol==='departure' || $plane===null)) {
			$this->rearPassGap=null;
			$this->phase='missed'; $this->clock=0.; $this->carFrames=[];
			$this->start=array_slice($this->start,0,6);
			$this->velocity ??= self::motionAlongPose($this->pose,$car['speed']);
			if($rearDrift) {
				$this->driftPass=true;
				$this->loosePass($car,$patrol==='escort' ? $plane : null);
				$this->clock=$dt;
			}
			$this->passSpeed=hypot(hypot($this->velocity[0],$this->velocity[2]),$this->velocity[1]);
		}
		if($this->phase==='missed') {
			$camera=$this->driftPass && $eye ? $eye() : null;
			$from=$this->pose;
			$step=$this->driftPass ? min($dt,max(0.,self::PASS_SECONDS-$this->clock+$dt)) : $dt;
			foreach(['x','y','z'] as $i=>$axis) $this->pose[$axis]+=$this->velocity[$i]*$step;
			$burst=$this->driftPass && $this->clock>=self::PASS_SECONDS-1e-9;
			$edge=$camera ? self::screenExtent($this->pose,$camera,$aspect) : 0.;
			if($camera && $edge>=self::BURST_MARGIN) {
				$lastEdge=self::screenExtent($from,$camera,$aspect);
				if($lastEdge<1. && $edge>$lastEdge) {
					// The flash starts at the last visible pose, even on the outside of a hard turn.
					$this->pose=$from;
					$burst=true;
				}
			}
			$hit=SeekerContact::first($this->previous,$this->pose,$beforeCar ?? $car,$car,true,$land,$water);
			if($hit!==null) return $this->finish($hit);
			if($burst) return $this->finish(['kind'=>'air','point'=>[$this->pose['x'],$this->pose['y'],$this->pose['z']]]);
			if(!$this->driftPass && $this->clock>=6.) return $this->finish(null);
			if($this->rearPassGap!==null) {
				if(!$this->driftPass || $plane===null || $patrol!=='escort' ||
					$edge>=.92) {
					$this->rearPassGap=null;
				} elseif($this->clock>=self::HANDOFF_SECONDS-1e-9) {
					if($camera && !self::clearSight($this->pose,$camera,$land)) $this->rearPassGap=null;
					else {
						$this->rearPassGap=$this->planeGap($plane);
						$this->start=[$this->pose['x'],$this->pose['y'],$this->pose['z'],
							$this->pose['yaw'],$this->pose['pitch'],$this->pose['roll']];
						$this->phase='return'; $this->clock=0.;
					}
				}
			}
			return null;
		}
		$drop=$this->phase==='drop';
		$carFrame=$this->animatedCar($car,$dt);
		$body=VehicleModel::basis($carFrame);
		$closing=$drop ? 0. : min(1.,$this->clock/self::CLOSE_SECONDS);
		$shoulder=$this->start[0]>=0. ? 1. : -1.;
		// Move the mark from above one rear corner into the bumper. The last dip is the driver's drift cue.
		$clearance=$this->start[6] ?? 1.32;
		$mark=[$shoulder*(1.34*(1.-$closing)+.8*sin(2.*M_PI*$closing)*(1.-$closing)),
			Car::BODY_HEIGHT+$clearance-($clearance+.16)*$closing**1.8, -Car::BODY_REAR-.9+1.08*$closing];
		$p=$carFrame;
		foreach(['x','y','z'] as $i=>$axis) $p[$axis]+=$body[$i]*$mark[0]+$body[$i+3]*$mark[1]+$body[$i+6]*$mark[2];
		if($drop) {
			// The first beat uses the moving wing as its reference. The next beat arcs around the rear quarter.
			$wing=Jet::projectileMount($plane,$this->slot);
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
			return $this->finish($hit);
		}
		$this->pose=$p;
		if($drop && $this->clock>=self::DROP_SECONDS-1e-9) { $this->phase='chase'; $this->clock=0.; }
		return null;
	}

	/** The driver's flick aims the prop once. It cannot earn a new lock by coasting past the jet later. */
	private function loosePass(array $car,?array $plane): void
	{
		$course=$car['yaw']-$car['drift'];
		$yaw=$course+max(-.95,min(.95,$car['drift']));
		$pitch=max(.18,min(.55,($car['pitch'] ?? 0.)+.20));
		// Travel with the car's original course; add the flick on top so even a sideways miss passes ahead.
		$lead=AirPatrol::ESCORT_LEAD + Car::BODY_REAR + 1.;
		$extra=$lead/(self::PASS_SECONDS*cos($yaw-$course));
		$travel=max(0.,$car['speed'])*cos($car['pitch'] ?? 0.);
		$vx=$travel*sin($course)+$extra*sin($yaw); $vz=$travel*cos($course)+$extra*cos($yaw);
		$this->velocity=[$vx,max(0.,$car['speed'])*sin($car['pitch'] ?? 0.)+$extra*tan($pitch),$vz];
		$this->pose['yaw']=atan2($vx,$vz);
		$this->pose['pitch']=atan2($this->velocity[1],hypot($vx,$vz));
		if($plane!==null) {
			$dx=$plane['x']-$this->pose['x']; $dy=$plane['y']-$this->pose['y']; $dz=$plane['z']-$this->pose['z'];
			$gap=hypot(hypot($dx,$dz),$dy);
			[$nx,$ny,$nz]=array_slice(VehicleModel::basis($this->pose),6);
			$along=$dx*$nx+$dy*$ny+$dz*$nz;
			if($gap>0. && $gap<=90. && $along>=$gap*cos(deg2rad(self::AIM_ANGLE))) $this->rearPassGap=$gap;
		}
	}

	private function behindBumper(array $car): bool
	{
		$body=VehicleModel::basis($car+['pitch'=>0.,'roll'=>0.]);
		$along=($this->pose['x']-$car['x'])*$body[6]+($this->pose['y']-$car['y'])*$body[7]+($this->pose['z']-$car['z'])*$body[8];
		return $along <= -Car::BODY_REAR;
	}

	private function planeGap(array $plane): float
	{
		return hypot(hypot($this->pose['x']-$plane['x'],$this->pose['z']-$plane['z']),$this->pose['y']-$plane['y']);
	}

	/** Move the arcade prop from its visible handoff to the escort. */
	private function boostedReturn(float $dt,array $plane,array $beforeCar,array $car,Landscape $land,float $water): ?array
	{
		// Advance the mesh by its carried speed plus an ease-in. The flash replaces the last partial step.
		$boost=$this->rearPassGap/.30;
		$distance=$this->passSpeed*$dt+$boost*(self::boostDistance($this->clock)-self::boostDistance(max(0.,$this->clock-$dt)));
		$gap=$this->planeGap($plane);
		$blend=$gap>0. ? min(1.,$distance/$gap) : 1.;
		foreach(['x','y','z'] as $axis) $this->pose[$axis]+=$blend*($plane[$axis]-$this->pose[$axis]);
		if($dt>0.) {
			foreach(['x','y','z'] as $i=>$axis) $this->velocity[$i]=($this->pose[$axis]-$this->previous[$axis])/$dt;
			[$x,$y,$z]=$this->velocity;
			$tip=self::smooth($this->clock/.10);
			$turn=atan2($x,$z)-$this->start[3];
			$this->pose['yaw']=$this->start[3]+$tip*atan2(sin($turn),cos($turn));
			$this->pose['pitch']=$this->start[4]+$tip*(atan2($y,hypot($x,$z))-$this->start[4]);
		}
		$hit=SeekerContact::first($this->previous,$this->pose,$beforeCar,$car,true,$land,$water);
		if($hit!==null) return $this->finish($hit);
		if($blend>=1.) return $this->finish(['kind'=>'plane','point'=>[$plane['x'],$plane['y'],$plane['z']]]);
		return null;
	}

	/** Preserve the animation for a demo branched mid-return before drift aiming was introduced. */
	private function recordedReturn(float $dt,array $plane,array $beforeCar,array $car,Landscape $land,float $water): ?array
	{
		$carry=0.;
		$eased=count($this->start)>=9;
		if($eased) {
			$ramp=$this->start[9] ?? .20; // Earlier recorded returns built speed in 200 ms.
			$duration=$ramp+($this->start[10] ?? .35);
			$t=min(1.,($this->clock+1e-9)/$duration);
			// Integrate a smoothstep speed ramp, then keep the gained speed through the end of the animation.
			$time=$t*$duration;
			$u=min(1.,$time/$ramp);
			$distance=$ramp*$u**3*(1.-.5*$u)+max(0.,$time-$ramp);
			$blend=$distance/($duration-.5*$ramp);
			// Carry the closing drift into the first frame. Its contribution fades to zero at the endpoint.
			$carry=$time-$duration*$blend;
		} elseif($this->rearPassGap===null) {
			// A take branched during an older return keeps the animation it was already playing.
			$t=min(1.,$this->clock/.60);
			$blend=self::smooth($t);
		} else {
			// The first boosted takes used a linear 100 ms speed ramp.
			$t=min(1.,($this->clock+1e-9)/.50);
			$ramp=.10/.50;
			$blend=($t<$ramp ? .5*$t*$t/$ramp : $t-.5*$ramp)/(1.-.5*$ramp);
		}
		foreach(['x','y','z'] as $i=>$axis)
			$this->pose[$axis]=$plane[$axis]+(1.-$blend)*$this->start[$i]+$carry*($this->start[6+$i] ?? 0.);
		$this->pose['y']+=3.*sin(M_PI*($eased ? $blend : $t));
		foreach(['yaw','pitch','roll'] as $i=>$axis) {
			$d=$plane[$axis]-$this->start[3+$i];
			$this->pose[$axis]=$this->start[3+$i]+$blend*atan2(sin($d),cos($d));
		}
		$this->pose['roll']+=sin(M_PI*$t);
		if($dt>0.) {
			foreach(['x','y','z'] as $i=>$axis) $this->velocity[$i]=($this->pose[$axis]-$this->previous[$axis])/$dt;
			if($this->rearPassGap!==null) {
				// Face the exhaust trail away from the direction the mesh is moving.
				[$x,$y,$z]=$this->velocity;
				$turn=atan2($x,$z)-$this->start[3];
				$tip=self::smooth($this->clock/.10);
				$this->pose['yaw']=$this->start[3]+$tip*atan2(sin($turn),cos($turn));
				$this->pose['pitch']=$this->start[4]+$tip*(atan2($y,hypot($x,$z))-$this->start[4]);
			}
		}
		$hit=SeekerContact::first($this->previous,$this->pose,$beforeCar,$car,true,$land,$water);
		if($hit!==null) return $this->finish($hit);
		if($t>=1.) return $this->finish(['kind'=>'plane','point'=>[$plane['x'],$plane['y'],$plane['z']]]);
		return null;
	}

	/** Integral of the ease-in speed curve; independent of the render or simulation rate. */
	private static function boostDistance(float $time): float
	{
		$u=min(1.,$time/self::BOOST_RAMP);
		return self::BOOST_RAMP*$u**3*(1.-.5*$u)+max(0.,$time-self::BOOST_RAMP);
	}

	private function finish(?array $hit): ?array
	{
		$this->driftPass=false;
		$this->impact=$hit['point'] ?? null; $this->mount=$hit['mount'] ?? null;
		$this->phase='reload'; $this->clock=0.; $this->start=$this->carFrames=[];
		$this->pose=$this->velocity=$this->rearPassGap=$this->airtime=null;
		return $hit;
	}

	/** Check terrain occlusion only at the two animation handoffs. */
	private static function clearSight(array $point,Camera $camera,Landscape $land): bool
	{
		$sight=[$point['x']-$camera->position[0],$point['y']-$camera->position[1],$point['z']-$camera->position[2]];
		$range=hypot(hypot($sight[0],$sight[2]),$sight[1]);
		return $land->raycast($camera->position,$sight,$range)===null;
	}

	/** Furthest projected edge as a fraction of the viewport half-extent (one at the frame edge). */
	private static function screenExtent(array $p,Camera $camera,float $aspect,float $padding=0.,float $near=.25): float
	{
		[$front,$right,$up]=$camera->basis();
		$x=$p['x']-$camera->position[0]; $y=$p['y']-$camera->position[1]; $z=$p['z']-$camera->position[2];
		$depth=$x*$front[0]+$y*$front[1]+$z*$front[2];
		if($depth<=$near) return INF;
		$half=$depth*tan($camera->fov/2);
		return max((abs($x*$right[0]+$y*$right[1]+$z*$right[2])+$padding)/$aspect,
			abs($x*$up[0]+$y*$up[1]+$z*$up[2])+$padding)/$half;
	}

	/** Leave space around the fuselage and the releasing wing in the actual chase camera. */
	private function inShot(array $plane,Camera $camera,float $aspect): bool
	{
		return self::screenExtent($plane,$camera,$aspect,3.,8.)<=.90
			&& self::screenExtent(Jet::projectileMount($plane,$this->slot),$camera,$aspect,3.,8.)<=.90;
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

	/** Swing the visible tail around the forward center of gravity on the flight path. */
	public static function modelPose(?array $shot): ?array
	{
		$p=$shot['pose'] ?? null;
		$t=$shot['airtime'] ?? 0.;
		if($p===null || $t<=0.) return $p;
		$side=($shot['slot'] ?? 0)===0 ? 1. : -1.;
		// Two overlapping beats break up the rhythm. Ease into them as the prop clears the wing.
		$wave=.78*sin(2.*M_PI*1.35*$t)+.22*sin(2.*M_PI*2.15*$t);
		$yaw=$p['yaw'];
		$p['yaw']+=$side*deg2rad(8.)*self::smooth($t/.24)*$wave;
		$pivot=self::CENTER_OF_GRAVITY*cos($p['pitch']);
		$p['x']+=$pivot*(sin($yaw)-sin($p['yaw']));
		$p['z']+=$pivot*(cos($yaw)-cos($p['yaw']));
		return $p;
	}

	/** A patrol carries one prop per wing. The other wing stays loaded throughout a pass. */
	public static function mountedSlots(?array $seeker): array
	{
		return ($seeker['phase'] ?? 'mounted')==='mounted' ? [0,1] : [1-($seeker['slot'] ?? 0)];
	}

	public function state(float $alpha=1.): array
	{
		$p=$this->pose;
		if($alpha<1. && $this->previous!==null && $p!==null) $p=AirPatrol::interpolate($this->previous,$p,$alpha);
		$airtime=$this->airtime!==null ? ['airtime'=>$this->previousAirtime+$alpha*($this->airtime-$this->previousAirtime)] : [];
		return ['phase'=>$this->phase,'slot'=>$this->slot,'clock'=>$this->clock,'start'=>$this->start,'passSpeed'=>$this->passSpeed,'velocity'=>$this->velocity,'impact'=>$this->impact,'pose'=>$p]+$airtime+($this->rearPassGap!==null ? ['rearPassGap'=>$this->rearPassGap] : [])+($this->driftPass ? ['driftPass'=>true] : [])+($this->mount ? ['mount'=>$this->mount] : [])+($this->carFrames ? ['carFrames'=>$this->carFrames] : []);
	}

	public function resume(array $s): void
	{
		$this->airtime=$s['airtime'] ?? null;
		$this->previousAirtime=$this->airtime ?? 0.;
		$this->driftPass=$s['driftPass'] ?? false;
		$this->slot=$s['slot'] ?? 0;
		$this->rearPassGap=$s['rearPassGap'] ?? null;
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

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
	// Inside this screen margin a loose prop can coast. At the margin, settle its next animation while visible.
	private const DECISION_EDGE = .72, LAST_VISIBLE = .92, EDGE_LEAD_SECONDS = .35;
	private const RETURN_RADIUS = 50., BOOST_RAMP = .24, BOOST_CRUISE = .18;
	/** An eligible coast tracks this gap; its last value marks the boosted return in recorded takes. */
	private ?float $rearPassGap=null;
	private string $phase = 'mounted';
	private int $slot = 0;
	private float $clock = 0., $passSpeed = 0.;
	private ?array $velocity = null, $impact = null;
	/** Drop poses end with the loom clearance above the roof. Older recorded drops used 1.32 m. */
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
			$this->phase='drop'; $this->clock=0.;
		}
		if($this->phase==='return' && $plane!==null && $patrol==='escort')
			return $this->returnToPlane($dt,$plane,$beforeCar ?? $car,$car,$land,$water);
		$rearDrift=$drifting && $this->phase==='chase' && $this->behindBumper($beforeCar ?? $car);
		if($this->phase!=='missed' && ($rearDrift || $patrol==='departure' || $plane===null)) {
			$this->rearPassGap=$rearDrift && $plane!==null && $patrol==='escort' ? $this->planeGap($plane) : null;
			$this->phase='missed'; $this->clock=0.; $this->carFrames=[];
			$this->start=array_slice($this->start,0,6);
			$this->velocity ??= self::motionAlongPose($this->pose,$car['speed']);
			if($rearDrift) {
				// Give the loose prop one upward flick, then let it coast along the player's drift.
				$this->pose['pitch']+=deg2rad(5.);
				$this->velocity[1]+=2.5;
			}
			$this->passSpeed=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$this->velocity)));
		}
		if($this->phase==='missed') {
			foreach(['x','y','z'] as $i=>$axis) $this->pose[$axis]+=$this->velocity[$i]*$dt;
			$hit=SeekerContact::first($this->previous,$this->pose,$beforeCar ?? $car,$car,true,$land,$water);
			if($this->clock>=6. || $hit!==null) return $this->finish($hit);
			if($this->rearPassGap!==null && $plane!==null && $patrol==='escort') {
				$gap=$this->planeGap($plane);
				$camera=$eye ? $eye() : null;
				$screen=$camera ? self::screenExtent($this->pose,$camera,$aspect) : 0.;
				$edge=$screen>=self::DECISION_EDGE;
				$gain=$this->rearPassGap-$gap;
				// At the edge, give a converging pass a short lead on the usual proximity cue.
				$anticipated=$edge && $dt>0. ? $gap-self::EDGE_LEAD_SECONDS*$gain/$dt : $gap;
				if($screen<self::LAST_VISIBLE && ($edge || $this->clock>=.10) && $gain>0. && $anticipated<=self::RETURN_RADIUS) {
					if($camera && !self::clearSight($this->pose,$camera,$land)) {
						$this->rearPassGap=null;
						return null;
					}
					$this->phase='return'; $this->clock=0.;
					$this->start=[$this->pose['x']-$plane['x'],$this->pose['y']-$plane['y'],$this->pose['z']-$plane['z'],
						$this->pose['yaw'],$this->pose['pitch'],$this->pose['roll'],
						$this->velocity[0]-$plane['vx'],$this->velocity[1]-$plane['vy'],$this->velocity[2]-$plane['vz'],self::BOOST_RAMP,self::BOOST_CRUISE];
				}
				$this->rearPassGap=$edge && $this->phase==='missed' ? null : $gap;
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

	/** Arcade return animation: curl from the pickup offset into the moving F5. */
	private function returnToPlane(float $dt,array $plane,array $beforeCar,array $car,Landscape $land,float $water): ?array
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

	private function finish(?array $hit): ?array
	{
		$this->impact=$hit['point'] ?? null; $this->mount=$hit['mount'] ?? null;
		$this->phase='reload'; $this->clock=0.; $this->start=$this->carFrames=[];
		$this->pose=$this->velocity=$this->rearPassGap=null;
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

	/** A patrol carries one prop per wing. The other wing stays loaded throughout a pass. */
	public static function mountedSlots(?array $seeker): array
	{
		return ($seeker['phase'] ?? 'mounted')==='mounted' ? [0,1] : [1-($seeker['slot'] ?? 0)];
	}

	public function state(float $alpha=1.): array
	{
		$p=$this->pose;
		if($alpha<1. && $this->previous!==null && $p!==null) $p=AirPatrol::interpolate($this->previous,$p,$alpha);
		return ['phase'=>$this->phase,'slot'=>$this->slot,'clock'=>$this->clock,'start'=>$this->start,'passSpeed'=>$this->passSpeed,'velocity'=>$this->velocity,'impact'=>$this->impact,'pose'=>$p]+($this->rearPassGap!==null ? ['rearPassGap'=>$this->rearPassGap] : [])+($this->mount ? ['mount'=>$this->mount] : [])+($this->carFrames ? ['carFrames'=>$this->carFrames] : []);
	}

	public function resume(array $s): void
	{
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

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Follow directed highway splines through a single window. A handoff starts a new leg under the moving car. */
final class JourneyDriver
{
	use DriverControls;

	private const TOP_SPEED=Car::TOP_SPEED_MPH*Car::MPS_PER_MPH;
	private const PLAN_STEP=9., PLAN_CELLS=32, BRAKING=6.;
	private const STOP_MARGIN=25.;
	private array $roads=[], $starts=[];
	private float $length=0., $station=0.;
	private array $speedSquared=[];
	private bool $boosting=false;
	public bool $arrived=false;
	public int $exit=0;

	/** Start on the car's own arm, including after a fork or a window handoff. */
	public static function board(Landscape $land,array $car,int $preferred): ?self
	{
		$roads=$land->roads(); $next=JourneyRoad::outwardLinks($roads,$land->recipe());
		$nearest=null; $at=0.; $best=INF;
		foreach($next as $i=>$slots) {
			$road=$roads[$i]['_road']; [$left,$top,$right,$bottom]=$road['bounds'];
			$x=$car['x']; $z=$car['z'];
			$reach=$roads[$i]['width']/2.+5.;
			if(max($left-$x,0.,$x-$right)**2+max($top-$z,0.,$z-$bottom)**2>min($best,$reach**2)) continue;
			$samples=$road['samples']; $stations=$road['stations'];
			// At a shared endpoint give the approaching road the tie so either onward arm remains available.
			for($k=1;$k<count($samples);$k++) {
				[$ax,$ay,$az]=$samples[$k-1]; [$bx,$by,$bz]=$samples[$k];
				$dx=$bx-$ax; $dz=$bz-$az;
				$t=max(0.,min(1.,(($x-$ax)*$dx+($z-$az)*$dz)/max(1.e-9,$dx*$dx+$dz*$dz)));
				$error=($x-$ax-$t*$dx)**2+($z-$az-$t*$dz)**2;
				if($error>$reach**2) continue;
				$error+=($car['y']-($ay+$t*($by-$ay)))**2;
				if($error>$best || ($error===$best && !($k===count($samples)-1 && $t===1.))) continue;
				$best=$error; $nearest=$i; $at=$stations[$k-1]+$t*($stations[$k]-$stations[$k-1]);
			}
		}
		if($nearest===null) return null;
		$self=new self;
		$self->exit=array_key_exists($preferred,$next[$nearest]) ? $preferred : array_key_first($next[$nearest]);
		for($i=$nearest;$i!==null;$i=$next[$i][$self->exit]) {
			$self->starts[]=$self->length; $self->roads[]=$roads[$i];
			$stations=$roads[$i]['_road']['stations']; $self->length+=$stations[array_key_last($stations)];
		}
		$self->station=$at;
		return $self;
	}

	private function pose(float $at): array
	{
		$at=min($this->length,max(0.,$at));
		$i=JourneySigns::atOrBefore($this->starts,$at);
		return JourneySigns::pose($this->roads[$i],$at-$this->starts[$i]);
	}

	/** Plan a block at a time, looking a full top-speed braking distance beyond its end. */
	private function speedLimit(): float
	{
		$step=self::PLAN_STEP;
		$cell=(int)floor($this->station/$step);
		if(!isset($this->speedSquared[$cell+1],$this->speedSquared[$cell])) {
			$first=intdiv($cell,self::PLAN_CELLS)*self::PLAN_CELLS;
			$end=$first+self::PLAN_CELLS;
			$brakingStep=2.*self::BRAKING*$step;
			$top=self::TOP_SPEED**2;
			$last=min((int)ceil($this->length/$step)-1,$end+(int)ceil($top/$brakingStep)+1);
			$local=[];
			$before=$this->pose($first*$step);
			$lastGrade=$first>0 ? ($before['position'][1]-$this->pose(($first-1)*$step)['position'][1])/$step : null;
			for($i=$first;$i<=$last;$i++) {
				$after=$this->pose(($i+1)*$step);
				$span=min($step,$this->length-$i*$step);
				[$ax,$az]=$before['heading']; [$bx,$bz]=$after['heading'];
				$turn=abs(atan2($ax*$bz-$az*$bx,$ax*$bx+$az*$bz))/$span;
				$grade=($after['position'][1]-$before['position'][1])/$span;
				$crest=$lastGrade===null ? 0. : max(0.,$lastGrade-$grade)/$step;
				$local[$i]=min($top,5.8/max(.0001,$turn+$crest));
				$lastGrade=$grade; $before=$after;
			}
			// Carry each corner's entry speed back along the approach (v² = u² + 2as).
			// Allow one extra sample for the steering and brake response.
			$next=$nextLocal=$top;
			$this->speedSquared=[];
			for($i=max($end,$last);$i>=$first;$i--) {
				$cap=$local[$i] ?? $top;
				$next=min($cap,$nextLocal,$next+$brakingStep);
				if($i<=$end) $this->speedSquared[$i]=$next;
				$nextLocal=$cap;
			}
		}
		$t=$this->station/$step-$cell;
		return sqrt($this->speedSquared[$cell]*(1.-$t)+$this->speedSquared[$cell+1]*$t);
	}

	/** @return array{float,float,bool,bool} Throttle/brake, steering, handbrake and nitrous. */
	public function controls(array $car): array
	{
		if($this->arrived) return [0.,0.,true,false];
		$here=$this->pose($this->station); [$x,,$z]=$here['position']; [$tx,$tz]=$here['heading'];
		// Project locally so a nearby road on a switchback cannot steal the cursor.
		$advance=($car['x']-$x)*$tx+($car['z']-$z)*$tz;
		$this->station=max(0.,min($this->length,$this->station+max(-8.,min(8.,$advance))));
		if($car['motion']!=='driving' || $car['roadHold']>0.) {
			$this->boosting=false;
			return [0.,0.,false,false];
		}
		$speed=$car['speed']; $remaining=$this->length-self::STOP_MARGIN-$this->station;
		if($remaining<=1.) {
			$this->arrived=abs($speed)<.3;
			return [0.,0.,true,false];
		}
		$look=max(7.,min(28.,7.+.5*max(0.,$speed)));
		[$gx,,$gz]=$this->pose($this->station+$look)['position'];
		[$steer,,$error]=self::pursuit($car,$gx-$car['x'],$gz-$car['z']);
		$limit=min($this->speedLimit(),sqrt(9.*$remaining));
		// Slow right down if a takeover or a landing leaves the car looking away from the lane.
		if(abs($error)>.5) $limit=min($limit,max(3.,10./abs($error)));
		if(abs($error)>1.6) $steer=$error>0. ? -1. : 1.;
		// Spend charge on aligned acceleration, and release early enough to brake for a bend or the destination.
		$this->boosting=$speed>=0. && abs($error)<.18 && abs($steer)<.45
			&& $car['nitrous']>($this->boosting ? .012 : .075)
			&& $limit-$speed>($this->boosting ? 2. : 6.);
		return [$this->pedal($speed,$limit,$this->boosting),$steer,false,$this->boosting];
	}
}

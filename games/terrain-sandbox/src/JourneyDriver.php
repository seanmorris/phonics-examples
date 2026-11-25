<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Follow directed highway splines through a single window. A handoff starts a new leg under the moving car. */
final class JourneyDriver
{
	use DriverControls;

	private const CRUISE=95.*Car::MPS_PER_MPH;
	private const STOP_MARGIN=25.;
	private array $roads=[], $starts=[];
	private float $length=0., $station=0., $plannedAt=-INF, $limit=0.;
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

	/** Look through the braking distance for curves and crests using the already graded tire surface. */
	private function cruiseLimit(): float
	{
		$limit=self::CRUISE; $step=9.; $before=$this->pose($this->station);
		for($d=$step;$d<=$limit**2/12.+2.*$step;$d+=$step) {
			$after=$this->pose($this->station+$d);
			[$ax,$az]=$before['heading']; [$bx,$bz]=$after['heading'];
			$curvature=abs(atan2($ax*$bz-$az*$bx,$ax*$bx+$az*$bz))/$step;
			$grade=($after['position'][1]-$before['position'][1])/$step;
			$crest=isset($lastGrade) ? max(0.,$lastGrade-$grade)/$step : 0.;
			$safe=5.8/max(.0001,$curvature+$crest);
			$limit=min($limit,sqrt($safe+12.*max(0.,$d-2.*$step)));
			if($this->station+$d >= $this->length) break;
			$lastGrade=$grade; $before=$after;
		}
		return $limit;
	}

	/** @return array{float,float,bool,bool} Throttle/brake, steering, handbrake and nitrous. */
	public function controls(array $car): array
	{
		if($this->arrived) return [0.,0.,true,false];
		$here=$this->pose($this->station); [$x,,$z]=$here['position']; [$tx,$tz]=$here['heading'];
		// Project locally so a nearby road on a switchback cannot steal the cursor.
		$advance=($car['x']-$x)*$tx+($car['z']-$z)*$tz;
		$this->station=max(0.,min($this->length,$this->station+max(-8.,min(8.,$advance))));
		if($car['motion']!=='driving' || $car['roadHold']>0.) return [0.,0.,false,false];
		if(abs($this->station-$this->plannedAt)>4.) {
			$this->limit=$this->cruiseLimit(); $this->plannedAt=$this->station;
		}
		$speed=$car['speed']; $remaining=$this->length-self::STOP_MARGIN-$this->station;
		if($remaining<=1.) {
			$this->arrived=abs($speed)<.3;
			return [0.,0.,true,false];
		}
		$look=max(7.,min(28.,7.+.5*max(0.,$speed)));
		[$gx,,$gz]=$this->pose($this->station+$look)['position'];
		[$steer,,$error]=self::pursuit($car,$gx-$car['x'],$gz-$car['z']);
		$limit=min($this->limit,sqrt(9.*$remaining));
		// Slow right down if a takeover or a landing leaves the car looking away from the lane.
		if(abs($error)>.5) $limit=min($limit,max(3.,10./abs($error)));
		if(abs($error)>1.6) $steer=$error>0. ? -1. : 1.;
		return [$this->pedal($speed,$limit),$steer,false,false];
	}
}

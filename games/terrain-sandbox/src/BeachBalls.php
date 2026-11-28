<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Lightweight, buoyant sandbox props. Each row holds position, velocity, pitch and roll. */
final class BeachBalls
{
	public const RADIUS = 2.;
	public const FIRST = 1000000, LIMIT = 16;
	private array $balls = [], $sleeping = [];

	public function states(): array { return $this->balls; }
	public function has(int $id): bool { return isset($this->balls[$id]); }
	public function wake(): void { $this->sleeping=[]; }
	public static function positions(array $states): array
	{
		return array_map(static fn($b)=>array_slice($b,0,3),$states);
	}

	public static function valid(mixed $states): bool
	{
		if(!is_array($states) || count($states)>self::LIMIT) return false;
		foreach($states as $id=>$row) {
			if(!is_int($id) || $id<self::FIRST || $id>=self::FIRST+self::LIMIT
				|| !is_array($row) || !array_is_list($row) || count($row)!==8) return false;
			foreach($row as $v) if((!is_int($v) && !is_float($v)) || !is_finite($v) || abs($v)>1e8) return false;
		}
		return true;
	}

	public function restore(mixed $states): void
	{
		if(!self::valid($states)) throw new \InvalidArgumentException('Invalid beach ball state.');
		$this->balls=$states; $this->wake();
	}

	/** Keep props in the shared landscape when the journey changes coordinate origins. */
	public function translated(array $shift,float $half): array
	{
		$rows=[];
		foreach($this->balls as $id=>$row) {
			foreach($shift as $i=>$delta) $row[$i]+=$delta;
			if(max(abs($row[0]),abs($row[2]))<=$half-self::RADIUS) $rows[$id]=$row;
		}
		return $rows;
	}

	public function spawn(array $origin,array $forward,float $distance,Landscape $land,float $water): int
	{
		if(count($this->balls)>=self::LIMIT) throw new \InvalidArgumentException('This landscape already has 16 beach balls.');
		$edge=$land->recipe()['worldSize']/2-self::RADIUS;
		$x=max(-$edge,min($edge,$origin[0]+$distance*$forward[0]));
		$z=max(-$edge,min($edge,$origin[2]+$distance*$forward[2]));
		$y=max($origin[1]+$distance*$forward[1],max($land->height($x,$z),$water)+self::RADIUS+1.);
		$id=self::FIRST;
		foreach($this->balls as $ball) {
			if(hypot($ball[0]-$x,$ball[2]-$z)<2*self::RADIUS && abs($ball[1]-$y)<2*self::RADIUS)
				$y=$ball[1]+2*self::RADIUS;
		}
		while(isset($this->balls[$id])) $id++;
		$this->balls[$id]=[$x,$y,$z,0.,0.,0.,0.,0.];
		return $id;
	}

	public function kick(int $id,array $direction,bool $tracer): void
	{
		$kick=$tracer ? 12. : 38.;
		foreach($direction as $i=>$component) {
			$impulse=$kick*$component;
			// A missile pops the prop upward even when it arrives from above.
			if(!$tracer && $i===1) $impulse=max(16.,22.+$impulse);
			$this->balls[$id][3+$i]=max(-90.,min(90.,$this->balls[$id][3+$i]+$impulse));
		}
		if($tracer) $this->balls[$id][4]+=3.;
		unset($this->sleeping[$id]);
	}

	/** Sweep the hull between physics poses so a fast pass can strike a resting ball. */
	private static function hull(array $pose,string $mode): array
	{
		$basis=VehicleModel::basis($pose);
		$parts=match($mode) {
			'jet'=>[[0,0,1.4],[0,4,1.1],[0,-4,1.2],[-2.8,-.7,1.2],[2.8,-.7,1.2]],
			'car'=>[[0,1.3,1.],[0,-1.3,1.],[0,0,1.]],
			default=>[[0,.85,.8],[0,-.85,.8]]
		};
		$centers=[];
		foreach($parts as [$side,$along,$radius])
			$centers[]=[ $pose['x']+$basis[0]*$side+$basis[6]*$along,
				$pose['y']+$basis[1]*$side+$basis[7]*$along,
				$pose['z']+$basis[2]*$side+$basis[8]*$along,$radius ];
		return $centers;
	}

	private static function push(array &$b,array $a,array $end,float $dt,bool $car): bool
	{
		$r=self::RADIUS+$end[3];
		$x=$b[0]-$a[0]; $y=$b[1]-$a[1]; $z=$b[2]-$a[2];
		$dx=$end[0]-$a[0]; $dy=$end[1]-$a[1]; $dz=$end[2]-$a[2];
		// In the ball's moving frame the hull sweeps this relative segment.
		$u=$dx-$b[3]*$dt; $v=$dy-$b[4]*$dt; $w=$dz-$b[5]*$dt;
		$c=$x*$x+$y*$y+$z*$z-$r*$r;
		if($c>0.) {
			$length=$u*$u+$v*$v+$w*$w; $dot=$x*$u+$y*$v+$z*$w;
			$disc=$dot*$dot-$length*$c;
			if($dot<=0. || $disc<0.) return false;
			$t=$c/($dot+sqrt($disc));
			if($t>1.) return false;
			$x-=$t*$u; $y-=$t*$v; $z-=$t*$w;
		}
		$n=sqrt($x*$x+$y*$y+$z*$z);
		if($n<.001) { $x=0.; $y=1.; $z=0.; $n=1.; }
		$x/=$n; $y/=$n; $z/=$n;
		$kick=max(0.,min(85.,1.35*($x*$u+$y*$v+$z*$w)/$dt));
		$depth=$r-(($b[0]-$end[0])*$x+($b[1]-$end[1])*$y+($b[2]-$end[2])*$z);
		if($kick<=0 && $depth<=.001) return false;
		foreach([$x,$y,$z] as $i=>$normal) {
			$b[$i]+=max(0.,$depth)*$normal;
		}
		$b[3]+=$kick*$x;
		$b[5]+=$kick*$z;
		$lift=$kick*$y+min(5.,.18*$kick);
		// The bumper meets the lower half of the ball. Give it a small hop
		// and keep repeated bumper contacts from building up launch speed.
		if($car) $lift=min(.16*$lift,max(0.,3.5-$b[4]));
		$b[4]+=$lift;
		return true;
	}

	public function step(float $dt,Landscape $land,float $water,string $mode,?array $before,?array $after): bool
	{
		if(!$this->balls || $dt<=0.) return false;
		$edge=$land->recipe()['worldSize']/2-self::RADIUS;
		$start=$before && $after ? self::hull($before,$mode) : [];
		$end=$start ? self::hull($after,$mode) : [];
		$changed=false;
		foreach($this->balls as $id=>&$b) {
			foreach($start as $i=>$a) if(self::push($b,$a,$end[$i],$dt,$mode==='car')) {
				unset($this->sleeping[$id]); break;
			}
			if(isset($this->sleeping[$id])) continue;
			$changed=true;
			$depth=max(0.,$water+self::RADIUS-$b[1]);
			$b[4]+=(-9.81+min(35.,$depth*9.81))*$dt;
			$drag=exp(-($depth>0. ? 1.8 : .15)*$dt);
			foreach([0,1,2] as $i) { $b[3+$i]*=$drag; $b[$i]+=$dt*$b[3+$i]; }
			foreach([0,2] as $i) if(abs($b[$i])>$edge) {
				$b[$i]=max(-$edge,min($edge,$b[$i])); $b[3+$i]*=-.6;
			}
			$floor=-INF;
			if($b[1]<=$land->height($b[0],$b[2])+self::RADIUS/.3) {
				$ground=$land->sample($b[0],$b[2]); [$nx,$ny,$nz]=$ground['normal'];
				$floor=$ground['height']+self::RADIUS/max(.3,$ny);
			}
			$rest=false;
			if($b[1]<=$floor) {
				$b[1]=$floor;
				$in=$b[3]*$nx+$b[4]*$ny+$b[5]*$nz;
				if($in<0.) {
					$bounce=$in < -1. ? .64 : 0.;
					foreach([$nx,$ny,$nz] as $i=>$normal) $b[3+$i]-=(1.+$bounce)*$in*$normal;
				}
				$friction=exp(-.85*$dt);
				foreach([3,4,5] as $i) $b[$i]*=$friction;
				$rest=$ny>.999;
			} elseif($depth>0. && abs($depth-1.)<.015) $rest=true;
			$b[6]=fmod($b[6]-$dt*$b[5]/self::RADIUS,2*M_PI);
			$b[7]=fmod($b[7]+$dt*$b[3]/self::RADIUS,2*M_PI);
			if($rest && max(abs($b[3]),abs($b[4]),abs($b[5]))<.035) {
				$b[3]=$b[4]=$b[5]=0.; $this->sleeping[$id]=true;
			}
		}
		return $changed;
	}
}

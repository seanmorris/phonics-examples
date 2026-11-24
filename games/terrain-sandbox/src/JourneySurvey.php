<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\JoinedTerrain;

/** Least-cost crossings on successively narrower terrain surveys. All distances and heights are meters. */
final class JourneySurvey
{
	private JoinedTerrain $ground;
	private float $sea;
	private int $span;
	private bool $split;
	private array $cache = [];

	public function __construct(array $recipe)
	{
		$this->ground = new JoinedTerrain($recipe);
		$this->sea = $recipe['waterLevel'];
		$this->span=$recipe['join']['span']; $this->split=count($recipe['join']['destinations'])===2;
	}

	private function height(float $x, float $z): float
	{
		$i = (int)round($x); $j = (int)round($z);
		return $this->cache[$i][$j] ??= $this->ground->height($i,$j);
	}

	/** Favor a dry shelf wide enough for a junction and its approach. */
	public function landing(float $x, float $center, int $reach): \Generator
	{
		$best = INF; $site = $center;
		for($offset=-$reach; $offset<=$reach; $offset+=64) {
			$z = $center+$offset;
			$h = $this->height($x,$z);
			$relief = 0.;
			foreach([[-120,0],[120,0],[0,-48],[0,48]] as [$dx,$dz])
				$relief += abs($this->height($x+$dx,$z+$dz)-$h);
			$cost = $relief + .22*max(0.,$h-$this->sea-8.) + 12.*max(0.,$this->sea+6.-$h) + .004*abs($offset);
			if($cost<$best) { $best=$cost; $site=$z; }
			yield;
		}
		return $site;
	}

	/** Pick both fork landings as one crossing; a flat spot can still be surrounded by cliffs. */
	public function portals(bool $split,?float $incoming): \Generator
	{
		$f=JourneyRoad::FORK; $best=INF; $chosen=[];
		$center=$incoming ?? 0.;
		$reach=$incoming!==null ? 128 : ($split ? 1536 : 6144);
		$step=$incoming!==null ? 128 : 384;
		for($z0=$center-$reach;$z0<=$center+$reach;$z0+=$step) {
			$total=0.; $ends=[];
			foreach($split ? [-1,1] : [0] as $side) {
				$minimum=INF; $mid=$split ? $side*3072. : $z0;
				for($z1=$mid-1152.;$z1<=$mid+1152.;$z1+=384.) {
					$score=0.;
					foreach($split ? [$side] : [-1,1] as $arm) {
						$old=$this->height($f-384.,$z0); $pz=$z0; $px=$f-384.;
						for($j=0;$j<=17;$j++) {
							$t=min(1.,$j/16.);
							$x=$f*(1.-2.*$t)+($j===17 ? 384. : 0.);
							$z=$z0+($z1-$z0)*$t*$t*(3.-2.*$t)+$arm*($split ? 160. : 485.)*sin(M_PI*$t)**2;
							$h=$this->height($x,$z); $run=hypot($x-$px,$z-$pz);
							$score+=self::climb($h-$old,$run)+.22*$run*max(0.,$this->sea+5.-$h)+$run;
							$old=$h; $px=$x; $pz=$z;
						}
					}
					if($score<$minimum) { $minimum=$score; $end=$z1; }
					yield;
				}
				$total+=$minimum; $ends[]=$end;
			}
			if($total<$best) { $best=$total; $chosen=[$z0,$ends]; }
		}
		return $chosen;
	}

	/** Local relief visible from the road. A shelf above a valley scores even if a higher peak exists. */
	private function outlook(float $x,float $z,float $h): float
	{
		if($h < $this->sea+6.) return 0.;
		$views=[];
		foreach([[1,0],[.707,.707],[0,1],[-.707,.707],[-1,0],[-.707,-.707],[0,-1],[.707,-.707]] as [$dx,$dz]) {
			$near = $this->height($x+384.*$dx,$z+384.*$dz);
			$far = $this->height($x+1152.*$dx,$z+1152.*$dz);
			// An intervening ridge hides the drop beyond it.
			$views[] = max(0.,min($h-$far,3.*($h+2.-$near)));
		}
		rsort($views,SORT_NUMERIC);
		return .5*$views[0]+.3*$views[1]+.2*$views[2];
	}

	/** Select a view from the finished deck, excluding road cuts that block the view. */
	public function roadsideView(array $points,array $origin): \Generator
	{
		$best=-INF; $site=null;
		foreach($points as $i=>[$x,$y,$z]) {
			if($i%3 || max(abs($x-$origin[0]),abs($z-$origin[1]))>$this->span*.5-500.) continue;
			$natural=$this->height($x,$z);
			$relief=$this->outlook($x,$z,$y+2.);
			$score=$relief-4.*abs($y-$natural)+.012*($natural-$this->sea);
			if($score>$best) { $best=$score; $site=[$x,$z,$relief]; }
			yield;
		}
		return $site;
	}

	private static function climb(float $rise,float $run): float
	{
		$rise=abs($rise);
		$excess=max(0.,$rise-.07*$run);
		return 3.*$rise + 30.*$excess + 3200.*$excess*$excess/$run;
	}

	/** Flood in eight directions so a valley can double back around the end of a wall. */
	private function crossing(array $guide,bool $freeStart): \Generator
	{
		[$from,$z0]=$guide[0]; [$to,$z1]=$guide[array_key_last($guide)];
		// A low-relief square needs fewer probes; keep the broad search for cliffs and ridges.
		$relief=0.;
		for($i=1;$i<17;$i++) {
			$t=$i/17.; $x=$from+($to-$from)*$t; $z=$z0+($z1-$z0)*$t;
			$h=$this->height($x,$z);
			$relief=max($relief,abs($this->height($x+160.,$z)-$h),abs($this->height($x,$z+160.)-$h));
		}
		$gentle=$relief<24.;
		$spacing=$gentle ? 352. : 224.;
		$n=(int)ceil(($to-$from)/$spacing); $dx=($to-$from)/$n;
		$radius=$gentle ? 18 : 60; $width=2*$radius+1; $edges=$nodes=[];
		$starts=[];
		for($i=0;$i<=$n;$i++) {
			$x=$i===$n ? $to : $from+$i*$dx;
			$c=$z0+($z1-$z0)*$i/$n;
			for($k=0;$k<$width;$k++) {
				$z=$c+($k-$radius)*$spacing; $id=$i*$width+$k;
				if($this->split && $from>0 && ($z1<0 ? $z> -384. : $z<384.)) continue;
				$h=$this->height($x,$z);
				$nodes[$id]=[$x,$z,$h];
				if($i===0 && ($freeStart || $k===$radius)) $starts[$id]=.1*abs($z-$z0);
				foreach([[-1,0],[0,-1],[-1,-1],[-1,1]] as [$di,$dk]) {
					if($i+$di<0 || $k+$dk<0 || $k+$dk>=$width) continue;
					// The first and last stations must cross the map edge, rather than running along it.
					if(($i===0 || $i===$n) && $di===0) continue;
					if(($i===1 || $i===$n) && $di!==0 && $dk!==0) continue;
					$j=$id+$di*$width+$dk; if(!isset($nodes[$j])) continue;
					[$a,$b,$old]=$nodes[$j];
					$run=hypot($x-$a,$z-$b);
					$mid=$this->height(($x+$a)*.5,($z+$b)*.5);
					$cost=$run*(1.+.001*max(0.,min($h,$mid,$old)-$this->sea))+self::climb($h-$mid,$run*.5)+self::climb($mid-$old,$run*.5)
						+.20*$run*max(0.,$this->sea+5.-min($h,$old,$mid));
					$edges[$id][$j]=$edges[$j][$id]=$cost;
				}
				if(($k&3)===0) yield;
			}
		}
		$end=$n*$width+$radius;
		[$forward,$back]=yield from self::flood($edges,$starts);
		[$reverse,$onward]=yield from self::flood($edges,[$end=>0.]);
		$best=INF; $view=null;
		$originZ=(($from<0 && $to<0) || !$this->split) ? 0. : ($z1<0 ? -1 : 1)*($this->span+JoinedTerrain::GAP)*.5;
		foreach($nodes as $id=>[$x,$z,$h]) {
			if(!isset($forward[$id],$reverse[$id])) continue;
			$t=($x-$from)/($to-$from);
			if(abs($z-$originZ)>$this->span*.5-1200.) continue;
			if($t<.32 || $t>.72 || $forward[$id]+$reverse[$id]>$forward[$end]*1.14+1200.) continue;
			$bank=abs($this->height($x,$z+25.)-$this->height($x,$z-25.))/50.;
			$score=$forward[$id]+$reverse[$id]-min(9000.,30.*$this->outlook($x,$z,$h))+500.*$bank+150.*max(0.,$this->sea+5.-$h);
			if($score<$best) {
				// An overlook on a dead-end spur would force the highway to reverse along itself.
				$seen=[];
				for($j=$id;isset($back[$j]);) { $j=$back[$j]; $seen[$j]=true; }
				for($j=$id;isset($onward[$j]);) { $j=$onward[$j]; if(isset($seen[$j])) continue 2; }
				$best=$score; $view=$id;
			}
			yield;
		}
		$view ??= $end;
		$west=$east=[];
		for($at=$view;;$at=$back[$at]) {
			$west[]=array_slice($nodes[$at],0,2);
			if(!isset($back[$at])) break;
		}
		for($at=$view;;$at=$onward[$at]) {
			$east[]=array_slice($nodes[$at],0,2);
			if(!isset($onward[$at])) break;
		}
		return [array_reverse($west),$east];
	}

	private static function flood(array $edges,array $cost): \Generator
	{
		$q=new \SplPriorityQueue; $parent=[];
		$q->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		foreach($cost as $id=>$c) $q->insert($id,-$c);
		$i=0;
		while(!$q->isEmpty()) {
			['data'=>$id,'priority'=>$p]=$q->extract();
			if(-$p>$cost[$id]) continue;
			foreach($edges[$id]??[] as $j=>$edge) {
				$c=$edge-$p;
				if($c>=($cost[$j]??INF)) continue;
				$parent[$j]=$id; $cost[$j]=$c; $q->insert($j,-$c);
			}
			if((++$i&31)===0) yield;
		}
		return [$cost,$parent];
	}

	/** Refine transversely to the corridor, including north/south runs and westbound bends. */
	private function refine(array $guide): \Generator
	{
		if(count($guide)<2) return $guide;
		// Fillet the coarse grid corners before offsetting the cross sections. Otherwise a
		// section on the inside of a bend can cross its neighbor and fold the spline back.
		$rounded=[$guide[0]];
		for($i=1;$i<count($guide)-1;$i++) {
			[$x,$z]=$guide[$i];
			foreach([$guide[$i-1],$guide[$i+1]] as [$a,$b]) $rounded[]=[$x+.38*($a-$x),$z+.38*($b-$z)];
		}
		$rounded[]=$guide[array_key_last($guide)];
		$guide=$rounded;
		$stations=[];
		foreach($guide as $i=>[$x,$z]) {
			if(!$i) { $stations[]=[$x,$z]; continue; }
			[$a,$b]=$guide[$i-1]; $n=(int)ceil(hypot($x-$a,$z-$b)/90.);
			for($j=1;$j<=$n;$j++) $stations[]=[$a+($x-$a)*$j/$n,$b+($z-$b)*$j/$n];
		}
		$cost=$prev=$parents=$sites=[]; $last=count($stations)-1;
		$room=array_fill(0,$last+1,2);
		for($i=1;$i<$last;$i++) {
			[$a,$b]=$stations[$i-1]; [$x,$z]=$stations[$i]; [$c,$d]=$stations[$i+1];
			$u=hypot($x-$a,$z-$b); $v=hypot($c-$x,$d-$z);
			$angle=acos(max(-1.,min(1.,(($x-$a)*($c-$x)+($z-$b)*($d-$z))/max(.01,$u*$v))));
			// The inside offset of a tight curve must stay well below its radius.
			$lanes=min(2,(int)(.22*min($u,$v)/max(.001,$angle)/32.));
			for($j=max(0,$i-2);$j<=min($last,$i+2);$j++) $room[$j]=min($room[$j],$lanes);
		}
		foreach($stations as $i=>[$x,$z]) {
			[$a,$b]=$stations[max(0,$i-1)]; [$c,$d]=$stations[min($last,$i+1)];
			$length=max(.01,hypot($c-$a,$d-$b)); $nx=($b-$d)/$length; $nz=($c-$a)/$length;
			$radius=$i===0 || $i===$last ? 0 : $room[$i]; $row=$next=[];
			for($k=-$radius;$k<=$radius;$k++) {
				$px=$x+32.*$k*$nx; $pz=$z+32.*$k*$nz; $h=$this->height($px,$pz);
				$row[$k]=[$px,$pz,$h];
				if($i===0) { $next[0]=0.; continue; }
				$side=abs($this->height($px+18.*$nx,$pz+18.*$nz)-$this->height($px-18.*$nx,$pz-18.*$nz));
				foreach($prev as $j=>[$ox,$oz,$old]) {
					if(!isset($cost[$j])) continue;
					$travel=($px-$ox)*$nz-($pz-$oz)*$nx;
					if($travel<.25*hypot($x-$a,$z-$b)) continue;
					$run=max(1.,hypot($px-$ox,$pz-$oz));
					$m=$this->height(($px+$ox)*.5,($pz+$oz)*.5);
					$v=$cost[$j]+$run+3.*$side+12.*abs($k-$j)+4.*abs($k)
						+self::climb($h-$m,.5*$run)+self::climb($m-$old,.5*$run)+.2*$run*max(0.,$this->sea+5.-min($h,$m));
					if($v<($next[$k]??INF)) { $next[$k]=$v; $parents[$i][$k]=$j; }
				}
			}
			$sites[]=$row; $prev=$row; $cost=$next; yield;
		}
		$path=[]; $k=0;
		for($i=$last;$i>=0;$i--) {
			$path[]=array_slice($sites[$i][$k],0,2);
			if($i) $k=$parents[$i][$k];
		}
		$path=array_reverse($path);
		for($pass=0;$pass<2;$pass++) {
			$rounded=$path;
			for($i=1;$i<count($path)-1;$i++) foreach([0,1] as $axis)
				$rounded[$i][$axis]=.25*($path[$i-1][$axis]+2.*$path[$i][$axis]+$path[$i+1][$axis]);
			$path=$rounded;
		}
		return $path;
	}

	/** Score broad, tangent-matched bends inside the fork. Forcing short switchbacks here
	 * would put the two paved branches back on top of each other. */
	public function branch(array $guide,int $side): \Generator
	{
		$best=INF; $chosen=[]; $last=count($guide)-1;
		foreach([-144.,-72.,0.,72.,144.] as $bulge) foreach([-96.,0.,96.] as $bend) {
			$cost=0.; $route=[]; $previous=null;
			foreach($guide as $i=>[$x,$z]) {
				$t=$i/$last;
				if($i && $i<$last) $z += $side*sin(M_PI*$t)**2*($bulge+$bend*sin(2.*M_PI*$t));
				$h=$this->height($x,$z);
				if($previous!==null) {
					[$px,$pz,$old]=$previous; $run=hypot($x-$px,$z-$pz);
					$m=$this->height(($x+$px)*.5,($z+$pz)*.5);
					$cost+=$run+self::climb($m-$old,.5*$run)+self::climb($h-$m,.5*$run)
						+.2*$run*max(0.,$this->sea+5.-min($m,$h));
				}
				$previous=[$x,$z,$h]; $route[]=[$x,$z];
			}
			if($cost<$best) { $best=$cost; $chosen=$route; }
			yield;
		}
		return $chosen;
	}

	/** Refine each side of the chosen overlook independently so smoothing retains the visit. */
	public function route(array $guide,bool $freeStart=false,bool $scenic=false): \Generator
	{
		if(!$scenic) return yield from $this->refine($guide);
		[$west,$east]=yield from $this->crossing($guide,$freeStart);
		$path=yield from $this->refine($west);
		$tail=yield from $this->refine($east);
		array_shift($tail);
		$this->cache=[];
		return [...$path,...$tail];
	}
}

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

	/** Survey the straight run outside a junction too. A flat landing on a cliff lip makes a deep cut. */
	private function approachCost(float $x,float $z,int $direction): float
	{
		$last=$this->height($x,$z); $cost=0.;
		for($i=1;$i<=12;$i++) {
			$at=$x+$direction*48.*$i;
			$h=$this->height($at,$z);
			$bank=abs($this->height($at,$z+16.)-$this->height($at,$z-16.));
			$cost+=self::climb($h-$last,48.)+4.*$bank+10.*max(0.,$this->sea+6.-$h);
			$last=$h;
		}
		return $cost;
	}

	/** Estimate earthworks across an entire fork, including the shared elevation change. */
	private function crossingCost(float $start,float $end,array $arms,int $steps): float
	{
		$f=JourneyRoad::FORK; $score=0.;
		$run=hypot(2.*$f,$end-$start);
		$rise=$this->height(-$f+384.,$end)-$this->height($f-384.,$start);
		$score+=12.*self::climb($rise,$run+768.);
		foreach($arms as $arm) {
			$px=$f; $pz=$start; $old=$this->height($px,$pz);
			for($j=1;$j<=$steps;$j++) {
				$t=$j/$steps; $x=$f*(1.-2.*$t);
				$z=$start+($end-$start)*$t*$t*(3.-2.*$t)+$arm*sin(M_PI*$t)**2;
				$h=$this->height($x,$z); $run=hypot($x-$px,$z-$pz);
				$score+=self::climb($h-$old,$run)+.22*$run*max(0.,$this->sea+5.-$h)+$run;
				$px=$x; $pz=$z; $old=$h;
			}
		}
		return $score;
	}

	/** Pick both fork landings together. Screen a wide area at low resolution before
	 * surveying the best crossings and their straight approaches at road scale. */
	public function portals(bool $split,?float $incoming): \Generator
	{
		$f=JourneyRoad::FORK; $center=$incoming ?? 0.;
		$reach=$incoming!==null ? 128 : ($split ? 1536 : 6144);
		$step=$incoming!==null || !$split ? 128 : 384;
		$exits=[]; $best=INF; $chosen=[];
		for($start=$center-$reach;$start<=$center+$reach;$start+=$step) {
			$total=$this->approachCost($f,$start,-1); $ends=[];
			foreach($split ? [-1,1] : [0] as $side) {
				$mid=$split ? $side*3072. : $start;
				$extent=$split ? 2048. : 3072.;
				$arms=$split ? [$side*160.] : [-485.,485.];
				$candidates=[];
				for($end=$mid-$extent;$end<=$mid+$extent;$end+=256.) {
					$key=(int)round($end);
					$exit=$exits[$key] ??= $this->approachCost(-$f,$end,1);
					$candidates[]=[$exit+$this->crossingCost($start,$end,$arms,4),$end,$exit];
					yield;
				}
				sort($candidates);
				$minimum=INF;
				foreach(array_slice($candidates,0,4) as [, $end,$exit]) {
					$score=$exit+$this->crossingCost($start,$end,$arms,20);
					if($score<$minimum) { $minimum=$score; $landing=$end; }
					yield;
				}
				$total+=$minimum; $ends[]=$landing;
			}
			if($total<$best) { $best=$total; $chosen=[$start,$ends]; }
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
	private function crossing(array $guide,bool $freeStart,?float $divider): \Generator
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
				if($divider!==null) {
					$t=max(0.,min(1.,($x-$from)/(JoinedTerrain::GAP*.5-$from)));
					$middle=$divider*(1.-$t*$t*(3.-2.*$t));
					if(($z1<0. ? -1. : 1.)*($z-$middle)<100.+284.*$t) continue;
				}
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
		$originZ=($divider===null) ? 0. : ($z1<0 ? -1 : 1)*($this->span+JoinedTerrain::GAP)*.5;
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
					if(($j!==0 || $k!==0) && $travel<.25*hypot($x-$a,$z-$b)) continue;
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

	/** Find a low-grade route down each side of the fork. Stations share X so the two
	 * pavements can meet tangentially while the survey moves each branch along the contours. */
	public function branch(array $guide,int $side,array $divider): \Generator
	{
		$last=count($guide)-1;
		$previous=$parents=$sites=[];
		foreach($guide as $i=>[$x,$center]) {
			// Keep the junction throats aligned, then release the road into a broad search corridor.
			$radius=min(14,2*min($i,$last-$i));
			$clearance=min(96.,abs($center-$divider[$i])*.60);
			$row=$cost=[];
			for($lane=-$radius;$lane<=$radius;$lane++) {
				$z=$center+48.*$lane;
				if($side*($z-$divider[$i]) < $clearance-1.e-6) continue;
				$h=$this->height($x,$z);
				$row[$lane]=[$z,$h];
				if(!$i) { $cost[$lane]=0.; continue; }
				$px=$guide[$i-1][0];
				$dx=$x-$px;
				$bank=abs($this->height($x,$z+16.)-$this->height($x,$z-16.));
				for($k=max(-14,$lane-2);$k<=min(14,$lane+2);$k++) {
					if(!isset($previous[$k])) continue;
					[$pz,$old]=$sites[$i-1][$k];
					$run=hypot($dx,$z-$pz);
					$mid=$this->height(.5*($x+$px),.5*($z+$pz));
					$score=$previous[$k]+$run+4.*$bank+4.*abs($lane)+16.*abs($lane-$k)
						+self::climb($mid-$old,.5*$run)+self::climb($h-$mid,.5*$run)
						+.2*$run*max(0.,$this->sea+6.-min($h,$mid,$old));
					if($score<($cost[$lane] ?? INF)) { $cost[$lane]=$score; $parents[$i][$lane]=$k; }
				}
				if(($lane&3)===0) yield;
			}
			$sites[$i]=$row; $previous=$cost;
		}
		$path=[]; $lane=0;
		for($i=$last;$i>=0;$i--) {
			$path[]=[$guide[$i][0],$sites[$i][$lane][0]];
			if($i) $lane=$parents[$i][$lane];
		}
		$path=array_reverse($path);
		$rounded=$path;
		for($i=1;$i<$last;$i++)
			$rounded[$i][1]=.2*$path[$i-1][1]+.6*$path[$i][1]+.2*$path[$i+1][1];
		return $rounded;
	}

	/** Refine each side of the chosen overlook independently so smoothing retains the visit. */
	public function route(array $guide,bool $freeStart=false,bool $scenic=false,?float $divider=null): \Generator
	{
		if(!$scenic) return yield from $this->refine($guide);
		[$west,$east]=yield from $this->crossing($guide,$freeStart,$divider);
		$path=yield from $this->refine($west);
		$tail=yield from $this->refine($east);
		array_shift($tail);
		$this->cache=[];
		return [...$path,...$tail];
	}
}

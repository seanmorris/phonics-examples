<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\JoinedTerrain;

/** Least-cost crossings on successively narrower terrain surveys. All distances and heights are meters. */
final class JourneySurvey
{
	private const TARGET_GRADE = .09;
	private const DETOUR_ERROR = 14.; // Meters of estimated cut or fill.
	private const VERGE = JourneyRoad::WIDTH/2;

	private JoinedTerrain $ground;
	private float $sea;
	private int $span;
	private array $cache = [];

	public function __construct(array $recipe)
	{
		$this->ground = new JoinedTerrain($recipe);
		$this->sea = $recipe['waterLevel'];
		$this->span=$recipe['join']['span'];
	}

	private function height(float $x, float $z): float
	{
		$i = (int)round($x); $j = (int)round($z);
		return $this->cache[$i][$j] ??= $this->ground->height($i,$j);
	}

	/** Search across the slope as well as along the highway. Multiple lanes per forward step
	 * allow long traverses with a gradual climb. Sample the grade halfway along each edge
	 * and the ground under both verges before choosing a lane. */
	private function contourCrossing(array $start,array $end,int $radius,
		float $minX,float $maxX,?float $divider,int $side,?array $access=null): \Generator
	{
		$freeEnds=$access!==null;
		[$x0,$z0]=$start;
		$dx=$end[0]-$x0; $dz=$end[1]-$z0;
		$length=hypot($dx,$dz); $ux=$dx/$length; $uz=$dz/$length;
		$rows=max(4,(int)ceil($length/96.)); $step=$length/$rows;
		$lateral=80.; $half=$this->span+JoinedTerrain::GAP*.5;
		$sites=$cost=$parents=[];
		for($i=0;$i<=$rows;$i++) {
			$row=$next=[];
			$cx=$x0+$dx*$i/$rows; $cz=$z0+$dz*$i/$rows;
			$width=$freeEnds ? $radius : min($radius,4*min($i,$rows-$i));
			for($k=-$width;$k<=$width;$k++) {
				if(($k&3)===0) yield;
				$x=$cx-$uz*$k*$lateral; $z=$cz+$ux*$k*$lateral;
				if($x<$minX-.001 || $x>$maxX+.001 || abs($z)>$half) continue;
				if($divider!==null && $side*($z-self::branchLimit($x,$minX,$divider,$side,$this->span))<0.) continue;
				$h=$this->height($x,$z);
				$row[$k]=[$x,$z,$h];
				if(!$i) {
					$next[$k]=$freeEnds ? $this->approachCost($x,$z,-1)+.15*abs($k)*$lateral+self::accessCost($access,$z) : 0.;
					continue;
				}
				$bank=max(abs($this->height($x-self::VERGE*$uz,$z+self::VERGE*$ux)-$h),
					abs($this->height($x+self::VERGE*$uz,$z-self::VERGE*$ux)-$h));
				$reach=$freeEnds && ($i===1 || $i===$rows) ? 0 : 4;
				for($j=$k-$reach;$j<=$k+$reach;$j++) {
					if(!isset($cost[$j])) continue;
					[$a,$b,$old]=$sites[$i-1][$j];
					$run=hypot($step,($k-$j)*$lateral);
					$m=$this->height(.5*($x+$a),.5*($z+$b));
					$bend=$i<2 ? 0. : atan2(($k-$j)*$lateral,$step)
						-atan2(($j-$parents[$i-1][$j])*$lateral,$step);
					$score=$cost[$j]+$run*(1.+.002*max(0.,$h-$this->sea)+.035*max(0.,$bank-2.)**2
						+.25*max(0.,$this->sea+5.-min($h,$old,$m)))+240.*$bend*$bend
						+self::climb($h-$m,.5*$run)+self::climb($m-$old,.5*$run);
					if($score<($next[$k]??INF)) { $next[$k]=$score; $parents[$i][$k]=$j; }
				}
			}
			$sites[]=$row; $cost=$next;
		}
		if(!$cost) return [];
		if($freeEnds) foreach($cost as $k=>&$score) {
			[$x,$z]=$sites[$rows][$k];
			$score+=.15*abs($k)*$lateral+$this->approachCost($x,$z,1);
			yield;
		}
		unset($score);
		$k=array_search(min($cost),$cost,true); $path=[];
		for($i=$rows;$i>=0;$i--) {
			$path[]=array_slice($sites[$i][$k],0,2);
			if($i) $k=$parents[$i][$k];
		}
		$path=array_reverse($path);
		if(!$freeEnds) { $path[0]=$start; $path[$rows]=$end; }
		// Round lane changes without smoothing the common eastward tangent of the last mile.
		$rounded=$path;
		for($i=$freeEnds ? 2 : 1;$i<=$rows-($freeEnds ? 2 : 1);$i++) foreach([0,1] as $axis)
			$rounded[$i][$axis]=.2*$path[$i-1][$axis]+.6*$path[$i][$axis]+.2*$path[$i+1][$axis];
		// Keep the vertical grader's station spacing comparable on long diagonal traverses.
		$stations=[$rounded[0]];
		for($i=1;$i<=$rows;$i++) {
			[$a,$b]=$rounded[$i-1]; [$x,$z]=$rounded[$i];
			$n=(int)ceil(hypot($x-$a,$z-$b)/64.);
			for($j=1;$j<$n;$j++) $stations[]=[$a+($x-$a)*$j/$n,$b+($z-$b)*$j/$n];
			$stations[]=$rounded[$i];
		}
		return $stations;
	}

	/** Cost of reaching each exit lane from the far edge of a square. A flat mesa top
	 * scores well locally even when every road to it must climb a cliff. Share this
	 * coarse survey between all candidate mouths, then survey the chosen road finely. */
	private function accessProfiles(float $from,float $to,float $center,array $stations,?float $entry=null,?float $endCenter=null): \Generator
	{
		$radius=min(72,(int)ceil($this->span/600.));
		$dz=$this->span/(2*$radius);
		// Hit each requested cross section exactly, including when surveying east to west.
		$stops=array_unique([...$stations,$to],SORT_REGULAR);
		if($to<$from) rsort($stops,SORT_NUMERIC); else sort($stops,SORT_NUMERIC);
		$xs=[$from]; $a=$from;
		foreach($stops as $stop) {
			$n=(int)ceil(abs($stop-$a)/$dz);
			for($j=1;$j<=$n;$j++) $xs[]=$j===$n ? $stop : $a+($stop-$a)*$j/$n;
			$a=$stop;
		}
		$previous=$cost=$profiles=[];
		$drift=$to===$from ? 0. : (($endCenter??$center)-$center)/($to-$from);
		$entryLane=$entry===null ? null : (int)round(($entry-$center)/$dz);
		$entryHeight=$entry===null ? 0. : $this->height($from,$entry);
		foreach($xs as $i=>$at) {
			$dx=$i ? $at-$xs[$i-1] : 0.;
			$x=round($at); $midX=round($at-.5*$dx); $shift=$drift*$dx;
			$rowCenter=$center+$drift*($at-$from);
			$heights=$next=$across=$midpoints=[];
			for($k=-$radius;$k<=$radius;$k++) {
				$z=$rowCenter+$k*$dz; $h=$heights[$k]=$this->ground->height($x,round($z));
				if(!$i) {
					$next[$k]=0.;
					if($entry!==null) $next[$k]=$k===$entryLane ? self::climb($h-$entryHeight,max(1.,abs($z-$entry))) : INF;
				} else {
					$next[$k]=INF;
					for($j=max(-$radius,$k-1);$j<=min($radius,$k+1);$j++) {
						$run=hypot($dx,$shift+($k-$j)*$dz);
						$m=$midpoints[$k+$j] ??= $this->ground->height($midX,round($rowCenter-.5*$shift+.5*($j+$k)*$dz));
						$next[$k]=min($next[$k],$cost[$j]+$this->accessEdge($previous[$j],$m,$h,$run,$endCenter!==null));
					}
				}
				if($k>-$radius) $across[$k]=$this->accessEdge($heights[$k-1],$this->ground->height($x,round($z-.5*$dz)),$h,$dz,$endCenter!==null);
				if(($k&3)===0) yield;
			}
			for($k=1-$radius;$k<=$radius;$k++) $next[$k]=min($next[$k],$next[$k-1]+$across[$k]);
			for($k=$radius-1;$k>=-$radius;$k--) $next[$k]=min($next[$k],$next[$k+1]+$across[$k+1]);
			$cost=$next; $previous=$heights;
			$id=array_search($at,$stations,true);
			if($id!==false) $profiles[$id]=[$rowCenter,$dz,$cost];
		}
		return $profiles;
	}

	private function accessEdge(float $a,float $mid,float $b,float $run,bool $divided): float
	{
		$depth=max(0.,$this->sea+6.-min($a,$mid,$b));
		return $run*(1.+.004*max(0.,$mid-$this->sea)+.25*$depth+($divided ? .012*$depth*$depth : 0.))
			+self::climb($mid-$a,.5*$run)+self::climb($b-$mid,.5*$run);
	}

	private static function accessCost(array $survey,float $z): float
	{
		[$center,$spacing,$cost]=$survey;
		$t=max(array_key_first($cost),min(array_key_last($cost),($z-$center)/$spacing));
		$k=(int)floor($t);
		return $cost[$k]+($t-$k)*(($cost[$k+1]??$cost[$k])-$cost[$k]);
	}

	/** The whole arrival road belongs to the destination. Choose its two landings together
	 * so different parents meet the same dry, low-grade route across the map edge. */
	public function arrivalRoad(float $from,float $to,float $center): \Generator
	{
		// Screen the full edge first: the valley mouth can be miles from the map center.
		[$access]=yield from $this->accessProfiles(JoinedTerrain::GAP*.5,$from,$center,[$from]);
		$best=INF; $landing=$center;
		$reach=$this->span/2-1024.;
		for($offset=-$reach;$offset<=$reach;$offset+=128.) {
			$z=$center+$offset; $cost=.20*abs($offset)+self::accessCost($access,$z);
			$old=$this->height($from-512.,$z);
			for($x=$from-384.;$x<=$to;$x+=128.) {
				$h=$this->height($x,$z);
				$cost+=self::climb($h-$old,128.)+.65*max(0.,$h-$this->sea)+32.*max(0.,$this->sea+6.-$h);
				$old=$h;
				if($cost>=$best) break;
			}
			if($cost<$best) { $best=$cost; $landing=$z; }
			yield;
		}
		return yield from $this->contourCrossing([$from,$landing],[$to,$landing],12,$from,$to,null,0,$access);
	}

	/** A shelf overlooking much lower dry ground needs a long approach even if the
	 * junction itself is level. Estimate the rise its surroundings cannot absorb. */
	private function shelfCost(float $x,float $z,int $direction): float
	{
		$h=$this->height($x,$z); $excess=0.;
		foreach([512.,1280.,2560.] as $run) foreach([-1.,0.,1.] as $side) {
			$ground=max($this->sea+6.,$this->height($x+$direction*$run,$z+$side*$run));
			$excess=max($excess,$h-$ground-self::TARGET_GRADE*hypot($run,$side*$run));
		}
		return 12.*$excess*$excess;
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

	/** Find a common landing from the approach and every destination's terrain access costs. */
	public function junction(?array $entry,array $arrivals): \Generator
	{
		$f=JourneyRoad::FORK; $incoming=$entry[2]??null; $center=$incoming??0.;
		$reach=$incoming!==null ? 128. : min($this->span/2-512.,1.5*JoinedTerrain::GAP);
		$half=$this->span+JoinedTerrain::GAP*.5;
		// Evaluate the approach once for each candidate landing. A fork can start
		// earlier if the two destination passes lie far apart.
		$earliest=$entry ? max($f-2560.,$entry[0]+320.) : $f-2560.;
		$xs=[$earliest,($earliest+$f)*.5,$f];
		$approach=yield from $this->accessProfiles($entry[0]??-$half,$f,$center,$xs,$incoming,$center);
		$exits=[];
		foreach($arrivals as [$cz,$route]) {
			[$tailX,$tailZ]=$route[0];
			$exits[]=yield from $this->accessProfiles($tailX,$earliest,$cz,$xs,$tailZ,$center);
		}
		$best=INF; $junction=[];
		foreach($xs as $i=>$x) for($z=$center-$reach;$z<=$center+$reach;$z+=128.) {
			$cost=$this->approachCost($x,$z,-1)+$this->shelfCost($x,$z,-1);
			$cost+=self::accessCost($approach[$i],$z);
			foreach($exits as $arm) $cost+=self::accessCost($arm[$i],$z);
			if($cost<$best) { $best=$cost; $junction=[$x,$z]; }
			yield;
		}
		return $junction;
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

	/** Move an off-center fork toward the divide gradually. Both branch searches
	 * must agree on this boundary even when the incoming road is miles off-center. */
	public static function branchLimit(float $x,float $from,float $divider,int $side,int $span): float
	{
		$transition=min(.5*$span,max(JoinedTerrain::GAP*.5-$from,2.*abs($divider)));
		$t=max(0.,min(1.,($x-$from)/$transition));
		return $divider*(1.-$t*$t*(3.-2.*$t))+$side*(JourneyRoad::SPLIT_OFFSET+(384.-JourneyRoad::SPLIT_OFFSET)*$t);
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
			$side=$z1<0 ? -1 : 1;
			if($divider!==null) {
				$limit=self::branchLimit($x,$from,$divider,$side,$this->span);
				if($side*($c-$limit)<0.) $c=$limit;
			}
			for($k=0;$k<$width;$k++) {
				$z=$c+($k-$radius)*$spacing; $id=$i*$width+$k;
				if(abs($z)>$this->span+JoinedTerrain::GAP*.5-32.) continue;
				if($divider!==null && $side*($z-$limit)<0.) continue;
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
					$depth=max(0.,$this->sea+5.-min($h,$old,$mid));
					$cost=$run*(1.+.001*max(0.,min($h,$mid,$old)-$this->sea))+self::climb($h-$mid,$run*.5)+self::climb($mid-$old,$run*.5)
						+$run*(.20*$depth+($divider!==null ? .012*$depth*$depth : 0.));
					$edges[$id][$j]=$edges[$j][$id]=$cost;
				}
				if(($k&3)===0) yield;
			}
		}
		$end=$n*$width+$radius;
		[$forward,$back]=yield from self::flood($edges,$starts);
		if(!isset($forward[$end])) throw new \RuntimeException('No connected highway corridor reaches the selected landing.');
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

	/** Bound a driveable elevation from both ends of the proposed road. Measure the
	 * resulting cut or fill over the pavement width, including between survey stations. */
	private function earthworks(array $path): \Generator
	{
		$rows=$low=$high=[]; $risk=[]; $score=$peak=0.;
		for($i=1;$i<count($path);$i++) {
			[$a,$b]=$path[$i-1]; [$x,$z]=$path[$i];
			$dx=$x-$a; $dz=$z-$b; $run=hypot($dx,$dz);
			$n=max(1,(int)ceil($run/36.)); $ds=$run/$n;
			$nx=-self::VERGE*$dz/max(.01,$run); $nz=self::VERGE*$dx/max(.01,$run);
			for($j=$i===1 ? 0 : 1;$j<=$n;$j++) {
				$px=$a+$dx*$j/$n; $pz=$b+$dz*$j/$n;
				$h=$this->height($px,$pz);
				$left=$this->height($px+$nx,$pz+$nz); $right=$this->height($px-$nx,$pz-$nz);
				$deck=max($this->sea+6.,$h); $k=count($rows); $cap=self::TARGET_GRADE*$ds;
				$low[]=$k ? min($deck,$low[$k-1]+$cap) : $deck;
				$high[]=$k ? max($deck,$high[$k-1]-$cap) : $deck;
				$rows[]=[$i,$ds,$h,min($h,$left,$right),max($h,$left,$right)];
			}
			yield;
		}
		for($k=count($rows)-1;$k>=0;$k--) {
			[$i,$ds,$h,$a,$b]=$rows[$k];
			if(isset($rows[$k+1])) {
				$cap=self::TARGET_GRADE*$rows[$k+1][1];
				$low[$k]=min($low[$k],$low[$k+1]+$cap);
				$high[$k]=max($high[$k],$high[$k+1]-$cap);
			}
			$deck=.5*($low[$k]+$high[$k]);
			$error=max($deck-$a,$b-$deck);
			// Open water deliberately uses a raised causeway. Charge it to candidates,
			// but only trigger the hillside search on exposed ground.
			if($h >= $this->sea) $risk[$i]=max($risk[$i]??0.,$error);
			$peak=max($peak,$error);
			if($k) $score+=$ds*(1.+.035*max(0.,$error-3.)**2);
			if(($k&63)===0) yield;
		}
		return [$risk,$score,$peak];
	}

	/** A possible overlap with a retained stretch (including the other side of the
	 * overlook) keeps the original road. Include the full pavement width. */
	private static function clearOfRoad(array $path,int $a,int $b,array $proposed): \Generator
	{
		for($j=1;$j<count($proposed);$j++) {
			[$x,$z]=$proposed[$j-1]; [$u,$v]=$proposed[$j];
			$left=min($x,$u)-JourneyRoad::WIDTH; $right=max($x,$u)+JourneyRoad::WIDTH;
			$bottom=min($z,$v)-JourneyRoad::WIDTH; $top=max($z,$v)+JourneyRoad::WIDTH;
			for($i=1;$i<count($path);$i++) {
				if(($i&255)===0) yield;
				if($i>=$a && $i<=$b+1) continue;
				[$x1,$z1]=$path[$i-1]; [$x2,$z2]=$path[$i];
				if(max($x1,$x2)>$left && min($x1,$x2)<$right
					&& max($z1,$z2)>$bottom && min($z1,$z2)<$top) return false;
			}
			yield;
		}
		return true;
	}

	/** Give bad dry stretches room to traverse a hillside in either direction. Leave
	 * good pavement, the overlook and fork landings in place. */
	private function detours(array $path,float $minX,float $maxX,?float $divider,int $side,?int $overlook): \Generator
	{
		[$risk]=yield from $this->earthworks($path);
		$last=count($path)-1; $windows=[];
		$start=0;
		foreach(array_unique([$overlook??$last,$last]) as $end) {
			for($i=$start+1;$i<=$end;$i++) {
				if(($risk[$i]??0.)<self::DETOUR_ERROR) continue;
				$a=max($start,$i-6); $b=min($end,$i+6);
				for($j=$i+1;$j<=$b && $b<$end && $b<$a+48;$j++) {
					if(($risk[$j]??0.)>=self::DETOUR_ERROR) $b=min($j+6,$end,$a+48);
				}
				if($b-$a>=4) $windows[]=[$a,$b];
				$i=$start=$b;
			}
			$start=$end;
		}
		// Work backwards so each original window keeps its indices.
		foreach(array_reverse($windows) as [$a,$b]) {
			[$x,$z]=$path[$a]; [$u,$v]=$path[$b];
			$run=hypot($u-$x,$v-$z);
			if($run<180.) continue;
			$proposed=yield from $this->contourCrossing($path[$a],$path[$b],min(16,(int)ceil($run/110.)),$minX,$maxX,$divider,$side);
			if(!$proposed) continue;
			// Preserve direction at the splice. A detour doubling back into the incoming
			// pavement would be impossible to follow even with an excellent grade.
			foreach([[$a,$a-1,1],[$b,$b+1,count($proposed)-2]] as [$join,$outside,$inside]) {
				if(!isset($path[$outside])) continue;
				[$x,$z]=$path[$join]; [$u,$v]=$path[$outside]; [$p,$q]=$proposed[$inside];
				if(($x-$u)*($p-$x)+($z-$v)*($q-$z)<.1*hypot($x-$u,$z-$v)*hypot($p-$x,$q-$z)) continue 2;
			}
			$start=max(0,$a-4);
			$before=array_slice($path,$start,$a-$start); $after=array_slice($path,$b+1,4);
			[, $oldCost,$oldPeak]=yield from $this->earthworks(array_slice($path,$start,$b-$start+1+count($after)));
			[, $cost,$peak]=yield from $this->earthworks([...$before,...$proposed,...$after]);
			if($cost>=.9*$oldCost || $peak>$oldPeak) continue;
			if(!(yield from self::clearOfRoad($path,$a,$b,$proposed))) continue;
			array_splice($path,$a,$b-$a+1,$proposed);
		}
		return $path;
	}

	/** Retain the scenic waypoint as a fixed tie while looking for gentler climbs. */
	public function route(array $guide,bool $freeStart=false,bool $scenic=false,?float $divider=null): \Generator
	{
		[$from]=$guide[0]; [$to,$endZ]=$guide[array_key_last($guide)];
		$overlook=null;
		if($scenic) {
			[$west,$east]=yield from $this->crossing($guide,$freeStart,$divider);
			$path=yield from $this->refine($west);
			$overlook=array_key_last($path);
			$tail=yield from $this->refine($east);
			$path=[...$path,...array_slice($tail,1)];
		} else $path=yield from $this->refine($guide);
		$path=yield from $this->detours($path,$from,$to,$divider,$endZ<0 ? -1 : 1,$overlook);
		$this->cache=[];
		return $path;
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{Edits,Landscape};

/** Advance warnings on the drivable spline. An instance follows one road between junctions, or one closed lap. */
final class RoadWarnings
{
	public const CURVE=2, HAIRPIN=3, SWERVE=4, KINK=5, MERGE=6, HILL=7, DIP=8;
	public const LEAD=1200*DisplayUnits::FOOT;
	private const PAIR_DISTANCE=200*DisplayUnits::FOOT;
	private const STEP=6.; // Survey interval in meters; vertical grades use a two-interval wheel path on either side.
	private array $sections=[], $starts=[], $occupied=[];
	private float $length=0.;
	private bool $closed=false;
	private string $entrance;

	private function append(array $road,int $direction): void
	{
		$stations=$road['_road']['stations'];
		$length=$stations[array_key_last($stations)];
		$this->starts[]=$this->length;
		$this->sections[]=[$road,$direction,$length];
		$this->length+=$length;
	}

	private function pose(float $at): array
	{
		if($this->closed) $at=fmod(fmod($at,$this->length)+$this->length,$this->length);
		else $at=max(0.,min($this->length,$at));
		$i=JourneySigns::atOrBefore($this->starts,$at);
		[$road,$direction,$length]=$this->sections[$i];
		$local=$at-$this->starts[$i];
		$pose=JourneySigns::pose($road,$direction>0 ? $local : $length-$local);
		if($direction<0) $pose['heading']=[-$pose['heading'][0],-$pose['heading'][1]];
		return $pose;
	}

	/** Join editable road chunks in geometric order, including chunks authored in reverse. */
	public static function sites(Landscape $land): \Generator
	{
		$roads=array_filter($land->roads(),static fn($r)=>$r['material']==='road');
		$nodes=$ends=$seen=$approaches=$incoming=$paths=[]; $roots=[];
		foreach($roads as $id=>$road) {
			$p=$road['points'];
			$a=JourneySigns::node($p[0]); $b=JourneySigns::node($p[array_key_last($p)]);
			$ends[$id]=[1=>$b,-1=>$a]; $nodes[$a][]=[$id,1]; $nodes[$b][]=[$id,-1];
			yield null;
		}
		foreach($nodes as $edges) if(count($edges)!==2) foreach($edges as $edge) $roots[]=$edge;
		foreach($roads as $id=>$_) $roots[]=[$id,1]; // Remaining components have no endpoints.
		foreach($roots as [$id,$dir]) {
			if(isset($seen[$id])) continue;
			$sections=[]; $votes=0;
			$first=$ends[$id][-$dir];
			// Walk through degree-two joins. A fork, a merge or a road end terminates this approach.
			do {
				$seen[$id]=true; $sections[]=[$id,$dir]; $votes+=$dir;
				$last=$ends[$id][$dir]; $links=$nodes[$last];
				if(count($links)!==2) break;
				[$id,$dir]=$links[$links[0][0]===$id ? 1 : 0];
				yield null;
			} while(!isset($seen[$id]));
			// Generated highways and circuit chunks are authored in driving order. The
			// topology walk may have started at the far end or met a reversed edit.
			$forward=$votes<=>0 ?: $sections[0][1];
			if($forward<0) { $sections=array_reverse($sections); [$first,$last]=[$last,$first]; }
			$path=new self;
			foreach($sections as [$roadId,$orientation]) $path->append($roads[$roadId],$orientation*$forward);
			if($path->length<.01) continue;
			$path->entrance=$first;
			$path->closed=$last===$first && count($nodes[$first])===2;
			if(!$path->closed) {
				$approaches[$first][]=[$path,-1];
				$approaches[$last][]=[$path,1];
				$incoming[$last][]=$path;
			}
			$paths[]=$path;
		}
		foreach($paths as $path) yield from $path->survey($roads,$incoming);

		// Two incoming arms join a departing trunk. A fork has an arriving trunk and needs no merge warning.
		foreach($approaches as $arms) {
			if(count($arms)!==3) continue;
			$poses=$headings=[]; $pair=[]; $best=.15;
			foreach($arms as [$path,$travel]) {
				$end=$travel>0 ? $path->length : 0.;
				$p=$path->pose($end-$travel*self::PAIR_DISTANCE); $poses[]=$p;
				$join=$path->pose($end)['position'];
				$dx=$join[0]-$p['position'][0]; $dz=$join[2]-$p['position'][2]; $length=max(.001,hypot($dx,$dz));
				// The two converging arms approach from the same side, even if an editor reversed one spline.
				foreach($headings as $j=>[$x,$z]) {
					$dot=($dx*$x+$dz*$z)/$length;
					if($dot>$best) { $best=$dot; $pair=[$j,count($headings)]; }
				}
				$headings[]=[$dx/$length,$dz/$length];
			}
			if(!$pair) continue;
			[$trunk]=array_values(array_diff(array_keys($arms),$pair));
			if($arms[$trunk][1]!==-1) continue;
			foreach($pair as $k=>$index) {
				[$path,$travel]=$arms[$index]; $end=$travel>0 ? $path->length : 0.;
				$p=$poses[$index]; $q=$poses[$pair[1-$k]];
				[$tx,$tz]=$p['heading'];
				$side=$travel*(-$tz*($q['position'][0]-$p['position'][0])+$tx*($q['position'][2]-$p['position'][2]));
				if(abs($side)>.1) yield from $path->warn($end,$travel,self::MERGE,$side>0 ? 1 : -1,$roads,$incoming);
			}
		}
	}

	/** Vertical separation from a tangent launch at top speed, after gravity acts across the measured span. */
	private static function launchGap(array $before,array $top,array $after): float
	{
		$a=hypot($top[0]-$before[0],$top[2]-$before[2]); $b=hypot($after[0]-$top[0],$after[2]-$top[2]);
		if(min($a,$b)<.1) return 0.;
		$up=($top[1]-$before[1])/$a; $down=($after[1]-$top[1])/$b;
		$distance=.5*($a+$b); $grade=.5*($up+$down); $speed=Car::TOP_SPEED_MPH*Car::MPS_PER_MPH;
		return .5*$distance*($up-$down)-.5*Session::DRIVING_GRAVITY*$distance**2*(1.+$grade**2)/$speed**2;
	}

	/** Detect lateral turns and downward changes of vertical grade in the same, time-sliced sampling pass. */
	private function survey(array $roads,array $incoming): \Generator
	{
		$n=max(1,(int)ceil($this->length/self::STEP)); $step=$this->length/$n;
		$bends=$crests=$window=[]; $bend=null; $crest=null;
		$start=$this->closed ? -2 : 0; $end=$n+($this->closed ? 1 : 0);
		for($i=$start;$i<=$end;$i++) {
			if(($i&31)===0) yield null;
			$p=$this->pose($i*$step);
			if($i>0 && $i<=$n) {
				[$ax,$az]=$previous['heading']; [$bx,$bz]=$p['heading'];
				$turn=atan2($ax*$bz-$az*$bx,$ax*$bx+$az*$bz);
				$horizontal=hypot($p['position'][0]-$previous['position'][0],$p['position'][2]-$previous['position'][2]);
				$side=abs($turn)*CurveSigns::MAX_RADIUS>=$horizontal && $horizontal>.01 ? ($turn<=>0.) : 0;
				if($bend!==null && $side!==$bend['side']) { $bends[]=$bend; $bend=null; }
				if($side!==0) {
					$bend??=['start'=>($i-1)*$step,'end'=>0.,'angle'=>0.,'side'=>$side];
					$bend['end']=$i*$step; $bend['angle']+=abs($turn);
				}
			}
			$previous=$p;
			$window[]=$p['position'];
			if(count($window)<5) continue;
			[$before,,$top,,$after]=$window; array_shift($window);
			$at=($i-2)*$step;
			$gap=self::launchGap($before,$top,$after);
			// A broad convex crest can exceed gravity before the wheels use their full droop.
			// Only extend the measurement where the local curvature already permits a launch.
			$span=6*$step;
			if($gap>0. && $gap<=CarSuspension::SAG && ($this->closed || ($at>=$span && $at+$span<=$this->length))) {
				$gap=self::launchGap($this->pose($at-$span)['position'],$top,$this->pose($at+$span)['position']);
			}
			if($gap>CarSuspension::SAG) {
				if($crest!==null && $at-$crest['end']>2.5*self::STEP) { $crests[]=$crest; $crest=null; }
				$crest??=['start'=>$at];
				$crest['end']=$at;
			}
		}
		if($bend!==null) $bends[]=$bend;
		if($crest!==null) $crests[]=$crest;
		if($this->closed && count($bends)>1) {
			$last=array_key_last($bends); $first=$bends[0];
			if($first['start']<.01 && $bends[$last]['end']>$this->length-.01 && $first['side']===$bends[$last]['side']) {
				$bends[$last]['end']=$this->length+$first['end']; $bends[$last]['angle']+=$first['angle'];
				array_shift($bends);
			}
		}
		yield from $this->turns(array_values(array_filter($bends,static fn($b)=>$b['angle']>=CurveSigns::MIN_TURN)),$roads,$incoming);
		if($this->closed && count($crests)>1 && $crests[0]['start']+$this->length-end($crests)['end']<2.5*self::STEP) {
			$last=array_pop($crests);
			$crests[0]['start']=$last['start']-$this->length;
		}
		foreach($crests as $c) {
			$at=$c['end']-$c['start']<4*self::STEP ? .5*($c['start']+$c['end']) : $c['start'];
			$here=$this->pose($at)['position'];
			$before=$this->pose($at-24.)['position'];
			$rise=$here[1]-$before[1];
			$kind=$rise>.015*hypot($here[0]-$before[0],$here[2]-$before[2]) ? self::HILL : self::DIP;
			yield from $this->warn($at,1,$kind,1,$roads,$incoming);
		}
	}

	private static function compound(array $a,array $b): bool
	{
		return $a['side']!==$b['side'] && $b['start']-$a['end']<=self::PAIR_DISTANCE
			&& max($a['angle'],$b['angle'])<3*M_PI/4;
	}

	private function turns(array $bends,array $roads,array $incoming): \Generator
	{
		if($this->closed && count($bends)>1) {
			$first=$bends[0]; $first['start']+=$this->length; $first['end']+=$this->length;
			if(self::compound(end($bends),$first)) { array_shift($bends); $bends[]=$first; }
		}
		for($i=0;$i<count($bends);$i++) {
			$a=$bends[$i]; $kind=$a['angle']>=3*M_PI/4 ? self::HAIRPIN : self::CURVE;
			if(isset($bends[$i+1]) && self::compound($a,$bends[$i+1])) {
				$b=$bends[++$i];
				$kind=min($a['angle'],$b['angle'])>=deg2rad(65.) ? self::KINK : self::SWERVE;
			}
			yield from $this->warn($a['start'],1,$kind,$a['side'],$roads,$incoming);
		}
	}

	/** Measure through a junction if the hazard lies less than one warning distance beyond it. */
	private function warn(float $station,int $travel,int $kind,int $side,array $roads,array $incoming): \Generator
	{
		$pending=[[$this,$station]]; $seen=[];
		while($pending) {
			[$path,$at]=array_pop($pending);
			$id=spl_object_id($path);
			if(isset($seen[$id])) continue;
			$seen[$id]=true;
			if($travel>0 && !$path->closed && $at<self::LEAD && isset($incoming[$path->entrance])) {
				foreach($incoming[$path->entrance] as $parent) $pending[]=[$parent,$parent->length+$at];
				yield null;
			} else yield $path->post($at,$travel,$kind,$side,$roads);
		}
	}

	/** Put a driver-facing diamond on the right shoulder. Try the other shoulder if the intersection covers it. */
	private function post(float $hazard,int $travel,int $kind,int $side,array $roads): ?array
	{
		if($this->closed && $this->length<self::LEAD) return null;
		foreach([0.,-5.,5.,-10.,10.] as $shift) {
			$at=$hazard-$travel*(self::LEAD+$shift);
			if(!$this->closed && ($at<-.01 || $at>$this->length+.01)) continue;
			$p=$this->pose($at); [$x,,$z]=$p['position']; [$tx,$tz]=$p['heading']; $tx*=$travel; $tz*=$travel;
			foreach([1,-1] as $shoulder) {
				$offset=$shoulder*($p['width']/2.+2.5); $sx=$x-$tz*$offset; $sz=$z+$tx*$offset;
				$key=round($sx/5.).','.round($sz/5.);
				if(isset($this->occupied[$key]) || Edits::onRoad($sx,$sz,$roads)) continue;
				$this->occupied[$key]=true;
				return [$sx,$sz,$tx,$tz,$kind*$side];
			}
		}
		return null;
	}
}

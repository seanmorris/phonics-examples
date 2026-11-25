<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\{Edits,Landscape};

/** Advance warnings on the drivable spline. An instance follows one road between junctions, or one closed lap. */
final class RoadWarnings
{
	public const CURVE=2, HAIRPIN=3, SWERVE=4, KINK=5, MERGE=6, HILL=7, DIP=8;
	public const LEAD=400*DisplayUnits::FOOT;
	private const POLE_SPACING=100*DisplayUnits::FOOT;
	private const FACE_WIDTH=5.30; // Keep separate turn diamonds clear of one another.
	private const PAIR_DISTANCE=200*DisplayUnits::FOOT;
	private const STEP=6.; // Survey interval in meters; vertical grades use a two-interval wheel path on either side.
	private array $sections=[], $starts=[], $features=[], $posts=[];
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
		foreach($paths as $path) yield from $path->survey();

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
				if(abs($side)>.1) $path->feature($end,$end,$travel,self::MERGE*($side>0 ? 1 : -1));
			}
		}
		// Survey all branches before resolving upstream warnings across a junction.
		foreach($paths as $path) {
			foreach($path->features as &$families) foreach($families as &$features)
				usort($features,static fn($a,$b)=>$a[0]<=>$b[0]);
			unset($families,$features);
			yield null;
		}
		foreach($paths as $path) foreach($path->features as $travel=>$families) foreach($families as $family=>$features)
			foreach($features as $i=>[$start,,$symbol]) yield from $path->schedule($start,$travel,$family,$i,$symbol,$incoming);
		$turnPosts=[];
		foreach($paths as $path) {
			$approaches=[];
			foreach($path->posts as [$hazard,$at,$travel,$symbol,$minimum]) {
				$placed=$path->post($at,$travel,$symbol,$minimum,$hazard,$roads);
				if($placed!==null) $approaches[$travel][]=$placed;
				yield null;
			}
			foreach($approaches as $travel=>$approach) yield from $path->sharedPoles($approach,$travel,$roads,$turnPosts);
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
	private function survey(): \Generator
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
		$this->turns(array_values(array_filter($bends,static fn($b)=>$b['angle']>=CurveSigns::MIN_TURN)));
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
			$this->feature($at,$c['end'],1,$kind);
		}
	}

	private static function compound(array $a,array $b): bool
	{
		return $a['side']!==$b['side'] && $b['start']-$a['end']<=self::PAIR_DISTANCE
			&& max($a['angle'],$b['angle'])<3*M_PI/4;
	}

	private function turns(array $bends): void
	{
		if($this->closed && count($bends)>1) {
			$first=$bends[0]; $first['start']+=$this->length; $first['end']+=$this->length;
			if(self::compound(end($bends),$first)) { array_shift($bends); $bends[]=$first; }
		}
		for($i=0;$i<count($bends);$i++) {
			$a=$bends[$i]; $end=$a['end']; $kind=$a['angle']>=3*M_PI/4 ? self::HAIRPIN : self::CURVE;
			if(isset($bends[$i+1]) && self::compound($a,$bends[$i+1])) {
				$b=$bends[++$i]; $end=$b['end'];
				$kind=min($a['angle'],$b['angle'])>=deg2rad(65.) ? self::KINK : self::SWERVE;
			}
			$this->feature($a['start'],$end,1,$kind*$a['side']);
		}
	}

	/** Keep road stations in driving order, including a compound bend spanning the lap seam. */
	private function feature(float $start,float $end,int $travel,int $symbol): void
	{
		$span=$end-$start;
		if($travel<0) $start=$this->length-$start;
		if($this->closed) $start=fmod(fmod($start,$this->length)+$this->length,$this->length);
		$family=abs($symbol)<=self::KINK ? 'turn' : abs($symbol);
		$this->features[$travel][$family][]=[$start,$start+$span,$symbol];
	}

	/** Follow the warning distance upstream. Compare against the previous feature on each actual approach. */
	private function schedule(float $start,int $travel,int|string $family,int $index,int $symbol,array $incoming): \Generator
	{
		if($this->closed && $this->length<self::LEAD) return;
		$pending=[[$this,$start,$index-1,[]]]; $seen=[];
		while($pending) {
			[$path,$hazard,$previous,$onward]=array_pop($pending);
			$id=spl_object_id($path);
			if(isset($seen[$id])) continue;
			$seen[$id]=true;
			$at=$hazard-self::LEAD; $minimum=-INF;
			$features=$path->features[$travel][$family] ?? [];
			$offset=0.;
			if($path->closed && $previous<0 && count($features)>1) {
				$previous=count($features)-1; $offset=-$path->length;
			}
			if($previous>=0) {
				[$priorStart,$end,$prior]=$features[$previous]; $end+=$offset;
				if($prior!==$symbol && $family==='turn') {
					// Even if the pole must move to find a clear shoulder, keep it past the previous bend.
					$minimum=$end+max(.1,min(self::STEP,.5*($hazard-$end)));
					$at=max($at,$minimum);
				} elseif($prior===$symbol && $at<=$end+self::STEP) {
					// A lap made entirely of overlapping identical warnings still needs its first sign.
					$covered=$path->closed || $priorStart>=self::LEAD || isset($incoming[$path->entrance]);
					if($covered && !($offset<0. && $path->oneCluster($features))) continue;
				}
			}
			if($travel>0 && !$path->closed && $at<0. && isset($incoming[$path->entrance])) {
				$onward[]=$path;
				foreach($incoming[$path->entrance] as $parent) {
					$last=count($parent->features[$travel][$family] ?? [])-1;
					$pending[]=[$parent,$parent->length+$hazard,$last,$onward];
				}
			} else {
				while($onward && $at>$path->length) {
					$at-=$path->length; $minimum-=$path->length; $hazard-=$path->length;
					$path=array_pop($onward);
				}
				$path->posts[]=[$hazard,$at,$travel,$symbol,$minimum];
			}
			yield null;
		}
	}

	private function oneCluster(array $features): bool
	{
		[, $end,$symbol]=end($features); $end-=$this->length;
		foreach($features as [$start,$next,$kind]) {
			if($kind!==$symbol || $start-self::LEAD>$end+self::STEP) return false;
			$end=$next;
		}
		return true;
	}

	/** Combine actual shoulder sites on one approach. Distances and sign order follow the pavement. */
	private function sharedPoles(array $sites,int $travel,array $roads,array &$turnPosts): \Generator
	{
		// At a symmetric fork, give the right-hand arrow first choice of the right shoulder.
		usort($sites,static fn($a,$b)=>($a['at']<=>$b['at']) ?: ($a['lead']<=>$b['lead']) ?: ($b['symbol']<=>$a['symbol']));
		if($this->closed && count($sites)>1) {
			// Cut the circular list at its widest gap so a lap line cannot separate a pair.
			$cut=0; $widest=$sites[0]['at']+$this->length-end($sites)['at'];
			foreach($sites as $i=>$site) if($i && $site['at']-$sites[$i-1]['at']>$widest) {
				$cut=$i; $widest=$site['at']-$sites[$i-1]['at'];
			}
			$wrapped=array_slice($sites,0,$cut);
			foreach($wrapped as &$site) $site['at']+=$this->length;
			unset($site);
			$sites=[...array_slice($sites,$cut),...$wrapped];
		}

		$symbols=[]; $turn=null; $minimum=-INF;
		foreach($sites as $i=>$site) {
			if(($i&31)===0) yield null;
			$at=$site['at'];
			$earliest=$at-$site['slack']; // Earliest legal position after a preceding, conflicting turn.
			$symbol=$site['symbol']; $isTurn=abs($symbol)<=self::KINK;
			$conflict=$isTurn && $turn!==null && $turn!==$symbol;
			if($symbols && ($conflict || $at-$first>self::POLE_SPACING+1.e-6 || ($earliest>$anchor['at'] && $at>=min($symbols)))) {
				yield $this->mount($anchor,$symbols,$turn,$minimum,$travel,$roads,$turnPosts);
				$symbols=[]; $turn=null; $minimum=-INF;
			}
			if(!$symbols) { $first=$at; $anchor=$site; }
			elseif($earliest>$anchor['at']) $anchor=$site;
			if($isTurn) $turn=$symbol;
			$minimum=max($minimum,$earliest);
			$symbols[$symbol]=min($symbols[$symbol] ?? INF,$at+$site['lead']);
		}
		if($symbols) yield $this->mount($anchor,$symbols,$turn,$minimum,$travel,$roads,$turnPosts);
	}

	/** Separate even coincident requests on the common approach to a fork. */
	private function mount(array $anchor,array $symbols,?int $turn,float $minimum,int $travel,array $roads,array &$turnPosts): ?array
	{
		$pose=$anchor['pose'];
		if($turn!==null) {
			if(!self::clearFooting($pose[0],$pose[1],$turn,$turnPosts)) {
				$site=$this->post($anchor['at'],$travel,$turn,$minimum,min($symbols),$roads,$turnPosts);
				if($site===null) return null;
				$pose=$site['pose'];
			}
			[$x,$z]=$pose;
			// Index the footprint in every cell it touches so a placement only queries one cell.
			$left=(int)floor(($x-self::FACE_WIDTH)/self::POLE_SPACING);
			$right=(int)floor(($x+self::FACE_WIDTH)/self::POLE_SPACING);
			$near=(int)floor(($z-self::FACE_WIDTH)/self::POLE_SPACING);
			$far=(int)floor(($z+self::FACE_WIDTH)/self::POLE_SPACING);
			for($col=$left;$col<=$right;$col++) for($row=$near;$row<=$far;$row++) {
				$turnPosts[$col][$row][]=[$x,$z,$turn];
			}
		}
		asort($symbols,SORT_NUMERIC);
		return [...$pose,...array_keys($symbols)];
	}

	private static function clearFooting(float $x,float $z,int $turn,array $turnPosts): bool
	{
		foreach($turnPosts[(int)floor($x/self::POLE_SPACING)][(int)floor($z/self::POLE_SPACING)] ?? [] as [$px,$pz,$other])
			if($other!==$turn && hypot($x-$px,$z-$pz)<self::FACE_WIDTH) return false;
		return true;
	}

	/** Put a driver-facing diamond on the right shoulder. Try the other shoulder if the intersection covers it. */
	private function post(float $station,int $travel,int $symbol,float $minimum,float $hazard,array $roads,array $turnPosts=[]): ?array
	{
		$shifts=[0.,-5.,5.,-10.,10.];
		if($turnPosts) {
			for($distance=15.;$distance<=self::POLE_SPACING;$distance+=5.) {
				$shifts[]=-$distance; $shifts[]=$distance;
			}
		}
		foreach($shifts as $shift) {
			$along=$station+$shift;
			if($along<$minimum || ($turnPosts && $along>$hazard)) continue;
			if(!$this->closed && ($along<-.01 || $along>$this->length+.01)) continue;
			$at=$travel<0 ? $this->length-$along : $along;
			$p=$this->pose($at); [$x,,$z]=$p['position']; [$tx,$tz]=$p['heading']; $tx*=$travel; $tz*=$travel;
			foreach([1,-1] as $shoulder) {
				$offset=$shoulder*($p['width']/2.+2.5); $sx=$x-$tz*$offset; $sz=$z+$tx*$offset;
				if(($turnPosts && !self::clearFooting($sx,$sz,$symbol,$turnPosts)) || Edits::onRoad($sx,$sz,$roads)) continue;
				$wrapped=$this->closed ? fmod(fmod($along,$this->length)+$this->length,$this->length) : $along;
				return ['at'=>$wrapped,'lead'=>$hazard-$along,'slack'=>$along-$minimum,
					'pose'=>[$sx,$sz,$tx,$tz],'symbol'=>$symbol];
			}
		}
		return null;
	}
}

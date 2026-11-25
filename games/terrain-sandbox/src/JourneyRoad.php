<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Edits;
use TerrainPhonics\JoinedTerrain;
use TerrainPhonics\Landscape;

/** Survey a west-to-east highway. Its stretches are ordinary editable roads with coincident junction knots. */
final class JourneyRoad
{
	public const WIDTH = 28.;
	public const FORK = -.34 * JoinedTerrain::GAP;
	public const SPLIT_RUN = 160.;
	public const SPLIT_OFFSET = 2.*self::WIDTH;

	public static function recipe(string $west, array $east, int $span): array
	{
		$destinations = [];
		foreach($east as $name) $destinations[] = ['preset'=>$name] + Session::preset($name);
		return ['preset'=>$west,'worldSize'=>$span,'join'=>['version'=>2,'span'=>$span,'destinations'=>$destinations]] + Session::preset($west);
	}

	public static function build(Landscape $land, array $arrival = [], ?callable $report = null, ?array $entry = null): \Generator
	{
		foreach($arrival as $road) yield from $land->queueSteps($road);
		if($entry===null && $arrival) {
			$last=end($arrival)['points'];
			$entry=end($last);
		}
		$survey=yield from self::survey($land,$report,$entry);
		foreach($survey['roads'] as $road) yield from $land->queueSteps($road);
		$land->commit();
		return $survey['views'];
	}

	/** Fit portable road records before a network reconciles approaches and grades the final pavement. */
	public static function survey(Landscape $land,?callable $report,?array $entry,array $sharedSlots=[]): \Generator
	{
		$r = $land->recipe();
		$half = $r['worldSize']/2;
		$span = $r['join']['span'];
		$origins = JoinedTerrain::origins($span,count($r['join']['destinations']));
		$split = count($origins) === 3;
		$survey = new JourneySurvey($r);
		$phases=count($r['join']['destinations'])+3;
		if($report) $report('Surveying valley entrances and exits…',0.);
		$tailX = $half-1536.;
		$arrivals = [];
		foreach(array_slice($origins,1) as [, $cz]) {
			$arrivals[]=[$cz,yield from $survey->arrivalRoad($tailX,$half,$cz)];
		}
		[$forkX,$forkZ] = yield from $survey->junction($entry,$arrivals);
		$first = $entry ? [$entry[0],$entry[2]] : [-$half,0.];
		if($report) $report('Following terrain through the departure square…',1./$phases);
		$approach = yield from $survey->route([$first,[$forkX-192.,$forkZ]],!$entry,!$entry,view:$trunkView);
		$approach[]=[$forkX-96.,$forkZ]; $approach[]=[$forkX,$forkZ];
		$paths=[$approach];
		$forks=$tails=$armViews=[];
		$squares=$entry ? [] : [0=>$origins[0]];
		foreach($arrivals as $i=>[, $tail]) {
			$side=$i===0 ? -1 : 1;
			$tailZ=$tail[0][1];
			if($report) $report('Finding an overlook and a road through '.($split ? ($side<0 ? 'the northern' : 'the southern') : 'the').' destination…',($i+2)/$phases);
			if($split) {
				// Only the shared pavement fixes the shape. Survey a single continuous
				// alignment from the diverging lanes all the way to each destination.
				$x=$forkX+self::SPLIT_RUN; $z=$forkZ+$side*self::SPLIT_OFFSET;
				$onward=yield from $survey->route([[$x,$z],[$tailX,$tailZ]],scenic:true,divider:$forkZ,view:$armView,sharedApproach:isset($sharedSlots[$i]));
				$path=[[$forkX,$forkZ],[$forkX+40.,$forkZ+$side*4.],
					[$forkX+80.,$forkZ+$side*16.],[$forkX+120.,$forkZ+$side*34.],...$onward];
				$forks[]=count($paths);
			} else {
				$onward=yield from $survey->route([[$forkX+96.,$forkZ],[$tailX,$tailZ]],scenic:true,view:$armView,sharedApproach:isset($sharedSlots[$i]));
				$path=[[$forkX,$forkZ],...$onward];
			}
			$squares[count($paths)]=$origins[$i+1];
			$armViews[]=$armView;
			$paths[]=$path;
			$tails[]=count($paths);
			$paths[]=$tail;
		}

		if($report) $report('Fitting the pavement to the surveyed ground…',($phases-1)/$phases);
		$paths=yield from self::bendStraights($land,$paths,$tails,$split,$trunkView,$armViews);
		// Each knot has one elevation, even when several branches meet there. Cap the grade in both
		// directions along every incident stretch; a third leg must not introduce a step at the fork.
		$graph = $height = $routes = [];
		foreach($paths as $path) {
			$route = [];
			foreach($path as $i=>[$x,$z]) {
				$key = $i===0 || $i===array_key_last($path) ? "junction:$x:$z" : count($routes).":$i";
				if(!isset($height[$key])) {
					$height[$key] = max($r['waterLevel']+6., $land->height($x,$z));
				}
				if($i>0) {
					$run=hypot($x-$path[$i-1][0],$z-$path[$i-1][1]);
					$cost=self::gradeLimit($run,$height[$key]-$height[$route[$i-1]]);
					$graph[$route[$i-1]][$key] = $cost;
					$graph[$key][$route[$i-1]] = $cost;
				}
				$route[] = $key;
				yield;
			}
			$last = count($route)-1;
			foreach([[0,1],[$last-1,$last]] as [$a,$b]) {
				$graph[$route[$a]][$route[$b]] = $graph[$route[$b]][$route[$a]] = 0.;
			}
			$routes[] = $route;
		}
		// The two arms of a fork share a road plane until their shoulders separate.
		if($split) {
			[$north,$south]=$forks;
			foreach($paths[$north] as $i=>[$x,$z]) {
				if($x >= $forkX+self::SPLIT_RUN) break;
				if(abs($z-$paths[$south][$i][1])>95.) continue;
				$a=$routes[$north][$i]; $b=$routes[$south][$i]; $joint=$routes[$north][0];
				$graph[$a][$b]=$graph[$b][$a]=0.;
				$graph[$a][$joint]=$graph[$joint][$a]=0.;
			}
		}
		// Center the road between the upper and lower feasible profiles. A short hollow
		// should not force a trench for miles, and a sharp summit should not force a high fill.
		// Anchor the last mile of each square solely to its own natural ground. Arriving
		// through a different parent must give the same merge pavement and departure height.
		$anchors=$entranceGround=[];
		foreach($tails as $leg) {
			$keys=$routes[$leg];
			$raw=array_map(static fn($key)=>$height[$key],$keys);
			$anchors+=array_combine($keys,self::landingProfile($paths[$leg],$raw));
		}
		// Tie each incoming ramp to the destination's natural elevation at the map boundary.
		// Otherwise distant mountains can move the road far above a low valley entrance.
		foreach($tails as $slot=>$leg) {
			if(!isset($sharedSlots[$slot])) continue;
			[$cx,$cz]=$squares[$leg-1];
			$inside=false;
			foreach($paths[$leg-1] as $i=>[$x,$z]) {
				$now=max(abs($x-$cx),abs($z-$cz))<=$span/2;
				if($now && !$inside) {
					$key=$routes[$leg-1][$i];
					$entranceGround[$key]=$height[$key];
				}
				$inside=$now;
			}
		}
		if($entry!==null) $anchors[$routes[0][0]]=$entry[1];
		// Share excess grade locally before enforcing the final grade bound. This minimizes
		// squared cut and fill, so one narrow ravine does not drag down an entire plateau.
		$links=[];
		foreach($graph as $a=>$neighbors) foreach($neighbors as $b=>$cost)
			if(strcmp((string)$a,(string)$b)<0) $links[]=[$a,$b,$cost,0.];
		for($pass=0;$pass<100;$pass++) {
			$change=0.;
			foreach($links as &$link) {
				[$a,$b,$cap,$residual]=$link;
				$d=$height[$a]-$height[$b]+2.*$residual;
				$correction=.5*($d-max(-$cap,min($cap,$d)));
				$delta=$residual-$correction;
				$height[$a]+=$delta; $height[$b]-=$delta;
				$link[3]=$correction; $change=max($change,abs($delta));
			}
			unset($link);
			yield;
			if($change<.01) break;
		}
		$low = self::envelope($graph,$height);
		$high = self::envelope($graph,array_map(static fn($h)=>-$h,$height));
		foreach($height as $key=>$_) $height[$key]=max($r['waterLevel']+6.,($low[$key]-$high[$key])*.5);
		for($pass=0;$pass<4;$pass++) {
			$smooth=$height;
			foreach($routes as $route) for($i=1;$i<count($route)-1;$i++) {
				$a=$route[$i-1]; $b=$route[$i]; $c=$route[$i+1];
				$l=$graph[$a][$b]; $h=$graph[$b][$c];
				if($l>0. && $h>0.) $smooth[$b]=.5*($height[$b]+($height[$a]*$h+$height[$c]*$l)/($l+$h));
			}
			$height=$smooth;
		}
		$low=self::envelope($graph,$height); $high=self::envelope($graph,array_map(static fn($h)=>-$h,$height));
		foreach($height as $key=>$_) $height[$key]=.5*($low[$key]-$high[$key]);
		foreach($entranceGround as $key=>$natural) if(abs($height[$key]-$natural)>32.) $anchors[$key]=$natural;
		$upper=self::envelope($graph,$anchors);
		$lower=self::envelope($graph,array_map(static fn($h)=>-$h,$anchors));
		foreach($height as $key=>$h) $height[$key]=max(-$lower[$key],min($upper[$key],$h));

		$views=$roads=[];
		foreach($paths as $leg=>$path) {
			$points = [];
			// Match level tangents across sea-level translations of the same shared landing.
			foreach($path as $i=>[$x,$z]) $points[] = [$x,round($height[$routes[$leg][$i]],6),$z];
			if(isset($squares[$leg])) {
				$site=yield from $survey->roadsideView($points,$squares[$leg]);
				if($site!==null) $views[]=$site;
			}
			$last = count($points)-1;
			$handles = [];
			foreach($points as $i=>$p) {
				$handle = Edits::roadHandles($points,[],$i);
				// All legs share a level eastward tangent at a junction. Flatten local extrema as well.
				foreach(['in','out'] as $side) {
					if($i===0 || $i===$last) { $handle[$side][1]=0.; $handle[$side][2]=0.; }
					elseif(($p[1]-$points[$i-1][1])*($points[$i+1][1]-$p[1]) <= 0.) $handle[$side][1]=0.;
				}
				$handles[] = $handle;
			}
			// Slice the fitted spline with its original handles so adjacent road records meet tangentially.
			for($i=0;$i<$last;$i+=96) {
				$roads[]=['type'=>'road','points'=>array_slice($points,$i,97),'width'=>self::WIDTH,'shoulder'=>18.,
					'crown'=>0.,'material'=>'road','stationSpacing'=>7.,'handles'=>array_slice($handles,$i,97)];
				yield;
			}
		}
		return ['roads'=>$roads,'views'=>$views];
	}

	/** Count the full drive through each arm, including the fixed fork pavement and common arrival road. */
	private static function bendStraights(Landscape $land,array $paths,array $tails,bool $split,?array $trunkView,array $armViews): \Generator
	{
		$incoming=$paths[0]; $r=$land->recipe(); $half=$r['worldSize']/2;
		$height=$land->height(...);
		foreach($tails as $slot=>$tail) {
			$branch=$paths[$tail-1]; $stub=$split ? 4 : 1;
			$sections=[array_slice($incoming,0,-2),array_slice($incoming,-3),array_slice($branch,0,$stub+1),
				array_slice($branch,$stub),$paths[$tail]];
			[$from]=$sections[3][0];
			$divider=$incoming[array_key_last($incoming)][1]; $side=$slot===0 ? -1 : 1;
			$allows=static function($x,$z,$px,$pz,$nx,$nz,$section) use($r,$height,$half,$split,$from,$divider,$side): bool {
				if(max(abs($px),abs($pz))>max($half-self::WIDTH/2,abs($x),abs($z))) return false;
				if($section===3 && $split) {
					$boundary=JourneySurvey::branchLimit($px,$from,$divider,$side,$r['join']['span']);
					// Preserve the separation already achieved by the contour survey.
					if($side*($pz-$boundary)<min(0.,$side*($z-JourneySurvey::branchLimit($x,$from,$divider,$side,$r['join']['span'])))) return false;
				}
				return RoadSerpentine::fitsGround($height,self::WIDTH/2,$r['waterLevel']+6.,$x,$z,$px,$pz,$nx,$nz);
			};
			$ties=[];
			foreach([0=>$trunkView,3=>$armViews[$slot]] as $section=>$view) {
				$index=array_search($view,$sections[$section],true);
				if($index!==false) $ties[$section][]=$index;
			}
			$sections=yield from RoadSerpentine::fit($sections,$slot===0 ? [0,3] : [3],$allows,$ties);
			if($slot===0) $paths[0]=[...$sections[0],...array_slice($sections[1],1)];
			$paths[$tail-1]=[...$sections[2],...array_slice($sections[3],1)];
		}
		return $paths;
	}

	/** The end of a destination's highway depends only on its own terrain. A distant stage
	 * preview can start here without building every junction between it and the departure. */
	public static function landing(Landscape $land,int $destination): \Generator
	{
		$r=$land->recipe(); $half=$r['worldSize']/2;
		$origins=JoinedTerrain::origins($r['join']['span'],count($r['join']['destinations']));
		$survey=new JourneySurvey($r);
		$path=yield from $survey->arrivalRoad($half-1536.,$half,$origins[$destination+1][1]);
		$raw=array_map(static fn($p)=>max($r['waterLevel']+6.,$land->height(...$p)),$path);
		$profile=self::landingProfile($path,$raw);
		[$x,$z]=end($path);
		return [$x,end($profile),$z];
	}

	private static function gradeLimit(float $run,float $rise): float
	{
		return max(.075*$run,min(.105*$run,abs($rise)));
	}

	/** Upper and lower grade envelopes on the shared landing, with level tangents at each end. */
	private static function landingProfile(array $path,array $raw): array
	{
		$low=$high=$raw; $last=count($path)-1; $cost=[];
		for($i=1;$i<=$last;$i++) {
			$run=hypot($path[$i][0]-$path[$i-1][0],$path[$i][1]-$path[$i-1][1]);
			$cost[$i]=($i===1 || $i===$last) ? 0. : self::gradeLimit($run,$raw[$i]-$raw[$i-1]);
			$low[$i]=min($low[$i],$low[$i-1]+$cost[$i]);
			$high[$i]=max($high[$i],$high[$i-1]-$cost[$i]);
		}
		for($i=$last;$i>0;$i--) {
			$low[$i-1]=min($low[$i-1],$low[$i]+$cost[$i]);
			$high[$i-1]=max($high[$i-1],$high[$i]-$cost[$i]);
		}
		return array_map(static fn($a,$b)=>.5*($a+$b),$low,$high);
	}

	public static function envelope(array $graph,array $values): array
	{
		$queue = new \SplPriorityQueue;
		foreach($values as $key=>$value) $queue->insert($key,-$value);
		$queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		while(!$queue->isEmpty()) {
			['data'=>$key,'priority'=>$priority]=$queue->extract();
			if(-$priority>$values[$key]) continue;
			foreach($graph[$key] as $neighbor=>$cost) {
				$limit=$values[$key]+$cost;
				if($limit>=($values[$neighbor]??INF)) continue;
				$values[$neighbor]=$limit;
				$queue->insert($neighbor,-$limit);
			}
		}
		return $values;
	}

	/** Moving a shared endpoint in the road editor moves every incident road in the same undo transaction. */
	public static function connectedEdits(Landscape $land, array $road): array
	{
		$id = $road['replaceAt'] ?? null;
		if($id === null) return [$road];
		$records = $land->roadRecords();
		$old = $records[$id]['points'];
		$changes = [];
		foreach([0,1] as $end) {
			$from = $old[$end===0 ? 0 : array_key_last($old)];
			$to = $road['points'][$end===0 ? 0 : array_key_last($road['points'])];
			if($from == $to) continue;
			foreach($records as $neighbor=>$record) {
				if($neighbor===$id) continue;
				foreach([0,array_key_last($record['points'])] as $index) {
					if($record['points'][$index] != $from) continue;
					$changes[$neighbor] ??= $record + ['replaceAt'=>$neighbor];
					$changes[$neighbor]['points'][$index] = $to;
				}
			}
		}
		return [$road,...array_values($changes)];
	}

	/** For each road record, map each reachable eastern exit to the next connected record on that route. */
	public static function outwardLinks(array $roads,array $r): array
	{
		$half=$r['worldSize']/2;
		$parents=$sources=$next=$queue=[];
		foreach($roads as $i=>$road) {
			if($road['material']!=='road') continue;
			$p=$road['points']; $last=end($p);
			$sources[$i]=JourneySigns::node($p[0]);
			$parents[JourneySigns::node($last)][]=$i;
			if(abs($last[0]-$half)>1.) continue;
			$slot=count($r['join']['destinations'] ?? [])===2 && $last[2]>0. ? 1 : 0;
			$queue[]=[$i,$slot,null];
		}
		// Each record gets at most one successor per reachable exit, also for edited networks with cycles.
		for($j=0;$j<count($queue);$j++) {
			[$i,$slot,$child]=$queue[$j];
			if(array_key_exists($slot,$next[$i] ?? [])) continue;
			$next[$i][$slot]=$child;
			foreach($parents[$sources[$i]] ?? [] as $parent) $queue[]=[$parent,$slot,$i];
		}
		return $next;
	}

	/** Resolve the start and first fork from the edited network. World coordinates can change when a knot is moved. */
	public static function entrances(Landscape $land,bool $journeyStage=false): array
	{
		if(!$journeyStage && !isset($land->recipe()['join'])) return [];
		$roads = $land->roads();
		$west = $incoming = null;
		$exits=self::outwardLinks($roads,$land->recipe());
		foreach($roads as $id=>$road) {
			if((!$exits || isset($exits[$id])) && ($west === null || $road['points'][0][0] < $west['points'][0][0])) $west = $road;
			$end = $road['points'][array_key_last($road['points'])];
			$branches = 0;
			foreach($roads as $next) if($next['points'][0] == $end) $branches++;
			if($branches >= 2 && ($incoming === null || $end[0] < $incoming['points'][array_key_last($incoming['points'])][0]))
				$incoming = $road;
		}
		$result = [];
		if($west !== null) $result['west'] = self::station($west,48.,false);
		if($incoming !== null) {
			$result['fork'] = self::station($incoming,140.,true);
			$result['junction'] = self::station($incoming,0.,true);
		}
		return $result;
	}

	/** Walk a distance along the pavement from either end without assuming its heading. */
	private static function station(array $road,float $distance,bool $fromEnd): array
	{
		$samples = $road['_road']['samples'];
		$last = array_key_last($samples);
		$i = $fromEnd ? $last : 0;
		while($distance > 0.) {
			$next = $i + ($fromEnd ? -1 : 1);
			if($next < 0 || $next > $last) break;
			$distance -= hypot($samples[$i][0]-$samples[$next][0],$samples[$i][2]-$samples[$next][2]);
			$i = $next;
		}
		[$x,,$z,$tx,$tz] = $samples[$i];
		return [$x,$z,atan2($tx,$tz)];
	}
}

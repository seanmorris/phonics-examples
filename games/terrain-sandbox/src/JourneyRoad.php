<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Edits;
use TerrainPhonics\JoinedTerrain;
use TerrainPhonics\Landscape;

/** Survey a west-to-east highway. Its stretches are ordinary editable roads with coincident junction knots. */
final class JourneyRoad
{
	public const FORK = -.34 * JoinedTerrain::GAP;

	public static function recipe(string $west, array $east, int $span): array
	{
		$destinations = [];
		foreach($east as $name) $destinations[] = ['preset'=>$name] + Session::preset($name);
		return ['preset'=>$west,'worldSize'=>$span,'join'=>['version'=>2,'span'=>$span,'destinations'=>$destinations]] + Session::preset($west);
	}

	/** The signed side is negative for the northern fork. Both branches have horizontal tangents at the junctions. */
	private static function forkGuide(float $from,float $to,float $z0,float $z1,float $swing): array
	{
		$n = (int)ceil(($to-$from)/96.);
		$points = [];
		for($i=0;$i<=$n;$i++) {
			$t = $i/$n;
			$x = $i===$n ? $to : $from+($to-$from)*$t;
			$shape = sin(M_PI*$t)**2;
			$z = $z0+($z1-$z0)*$t*$t*(3.-2.*$t)+$swing*$shape;
			$points[] = [$x,$i===$n ? $z1 : $z];
		}
		return $points;
	}

	public static function build(Landscape $land, array $arrival = [], ?callable $report = null): \Generator
	{
		$r = $land->recipe();
		$half = $r['worldSize']/2;
		$span = $r['join']['span'];
		$origins = JoinedTerrain::origins($span,count($r['join']['destinations']));
		$split = count($origins) === 3;
		$survey = new JourneySurvey($r);
		$entry = null;
		foreach($arrival as $road) {
			yield from $land->queueSteps($road);
			$entry = $road['points'][array_key_last($road['points'])];
		}
		[$forkZ,$ends] = yield from $survey->portals($split,$entry[2] ?? null);
		$first = $entry ? [$entry[0],$entry[2]] : [-$half,0.];
		if($report) $report('Following terrain through the departure square…');
		$approach = yield from $survey->route([$first,[self::FORK-192.,$forkZ]],!$entry,!$entry);
		$approach[]=[self::FORK-96.,$forkZ]; $approach[]=[self::FORK,$forkZ];
		$paths=[$approach];
		$forks=$tails=[];
		$squares=$entry ? [] : [0=>$origins[0]];
		// A divided highway needs a shared throat, but no fixed landing at the far edge
		// of the connector. Survey each branch all the way into its destination.
		$throatEnd=$split ? self::FORK+672. : -self::FORK;
		$guides=[];
		foreach([-1,1] as $side) {
			$end=$split ? $forkZ+$side*240. : $ends[0];
			$guides[$side]=self::forkGuide(self::FORK,$throatEnd,$forkZ,$end,$side*($split ? 0. : 485.));
		}
		$divider=array_map(static fn($n,$s)=>.5*($n[1]+$s[1]),$guides[-1],$guides[1]);
		if($report) $report('Following the contours through the fork…');
		foreach([-1,1] as $side) {
			$z = $guides[$side][array_key_last($guides[$side])][1];
			$forks[] = count($paths);
			$paths[] = yield from $survey->branch($guides[$side],$side,$divider);
			if(!$split && $side<0) continue;
			[, $center] = $origins[$split && $side>0 ? 2 : 1];
			// Both parents use the same final approach on a shared destination square.
			$tailX = $half-1536.;
			$tailZ = yield from $survey->landing($tailX,$center,1280);
			$edgeZ = yield from $survey->landing($half,$center,1280);
			if($report) $report('Finding an overlook and a road through '.($split ? ($side<0 ? 'the northern' : 'the southern') : 'the'). ' destination…');
			$onward = yield from $survey->route([[$throatEnd+192.,$z],[$tailX,$tailZ]],scenic:true,divider:$split ? $forkZ : null);
			$squares[count($paths)]=$origins[$split && $side>0 ? 2 : 1];
			$paths[] = [[$throatEnd,$z],[$throatEnd+96.,$z],...$onward];
			$tails[]=count($paths);
			$paths[] = yield from $survey->route([[$tailX,$tailZ],[$half,$edgeZ]]);
		}

		if($report) $report('Fitting the pavement to the surveyed ground…');
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
					$rise=abs($height[$key]-$height[$route[$i-1]]);
					$cost=max(.075*$run,min(.105*$run,$rise));
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
		// Close branches share a road plane until their shoulders separate.
		[$north,$south] = $forks;
		foreach($paths[$north] as $i=>[$x,$z]) {
			if(abs($z-$paths[$south][$i][1])>95.) continue;
			$a=$routes[$north][$i]; $b=$routes[$south][$i];
			$graph[$a][$b]=$graph[$b][$a]=0.;
			$end=$i<count($paths[$north])/2 ? 0 : array_key_last($paths[$north]);
			if(!$split || $end===0) {
				$joint=$routes[$north][$end];
				$graph[$a][$joint]=$graph[$joint][$a]=0.;
			}
		}
		// Center the road between the upper and lower feasible profiles. A short hollow
		// should not force a trench for miles, and a sharp summit should not force a high fill.
		// Anchor the last mile of each square solely to its own natural ground. Arriving
		// through a different parent must give the same merge pavement and departure height.
		$anchors=[];
		foreach($tails as $leg) {
			$keys=$routes[$leg]; $local=[];
			for($i=1;$i<count($keys);$i++) {
				$a=$keys[$i-1]; $b=$keys[$i];
				$local[$a][$b]=$local[$b][$a]=$graph[$a][$b];
			}
			$raw=array_intersect_key($height,$local);
			$lo=self::envelope($local,$raw);
			$hi=self::envelope($local,array_map(static fn($h)=>-$h,$raw));
			foreach($raw as $k=>$_) $anchors[$k]=max($r['waterLevel']+6.,.5*($lo[$k]-$hi[$k]));
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
		$upper=self::envelope($graph,$anchors);
		$lower=self::envelope($graph,array_map(static fn($h)=>-$h,$anchors));
		foreach($height as $key=>$h) $height[$key]=max(-$lower[$key],min($upper[$key],$h));

		$views=[];
		foreach($paths as $leg=>$path) {
			$points = [];
			foreach($path as $i=>[$x,$z]) $points[] = [$x,$height[$routes[$leg][$i]],$z];
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
				yield from $land->queueSteps(['type'=>'road','material'=>'road','width'=>28.,'shoulder'=>18.,'stationSpacing'=>7.,
					'points'=>array_slice($points,$i,97),'handles'=>array_slice($handles,$i,97)]);
				yield;
			}
		}
		$land->commit();
		return $views;
	}

	private static function envelope(array $graph,array $values): array
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

	/** Resolve the start and first fork from the edited network. World coordinates can change when a knot is moved. */
	public static function entrances(Landscape $land): array
	{
		if(!isset($land->recipe()['join'])) return [];
		$roads = $land->roads();
		$west = $incoming = null;
		foreach($roads as $road) {
			if($west === null || $road['points'][0][0] < $west['points'][0][0]) $west = $road;
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

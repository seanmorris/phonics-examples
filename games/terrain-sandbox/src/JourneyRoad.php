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
		return ['preset'=>$west,'worldSize'=>$span,'join'=>['span'=>$span,'destinations'=>$destinations]] + Session::preset($west);
	}

	/** The signed side is negative for the northern fork. Both branches have horizontal tangents at the junctions. */
	private static function path(float $from,float $to,float $z0,float $z1,float $swing,bool $highway): array
	{
		$n = (int)ceil(($to-$from)/($highway ? 256. : 96.));
		$points = [];
		for($i=0;$i<=$n;$i++) {
			$t = $i/$n;
			$x = $i===$n ? $to : $from+($to-$from)*$t;
			$shape = sin(M_PI*$t)**2;
			$wave = $highway ? sin(($x-$from)/2300.)+.35*sin(($x-$from)/790.) : 1.;
			$z = $z0+($z1-$z0)*$t*$t*(3.-2.*$t)+$swing*$shape*$wave;
			$points[] = [$x,$i===$n ? $z1 : $z];
		}
		return $points;
	}

	public static function build(Landscape $land): \Generator
	{
		$r = $land->recipe();
		$half = $r['worldSize']/2;
		$span = $r['join']['span'];
		$split = count($r['join']['destinations']) === 2;
		$paths = [self::path(-$half,self::FORK,0.,0.,800.,true)];
		foreach([-1,1] as $side) {
			$endZ = $split ? $side*.60*JoinedTerrain::GAP : 0.;
			$paths[] = self::path(self::FORK,-self::FORK,0.,$endZ,$side*($split ? 100. : 420.),false);
			if($split) $paths[] = self::path(-self::FORK,$half,$endZ,$side*($span+JoinedTerrain::GAP)/2,750.,true);
		}
		if(!$split) $paths[] = self::path(-self::FORK,$half,0.,0.,-720.,true);

		// Each knot has one elevation, even when several branches meet there. Cap the grade in both
		// directions along every incident stretch; a third leg must not introduce a step at the fork.
		$graph = $height = $routes = [];
		$queue = new \SplPriorityQueue;
		foreach($paths as $path) {
			$route = [];
			foreach($path as $i=>[$x,$z]) {
				$key = $i===0 || $i===array_key_last($path) ? "junction:$x:$z" : count($routes).":$i";
				if(!isset($height[$key])) {
					$height[$key] = max($r['waterLevel']+6., $land->height($x,$z));
					$queue->insert($key,-$height[$key]);
				}
				if($i>0) {
					$cost = .075*hypot($x-$path[$i-1][0],$z-$path[$i-1][1]);
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
			// While the two forks still share asphalt or a graded shoulder, both road planes
			// must have the same elevation. Otherwise the upper road can cut a lip in the lower one.
			if($path[0][0] === self::FORK) {
				$last = count($route)-1;
				foreach($path as $i=>[$x,$z]) {
					if(abs($z)>90.) continue;
					$end = $i <= $last/2 ? 0 : $last;
					if($end===$last && $split) continue;
					$graph[$route[$end]][$route[$i]] = 0.;
					$graph[$route[$i]][$route[$end]] = 0.;
				}
			}
			$routes[] = $route;
		}
		$queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		while(!$queue->isEmpty()) {
			['data'=>$key,'priority'=>$priority] = $queue->extract();
			if(-$priority > $height[$key]) continue;
			foreach($graph[$key] as $neighbor=>$cost) {
				$limit = $height[$key]+$cost;
				if($height[$neighbor] <= $limit) continue;
				$height[$neighbor] = $limit;
				$queue->insert($neighbor,-$limit);
			}
		}
		foreach($paths as $leg=>$path) {
			$points = [];
			foreach($path as $i=>[$x,$z]) $points[] = [$x,$height[$routes[$leg][$i]],$z];
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
				yield from $land->queueSteps(['type'=>'road','material'=>'road','width'=>28.,'shoulder'=>18.,
					'points'=>array_slice($points,$i,97),'handles'=>array_slice($handles,$i,97)]);
				yield;
			}
		}
		$land->commit();
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

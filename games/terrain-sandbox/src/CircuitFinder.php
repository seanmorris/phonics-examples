<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;
use TerrainPhonics\Recipe;

/** Searches a bounded map of the canyon floor for a navigable cycle around high ground. */
final class CircuitFinder
{
	private const OFFSETS = [[1,0],[0,1],[-1,0],[0,-1],[1,1],[-1,1],[-1,-1],[1,-1]];
	private const MAX_GRADE = .42;
	private const HALF_LANE = 7.;
	public const DEFAULTS = [
		'circuitMinLap'=>null, 'circuitMinArea'=>null, 'circuitGradePreference'=>8.,
		'circuitCausewayDepth'=>30., 'circuitStartTolerance'=>.5, 'circuitTurnRadius'=>6.,
	];
	private readonly array $controls;
	private array $heights = [], $routeHeights = [], $open = [];
	private readonly ?float $causewayDeck;
	// A 30 m fill costs as much as another routing cell of level driving.
	private const FILL_COST_SCALE = 30.;
	private array $corridorDistance = [], $fillCost = [];
	/** One local and one regional survey per live landscape. Only completed surveys are shared. */
	private static ?\WeakMap $surveys = null;
	private float $left, $top, $step;
	private int $side;

	public function __construct(
		private readonly Landscape $landscape,
		private readonly float $dryLevel,
		private readonly float $swell,
		private readonly float $minimumDifference = 120.,
		private readonly ?float $startHeight = null,
		bool $causeways = false,
		array $controls = []
	) {
		$this->controls = array_intersect_key($controls,self::DEFAULTS) + self::DEFAULTS;
		$this->causewayDeck = $causeways ? $dryLevel+$swell+1.5 : null;
	}

	/** Automatic minima in search order, shared with the Roads panel. Lengths are meters, areas are square meters. */
	public static function searchWindows(float $worldSize, float $difference, bool $causeways): array
	{
		$half = $worldSize/2-16;
		$radius = min(1408.,$half);
		$step = max(8.,2**floor(log(max(8.,$radius/80),2)));
		$local = ['local'=>['radius'=>$radius,'step'=>$step,
			'minLap'=>$causeways ? 400. : max(180.,$step*12),'minArea'=>4*$step**2]];
		if($half<2400.) return $local;

		$radius = min(6400.,$half);
		$regional = ['regional'=>['radius'=>$radius,'step'=>max(16.,round($radius/134/8)*8),
			'minLap'=>$difference<0. ? 1800. : 3000.,'minArea'=>$difference<0. ? 180000. : 800000.]];
		return abs($difference)<60. ? $local + $regional : $regional + $local;
	}

	/** Give the session a scheduling boundary throughout sampling, graph search and lane validation. */
	public function find(float $x, float $z, GateShapes $shapes, int $laps): \Generator
	{
		$size = $this->landscape->recipe()['worldSize'];
		foreach(self::searchWindows($size,$this->minimumDifference,$this->causewayDeck !== null) as $kind=>$window)
		{
			$circuit = yield from $this->scan($x,$z,$size/2-16,$window,$shapes,$laps,$kind === 'regional');
			if($circuit !== null) return $circuit;
		}
		return null;
	}

	private function scan(float $x, float $z, float $half, array $window, GateShapes $shapes, int $laps, bool $regional): \Generator
	{
		$minimumLap = $this->controls['circuitMinLap'] ?? $window['minLap'];
		$minimumArea = $this->controls['circuitMinArea'] ?? $window['minArea'];
		$this->heights = $this->routeHeights = $this->open = $this->corridorDistance = $this->fillCost = [];
		$lake = $this->minimumDifference<0.;
		$bands = $lake ? ($regional ? [180.,260.,360.] : [50.,90.,160.])
			: ($regional ? [105.,150.,75.,200.,280.,55.,380.] : [6.,10.,14.,20.,26.,38.,65.,95.]);
		if($regional && !$lake && $this->causewayDeck !== null) array_unshift($bands,28.);
		$this->step = $window['step'];
		$radius = $window['radius'];
		$this->side = 2*(int)floor($radius/$this->step)+1;
		$radius = ($this->side-1)*$this->step/2;
		$this->left = max(-$half,min($half-2*$radius,round(($x-$radius)/$this->step)*$this->step));
		$this->top = max(-$half,min($half-2*$radius,round(($z-$radius)/$this->step)*$this->step));
		$total = $this->side**2;
		$nearby = [];
		$dry = $this->dryLevel+$this->swell+.25;
		if($lake) $dry = max($dry,$this->dryLevel-$this->minimumDifference);
		$this->heights = yield from $this->survey($regional);
		for($i = 0; $i<$total; $i++)
		{
			[$px,$pz] = $this->point($i);
			$height = $this->heights[$i];
			$deck = $height;
			if($this->causewayDeck !== null) {
				// Keep the original elevations for finding the enclosed feature. Drive across the proposed deck.
				$deck = $height < $this->dryLevel-$this->controls['circuitCausewayDepth'] ? -INF : max($height,$this->causewayDeck);
				$this->routeHeights[] = $deck;
				if($deck>$height) $this->fillCost[$i] = ($deck-$height)/self::FILL_COST_SCALE;
			}
			if($deck>$dry && hypot($px-$x,$pz-$z)<.45*$radius) $nearby[] = $deck;
			if($i%16 === 0) yield;
		}
		if($this->causewayDeck === null) $this->routeHeights = $this->heights;
		if(!$nearby) return null;
		sort($nearby,SORT_NUMERIC);
		$floor = $nearby[(int)(.06*(count($nearby)-1))];
		$lakes = $lake ? (yield from $this->features($this->dryLevel,$x,$z,$regional,true,$minimumArea)) : [];
		if($lake && !$lakes) return null;
		// Try the low floor first, then a broader elevation range if a saddle separates nearby passages.
		$attempts = 0;
		$dryCircuit = null;
		$car = new Car;
		foreach($bands as $relief)
		{
			$ceiling = $floor+$relief;
			$this->open = array_map(static fn(float $h): bool => $h>$dry && $h<=$ceiling,$this->routeHeights);
			$islands = $lake ? $lakes : (yield from $this->features($ceiling,$x,$z,$regional,false,$minimumArea));
			$forks = [];
			if($regional && $islands)
				[$this->corridorDistance,$forks] = yield from CorridorGraph::trace($this->open,$this->side);
			foreach(array_slice($islands,0,6) as [$island,$peak,,$area])
			{
				foreach($this->portals($island,$x,$z) as [$start,$end,$axis,$cut,$tip,$side])
				{
					if(++$attempts>48) return $dryCircuit;
					$path = yield from $this->route($start,$end,$axis,$cut,$tip,$side);
					if(count($path)<12) continue;
					$points = yield from $this->smooth($path,$x,$z);
					if(count($points)>Circuit::MAX_POINTS || !$this->roundEnough($points)) continue;
					if($regional || $this->causewayDeck !== null)
					{
						$circuit = yield from $this->gradeFeature($points,$forks,$area,$peak,$shapes,$laps,$x,$z,$minimumLap);
						if($circuit?->floodedLevel !== null) return $circuit;
						$dryCircuit ??= $circuit;
						continue;
					}
					$range = yield from $this->lane($points,$dry);
					if($range === null) continue;
					[$low,$high] = $range;
					$reliefFound = $lake ? $this->dryLevel-$low : $peak-$high;
					if(!$this->fitsRelief($reliefFound)) continue;
					$wet = ceil(2*($high+2.+$this->swell))/2;
					$points = yield from $this->startAtHeight($this->landscape,$points,$x,$z);
					if($points === null) continue;
					$circuit = new Circuit($points,$this->dryLevel,$wet<min(10000.,$lake ? INF : $peak-3.) ? $wet : null,
						heightDifference: $reliefFound, startHeight: $this->landscape->height(...$points[0]));
					if($circuit->length<$minimumLap) continue;
					try
					{
						$road = $circuit->course($this->landscape,$shapes,$laps,false);
						[$sx,$sz,$heading] = $circuit->approach();
						if($car->checkedSite($this->landscape,$dry,$sx,$sz,$heading,$road->posts()) === null) continue;
						if($circuit->floodedLevel !== null) $circuit->course($this->landscape,$shapes,$laps,true);
					}
					catch(\RuntimeException) { continue; }
					// This circuit has passed. Move its lap line to a site with room if one is near and passes too.
					if(($restart = $this->clearStart($circuit)) !== null) {
						try
						{
							$moved = new Circuit($restart,$this->dryLevel,$circuit->floodedLevel,
								heightDifference: $reliefFound, startHeight: $this->landscape->height(...$restart[0]));
							$road = $moved->course($this->landscape,$shapes,$laps,false);
							[$sx,$sz,$heading] = $moved->approach();
							if($car->checkedSite($this->landscape,$dry,$sx,$sz,$heading,$road->posts()) !== null) {
								if($moved->floodedLevel !== null) $moved->course($this->landscape,$shapes,$laps,true);
								$circuit = $moved;
							}
						}
						catch(\RuntimeException) {}
					}
					if($circuit->floodedLevel !== null) return $circuit;
					$dryCircuit ??= $circuit;
				}
			}
		}
		return $dryCircuit;
	}

	/** The elevation grid is independent of water, wave height, the chosen feature and the start height. */
	private function survey(bool $regional): \Generator
	{
		self::$surveys ??= new \WeakMap;
		$land = $this->landscape;
		$key = [$land->revision(),$this->left,$this->top,$this->step,$this->side];
		$cached = self::$surveys[$land][(int)$regional] ?? null;
		if($cached !== null && $cached[0] === $key) return $cached[1];

		$parameters = $land->recipe();
		$spacing = $parameters['spacing'];
		$natural = $land->heightEditIndex()->isEmpty() ? new Recipe($parameters) : null;
		$heights = [];
		for($row=0; $row<$this->side; $row++)
		{
			$z = $this->top + $row*$this->step;
			$iz = $z/$spacing;
			$latticeRow = $natural !== null && $iz === floor($iz);
			for($col=0; $col<$this->side; $col++)
			{
				if(($col & 15) === 0) yield;
				$x = $this->left + $col*$this->step;
				$ix = $x/$spacing;
				// A natural lattice node is the recipe itself. These widely spaced probes have no tile locality.
				$heights[] = $latticeRow && $ix === floor($ix) ? $natural->height($ix*$spacing,$iz*$spacing)
					: $land->meshHeight($x,$z,$this->step);
			}
		}
		if($land->revision() === $key[0])
		{
			$windows = self::$surveys[$land] ?? [];
			$windows[(int)$regional] = [$key,$heights];
			self::$surveys[$land] = $windows;
		}
		return $heights;
	}

	/** Find hills enclosed by the search window and rank them by distance from the requested site. */
	private function features(float $threshold, float $x, float $z, bool $regional, bool $lake, float $minimumArea): \Generator
	{
		$seen = [];
		$islands = [];
		$n = $this->side;
		$direction = $lake ? -1. : 1.;
		foreach($this->heights as $i => $height)
		{
			if($i%128 === 0) yield;
			if($direction*($height-$threshold)<=0 || isset($seen[$i])) continue;
			$queue = [$i];
			$seen[$i] = true;
			$peak = $i;
			$boundary = false;
			$near = INF;
			for($j = 0; $j<count($queue); $j++)
			{
				if($j%64 === 0) yield;
				$id = $queue[$j];
				$col = $id%$n;
				$row = intdiv($id,$n);
				$boundary = $boundary || min($col,$row)===0 || max($col,$row)===$n-1;
				if($direction*($this->heights[$id]-$this->heights[$peak])>0) $peak = $id;
				[$px,$pz] = $this->point($id);
				$near = min($near,hypot($x-$px,$z-$pz));
				foreach(array_slice(self::OFFSETS,0,4) as [$dx,$dz])
				{
					$cx = $col+$dx; $cz = $row+$dz;
					if(min($cx,$cz)<0 || max($cx,$cz)>=$n) continue;
					$next = $cz*$n+$cx;
					if(!isset($seen[$next]) && $direction*($this->heights[$next]-$threshold)>0)
					{
						$queue[] = $next;
						$seen[$next] = true;
					}
				}
			}
			$area = count($queue)*$this->step**2;
			if(!$boundary && count($queue)>=4 && $area>=$minimumArea
				&& $direction*($this->heights[$peak]-$threshold)>($regional && !$lake ? 65. : 2.))
				$islands[] = [$peak,$this->heights[$peak],$near,$area];
		}
		usort($islands,static fn(array $a,array $b): int => $a[2]<=>$b[2] ?: $a[0]<=>$b[0]);
		return $islands;
	}

	/** Pick one crossing from each passage across a ray extending out from the hill. */
	private function portals(int $island, float $x, float $z): array
	{
		$n = $this->side;
		$col = $island%$n;
		$row = intdiv($island,$n);
		$portals = [];
		foreach([[0,$row,$col],[1,$col,$row]] as [$axis,$cut,$tip])
		foreach([-1,1] as $side)
		{
			$best = null;
			$score = INF;
			for($along=$tip; $along>=0 && $along<$n; $along+=$side)
			{
				$a = $axis===0 ? $cut*$n+$along : $along*$n+$cut;
				$b = $a+($axis===0 ? $n : 1);
				if($this->edge($a,$b,$this->step))
				{
					[$px,$pz] = $this->point($a);
					$cost = hypot($px-$x,$pz-$z)+4*abs($this->routeHeights[$a]-$this->routeHeights[$b]);
					if($cost<$score) { $best = [$a,$b,$axis,$cut,$tip,$side,$cost]; $score = $cost; }
				}
				elseif($best !== null)
				{
					$portals[] = $best;
					$best = null; $score = INF;
				}
			}
			if($best !== null) $portals[] = $best;
		}
		usort($portals,static fn(array $a,array $b): int => $a[6]<=>$b[6] ?: $a[0]<=>$b[0]);
		return array_slice($portals,0,6);
	}

	private function edge(int $from, int $to, float $distance): bool
	{
		return $this->open[$from] && $this->open[$to]
			&& abs($this->routeHeights[$from]-$this->routeHeights[$to]) <= self::MAX_GRADE*$distance;
	}

	/** Join the two sides of the cut without crossing it again. Closing the gap then wraps the hill. */
	private function route(int $start, int $end, int $axis, int $cut, int $tip, int $side): \Generator
	{
		$n = $this->side;
		$frontier = new \SplPriorityQueue;
		$frontier->insert($start, [0.,-$start]);
		$closed = []; $cost = [$start=>0.]; $previous = [];
		$gradePreference = $this->controls['circuitGradePreference'];
		$goalX = $end%$n; $goalZ = intdiv($end,$n);
		while(!$frontier->isEmpty())
		{
			$id = $frontier->extract();
			if(isset($closed[$id])) continue;
			if($id === $end)
			{
				$path = [$end];
				while($id !== $start) { $id = $previous[$id]; $path[] = $id; }
				return array_reverse($path);
			}
			$closed[$id] = true;
			if(count($closed)%24 === 0) yield;
			$x = $id%$n; $z = intdiv($id,$n);
			foreach(self::OFFSETS as [$dx,$dz])
			{
				$nx = $x+$dx; $nz = $z+$dz;
				if(min($nx,$nz)<0 || max($nx,$nz)>=$n) continue;
				if($axis === 0 ? min($z,$nz)===$cut && $dz!==0 && $side*(($x+$nx)/2-$tip)>=0
					: min($x,$nx)===$cut && $dx!==0 && $side*(($z+$nz)/2-$tip)>=0) continue;
				$next = $nz*$n+$nx;
				$diagonal = $dx!==0 && $dz!==0;
				$distance = $this->step*($diagonal ? M_SQRT2 : 1);
				if(isset($closed[$next]) || !$this->edge($id,$next,$distance)) continue;
				if($diagonal && (!$this->open[$id+$dx] || !$this->open[$id+$dz*$n])) continue;
				$grade = ($this->routeHeights[$next]-$this->routeHeights[$id])/$distance;
				$deviation = $this->corridorDistance ? ($this->corridorDistance[$next] ?? 5) : 0;
				$weight = $cost[$id]+$distance*(1+$gradePreference*$grade**2+.022*$deviation+($this->fillCost[$next] ?? 0.));
				if($weight>=($cost[$next] ?? INF)) continue;
				$cost[$next] = $weight;
				$previous[$next] = $id;
				$frontier->insert($next, [-$weight-$this->step*hypot($goalX-$nx,$goalZ-$nz),-$next]);
			}
		}
		return [];
	}

	/** Put gates downstream of identified forks where they can replace an evenly spaced gate. */
	private function junctionStations(array $points, array $forks): array
	{
		$stations = [];
		foreach($forks as $fork)
		{
			[$x,$z] = $this->point($fork);
			$distance = 0.;
			$nearest = 5*$this->step;
			$station = null;
			foreach($points as $i => [$ax,$az])
			{
				[$bx,$bz] = $points[($i+1)%count($points)];
				$dx = $bx-$ax; $dz = $bz-$az;
				$length = hypot($dx,$dz);
				if($length<1e-6) continue;
				$fraction = max(0.,min(1.,(($x-$ax)*$dx+($z-$az)*$dz)/$length**2));
				$gap = hypot($x-$ax-$fraction*$dx,$z-$az-$fraction*$dz);
				if($gap<$nearest) { $nearest = $gap; $station = $distance+$fraction*$length; }
				$distance += $length;
			}
			if($station !== null && $station<$distance) $stations[] = $station;
		}
		sort($stations,SORT_NUMERIC);
		$spaced = [];
		foreach($stations as $station)
			if(!$spaced || $station-end($spaced)>=2*$this->step) $spaced[] = $station;
		return $spaced;
	}

	private function gradeFeature(array $points, array $forks, float $area, float $peak, GateShapes $shapes, int $laps,
		float $x, float $z, float $minimumLap): \Generator
	{
		$lake = $this->minimumDifference<0.;
		$circuit = new Circuit($points,$this->dryLevel,null);
		if($circuit->length<$minimumLap) return null;
		$roads = yield from CircuitRoad::grade($this->landscape,$circuit,$this->causewayDeck);
		if($roads === null) return null;
		$graded = $this->landscape->fork();
		try
		{
			foreach($roads as $road) { yield from $graded->queueSteps($road); yield; }
			$graded->commit();
			$range = yield from CircuitRoad::clearance($graded,$circuit,$this->dryLevel+$this->swell+.25,count($roads));
			if($range === null) return null;
			[$low,$high] = $range;
			$reliefFound = $lake ? $this->dryLevel-$low : $peak-$high;
			if(!$this->fitsRelief($reliefFound)) return null;
			$points = yield from $this->startAtHeight($graded,$points,$x,$z);
			if($points === null) return null;
			if($this->causewayDeck !== null && !(yield from $this->clearCauseway($graded,count($roads)))) return null;
			$stations = $this->junctionStations($points,$forks);
			$wet = ceil(2*($high+3.+$this->swell))/2;
			$circuit = new Circuit($points,$this->dryLevel,$wet<min(10000.,$lake ? INF : $peak-3.) ? $wet : null,
				$stations,$area,$roads,$reliefFound,$graded->height(...$points[0]));
			$road = yield from $circuit->placing($graded,$shapes,$laps,false);
			[$sx,$sz,$heading] = $circuit->approach();
			if((new Car)->checkedSite($graded,$this->dryLevel+$this->swell,$sx,$sz,$heading,$road->posts()) === null)
				return null;
			$circuit->placed["dry:$laps"] = $road;
			if($circuit->floodedLevel !== null)
				$circuit->placed["flooded:$laps"] = yield from $circuit->placing($graded,$shapes,$laps,true);
			// Everything else is settled, so moving the lap line cannot change which circuit is chosen. If the
			// moved start fails any of the checks the first one passed, keep the first.
			$restart = $this->clearStart($circuit);
			if($restart !== null) {
				try {
					$moved = new Circuit($restart,$this->dryLevel,$circuit->floodedLevel,$this->junctionStations($restart,$forks),
						$area,$roads,$reliefFound,$graded->height(...$restart[0]));
					$road = yield from $moved->placing($graded,$shapes,$laps,false);
					[$sx,$sz,$heading] = $moved->approach();
					if((new Car)->checkedSite($graded,$this->dryLevel+$this->swell,$sx,$sz,$heading,$road->posts()) !== null) {
						$moved->placed["dry:$laps"] = $road;
						if($moved->floodedLevel !== null)
							$moved->placed["flooded:$laps"] = yield from $moved->placing($graded,$shapes,$laps,true);
						$circuit = $moved;
					}
				} catch(\RuntimeException | \InvalidArgumentException) {}
			}
			$circuit->rememberGrading($this->landscape,$graded);
			return $circuit;
		}
		catch(\RuntimeException | \InvalidArgumentException) { return null; }
		finally { $graded->close(); }
	}

	/**
	 * Where to move a finished circuit's lap line so its gate has the room every other gate gets. The search puts
	 * the start at the middle of the stretch nearest the camera, which can be in a chicane. Look up and down the
	 * road from there and take the nearest site with both posts off the pavement and no sharp corner beside it.
	 * A start pinned to a requested elevation stays where it is.
	 * @return list<array{float,float}>|null The loop from its new start, or null when the start already has room
	 * or none is found within 128 m.
	 */
	private function clearStart(Circuit $circuit): ?array
	{
		if($this->startHeight !== null || $circuit->clearSite(0.)) return null;
		for($step = 8.; $step <= min(128.,.2*$circuit->length); $step += 8.) {
			foreach([$step,$circuit->length-$step] as $at) {
				if($circuit->clearSite($at)) return $circuit->startingAt($at);
			}
		}
		return null;
	}

	/** Check the fitted road itself. A curve can cut across deeper water between routing cells. */
	private function clearCauseway(Landscape $graded, int $sectionCount): \Generator
	{
		$crest = $this->dryLevel+$this->swell+.25;
		foreach(array_slice($graded->roads(),-$sectionCount) as $road) {
			$half = $road['width']/2;
			foreach($road['_road']['samples'] as $i=>[$x,,$z,$tx,$tz]) {
				if($i%8 === 0) yield;
				if($this->landscape->height($x,$z) < $this->dryLevel-$this->controls['circuitCausewayDepth']) return false;
				if($graded->height($x+$tz*$half,$z-$tx*$half) <= $crest
					|| $graded->height($x-$tz*$half,$z+$tx*$half) <= $crest) return false;
			}
		}
		return true;
	}

	private function fitsRelief(float $found): bool
	{
		return $this->minimumDifference<0. ? $found<=$this->minimumDifference : $found>=$this->minimumDifference;
	}

	/** Move the start to a straight at the requested elevation while keeping the circuit and its grade. */
	private function startAtHeight(Landscape $land, array $points, float $x, float $z): \Generator
	{
		if($this->startHeight === null) return $points;
		$goal = $this->startHeight;
		$tolerance = $this->controls['circuitStartTolerance'];
		$iterations = $tolerance < self::DEFAULTS['circuitStartTolerance'] ? 52 : 10;
		$best = INF;
		$result = null;
		$n = count($points);
		$car = new Car;
		foreach($points as $i => [$ax,$az])
		{
			if($i%12 === 0) yield;
			[$bx,$bz] = $points[($i+1)%$n];
			$dx = $bx-$ax;
			$dz = $bz-$az;
			$length = hypot($dx,$dz);
			if($length<26.) continue;
			$before = 21./$length;
			$after = 1.-4./$length;
			$h0 = $land->height($ax+$before*$dx,$az+$before*$dz);
			$h1 = $land->height($ax+$after*$dx,$az+$after*$dz);
			if($goal<min($h0,$h1)-$tolerance || $goal>max($h0,$h1)+$tolerance) continue;
			$ascending = $h1>=$h0;
			for($j=0;$j<$iterations;$j++)
			{
				$mid = ($before+$after)/2;
				$height = $land->height($ax+$mid*$dx,$az+$mid*$dz);
				if($j>=9 && abs($height-$goal)<=$tolerance) break;
				if(($height<$goal)===$ascending) $before = $mid;
				else $after = $mid;
			}
			$sx = $ax+$mid*$dx;
			$sz = $az+$mid*$dz;
			$score = hypot($sx-$x,$sz-$z)+60*abs($height-$goal);
			if(abs($height-$goal)>$tolerance || $score>=$best) continue;
			$yaw = atan2($dx,$dz);
			if($car->checkedSite($land,$this->dryLevel+$this->swell,$sx-20*sin($yaw),$sz-20*cos($yaw),$yaw,[]) === null)
				continue;
			$result = [[$sx,$sz],...array_slice($points,$i+1),...array_slice($points,0,$i+1)];
			$best = $score;
		}
		return $result;
	}

	private function point(int $id): array
	{
		return [$this->left+($id%$this->side)*$this->step,$this->top+intdiv($id,$this->side)*$this->step];
	}

	/** Round the grid turns, then put the start near the requested site on a straight. */
	private function smooth(array $path, float $x, float $z): \Generator
	{
		$points = [];
		$count = count($path);
		foreach($path as $i => $id)
		{
			$next = $path[($i+1)%$count];
			$last = $path[($i+$count-1)%$count];
			if($next-$id === $id-$last) continue;
			$points[] = $this->point($id);
		}
		// Scale the existing corner cutback with the requested radius. The segment cap keeps
		// adjacent corners apart on short straights; roundEnough checks the resulting curve.
		$radiusScale = $this->controls['circuitTurnRadius']/self::DEFAULTS['circuitTurnRadius'];
		$cutback = max(3.,$this->step*.24)*$radiusScale;
		for($pass=0;$pass<2;$pass++)
		{
			$rounded = [];
			foreach($points as $i => [$px,$pz])
			{
				[$nx,$nz] = $points[($i+1)%count($points)];
				$share = min(.25,$cutback/hypot($nx-$px,$nz-$pz));
				$rounded[] = [$px+$share*($nx-$px),$pz+$share*($nz-$pz)];
				$rounded[] = [$nx+$share*($px-$nx),$nz+$share*($pz-$nz)];
			}
			$points = $rounded;
		}
		// The original loop begins at a rounded grid corner. Shape its closing edge before selecting a lap line.
		$height=$this->landscape->height(...);
		$allows=function($x,$z,$px,$pz,$nx,$nz) use($height): bool {
			$col=(int)round(($px-$this->left)/$this->step); $row=(int)round(($pz-$this->top)/$this->step);
			if(min($col,$row)<0 || max($col,$row)>=$this->side || !$this->open[$row*$this->side+$col]) return false;
			return RoadSerpentine::fitsGround($height,CircuitRoad::WIDTH/2,$this->dryLevel+$this->swell+.25,$x,$z,$px,$pz,$nx,$nz);
		};
		[$points]=yield from RoadSerpentine::fit([[...$points,$points[0]]],[0],$allows);
		array_pop($points);
		$near = INF; $start = 0;
		foreach($points as $i => [$px,$pz])
		{
			[$nx,$nz] = $points[($i+1)%count($points)];
			$cost = hypot(($px+$nx)/2-$x,($pz+$nz)/2-$z)+max(0.,$this->step-hypot($nx-$px,$nz-$pz));
			if($cost<$near) { $near = $cost; $start = $i; }
		}
		$line = [...array_slice($points,$start),...array_slice($points,0,$start)];
		[$px,$pz] = $line[0]; [$nx,$nz] = $line[1];
		return [[($px+$nx)/2,($pz+$nz)/2],...array_slice($line,1),$line[0]];
	}

	private function roundEnough(array $points): bool
	{
		$count = count($points);
		foreach($points as $i => [$x,$z])
		{
			[$px,$pz] = $points[($i+$count-1)%$count];
			[$nx,$nz] = $points[($i+1)%$count];
			$cross = abs(($x-$px)*($nz-$z)-($z-$pz)*($nx-$x));
			if(hypot($x-$px,$z-$pz)*hypot($nx-$x,$nz-$z)*hypot($nx-$px,$nz-$pz)
				< 2*$this->controls['circuitTurnRadius']*$cross) return false;
		}
		return true;
	}

	/** Check elevation along three lines across the lane and return their height range. */
	private function lane(array $points, float $dry): \Generator
	{
		$highest = -INF;
		$lowest = INF;
		foreach($points as $i => [$x,$z])
		{
			[$nx,$nz] = $points[($i+1)%count($points)];
			$dx = $nx-$x; $dz = $nz-$z;
			$length = hypot($dx,$dz);
			if($length<1e-6) continue;
			$steps = (int)ceil($length/6);
			$acrossX = $dz/$length*self::HALF_LANE;
			$acrossZ = -$dx/$length*self::HALF_LANE;
			$last = null;
			for($j=0;$j<=$steps;$j++)
			{
				if($j%8 === 0) yield;
				$px = $x+$j/$steps*$dx; $pz = $z+$j/$steps*$dz;
				$heights = [];
				foreach([-1,0,1] as $side)
					$heights[] = $this->landscape->height($px+$side*$acrossX,$pz+$side*$acrossZ);
				if(min($heights)<=$dry || max($heights)-min($heights)>2*self::HALF_LANE*.58) return null;
				if($last !== null)
					foreach($heights as $k => $height)
						if(abs($height-$last[$k])>$length/$steps*self::MAX_GRADE) return null;
				$last = $heights;
				$highest = max($highest,...$heights);
				$lowest = min($lowest,...$heights);
			}
		}
		return [$lowest,$highest];
	}
}

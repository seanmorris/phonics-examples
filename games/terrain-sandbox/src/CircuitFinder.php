<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Searches a bounded map of the canyon floor for a navigable cycle around high ground. */
final class CircuitFinder
{
	private const OFFSETS = [[1,0],[0,1],[-1,0],[0,-1],[1,1],[-1,1],[-1,-1],[1,-1]];
	private const MAX_GRADE = .42;
	private const HALF_LANE = 7.;
	private const MIN_TURN_RADIUS = 6.;
	private array $heights = [], $open = [];
	private float $left, $top, $step;
	private int $side;

	public function __construct(
		private readonly Landscape $landscape,
		private readonly float $dryLevel,
		private readonly float $swell
	) {}

	/** Give the session a scheduling boundary throughout sampling, graph search and lane validation. */
	public function find(float $x, float $z, GateShapes $shapes, int $laps): \Generator
	{
		$half = $this->landscape->recipe()['worldSize']/2-16;
		$local = min(1408.,$half);
		$nearby = yield from $this->scan($x,$z,$half,$local,$shapes,$laps);
		if($nearby?->floodedLevel !== null || $local===$half) return $nearby;
		$regional = yield from $this->scan($x,$z,$half,min(5120.,$half),$shapes,$laps);
		return $regional?->floodedLevel !== null ? $regional : ($nearby ?? $regional);
	}

	private function scan(float $x, float $z, float $half, float $radius, GateShapes $shapes, int $laps): \Generator
	{
		$this->heights = $this->open = [];
		$bands = $radius<=1408. ? [6.,10.,14.,20.,26.,38.,65.,95.] : [32.,55.,105.,180.,320.];
		$this->step = max(8., 2**floor(log(max(8.,$radius/80),2)));
		$this->side = 2*(int)floor($radius/$this->step)+1;
		$radius = ($this->side-1)*$this->step/2;
		$this->left = max(-$half,min($half-2*$radius,round(($x-$radius)/$this->step)*$this->step));
		$this->top = max(-$half,min($half-2*$radius,round(($z-$radius)/$this->step)*$this->step));
		$total = $this->side**2;
		$nearby = [];
		$dry = $this->dryLevel+$this->swell+.25;
		for($i = 0; $i<$total; $i++)
		{
			[$px,$pz] = $this->point($i);
			$height = $this->landscape->height($px,$pz);
			$this->heights[] = $height;
			if($height>$dry && hypot($px-$x,$pz-$z)<.45*$radius) $nearby[] = $height;
			if($i%16 === 0) yield;
		}
		if(!$nearby) return null;
		sort($nearby,SORT_NUMERIC);
		$floor = $nearby[(int)(.06*(count($nearby)-1))];
		// Try the low floor first, then a broader elevation range if a saddle separates nearby passages.
		$attempts = 0;
		$dryCircuit = null;
		$car = new Car;
		foreach($bands as $relief)
		{
			$ceiling = $floor+$relief;
			$this->open = array_map(static fn(float $h): bool => $h>$dry && $h<=$ceiling,$this->heights);
			$islands = yield from $this->islands($ceiling,$x,$z);
			foreach(array_slice($islands,0,6) as [$island,$peak])
			{
				foreach($this->portals($island,$x,$z) as [$start,$end,$axis,$cut,$tip,$side])
				{
					if(++$attempts>48) return $dryCircuit;
					$path = yield from $this->route($start,$end,$axis,$cut,$tip,$side);
					if(count($path)<12) continue;
					$points = $this->smooth($path,$x,$z);
					if(count($points)>Circuit::MAX_POINTS || !$this->roundEnough($points)) continue;
					$high = yield from $this->lane($points,$dry);
					if($high === null) continue;
					$wet = ceil(2*($high+2.+$this->swell))/2;
					$circuit = new Circuit($points,$this->dryLevel,$wet<min(10000.,$peak-3.) ? $wet : null);
					if($circuit->length<max(180.,$this->step*12) || $circuit->length>40000.) continue;
					try
					{
						$road = $circuit->course($this->landscape,$shapes,$laps,false);
						[$sx,$sz,$heading] = $circuit->approach();
						if($car->checkedSite($this->landscape,$dry,$sx,$sz,$heading,$road->posts()) === null) continue;
						if($circuit->floodedLevel !== null) $circuit->course($this->landscape,$shapes,$laps,true);
					}
					catch(\RuntimeException) { continue; }
					if($circuit->floodedLevel !== null) return $circuit;
					$dryCircuit ??= $circuit;
				}
			}
		}
		return $dryCircuit;
	}

	/** Find hills enclosed by the search window and rank them by distance from the requested site. */
	private function islands(float $ceiling, float $x, float $z): \Generator
	{
		$seen = [];
		$islands = [];
		$n = $this->side;
		foreach($this->heights as $i => $height)
		{
			if($i%128 === 0) yield;
			if($height<=$ceiling || isset($seen[$i])) continue;
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
				if($this->heights[$id]>$this->heights[$peak]) $peak = $id;
				[$px,$pz] = $this->point($id);
				$near = min($near,hypot($x-$px,$z-$pz));
				foreach(array_slice(self::OFFSETS,0,4) as [$dx,$dz])
				{
					$cx = $col+$dx; $cz = $row+$dz;
					if(min($cx,$cz)<0 || max($cx,$cz)>=$n) continue;
					$next = $cz*$n+$cx;
					if(!isset($seen[$next]) && $this->heights[$next]>$ceiling)
					{
						$queue[] = $next;
						$seen[$next] = true;
					}
				}
			}
			if(!$boundary && count($queue)>=4 && $this->heights[$peak]>$ceiling+2)
				$islands[] = [$peak,$this->heights[$peak],$near];
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
					$cost = hypot($px-$x,$pz-$z)+4*abs($this->heights[$a]-$this->heights[$b]);
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
			&& abs($this->heights[$from]-$this->heights[$to]) <= self::MAX_GRADE*$distance;
	}

	/** Join the two sides of the cut without crossing it again. Closing the gap then wraps the hill. */
	private function route(int $start, int $end, int $axis, int $cut, int $tip, int $side): \Generator
	{
		$n = $this->side;
		$frontier = new \SplPriorityQueue;
		$frontier->insert($start, [0.,-$start]);
		$closed = []; $cost = [$start=>0.]; $previous = [];
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
				$grade = ($this->heights[$next]-$this->heights[$id])/$distance;
				$weight = $cost[$id]+$distance*(1+8*$grade**2);
				if($weight>=($cost[$next] ?? INF)) continue;
				$cost[$next] = $weight;
				$previous[$next] = $id;
				$frontier->insert($next, [-$weight-$this->step*hypot($goalX-$nx,$goalZ-$nz),-$next]);
			}
		}
		return [];
	}

	private function point(int $id): array
	{
		return [$this->left+($id%$this->side)*$this->step,$this->top+intdiv($id,$this->side)*$this->step];
	}

	/** Round the grid turns, then put the start near the requested site on a straight. */
	private function smooth(array $path, float $x, float $z): array
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
		for($pass=0;$pass<2;$pass++)
		{
			$rounded = [];
			foreach($points as $i => [$px,$pz])
			{
				[$nx,$nz] = $points[($i+1)%count($points)];
				$share = min(.25,max(3.,$this->step*.24)/hypot($nx-$px,$nz-$pz));
				$rounded[] = [$px+$share*($nx-$px),$pz+$share*($nz-$pz)];
				$rounded[] = [$nx+$share*($px-$nx),$nz+$share*($pz-$nz)];
			}
			$points = $rounded;
		}
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
				< 2*self::MIN_TURN_RADIUS*$cross) return false;
		}
		return true;
	}

	/** Check elevation along three lines across the lane. Return its highest point for the flooded variant. */
	private function lane(array $points, float $dry): \Generator
	{
		$highest = -INF;
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
			}
		}
		return $highest;
	}
}

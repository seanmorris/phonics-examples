<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\EditIndex;
use TerrainPhonics\Landscape;

/** Connected ground around one depot, discovered on demand and retained between deliveries. */
final class DriveRegion
{
	private const GRADE = .38;
	private const COARSE_STEP = 64., FINE_STEP = 2.;
	private const NEIGHBORS = [[-1,0],[1,0],[0,-1],[0,1]];
	private float $cell, $limit, $baseX, $baseZ;
	private int $radius, $side, $origin;
	private array $nodes = [], $edges = [], $connected = [];
	private array $disconnected = [];
	// Every verified node is queued once. The cursor separates expanded nodes from the depot frontier.
	private array $depotQueue = [];
	private int $expanded = 0, $candidateWork = 0;
	private array $nearbyWalks = [];
	private bool $level, $flatBase;
	private float $dryRelief;
	private EditIndex $edits;

	public static function cellSize(array $recipe, float $cap): float
	{
		$limit = $recipe['worldSize']/2-6;
		$spacing = $recipe['spacing'];
		return max(1.,round(min($cap,max(4.,$limit/12))/$spacing))*$spacing;
	}

	public function __construct(private Landscape $land, private float $water, private array $start, float $maxCell = 192.)
	{
		$recipe = $land->recipe();
		$this->limit = $recipe['worldSize']/2-6;
		$spacing = $recipe['spacing'];
		$this->cell = self::cellSize($recipe,$maxCell);
		$this->baseX = round($start['x']/$spacing)*$spacing;
		$this->baseZ = round($start['z']/$spacing)*$spacing;
		$this->radius = (int)ceil(min(1.4*DeliveryRun::MAX_LEG,2*$this->limit)/$this->cell)+1;
		$this->side = 2*$this->radius+1;
		$this->origin = $this->radius*($this->side+1);
		$this->nodes[$this->origin] = [$start['x'],$start['z'],$start['y']];
		$this->connected[$this->origin] = true;
		$this->depotQueue[] = $this->origin;
		$this->edits = $land->heightEditIndex();
		$bounds = $land->bounds();
		$this->dryRelief = $bounds['minY'] > $water+.12 ? $bounds['maxY']-$bounds['minY'] : INF;
		$this->flatBase = $recipe['heightScale'] == 0 && ($recipe['detailAmplitude'] == 0 || $recipe['detailOctaves'] === 0);
		$this->level = $this->flatBase && $this->edits->isEmpty();
	}

	public function isLevel(): bool { return $this->level; }

	/** On sculpted flat maps, test the depot exits before tracing long sight lines across an island or moat. */
	public function hasDestinations(): \Generator
	{
		if($this->flatBase && !$this->level && $this->expanded === 0) yield from $this->expandDepot();
		return !$this->exhausted() || count($this->depotQueue)>1;
	}

	public function nearest(float $x, float $z, bool $reachableOnly = false): ?int
	{
		$col = (int)round(($x-$this->baseX)/$this->cell);
		$row = (int)round(($z-$this->baseZ)/$this->cell);
		if(max(abs($col),abs($row)) > $this->radius) return null;
		$id = $this->origin+$row*$this->side+$col;
		if($reachableOnly && $this->exhausted() && !isset($this->connected[$id])) return null;
		return $this->point($id) === null ? null : $id;
	}

	/** Coordinates are the exact pickup and edge endpoints, including a depot between lattice sites. */
	public function point(int $id): ?array
	{
		if(array_key_exists($id,$this->nodes)) return $this->nodes[$id];
		$x = $this->baseX+($id%$this->side-$this->radius)*$this->cell;
		$z = $this->baseZ+(intdiv($id,$this->side)-$this->radius)*$this->cell;
		return $this->nodes[$id] = max(abs($x),abs($z)) > $this->limit ? null : [$x,$z,$this->land->height($x,$z)];
	}

	/** A terrain edit can split the car from its depot. Check the actual start of each new search as well. */
	public function containsPosition(float $x, float $z): \Generator
	{
		if($x === $this->start['x'] && $z === $this->start['z']) return true;
		if(max(abs($x),abs($z)) > $this->limit) return false;
		$id = $this->nearest($x,$z);
		if($id === null || !(yield from $this->connect($id))) return false;
		$node = $this->point($id);
		if($this->level || ($x === $node[0] && $z === $node[1])) return true;
		return yield from $this->driveEdge($node,[$x,$z,$this->land->height($x,$z)]);
	}

	/** A coarse flood proposes a route. Check each new span before extending the depot component. */
	public function connect(int $goal, bool $quick = false): \Generator
	{
		if(isset($this->connected[$goal])) return true;
		if(isset($this->disconnected[$goal]) || $this->exhausted()) return false;
		if($this->level)
		{
			if($this->start['y'] <= $this->water+.12) return false;
			$this->connected[$goal] = true;
			$this->depotQueue[] = $goal;
			return true;
		}
		// A first pass lets other bearings compete before committing to repeated detours for this goal.
		$attempts = $quick ? 2 : 8;
		$limit = $quick ? 80 : 480;
		for($attempt=0; $attempt<$attempts; $attempt++)
		{
			$path = yield from $this->route($goal,$limit);
			if($path === null) return false;
			[$id,$next] = $path;
			while($id !== $goal)
			{
				$to = $next[$id];
				if(!(yield from $this->extend($id,$to))) break;
				$id = $to;
			}
			if($id === $goal) return true;
		}
		return false;
	}

	/** Verified cells can grow from either end of a candidate search. Exhaustion rules out all other cells. */
	private function exhausted(): bool { return $this->expanded === count($this->depotQueue); }

	/** Verified bends beyond the confined walks can also supply a hidden box. */
	public function remainingPoints(): \Generator
	{
		foreach($this->nearbyWalks as $walk)
			if(count($walk['seen']) === count($this->depotQueue)) return;
		foreach($this->depotQueue as $i=>$id)
		{
			if(($i & 63) === 0) yield;
			if($id === $this->origin) continue;
			foreach($this->nearbyWalks as $walk) if(isset($walk['seen'][$id])) continue 2;
			yield $this->point($id);
		}
	}

	private function extend(int $from, int $to): \Generator
	{
		if(isset($this->connected[$to])) return true;
		$key = $this->edgeKey($from,$to);
		if(($this->edges[$key] ?? true) === false) return false;
		$b = $this->point($to);
		if($b === null || !(yield from $this->driveEdge($this->point($from),$b)))
		{
			$this->edges[$key] = false;
			return false;
		}
		$this->connected[$to] = true;
		$this->depotQueue[] = $to;
		return true;
	}

	private function expandDepot(): \Generator
	{
		$id = $this->depotQueue[$this->expanded];
		$col = $id%$this->side; $row = intdiv($id,$this->side);
		foreach(self::NEIGHBORS as [$dx,$dz])
		{
			$x = $col+$dx; $z = $row+$dz;
			if(min($x,$z)<0 || max($x,$z)>=$this->side) continue;
			yield;
			yield from $this->extend($id,$z*$this->side+$x);
		}
		$this->expanded++;
	}

	/** Replay discovered sites from the new driver position, then resume the radius-limited walk where it last yielded. */
	public function nearby(float $radius): \Generator
	{
		$key = (string)$radius;
		$this->nearbyWalks[$key] ??= ['queue'=>[$this->origin], 'seen'=>[$this->origin=>true], 'head'=>0, 'direction'=>0];
		$walk = &$this->nearbyWalks[$key];
		for($i=1; $i<count($walk['queue']); $i++) yield $this->point($walk['queue'][$i]);
		while($walk['head'] < count($walk['queue']))
		{
			yield;
			$id = $walk['queue'][$walk['head']];
			[$dx,$dz] = self::NEIGHBORS[$walk['direction']];
			$x = $id%$this->side+$dx;
			$z = intdiv($id,$this->side)+$dz;
			$n = $z*$this->side+$x;
			$added = min($x,$z)>=0 && max($x,$z)<$this->side && !isset($walk['seen'][$n])
				&& hypot($x-$this->radius,$z-$this->radius)*$this->cell <= $radius
				&& (yield from $this->extend($id,$n));
			if(++$walk['direction'] === count(self::NEIGHBORS))
			{
				$walk['direction'] = 0;
				$walk['head']++;
			}
			if($added)
			{
				$walk['seen'][$n] = true;
				$walk['queue'][] = $n;
				yield $this->point($n);
			}
		}
	}

	private function edgeKey(int $a, int $b): int
	{
		return min($a,$b)*2+(int)(abs($a-$b)>1);
	}

	/** Search back toward the depot until the flood touches any previously verified cell. */
	private function route(int $goal, int $limit): \Generator
	{
		$queue = new \SplPriorityQueue;
		$queue->insert($goal,0.);
		$seen = [$goal=>true];
		$next = [];
		for($work=0; !$queue->isEmpty() && $work<$limit; $work++)
		{
			// Give the depot a turn even if successive mainland searches hit their work cap.
			if(++$this->candidateWork >= 64)
			{
				$this->candidateWork = 0;
				yield from $this->expandDepot();
				if(isset($this->connected[$goal])) return [$goal,[]];
				if($this->exhausted()) return null;
			}
			$id = $queue->extract();
			if(isset($this->connected[$id])) return [$id,$next];
			$col = $id%$this->side; $row = intdiv($id,$this->side);
			foreach(self::NEIGHBORS as [$dx,$dz])
			{
				$x = $col+$dx; $z = $row+$dz;
				if(min($x,$z)<0 || max($x,$z)>=$this->side) continue;
				$n = $z*$this->side+$x;
				if(isset($seen[$n])) continue;
				$key = $this->edgeKey($id,$n);
				if(!isset($this->edges[$key]))
				{
					yield;
					$end = $this->point($n);
					$this->edges[$key] = $end !== null && (yield from $this->coarseEdge($this->point($id),$end));
				}
				if(!$this->edges[$key]) continue;
				$seen[$n] = true;
				$next[$n] = $id;
				if(isset($this->connected[$n])) return [$n,$next];
				$queue->insert($n,-hypot($x-$this->radius,$z-$this->radius));
			}
			if(($work & 7) === 0) yield;
		}
		// Only a fully exhausted flood proves an island is separate. A work-limited search can retry.
		if($queue->isEmpty()) $this->disconnected += $seen;
		return null;
	}

	private function coarseEdge(array $a, array $b): \Generator
	{
		[$x,$z,$h] = $a;
		$dx = $b[0]-$x; $dz = $b[1]-$z;
		$length = hypot($dx,$dz);
		if(min($h,$b[2]) <= $this->water+.12 || abs($b[2]-$h) > self::GRADE*$length) return false;
		$n = max(1,(int)ceil($length/self::COARSE_STEP));
		$rise = self::GRADE*$length/$n;
		for($i=1; $i<$n; $i++)
		{
			yield;
			$y = $this->land->height($x+$i*$dx/$n,$z+$i*$dz/$n);
			if($y <= $this->water+.12 || abs($y-$h)>$rise) return false;
			$h = $y;
		}
		return abs($b[2]-$h) <= $rise;
	}

	/** Three ground heights across the car, one metre to either side of its centre. */
	private function footprint(float $x, float $z, float $sideX, float $sideZ): array
	{
		return [$this->land->height($x-$sideX,$z-$sideZ),$this->land->height($x,$z),
			$this->land->height($x+$sideX,$z+$sideZ)];
	}

	private function supports(array $section): bool
	{
		[$left,$middle,$right] = $section;
		return min($section) > $this->water+.12 && abs($right-$left) <= 2*self::GRADE
			&& abs($middle-($left+$right)/2) <= .30;
	}

	private static function rise(array $a, array $b): float
	{
		return max(abs($a[0]-$b[0]),abs($a[1]-$b[1]),abs($a[2]-$b[2]));
	}

	/** Check both tyre tracks and the centre at driving resolution wherever relief could exceed a driving limit. */
	private function driveEdge(array $a, array $b): \Generator
	{
		$dx = $b[0]-$a[0]; $dz = $b[1]-$a[1]; $length = hypot($dx,$dz);
		if(min($a[2],$b[2]) <= $this->water+.12 || abs($b[2]-$a[2]) > self::GRADE*$length) return false;
		if($length == 0.) return true;
		$steps = (int)ceil($length/self::FINE_STEP);
		$limit = self::GRADE*$length/$steps;
		// The full height interval bounds every rise, side slope and centre deviation in a dry swath.
		if($this->dryRelief <= min(.30,$limit)) return true;
		if($this->flatBase && !$this->edits->intersect([
			min($a[0],$b[0])-1,min($a[1],$b[1])-1,max($a[0],$b[0])+1,max($a[1],$b[1])+1,
		])) return true;
		$sideX = $dz/$length; $sideZ = -$dx/$length;
		$previous = $this->footprint($a[0],$a[1],$sideX,$sideZ);
		if(!$this->supports($previous)) return false;
		for($i=1; $i<=$steps; $i++)
		{
			yield;
			$section = $this->footprint($a[0]+$dx*$i/$steps,$a[1]+$dz*$i/$steps,$sideX,$sideZ);
			if(!$this->supports($section) || self::rise($previous,$section)>$limit) return false;
			$previous = $section;
		}
		return true;
	}
}

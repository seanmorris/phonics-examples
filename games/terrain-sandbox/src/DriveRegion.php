<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Connected ground around one depot, discovered on demand and retained between deliveries. */
final class DriveRegion
{
	private const GRADE = .38;
	private const NEIGHBORS = [[-1,0],[1,0],[0,-1],[0,1]];
	private float $cell, $limit, $baseX, $baseZ;
	private int $radius, $side, $origin;
	private array $nodes = [], $edges = [], $connected = [];
	private bool $level;

	public function __construct(private Landscape $land, private float $water, private array $start)
	{
		$recipe = $land->recipe();
		$this->limit = $recipe['worldSize']/2-6;
		$spacing = $recipe['spacing'];
		$this->cell = max(1.,round(min(96.,max(4.,$this->limit/12))/$spacing))*$spacing;
		$this->baseX = round($start['x']/$spacing)*$spacing;
		$this->baseZ = round($start['z']/$spacing)*$spacing;
		$this->radius = (int)ceil(min(1.4*DeliveryRun::MAX_LEG,2*$this->limit)/$this->cell)+1;
		$this->side = 2*$this->radius+1;
		$this->origin = $this->radius*($this->side+1);
		$this->nodes[$this->origin] = [$start['x'],$start['z'],$start['y']];
		$this->connected[$this->origin] = true;
		$this->level = $recipe['heightScale'] == 0 && $recipe['detailAmplitude'] == 0 && $land->revision() === 0;
	}

	public function isLevel(): bool { return $this->level; }

	public function nearest(float $x, float $z): ?int
	{
		$col = (int)round(($x-$this->baseX)/$this->cell);
		$row = (int)round(($z-$this->baseZ)/$this->cell);
		if(max(abs($col),abs($row)) > $this->radius) return null;
		$id = $this->origin+$row*$this->side+$col;
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

	/** A coarse flood proposes a route. Only edges checked at driving scale extend the depot's component. */
	public function connect(int $goal): \Generator
	{
		if(isset($this->connected[$goal])) return true;
		if($this->level) return $this->start['y'] > $this->water+.12;
		for($attempt=0; $attempt<8; $attempt++)
		{
			$path = yield from $this->route($goal);
			if($path === null) return false;
			[$id,$next] = $path;
			while($id !== $goal)
			{
				$to = $next[$id];
				$key = $this->edgeKey($id,$to);
				if(!(yield from $this->driveEdge($this->point($id),$this->point($to))))
				{
					$this->edges[$key] = false;
					break;
				}
				$this->connected[$to] = true;
				$id = $to;
			}
			if($id === $goal) return true;
		}
		return false;
	}

	private function edgeKey(int $a, int $b): int
	{
		return min($a,$b)*2+(int)(abs($a-$b)>1);
	}

	/** Search back toward the depot until the flood touches any previously verified cell. */
	private function route(int $goal): \Generator
	{
		$queue = new \SplPriorityQueue;
		$queue->insert($goal,0.);
		$seen = [$goal=>true];
		$next = [];
		for($work=0; !$queue->isEmpty() && $work<480; $work++)
		{
			$id = $queue->extract();
			if(isset($this->connected[$id])) return [$id,$next];
			$col = $id%$this->side; $row = intdiv($id,$this->side);
			foreach(self::NEIGHBORS as [$dx,$dz])
			{
				$x = $col+$dx; $z = $row+$dz;
				if(min($x,$z)<0 || max($x,$z)>=$this->side) continue;
				$n = $z*$this->side+$x;
				if(isset($seen[$n])) continue;
				$end = $this->point($n);
				if($end === null) continue;
				$key = $this->edgeKey($id,$n);
				$this->edges[$key] ??= yield from $this->coarseEdge($this->point($id),$end);
				if(!$this->edges[$key]) continue;
				$seen[$n] = true;
				$next[$n] = $id;
				if(isset($this->connected[$n])) return [$n,$next];
				$queue->insert($n,-hypot($x-$this->radius,$z-$this->radius));
			}
			yield;
		}
		return null;
	}

	private function coarseEdge(array $a, array $b): \Generator
	{
		[$x,$z,$h] = $a;
		$dx = $b[0]-$x; $dz = $b[1]-$z;
		$n = max(1,(int)ceil(hypot($dx,$dz)/24.));
		$rise = self::GRADE*hypot($dx,$dz)/$n;
		for($i=1; $i<=$n; $i++)
		{
			yield;
			$y = $i === $n ? $b[2] : $this->land->height($x+$i*$dx/$n,$z+$i*$dz/$n);
			if($y <= $this->water+.12 || $h <= $this->water+.12 || abs($y-$h)>$rise) return false;
			$h = $y;
		}
		return true;
	}

	/** Check longitudinal grade in both directions, cross-slope, and dry ground across the car's footprint. */
	private function driveEdge(array $a, array $b): \Generator
	{
		$dx = $b[0]-$a[0]; $dz = $b[1]-$a[1]; $length = hypot($dx,$dz);
		$steps = max(1,(int)ceil($length/2.));
		$rise = self::GRADE*$length/$steps;
		// A metre either side encloses the tyre tracks and the body's .97 metre half-width.
		$sideX = $dz/$length; $sideZ = -$dx/$length;
		$previous = null;
		for($i=0; $i<=$steps; $i++)
		{
			yield;
			$x = $a[0]+$dx*$i/$steps; $z = $a[1]+$dz*$i/$steps;
			$left = $this->land->height($x-$sideX,$z-$sideZ);
			$right = $this->land->height($x+$sideX,$z+$sideZ);
			$middle = $this->land->height($x,$z);
			if(min($left,$middle,$right) <= $this->water+.12 || abs($right-$left)>2*self::GRADE
				|| abs($middle-($left+$right)/2)>.30) return false;
			if($previous !== null && max(abs($left-$previous[0]),abs($middle-$previous[1]),abs($right-$previous[2]))>$rise)
				return false;
			$previous = [$left,$middle,$right];
		}
		return true;
	}
}

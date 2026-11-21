<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\EditIndex;
use TerrainPhonics\Landscape;

/** Connected ground around one depot, discovered on demand and retained between deliveries. */
final class DriveRegion
{
	private const GRADE = .38;
	// Use the footprint at driving resolution only where the broad samples approach a driving limit.
	private const REFINE_GRADE = .16;
	private const COARSE_STEP = 64., SCREEN_STEP = 32., FINE_STEP = 2.;
	private const NEIGHBORS = [[-1,0],[1,0],[0,-1],[0,1]];
	private float $cell, $limit, $baseX, $baseZ;
	private int $radius, $side, $origin;
	private array $nodes = [], $edges = [], $connected = [];
	private array $disconnected = [];
	private bool $level;
	private EditIndex $edits;
	private bool $fineNoise;

	public function __construct(private Landscape $land, private float $water, private array $start, float $maxCell = 192.)
	{
		$recipe = $land->recipe();
		$this->limit = $recipe['worldSize']/2-6;
		$spacing = $recipe['spacing'];
		$this->cell = max(1.,round(min($maxCell,max(4.,$this->limit/12))/$spacing))*$spacing;
		$this->baseX = round($start['x']/$spacing)*$spacing;
		$this->baseZ = round($start['z']/$spacing)*$spacing;
		$this->radius = (int)ceil(min(1.4*DeliveryRun::MAX_LEG,2*$this->limit)/$this->cell)+1;
		$this->side = 2*$this->radius+1;
		$this->origin = $this->radius*($this->side+1);
		$this->nodes[$this->origin] = [$start['x'],$start['z'],$start['y']];
		$this->connected[$this->origin] = true;
		$this->edits = $land->editIndex();
		// A tall, short-wavelength feature can hide entirely between broad probes.
		$frequency = 8*$recipe['baseFrequency']; $amplitude = $recipe['detailAmplitude'];
		$this->fineNoise = $recipe['heightScale'] > 0 && $recipe['baseFrequency']*self::SCREEN_STEP >= .5;
		for($i=0; $i<$recipe['detailOctaves'] && !$this->fineNoise; $i++)
		{
			$this->fineNoise = $frequency*self::SCREEN_STEP >= .5 && $amplitude >= .30
				&& $amplitude*$frequency >= self::REFINE_GRADE;
			$amplitude *= $recipe['persistence']; $frequency *= $recipe['lacunarity'];
		}
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

	/** A coarse flood proposes a route. Check each new span before extending the depot component. */
	public function connect(int $goal): \Generator
	{
		if(isset($this->connected[$goal])) return true;
		if(isset($this->disconnected[$goal])) return false;
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

	/** Flood outward from the depot when point samples have missed a pocket or a winding strip of road. */
	public function nearby(float $radius): \Generator
	{
		$queue = [$this->origin];
		$seen = [$this->origin=>true];
		for($i=0; $i<count($queue); $i++)
		{
			$id = $queue[$i];
			$col = $id%$this->side; $row = intdiv($id,$this->side);
			foreach(self::NEIGHBORS as [$dx,$dz])
			{
				yield;
				$x = $col+$dx; $z = $row+$dz;
				if(hypot($x-$this->radius,$z-$this->radius)*$this->cell > $radius
					|| min($x,$z)<0 || max($x,$z)>=$this->side) continue;
				$n = $z*$this->side+$x;
				if(isset($seen[$n])) continue;
				$point = $this->point($n);
				if($point === null) continue;
				if(!isset($this->connected[$n]))
				{
					$key = $this->edgeKey($id,$n);
					if(($this->edges[$key] ?? true) === false) continue;
					if(!$this->level && !(yield from $this->driveEdge($this->point($id),$point)))
					{
						$this->edges[$key] = false;
						continue;
					}
					$this->connected[$n] = true;
				}
				$seen[$n] = true;
				$queue[] = $n;
				yield $point;
			}
		}
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

	/** Recheck the interior of one uncertain span, reusing its two sampled ends. */
	private function fineSpan(float $x, float $z, float $dx, float $dz, float $length,
		float $sideX, float $sideZ, array $previous, array $last): \Generator
	{
		$steps = (int)ceil($length/self::FINE_STEP);
		$limit = self::GRADE*$length/$steps;
		for($i=1; $i<$steps; $i++)
		{
			yield;
			$section = $this->footprint($x+$dx*$i/$steps,$z+$dz*$i/$steps,$sideX,$sideZ);
			if(!$this->supports($section) || self::rise($previous,$section) > $limit) return false;
			$previous = $section;
		}
		return self::rise($previous,$last) <= $limit;
	}

	/** Screen a proposed route at wide intervals. Recheck steep, wet or edited spans at tyre scale. */
	private function driveEdge(array $a, array $b): \Generator
	{
		$dx = $b[0]-$a[0]; $dz = $b[1]-$a[1]; $length = hypot($dx,$dz);
		if($length == 0.) return $a[2] > $this->water+.12;
		$sideX = $dz/$length; $sideZ = -$dx/$length;
		$steps = max(1,(int)ceil($length/self::SCREEN_STEP));
		$span = $length/$steps;
		$x = $a[0]; $z = $a[1];
		$previous = $this->footprint($x,$z,$sideX,$sideZ);
		if(!$this->supports($previous)) return false;
		for($i=1; $i<=$steps; $i++)
		{
			yield;
			$nx = $a[0]+$dx*$i/$steps; $nz = $a[1]+$dz*$i/$steps;
			$section = $this->footprint($nx,$nz,$sideX,$sideZ);
			$rise = self::rise($previous,$section);
			if(!$this->supports($section) || $rise > self::GRADE*$span) return false;
			if($length <= self::FINE_STEP) return true;
			$nearLimit = $this->fineNoise || $rise > self::REFINE_GRADE*$span
				|| max(abs($previous[2]-$previous[0]),abs($section[2]-$section[0])) > 2*self::REFINE_GRADE
				|| min(min($previous),min($section)) <= $this->water+.12+self::GRADE*$span/2
				|| $this->edits->intersect([min($x,$nx)-1,min($z,$nz)-1,max($x,$nx)+1,max($z,$nz)+1]);
			if(!$nearLimit)
			{
				// Test the midpoint and a metre inward from both ends. Opposing slopes can average to zero.
				$middle = $this->land->height(($x+$nx)/2,($z+$nz)/2);
				$nearLimit = max(abs($middle-$previous[1]),abs($section[1]-$middle)) > self::REFINE_GRADE*$span/2
					|| abs($this->land->height($x-$sideZ,$z+$sideX)-$previous[1]) > self::REFINE_GRADE
					|| abs($this->land->height($nx+$sideZ,$nz-$sideX)-$section[1]) > self::REFINE_GRADE;
			}
			if($nearLimit && !(yield from $this->fineSpan($x,$z,$nx-$x,$nz-$z,$span,$sideX,$sideZ,$previous,$section)))
				return false;
			$x = $nx; $z = $nz; $previous = $section;
		}
		return true;
	}
}

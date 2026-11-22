<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** A closed horizontal racing line, shared by the road gates and floating arches. */
final class Circuit
{
	public const MAX_POINTS = 1024;
	public readonly float $length;
	private ?array $gatePlan = null;

	/** @param list<array{float,float}> $points Ordered X/Z points without a duplicate closing point. */
	public function __construct(
		public readonly array $points,
		public readonly float $dryLevel,
		public readonly ?float $floodedLevel,
		public readonly array $junctions = [],
		public readonly ?float $featureArea = null,
		public readonly array $road = [],
		public readonly ?float $heightDifference = null,
		public readonly ?float $startHeight = null
	)
	{
		$length = 0.0;
		foreach($points as $i => [$x,$z])
		{
			[$nx,$nz] = $points[($i+1)%count($points)];
			$length += hypot($nx-$x,$nz-$z);
		}
		$this->length = $length;
	}

	/** @return list<array{float,float,float}> X, Z and the heading through each gate in race order. */
	public function gates(): array
	{
		if($this->gatePlan !== null) return $this->gatePlan;
		$count = min(Course::MAX_CHECKPOINTS+1, max(8, (int)ceil($this->length/240)));
		$spacing = $this->length/$count;
		$stations = [0.];
		for($i = 1; $i<$count; $i++)
		{
			$station = $i*$spacing;
			$best = $station;
			$gap = .3*$spacing;
			foreach($this->junctions as $junction)
			{
				$after = $junction+30;
				if(abs($after-$station)>=$gap) continue;
				$best = $after;
				$gap = abs($after-$station);
			}
			$stations[] = $best;
		}
		return $this->gatePlan = $this->stations($stations);
	}

	/** Sample X, Z and heading at ordered distances along the centerline. */
	public function stations(array $stations): array
	{
		$result = [];
		$distance = 0.0;
		foreach($this->points as $i => [$x,$z])
		{
			[$nx,$nz] = $this->points[($i+1)%count($this->points)];
			$dx = $nx-$x;
			$dz = $nz-$z;
			$segment = hypot($dx,$dz);
			if($segment === 0.0) continue;
			while(isset($stations[count($result)]) && $stations[count($result)] < $distance+$segment)
			{
				$fraction = ($stations[count($result)]-$distance)/$segment;
				$result[] = [$x+$fraction*$dx,$z+$fraction*$dz,atan2($dx,$dz)];
			}
			$distance += $segment;
		}
		return $result;
	}

	/** Place the circuit's gates in one go; placing() spreads the same work out. */
	public function course(Landscape $landscape, GateShapes $shapes, int $laps, bool $flooded, ?float $legacyWidth = null): Course
	{
		$placing = $this->placing($landscape, $shapes, $laps, $flooded, $legacyWidth);
		foreach($placing as $_) {}
		return $placing->getReturn();
	}

	/**
	 * Courses the search placed on its graded copy, keyed by variant and laps, so applying the circuit can take them
	 * rather than place every gate again.
	 */
	public array $placed = [];

	/**
	 * Place the circuit's gates one at a time, yielding after each, so a search or a frame can pause between them.
	 * Placing a graded circuit's gates took 0.7 s in one step.
	 * @return \Generator Returns the Course.
	 */
	public function placing(Landscape $landscape, GateShapes $shapes, int $laps, bool $flooded, ?float $legacyWidth = null): \Generator
	{
		if($flooded && $this->floodedLevel === null) throw new \RuntimeException('This circuit has no flooded variant.');
		$course = new Course($shapes);
		$ground = $landscape->height(...);
		$kind = $flooded ? 'water' : 'road';
		$width = $this->featureArea === null ? null : ($flooded ? 24. : $legacyWidth);
		$height = $flooded ? null : 2 * $shapes->kind('road')['size']['default'][1];
		foreach($this->gates() as $i => [$x,$z,$yaw])
		{
			if(!$flooded && $this->featureArea !== null && $legacyWidth === null)
				[$x,$z,$width] = CircuitRoad::gateSpan($landscape,$x,$z,$yaw);
			$size = $shapes->fit($kind,$width,$height);
			$role = $i === 0 ? 'start' : 'checkpoint';
			$base = $flooded ? $this->floodedLevel : $ground($x,$z);
			// High ground outside the deck must not lift the banner off a road that cuts into a wall.
			$deck = (CircuitRoad::WIDTH/2 + 1)**2;
			$fit = $flooded ? $ground : static fn(float $px, float $pz): float
				=> ($px-$x)**2 + ($pz-$z)**2 <= $deck ? $ground($px,$pz) : min($ground($px,$pz), $base);
			$placed = $course->place($role, $kind, $x, $base, $z, $yaw, $laps, $size['width'],$size['height'],$fit);
			if($placed['refused'] !== null) throw new \RangeException('The circuit needs more room between its gates.');
			yield;
		}
		return $course;
	}

	/** A boarding point upstream of the start gate. */
	public function approach(): array
	{
		[$x,$z] = $this->points[0];
		[$nx,$nz] = $this->points[1];
		$yaw = atan2($nx-$x,$nz-$z);
		$remaining = 20.;
		for($i=count($this->points)-1;$i>=0;$i--)
		{
			[$px,$pz] = $this->points[$i];
			$length = hypot($px-$x,$pz-$z);
			if($length >= $remaining)
				return [$x+($px-$x)*$remaining/$length,$z+($pz-$z)*$remaining/$length,$yaw];
			$remaining -= $length;
			[$x,$z] = [$px,$pz];
		}
		return [$x,$z,$yaw];
	}

	public function export(): array
	{
		$record = ['points'=>$this->points, 'dryLevel'=>$this->dryLevel, 'floodedLevel'=>$this->floodedLevel];
		if($this->featureArea !== null)
			$record += ['featureArea'=>$this->featureArea, 'junctions'=>$this->junctions];
		if($this->heightDifference !== null) $record['heightDifference'] = $this->heightDifference;
		if($this->startHeight !== null) $record['startHeight'] = $this->startHeight;
		return $record;
	}

	public static function import(mixed $record, float $half): self
	{
		$invalid = static fn() => new \InvalidArgumentException('Invalid saved circuit.');
		$number = static fn(mixed $value): bool => (is_float($value) || is_int($value)) && is_finite((float)$value);
		if(!is_array($record) || array_diff(array_keys($record),
			['points','dryLevel','floodedLevel','junctions','featureArea','heightDifference','startHeight'])) throw $invalid();
		$points = $record['points'] ?? [];
		$dry = $record['dryLevel'] ?? null;
		$wet = $record['floodedLevel'] ?? null;
		if(!is_array($points) || !array_is_list($points) || count($points)<3 || count($points)>self::MAX_POINTS
			|| !$number($dry) || abs($dry)>10000 || $wet !== null && (!$number($wet) || $wet<=$dry || $wet>10000)) throw $invalid();
		foreach($points as $point)
			if(!is_array($point) || !array_is_list($point) || count($point)!==2 || !$number($point[0]) || !$number($point[1])
				|| max(abs($point[0]),abs($point[1]))>$half) throw $invalid();
		$area = $record['featureArea'] ?? null;
		$junctions = $record['junctions'] ?? [];
		$relief = $record['heightDifference'] ?? null;
		$startHeight = $record['startHeight'] ?? null;
		if($area !== null && (!$number($area) || $area<=0 || $area>(2*$half)**2)
			|| !is_array($junctions) || !array_is_list($junctions) || count($junctions)>self::MAX_POINTS
			|| $area === null && $junctions || $relief !== null && !$number($relief)
			|| $startHeight !== null && !$number($startHeight)) throw $invalid();
		$circuit = new self($points, (float)$dry, $wet === null ? null : (float)$wet, $junctions, $area, [], $relief,$startHeight);
		if($circuit->length<120 || $circuit->length>40000) throw $invalid();
		foreach($junctions as $junction)
			if(!$number($junction) || $junction<0 || $junction>=$circuit->length) throw $invalid();
		return $circuit;
	}
}

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
		public readonly ?float $floodedLevel
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
		$gates = [];
		$distance = 0.0;
		foreach($this->points as $i => [$x,$z])
		{
			[$nx,$nz] = $this->points[($i+1)%count($this->points)];
			$dx = $nx-$x;
			$dz = $nz-$z;
			$segment = hypot($dx,$dz);
			if($segment === 0.0) continue;
			while(count($gates)<$count && count($gates)*$spacing < $distance+$segment)
			{
				$fraction = (count($gates)*$spacing-$distance)/$segment;
				$gates[] = [$x+$fraction*$dx,$z+$fraction*$dz,atan2($dx,$dz)];
			}
			$distance += $segment;
		}
		return $this->gatePlan = $gates;
	}

	public function course(Landscape $landscape, GateShapes $shapes, int $laps, bool $flooded): Course
	{
		if($flooded && $this->floodedLevel === null) throw new \RuntimeException('This circuit has no flooded variant.');
		$course = new Course($shapes);
		$ground = $landscape->height(...);
		foreach($this->gates() as $i => [$x,$z,$yaw])
		{
			$role = $i === 0 ? 'start' : 'checkpoint';
			$kind = $flooded ? 'water' : 'road';
			$placed = $course->place($role, $kind, $x, $flooded ? $this->floodedLevel : $ground($x,$z), $z, $yaw, $laps,
				ground: $ground);
			if($placed['refused'] !== null) throw new \RuntimeException('The circuit needs more room between its gates.');
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
		return ['points'=>$this->points, 'dryLevel'=>$this->dryLevel, 'floodedLevel'=>$this->floodedLevel];
	}

	public static function import(mixed $record, float $half): self
	{
		$invalid = static fn() => new \InvalidArgumentException('Invalid saved circuit.');
		$number = static fn(mixed $value): bool => (is_float($value) || is_int($value)) && is_finite((float)$value);
		if(!is_array($record) || array_diff(array_keys($record), ['points','dryLevel','floodedLevel'])) throw $invalid();
		$points = $record['points'] ?? [];
		$dry = $record['dryLevel'] ?? null;
		$wet = $record['floodedLevel'] ?? null;
		if(!is_array($points) || !array_is_list($points) || count($points)<3 || count($points)>self::MAX_POINTS
			|| !$number($dry) || abs($dry)>10000 || $wet !== null && (!$number($wet) || $wet<=$dry || $wet>10000)) throw $invalid();
		foreach($points as $point)
			if(!is_array($point) || !array_is_list($point) || count($point)!==2 || !$number($point[0]) || !$number($point[1])
				|| max(abs($point[0]),abs($point[1]))>$half) throw $invalid();
		$circuit = new self($points, (float)$dry, $wet === null ? null : (float)$wet);
		if($circuit->length<120 || $circuit->length>40000) throw $invalid();
		return $circuit;
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** A closed horizontal racing line, shared by the road gates and floating arches. */
final class Circuit
{
	public const MIN_LENGTH = 120.;
	public const MAX_POINTS = 1024;
	private const MIN_GATE_SPACING = 150 * DisplayUnits::FOOT;
	private const MIN_WAYPOINT_SPACING = 100 * DisplayUnits::FOOT;
	public readonly float $length;
	private array $vertexStations = [];
	private ?array $stationDistances = null;
	private array $forkStations = [];
	private ?array $waypoints = null;
	private ?array $gatePlan = null;
	/** Guidance points in travel order for each numbered gate's approach, with zero for the lap line. */
	private array $guideLegs = [];

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
			$this->vertexStations[] = $length;
			[$nx,$nz] = $points[($i+1)%count($points)];
			$length += hypot($nx-$x,$nz-$z);
		}
		$this->length = $length;
	}

	/** Distances along the former full gate layout, including its adjustments for corridor forks. */
	private function waypointStations(): array
	{
		if($this->stationDistances !== null) return $this->stationDistances;
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
				$this->forkStations[$i] = true;
			}
			$stations[] = $best;
		}
		return $this->stationDistances = $stations;
	}

	/** Original route stations, used as waypoint anchors and to validate historical saves. */
	public function waypoints(): array
	{
		return $this->waypoints ??= $this->stations($this->waypointStations());
	}

	/** Keep roughly one station in three. Prefer a nearby fork and leave room either side of the lap line. */
	public function gateWaypointIndices(): array
	{
		if($this->length <= 0.) return [];
		$stations = $this->waypointStations();
		$count = count($stations);
		$gates = max(1, min((int)round($count/3), (int)floor($this->length/self::MIN_GATE_SPACING)));
		$spacing = $this->length/$gates;
		$indices = [0];
		$last = 0.;
		for($i = 1; $i < $gates; $i++) {
			$best = null; $bestGap = INF; $bestFork = false;
			foreach($stations as $index=>$at) {
				$gap = abs($at-$i*$spacing);
				if($gap > .45*$spacing || $at-$last < self::MIN_GATE_SPACING || $this->length-$at < self::MIN_GATE_SPACING) continue;
				$fork = isset($this->forkStations[$index]);
				if(($bestFork && !$fork) || ($fork === $bestFork && $gap >= $bestGap)) continue;
				$best = $index; $bestGap = $gap; $bestFork = $fork;
			}
			if($best === null) continue;
			$indices[] = $best;
			$last = $stations[$best];
		}
		return $indices;
	}

	/** @return list<array{float,float,float}> X, Z and the heading through each scored gate in race order. */
	public function gates(): array
	{
		if($this->gatePlan !== null) return $this->gatePlan;
		$points = $this->waypoints();
		return $this->gatePlan = array_map(static fn(int $i): array => $points[$i], $this->gateWaypointIndices());
	}

	private function guideLeg(int $nextGate): array
	{
		$indices = $this->gateWaypointIndices();
		$stations = $this->waypointStations();
		$first = $indices[$nextGate === 0 ? count($indices)-1 : $nextGate-1];
		$end = $nextGate === 0 ? count($stations) : $indices[$nextGate];
		$stations[] = $this->length;
		$from = $stations[$first]; $to = $stations[$end];
		$points = $this->waypoints();
		$line = [[$from, $points[$first][0], $points[$first][1]]];
		foreach($this->vertexStations as $i=>$at) {
			if($at >= $to) break;
			if($at > $from) $line[] = [$at, ...$this->points[$i]];
		}
		$finish = $points[$indices[$nextGate]];
		$line[] = [$to, $finish[0], $finish[1]];

		// Target 600 ft at one mile and 200 ft at ten miles. Store each interval's spacing so even
		// an endurance lap needs no per-waypoint meshes, terrain probes or large lookup tables.
		$miles = $this->length/(5280*DisplayUnits::FOOT);
		$spacing = (600.-400.*max(0.,min(1.,($miles-1.)/9.)))*DisplayUnits::FOOT;
		$spans = [];
		for($i = $first+1; $i <= $end; $i++) {
			$at = $stations[$i];
			$span = $at-$from;
			if($i < $end && min($span,$to-$at) < self::MIN_WAYPOINT_SPACING) continue;
			$pieces = max(1, min((int)round($span/$spacing), (int)floor($span/self::MIN_WAYPOINT_SPACING)));
			$spans[] = [$from, $at, $span/$pieces];
			$from = $at;
		}
		return [$line,$spans];
	}

	/** Aim ahead on the current leg. A missed waypoint or a recovery immediately rejoins at the nearest segment. */
	public function waypointAhead(int $nextGate, float $x, float $z): ?array
	{
		[$line,$spans] = $this->guideLegs[$nextGate] ??= $this->guideLeg($nextGate);
		$last = count($line)-1;
		$nearest = INF;
		$segment = 0; $progress = $line[0][0];
		for($i = 0; $i < $last; $i++) {
			[$a,$ax,$az] = $line[$i];
			[$b,$bx,$bz] = $line[$i+1];
			$dx = $bx-$ax; $dz = $bz-$az;
			$length2 = $dx*$dx+$dz*$dz;
			if($length2 < 1e-12) continue;
			$t = max(0., min(1., (($x-$ax)*$dx+($z-$az)*$dz)/$length2));
			$distance2 = ($ax+$t*$dx-$x)**2+($az+$t*$dz-$z)**2;
			if($distance2 >= $nearest) continue;
			$nearest = $distance2;
			$segment = $i;
			$progress = $a+$t*($b-$a);
		}
		// Hand off just before the waypoint so the arrow never swings back as the vehicle passes it.
		$ahead = $progress+12.;
		foreach($spans as [$from,$to,$step]) {
			if($ahead >= $to) continue;
			$at = min($to, $from+(floor(($ahead-$from)/$step)+1)*$step);
			if($at >= $line[$last][0]-1e-8) return null;
			for($i = $segment; $i < $last; $i++) {
				[$a,$ax,$az] = $line[$i];
				[$b,$bx,$bz] = $line[$i+1];
				if($b < $at || $b <= $a) continue;
				$t = ($at-$a)/($b-$a);
				return [$ax+$t*($bx-$ax), $az+$t*($bz-$az)];
			}
			break;
		}
		return null;
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
	public function course(Landscape $landscape, GateShapes $shapes, int $laps, bool $flooded, ?float $legacyWidth = null,
		bool $legacyPavementFit = false, bool $legacyDensity = false): Course
	{
		$placing = $this->placing($landscape, $shapes, $laps, $flooded, $legacyWidth, $legacyPavementFit, $legacyDensity);
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
	public function placing(Landscape $landscape, GateShapes $shapes, int $laps, bool $flooded, ?float $legacyWidth = null,
		bool $legacyPavementFit = false, bool $legacyDensity = false): \Generator
	{
		if($flooded && $this->floodedLevel === null) throw new \RuntimeException('This circuit has no flooded variant.');
		$course = new Course($shapes);
		$ground = $landscape->height(...);
		$kind = $flooded ? 'water' : 'road';
		$regional = $this->featureArea !== null;
		$width = $flooded ? ($regional ? 24. : null) : $legacyWidth;
		$fitPavement = !$flooded && $legacyWidth === null;
		$height = $flooded ? null : 2 * $shapes->kind('road')['size']['default'][1];
		foreach(($legacyDensity ? $this->waypoints() : $this->gates()) as $i => [$x,$z,$yaw])
		{
			if($fitPavement)
				[$x,$z,$width] = CircuitRoad::gateSpan($landscape,$x,$z,$yaw,$regional,$pavement);
			// Keep the original footing over a stock generated road. An edited section uses its own pavement span.
			$fittedSpan = $fitPavement && isset($pavement)
				&& (!$regional || (!$legacyPavementFit && $pavement !== CircuitRoad::WIDTH));
			$opening = $legacyPavementFit && $fittedSpan ? max($width,$shapes->kind('road')['size']['width'][0]) : $width;
			$size = $shapes->fit($kind,$opening,$height,$fittedSpan);
			$role = $i === 0 ? 'start' : 'checkpoint';
			$base = $flooded ? $this->floodedLevel : $ground($x,$z);
			// High ground outside the deck must not lift the banner off a road that cuts into a wall.
			$pavementHalfWidth = $fittedSpan ? $width/2 - CircuitRoad::POST_CLEARANCE : CircuitRoad::WIDTH/2;
			$deck = ($pavementHalfWidth + 1)**2;
			$fit = $flooded ? $ground : static fn(float $px, float $pz): float
				=> ($px-$x)**2 + ($pz-$z)**2 <= $deck ? $ground($px,$pz) : min($ground($px,$pz), $base);
			$placed = $course->place($role, $kind, $x, $base, $z, $yaw, $laps, $size['width'], $size['height'], $fit, $fittedSpan);
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
		if($circuit->length<self::MIN_LENGTH) throw $invalid();
		foreach($junctions as $junction)
			if(!$number($junction) || $junction<0 || $junction>=$circuit->length) throw $invalid();
		return $circuit;
	}
}

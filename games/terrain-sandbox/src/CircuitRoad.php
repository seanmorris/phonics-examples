<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Grades the regional route as one continuous road, following the existing floor and banks. */
final class CircuitRoad
{
	public const WIDTH = 36.;
	private const SHOULDER = 16.;
	// Distance from the asphalt edge to the inner face of a checkpoint post, in meters.
	public const POST_CLEARANCE = .75;
	private const STATION_SPACING = 40.;
	// At 75 mph a 140 m vertical radius asks for 8.0 m/s² of downward acceleration, below gravity.
	private const MIN_CREST_RADIUS = 140.;

	/** Fit both posts to the painted spline along the original checkpoint crossing plane. */
	public static function gateSpan(Landscape $land, float $x, float $z, float $yaw): array
	{
		$acrossX = cos($yaw);
		$acrossZ = -sin($yaw);
		$material = $land->material($x,$z,$offset,$center);
		if($material !== 'road' || $center === null || abs($center[0]) > $center[2])
			return [$x,$z,self::WIDTH + 2*self::POST_CLEARANCE];
		$half = $center[2] + self::POST_CLEARANCE;
		$edges = [];
		foreach([-1,1] as $side) {
			$distance = $half;
			// On a bend the fitted spline can sit to one side of the racing line or cross it at an angle.
			// Correct along the gate span until the post clears the local pavement edge.
			for($i = 0; $i < 6; $i++) {
				$land->material($x+$side*$distance*$acrossX,$z+$side*$distance*$acrossZ,$offset,$mark);
				if($mark === null) break;
				$error = $mark[2] + self::POST_CLEARANCE - abs($mark[0]);
				if(abs($error) < .001) break;
				$distance += $error;
			}
			$edges[] = $side*$distance;
		}
		[$left,$right] = $edges;
		$shift = round(($right+$left)/2,3);
		return [$x+$shift*$acrossX,$z+$shift*$acrossZ,round($right-$left,3)];
	}

	/** @return \Generator The road edit records for a validated centerline. */
	public static function grade(Landscape $land, Circuit $circuit, ?float $causewayDeck = null): \Generator
	{
		$count = (int)ceil($circuit->length/self::STATION_SPACING);
		$line = $circuit->stations(array_map(fn(int $i): float => $i*$circuit->length/$count,range(0,$count-1)));
		$ground = [];
		foreach($line as $i => [$x,$z])
		{
			if($i%16 === 0) yield;
			$height = $land->height($x,$z);
			$ground[] = $causewayDeck === null ? $height : max($height,$causewayDeck);
		}
		// Ease the vertical alignment over roughly 55 m on either side, including at the lap line.
		for($pass = 0; $pass < 4; $pass++) $ground = self::smooth($ground);
		$banks = $bends = [];
		foreach($line as $i => [$x,$z])
		{
			if($i%16 === 0) yield;
			$p = ($i+$count-1)%$count;
			$n = ($i+1)%$count;
			[$px,$pz] = $line[$p];
			[$nx,$nz] = $line[$n];
			$length = hypot($nx-$px,$nz-$pz);
			if($length<1.) return null;
			$leftX = ($pz-$nz)/$length;
			$leftZ = ($nx-$px)/$length;
			$turn = ($x-$px)*($nz-$z)-($z-$pz)*($nx-$x);
			// A 36 m road brings sections of its inside lane close together on a tight uphill turn.
			// Ease the approach and exit toward the apex height as the deflection approaches 60 degrees.
			$span = hypot($x-$px,$z-$pz)*hypot($nx-$x,$nz-$z);
			if($span < 1.) return null;
			$cosine = (($x-$px)*($nx-$x)+($z-$pz)*($nz-$z))/$span;
			$bends[] = max(0.,min(1.,2*(1.-$cosine)));
			$left = $land->height($x+14*$leftX,$z+14*$leftZ);
			$right = $land->height($x-14*$leftX,$z-14*$leftZ);
			if($causewayDeck !== null) {
				$left = max($left,$causewayDeck);
				$right = max($right,$causewayDeck);
			}
			$bank = max(-.18,min(.18,($left-$right)/28));
			// Carry the existing bank into a corner when the outside wall already rises.
			$banks[] = $bank*$turn<0 ? $bank*min(1.,abs($turn)/140) : 0.;
		}

		$points = [];
		foreach($line as $i => [$x,$z])
		{
			$change = $weight = 0.;
			foreach([-2=>.35,-1=>.85,1=>.85,2=>.35] as $offset => $influence)
			{
				$j = ($i+$count+$offset)%$count;
				$pull = $influence*$bends[$j];
				$change += $pull*($ground[$j]-$ground[$i]);
				$weight += $pull;
			}
			$points[] = [$x,$ground[$i]+$change/max(1.,$weight),$z];
		}
		$banks = self::smooth($banks);
		if($causewayDeck !== null) {
			// The low edge of a banked turn needs the same wave clearance as the straight deck.
			foreach($points as $i=>[, $y]) {
				$limit = max(0.,($y-$causewayDeck)/(self::WIDTH/2));
				$banks[$i] = max(-$limit,min($banks[$i],$limit));
			}
		}
		$edits = yield from CircuitCurves::sections($points,$banks);
		foreach($edits as &$edit) {
			$edit += ['type'=>'road','width'=>self::WIDTH,'shoulder'=>self::SHOULDER,'crown'=>.08,'material'=>'road'];
		}
		return $edits;
	}

	/** Close an editor road with a continuous default tangent at the start gate. */
	public static function closeLoop(array $road): array
	{
		$points = $road['points']; $last = count($points)-1;
		if($last < 2) throw new \RuntimeException('Place at least three distinct circuit points.');
		if(hypot($points[$last][0]-$points[0][0],$points[$last][2]-$points[0][2]) < .001) {
			if($last < 3) throw new \RuntimeException('Place at least three distinct circuit points.');
			return $road;
		}
		if(count($points)>=128) throw new \RuntimeException('Use the first point as the last point to close a 128-point road.');
		$tangent = array_map(static fn($b,$a) => ($b-$a)/6.,$points[1],$points[$last]);
		$handles = $road['handles'] ?? array_fill(0,count($points),null);
		$handles[0]['out'] ??= $tangent;
		$handles[] = ['in'=>array_map(static fn($v) => -$v,$tangent)];
		$road['points'][] = $points[0]; $road['handles'] = $handles;
		if(isset($road['banks'])) $road['banks'][] = $road['banks'][0];
		return $road;
	}

	/** Place the race line on the actual graded spline, including its closing span. */
	public static function manualCircuit(Landscape $graded, int $index, float $dry): Circuit
	{
		$keys = array_keys($graded->roadRecords());
		$road = $graded->roads()[array_search($index,$keys,true)];
		$line = []; $high = $dry;
		foreach($road['_road']['samples'] as [$x,$y,$z]) { $line[] = [$x,$z]; $high = max($high,$y); }
		array_pop($line); // The spline ends at the first point.
		$wet = ceil(2*($high+4.))/2;
		return self::raceLine($graded,$line,$line[0],$dry,$wet<=10000. ? $wet : null,null);
	}

	/** Follow the authored splines after a preview edit, retaining the start nearest the generator's original gate. */
	public static function editedCircuit(Landscape $graded, Circuit $plan, int $sectionCount): Circuit
	{
		$line = [];
		foreach(array_slice($graded->roads(),-$sectionCount) as $road) {
			$samples = $road['_road']['samples'];
			for($i=0,$n=count($samples)-1; $i<$n; $i++) $line[] = [$samples[$i][0],$samples[$i][2]];
		}
		return self::raceLine($graded,$line,$plan->points[0],$plan->dryLevel,$plan->floodedLevel,$plan->featureArea);
	}

	private static function raceLine(Landscape $graded, array $line, array $origin, float $dry, ?float $wet, ?float $area): Circuit
	{
		if(count($line)<3) throw new \RuntimeException('The edited circuit needs a closed route.');
		$trace = new Circuit($line,$dry,null);
		if($trace->length < Circuit::MIN_LENGTH)
			throw new \RuntimeException('A circuit must be at least ' . DisplayUnits::feet(Circuit::MIN_LENGTH) . ' ft long.');
		$count = min(Circuit::MAX_POINTS,max(24,(int)ceil($trace->length/12.)));
		$route = []; $nearest = INF; $start = 0;
		$half = $graded->recipe()['worldSize']/2;
		foreach($trace->stations(array_map(static fn($i) => $i*$trace->length/$count,range(0,$count-1))) as [$x,$z]) {
			if(max(abs($x),abs($z))>$half) throw new \RuntimeException('Keep the circuit curves inside the editable map.');
			$distance = hypot($x-$origin[0],$z-$origin[1]);
			if($distance<$nearest) { $start = count($route); $nearest = $distance; }
			$route[] = [$x,$z];
		}
		$route = array_merge(array_slice($route,$start),array_slice($route,0,$start));
		return new Circuit($route,$dry,$wet,featureArea:$area,
			startHeight:$graded->height(...$route[0]));
	}

	/** Smooth evenly spaced stations using the same neighbors on both sides of the lap line. */
	private static function smooth(array $values): array
	{
		$count = count($values);
		$result = [];
		foreach($values as $i => $value)
			$result[] = .25*($values[($i+$count-1)%$count]+2*$value+$values[($i+1)%$count]);
		return $result;
	}

	/** Measure the prepared road's grade and width, and return its height range. */
	public static function clearance(Landscape $land, Circuit $circuit, float $dry, int $sections = 0): \Generator
	{
		// A section's rounded cap can affect either lane between the regular 10 m probes.
		// Check the finished pavement near each join at the finer road sampling interval.
		if($sections>1) foreach(array_slice($land->roads(),-$sections) as $road) {
			$path = $road['_road'];
			$last = count($path['samples'])-1;
			foreach([0,$last] as $end) {
				for($i=$end; $i>=0 && $i<=$last; $i+=($end===0 ? 1 : -1)) {
					if(abs($path['stations'][$i]-$path['stations'][$end])>$path['radius']+8.) break;
					if($i%8===0) yield;
					[$x,,$z,$tx,$tz] = $path['samples'][$i];
					foreach([-14.,0.,14.] as $lane) {
						$px = $x-$tz*$lane; $pz = $z+$tx*$lane;
						$rise = 2*$land->height($px,$pz)-$land->height($px-4*$tx,$pz-4*$tz)
							-$land->height($px+4*$tx,$pz+4*$tz);
						if($rise > 16/self::MIN_CREST_RADIUS) return null;
					}
				}
			}
		}
		$count = (int)ceil($circuit->length/10);
		$line = $circuit->stations(array_map(fn(int $i): float => $i*$circuit->length/$count,range(0,$count-1)));
		$high = -INF;
		$low = INF;
		$previous = [];
		// Start two stations before zero so the closing crest gets the same three-point check.
		for($i = -2; $i < $count; $i++)
		{
			if($i%8 === 0) yield;
			[$x,$z] = $line[($count+$i)%$count];
			[$ax,$az] = $line[($count+$i-1)%$count];
			[$bx,$bz] = $line[($count+$i+1)%$count];
			$chord = hypot($bx-$ax,$bz-$az);
			if($chord < 1.) return null;
			$dx = 12*($bz-$az)/$chord;
			$dz = -12*($bx-$ax)/$chord;
			$heights = [];
			foreach([-1,0,1] as $lane)
			{
				$px = $x+$lane*$dx;
				$pz = $z+$lane*$dz;
				$h = $land->height($px,$pz);
				if($h <= $dry) return null;
				if(isset($previous[$lane]))
				{
					[$lastX,$lastZ,$lastH,$lastRun,$lastGrade] = $previous[$lane];
					$run = hypot($px-$lastX,$pz-$lastZ);
					if($run < 1.) return null;
					$grade = ($h-$lastH)/$run;
					if($lane === 0 && abs($grade) > .46) return null;
					// The two grades are measured at the midpoints of adjacent runs.
					if($lastGrade !== null && $lastGrade-$grade > ($lastRun+$run)/(2*self::MIN_CREST_RADIUS)) return null;
				}
				else
				{
					$run = 0.;
					$grade = null;
				}
				$previous[$lane] = [$px,$pz,$h,$run,$grade];
				$heights[$lane] = $h;
			}
			if(abs($heights[-1]-$heights[1]) > 6. || $land->material($x,$z) !== 'road') return null;
			$high = max($high,...$heights);
			$low = min($low,...$heights);
		}
		return [$low,$high];
	}
}

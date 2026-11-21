<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Grades the regional route as one continuous road, following the existing floor and banks. */
final class CircuitRoad
{
	public const WIDTH = 36.;
	private const SHOULDER = 16.;
	private const STATION_SPACING = 40.;
	// At 75 mph a 140 m vertical radius asks for 8.0 m/s² of downward acceleration, below gravity.
	private const MIN_CREST_RADIUS = 140.;

	/** @return \Generator The road edit records for a validated centreline. */
	public static function grade(Landscape $land, Circuit $circuit): \Generator
	{
		$count = (int)ceil($circuit->length/self::STATION_SPACING);
		$line = $circuit->stations(array_map(fn(int $i): float => $i*$circuit->length/$count,range(0,$count-1)));
		$ground = [];
		foreach($line as $i => [$x,$z])
		{
			if($i%16 === 0) yield;
			$ground[] = $land->height($x,$z);
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
			// Ease the approach and exit towards the apex height as the deflection approaches 60 degrees.
			$span = hypot($x-$px,$z-$pz)*hypot($nx-$x,$nz-$z);
			if($span < 1.) return null;
			$cosine = (($x-$px)*($nx-$x)+($z-$pz)*($nz-$z))/$span;
			$bends[] = max(0.,min(1.,2*(1.-$cosine)));
			$left = $land->height($x+14*$leftX,$z+14*$leftZ);
			$right = $land->height($x-14*$leftX,$z-14*$leftZ);
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
		$edits = [];
		// The two extra stations at each end let a section fade onto the fully graded neighbouring spline.
		for($start = 0; $start<$count; $start+=64)
		{
			$end = min($start+64,$count);
			$ids = array_map(static fn(int $i): int => ($i+$count)%$count,range($start-2,$end+2));
			$edits[] = ['type'=>'road','points'=>array_map(fn(int $i): array => $points[$i],$ids),
				'banks'=>array_map(fn(int $i): float => $banks[$i],$ids),'width'=>self::WIDTH,
				'shoulder'=>self::SHOULDER,'endBlend'=>1.5*self::STATION_SPACING,'crown'=>.08,'material'=>'road'];
		}
		return $edits;
	}

	/** Smooth evenly spaced stations using the same neighbours on both sides of the lap line. */
	private static function smooth(array $values): array
	{
		$count = count($values);
		$result = [];
		foreach($values as $i => $value)
			$result[] = .25*($values[($i+$count-1)%$count]+2*$value+$values[($i+1)%$count]);
		return $result;
	}

	/** Measure the prepared road's grade and width, and return its height range. */
	public static function clearance(Landscape $land, Circuit $circuit, float $dry): \Generator
	{
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

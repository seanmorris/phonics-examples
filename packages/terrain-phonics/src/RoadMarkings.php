<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Paint coordinates shared by unambiguous, end-to-start pavement sections. */
final class RoadMarkings
{
	public const DASH_METERS = 3.048;

	/** Derive the visible paint paths from committed control points, independent of edit order. */
	public static function connect(array $operations): array
	{
		$starts = $ends = $next = $previous = $remaining = [];
		foreach($operations as $i=>$op) {
			if($op['type'] !== 'road') continue;
			unset($operations[$i]['_road']['ink']);
			if($op['material'] !== 'road') continue;
			$points = $op['points'];
			$starts[serialize([$points[0],$op['width']])][] = $i;
			$ends[serialize([$points[array_key_last($points)],$op['width']])][] = $i;
		}
		foreach($ends as $key=>$from) {
			$to = $starts[$key] ?? [];
			if(count($from) !== 1 || count($to) !== 1) continue;
			$next[$from[0]] = $to[0];
			$previous[$to[0]] = $from[0];
			$remaining[$from[0]] = $remaining[$to[0]] = true;
		}
		// Visit open chains from their physical start, then any remaining closed loops.
		foreach([...array_keys(array_diff_key($remaining,$previous)), ...array_keys($remaining)] as $first) {
			if(!isset($remaining[$first])) continue;
			$offsets = []; $length = 0.; $i = $first;
			while(isset($remaining[$i])) {
				unset($remaining[$i]);
				$offsets[$i] = $length;
				$stations = $operations[$i]['_road']['stations'];
				$length += $stations[array_key_last($stations)];
				$i = $next[$i] ?? null;
			}
			$lap = $i === $first ? $length : 0.;
			$pivot = $lap > 0. ? self::middleSample($operations,$offsets,$lap/2) : 0.;
			// The encoded phase can reverse at the center of a dash or gap without reversing the painted pattern.
			$halfPattern = max(1.,round($lap/(2*self::DASH_METERS)))*self::DASH_METERS;
			foreach($offsets as $id=>$offset) {
				$operations[$id]['_road']['ink'] = ['offset'=>$offset,'lap'=>$lap,'pivot'=>$pivot,
					'rising'=>$pivot > 0. ? $halfPattern/$pivot : 0.,
					'falling'=>$lap > $pivot ? $halfPattern/($lap-$pivot) : 0.];
			}
			foreach($offsets as $id=>$_) {
				foreach([0=>$previous[$id] ?? null,1=>$next[$id] ?? null] as $end=>$neighbor) {
					if($neighbor === null) continue;
					$road = $operations[$id]['_road'];
					$segments = [];
					[$x,,$z] = $road['samples'][$end ? array_key_last($road['samples']) : 0];
					$j = $neighbor;
					do {
						$other = $operations[$j]['_road'];
						$further = $end ? ($next[$j] ?? null) : ($previous[$j] ?? null);
						if(!self::continuation($segments,$other,$end,$x,$z,2*$road['gridSize'],$further === null)) break;
						$j = $further;
					} while($j !== null && $j !== $id && $j !== $neighbor);
					$operations[$id]['_road']['ink']['ends'][$end] = $segments;
				}
			}
		}
		return $operations;
	}

	/** Snap the phase reversal to a real sample so every projected segment stays linear. */
	private static function middleSample(array $operations,array $offsets,float $middle): float
	{
		foreach($offsets as $id=>$offset) {
			$stations = $operations[$id]['_road']['stations'];
			$lo = 0; $hi = array_key_last($stations);
			$wanted = $middle-$offset;
			if($stations[$hi] < $wanted) continue;
			while($lo+1 < $hi) {
				$m = intdiv($lo+$hi,2);
				if($stations[$m] < $wanted) $lo = $m; else $hi = $m;
			}
			return $offset+($wanted-$stations[$lo] < $stations[$hi]-$wanted ? $stations[$lo] : $stations[$hi]);
		}
		return 0.;
	}

	/** Neighboring segments that can beat the shared endpoint as the nearest projection inside its cap. */
	private static function continuation(array &$segments, array $road, int $end, float $x, float $z, float $reach, bool $open): bool
	{
		$samples = $road['samples']; $last = count($samples)-1;
		for($i=$end ? 0 : $last-1; $i>=0 && $i<$last; $i+=$end ? 1 : -1) {
			[$ax,,$az] = $samples[$i];
			[$bx,,$bz] = $samples[$i+1];
			$start = self::station($road,$i);
			$cap = $open && $end && $i === $last-1 ? 2 : (int)($open && !$end && $i === 0);
			$segments[] = [$ax,$az,$bx-$ax,$bz-$az,$start,self::station($road,$i+1)-$start,$cap,
				$road['stations'][$i],$road['stations'][$i+1]-$road['stations'][$i]];
			if(hypot(($end ? $bx : $ax)-$x,($end ? $bz : $az)-$z) > $reach) return false;
		}
		return true;
	}

	/** Distance used to encode dash phase at a sample, including wraparound of a closed lap. */
	public static function station(array $road, int $i): float
	{
		$s = $road['stations'][$i];
		if(!isset($road['ink'])) return $s;
		$ink = $road['ink'];
		$s += $ink['offset'];
		$lap = $ink['lap']; $pivot = $ink['pivot'];
		if($pivot <= 0. || $pivot >= $lap) return $s;
		return self::DASH_METERS/2 + ($s <= $pivot ? $s*$ink['rising'] : ($lap-$s)*$ink['falling']);
	}
}

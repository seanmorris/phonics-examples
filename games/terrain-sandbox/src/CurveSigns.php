<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Landscape;

/** Rows of chevrons on the outside of sustained bends in committed asphalt splines. */
final class CurveSigns
{
	private const MAX_RADIUS = 300 * DisplayUnits::FOOT;
	private const MIN_TURN = M_PI/6;
	public const SPACING = 18 * DisplayUnits::FOOT;
	private const SETBACK = 2.;

	/** Yield [x, z, tangent x/z, turn sign] or null while scanning straights. */
	public static function sites(Landscape $land): \Generator
	{
		$run = []; $side = 0; $angle = 0.; $last = null; $width = null;
		foreach($land->roads() as $road) {
			if($road['material'] !== 'road') continue;
			$samples = $road['_road']['samples'];
			$first = $samples[0];
			if($last !== null && ($width !== $road['width'] || hypot($last[0]-$first[0],$last[2]-$first[2]) > .01)) {
				yield from self::row($run,$angle,$side);
				$run = []; $angle = 0.; $side = 0;
			}
			$width = $road['width'];
			for($i = 1, $count = count($samples); $i < $count; $i++) {
				if(($i & 63) === 1) yield null;
				$a = $samples[$i-1]; $b = $samples[$i];
				$length = hypot($b[0]-$a[0],$b[2]-$a[2]);
				if($length < 1e-6) continue;
				$turn = atan2($a[3]*$b[4]-$a[4]*$b[3], $a[3]*$b[3]+$a[4]*$b[4]);
				$next = abs($turn)*self::MAX_RADIUS >= $length ? ($turn > 0 ? 1 : -1) : 0;
				if($next !== $side) {
					yield from self::row($run,$angle,$side);
					$run = []; $angle = 0.; $side = $next;
				}
				if($side === 0) continue;
				$offset = $side*($width/2+self::SETBACK);
				if(!$run) $run[] = [$a[0]+$offset*$a[4],$a[2]-$offset*$a[3],$a[3],$a[4],0.];
				$x = $b[0]+$offset*$b[4]; $z = $b[2]-$offset*$b[3];
				$previous = $run[array_key_last($run)];
				$run[] = [$x,$z,$b[3],$b[4],$previous[4]+hypot($x-$previous[0],$z-$previous[1])];
				$angle += abs($turn);
			}
			$last = $samples[array_key_last($samples)];
		}
		yield from self::row($run,$angle,$side);
	}

	/** Measure spacing on the outside shoulder, which travels farther around a turn than the centerline. */
	private static function row(array $run, float $angle, int $side): \Generator
	{
		if($angle < self::MIN_TURN || count($run) < 2) return;
		$length = $run[array_key_last($run)][4];
		if($length < self::SPACING) return;
		$count = max(1,(int)round($length/self::SPACING));
		$spacing = $length/$count;
		$segment = 1;
		for($i = 0; $i < $count; $i++) {
			$at = ($i+.5)*$spacing;
			while($run[$segment][4] < $at) $segment++;
			[$ax,$az,$atx,$atz,$start] = $run[$segment-1];
			[$bx,$bz,$btx,$btz,$end] = $run[$segment];
			$t = ($at-$start)/($end-$start);
			$x = $ax+($bx-$ax)*$t; $z = $az+($bz-$az)*$t;
			$tx = $atx+($btx-$atx)*$t; $tz = $atz+($btz-$atz)*$t;
			$norm = hypot($tx,$tz);
			if($norm < 1e-8) continue;
			yield [$x,$z,$tx/$norm,$tz/$norm,$side];
		}
	}
}

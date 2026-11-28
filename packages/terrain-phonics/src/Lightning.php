<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Where and when lightning strikes, worked out from the clock and the place alone.
 *
 * The map is divided into squares and time into slots. A hash of the square and the slot says whether that square
 * strikes in that slot, when, and where. Nothing is remembered, so a replay or a second viewer sees the same storm.
 */
final class Lightning
{
	/**
	 * Width of a square in meters, length of a slot in seconds, and the seconds the brightness timeline is written
	 * for. The weather's `boltSeconds` control stretches that timeline, to no more than one slot.
	 */
	public const SQUARE = 4096., SLOT = 2., LIFE = .8;
	/** Seconds the bolt takes to come down before its first bright stroke. */
	public const DESCENT = .06;
	/** Squares looked at on every side of the viewer. */
	public const AROUND = 4;
	/** The share of squares that strike in one slot at the full rate. */
	private const SHARE = .05;
	/**
	 * Rings of ground heights read around a strike: radius as a share of the reach, how many, and whether the ring
	 * is centered on the best place found so far. The outer ring and the two that follow the best add up to the
	 * reach.
	 */
	private const RINGS = [[.75, 10, false], [.375, 6, false], [.1875, 8, true], [.0625, 6, true]];

	private static ?string $noise = null;

	/**
	 * List the strikes that begin in one slot of time in the squares around a place.
	 *
	 * A strike needs opaque cloud above it, so thin cover strikes less often than a full deck.
	 *
	 * @param Weather $weather Weather whose lightning rate, cloud cover and cloud position decide the strikes.
	 * @param int $slot Slot of time, counted in units of SLOT seconds.
	 * @param float $x Viewer's world X in meters.
	 * @param float $z Viewer's world Z in meters.
	 * @return array Strikes, each the X and Z it leaves the cloud at, its start in seconds and a seed for its shape.
	 */
	public static function begun(Weather $weather, int $slot, float $x, float $z): array
	{
		$c = $weather->controls();
		$share = self::SHARE * $c['lightning'] / 100.;

		if($share <= 0. || $c['coverage'] <= 0) return [];

		$cx = (int)floor($x / self::SQUARE);
		$cz = (int)floor($z / self::SQUARE);
		$strikes = [];

		for($j = $cz - self::AROUND; $j <= $cz + self::AROUND; $j++)
		for($i = $cx - self::AROUND; $i <= $cx + self::AROUND; $i++)
		{
			// Six 16-bit numbers from a digest. A checksum such as CRC-32 will not do here: neighboring squares
			// would strike together.
			[, $chance, $when, $across, $along, $low, $high] = unpack('v6', md5(pack('l3', $i, $j, $slot), true));

			if($chance / 65536. >= $share) continue;

			$start = ($slot + $when / 65536.) * self::SLOT;
			$sx = ($i + $across / 65536.) * self::SQUARE;
			$sz = ($j + $along / 65536.) * self::SQUARE;

			if(!self::clouded($sx, $sz, $weather->offset($start), $c['coverage'] / 100.)) continue;

			$strikes[] = [$sx, $sz, $start, $low | ($high & 0xFF) << 16];
		}

		return $strikes;
	}

	/**
	 * List the strikes lit at one moment around a place.
	 *
	 * @param Weather $weather Weather whose lightning rate, cloud cover and cloud position decide the strikes.
	 * @param float $time Simulation time in seconds.
	 * @param float $x Viewer's world X in meters.
	 * @param float $z Viewer's world Z in meters.
	 * @return array Strikes as begun() lists them, limited to those between their start and the end of their life.
	 */
	public static function lit(Weather $weather, float $time, float $x, float $z): array
	{
		$slot = (int)floor($time / self::SLOT);
		$life = $weather->controls()['boltSeconds'];
		$lit = [];

		foreach([$slot - 1, $slot] as $n)
		foreach(self::begun($weather, $n, $x, $z) as $strike)
		if($time >= $strike[2] && $time - $strike[2] < $life) $lit[] = $strike;

		return $lit;
	}

	/**
	 * Tell whether opaque cloud lies over a place, from the field the cloud sheet is drawn with.
	 *
	 * @param float $x World X in meters.
	 * @param float $z World Z in meters.
	 * @param array $offset How far the wind has carried the clouds along X and Z, from Weather::offset().
	 * @param float $coverage Cloud cover from 0 to 1.
	 * @return bool Whether the sheet is close to opaque there.
	 */
	public static function clouded(float $x, float $z, array $offset, float $coverage): bool
	{
		self::$noise ??= file_get_contents(dirname(__DIR__) . '/textures/cloud-noise.r8');
		$u = ($x - $offset[0]) / 16384.;
		$v = ($z - $offset[1]) / 16384.;
		// The two scales and the cutoff are those of cloudSample() in weather.glsl.
		$field = self::noise($u, $v) * .77 + self::noise($v * 4. + .37, $u * 4. + .37) * .23;

		return $field >= .94 - .82 * $coverage + .04;
	}

	/**
	 * Read the repeating cloud noise between its four nearest values.
	 *
	 * @param float $u Position across the noise, one unit per repeat.
	 * @param float $v Position along the noise, one unit per repeat.
	 * @return float Noise from 0 to 1.
	 */
	private static function noise(float $u, float $v): float
	{
		$x = ($u - floor($u)) * 128. - .5;
		$y = ($v - floor($v)) * 128. - .5;
		$ix = (int)floor($x);
		$iy = (int)floor($y);
		$fx = $x - $ix;
		$fy = $y - $iy;

		// The noise is 128 values square and repeats, so both neighbors wrap.
		$row = ($iy & 127) << 7;
		$next = (($iy + 1) & 127) << 7;
		$a = $ix & 127;
		$b = ($ix + 1) & 127;
		$n = self::$noise;

		$low = ord($n[$row | $a]) * (1. - $fx) + ord($n[$row | $b]) * $fx;
		$high = ord($n[$next | $a]) * (1. - $fx) + ord($n[$next | $b]) * $fx;

		return ($low * (1. - $fy) + $high * $fy) / 255.;
	}

	/**
	 * Move a strike to the highest ground near where it would have landed.
	 *
	 * Each height is a single-point lookup. Two rings are read around the starting place, then two smaller ones
	 * around the best place found, so a hilltop or a ridge within the reach draws the strike to it. With no reach
	 * the strike lands straight below where it began.
	 *
	 * @param Landscape $ground Landscape to read heights from.
	 * @param float $x World X the strike started over, in meters.
	 * @param float $z World Z the strike started over, in meters.
	 * @param float $floor Water level in meters. A strike over water lands on its surface.
	 * @param float $reach The farthest the strike may move toward high ground, in meters.
	 * @return array Where the strike lands, as X, height and Z in meters.
	 */
	public static function ground(Landscape $ground, float $x, float $z, float $floor, float $reach = 800.): array
	{
		$recipe = $ground->recipe();
		$half = $recipe['worldSize'] / 2.;
		$spacing = $recipe['spacing'];

		// Past the edge of the map the ground is scenery with no height to look up. The bolt runs down to the
		// water level there, and whatever stands in front of it hides the rest.
		if(abs($x) > $half || abs($z) > $half) return [$x, $floor, $z];

		$best = [round($x / $spacing) * $spacing, round($z / $spacing) * $spacing];
		$from = $best;
		$high = $ground->meshHeight($best[0], $best[1], INF);

		foreach($reach > 0. ? self::RINGS : [] as $ring => [$share, $count, $follows])
		{
			if($follows) $from = $best;

			$radius = $share * $reach;

			for($n = 0; $n < $count; $n++)
			{
				// Alternate rings are turned half a step, so no two probes line up along a spoke.
				$angle = 2. * M_PI * ($n + .5 * ($ring & 1)) / $count;
				$px = round(($from[0] + $radius * cos($angle)) / $spacing) * $spacing;
				$pz = round(($from[1] + $radius * sin($angle)) / $spacing) * $spacing;

				if(abs($px) > $half || abs($pz) > $half) continue;

				$height = $ground->meshHeight($px, $pz, INF);

				if($height > $high) [$high, $best] = [$height, [$px, $pz]];
			}
		}

		return [$best[0], max($high, $floor), $best[1]];
	}

	/**
	 * Place the top of a bolt in the cloud, between where the strike began and the point over its landing.
	 *
	 * A strike drawn far to one side toward high ground would otherwise lie almost flat under a low cloud. The top
	 * moves toward the landing until the bolt leans no more than about 31 degrees: 0.6 meters sideways for each
	 * meter it falls.
	 *
	 * @param float $x World X the strike began over, in meters.
	 * @param float $z World Z the strike began over, in meters.
	 * @param array $land Where the strike lands, as X, height and Z in meters.
	 * @param float $deck Height of the cloud base in meters.
	 * @return array The top of the bolt, as X, height and Z in meters.
	 */
	public static function top(float $x, float $z, array $land, float $deck): array
	{
		$aside = hypot($x - $land[0], $z - $land[2]);
		$share = $aside > 0. ? min(1., .6 * max(0., $deck - $land[1]) / $aside) : 0.;

		return [$land[0] + ($x - $land[0]) * $share, $deck, $land[2] + ($z - $land[2]) * $share];
	}

	/**
	 * Tell how a strike shines a given time after it begins.
	 *
	 * The bolt first comes down dimly. When it reaches the ground the main channel flashes, and flashes again once
	 * or twice as it fades. The forks shine with the first flash only and fade over about a quarter of a second.
	 *
	 * @param float $age Seconds since the strike began.
	 * @param int $seed The strike's seed from begun().
	 * @param float $life Seconds the strike lasts. The whole timeline is stretched to fit.
	 * @return array Main channel light, fork light and how much of the bolt has come down, each from 0 to 1.
	 */
	public static function glow(float $age, int $seed, float $life = self::LIFE): array
	{
		$age *= self::LIFE / $life;

		if($age < 0. || $age >= self::LIFE) return [0., 0., 0.];

		// The bolt reaches the ground with two thirds of its paths drawn; the forks finish as the flash begins.
		if($age < self::DESCENT) return [.5, .5, $age / self::DESCENT * 2. / 3.];

		$since = $age - self::DESCENT;
		$main = exp(-$since / .1);
		$again = 0.;

		for($n = 1; $n <= 1 + ($seed & 1); $n++)
		{
			// Each later flash follows the one before by 90 to 180 milliseconds and is four fifths as bright.
			$again += .09 + .03 * (($seed >> (2 * $n)) & 3);

			if($since >= $again) $main = max($main, .8 ** $n * exp(-($since - $again) / .08));
		}

		// The last light dies away over the final 150 milliseconds, so the strike does not cut off.
		$main *= min(1., (self::LIFE - $age) / .15);

		return [$main, exp(-$since / .13), 1.];
	}
}

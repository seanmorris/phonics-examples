<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Cross section shared by canyon relief, mesh workers and exposed rock materials. */
final class CanyonBeds
{
	/** Bed bottoms in spacing units. The final contact closes the repeating section. */
	public const CONTACTS = [-5.0, -3.06, -1.85, -1.29, -.05, 1.29, 2.04, 5.0];
	/** Fraction of each bed's horizontal run over which it rises. Short runs form cliffs. */
	public const RAMPS = [[.03,.98], [.03,.97], [.33,.79], [.14,.84], [.03,.97], [.19,.81], [.26,.76]];
	/** Rise per meter east and north, in world X/Z coordinates. */
	public const DIP = [.006, -.003];

	/**
	 * Remap a coarse elevation relative to the dipping beds into a ledge or talus slope.
	 * @param float $height Elevation above the bedding datum in meters.
	 * @param float $spacing Positive scale of the section in meters.
	 * @param float $strength Interpolation from the unmodified slope to the bed profile.
	 * @return array Remapped elevation and partial derivatives with respect to height and strength.
	 */
	public static function shape(float $height, float $spacing, float $strength): array
	{
		// Reduce in meters first so even submillimeter spacing cannot overflow height / spacing.
		// fmod() returns a height inside one period unchanged, so only a height outside it pays for the call.
		$period = 10 * $spacing;
		$level = ($height < $period && $height > -$period ? $height : fmod($height, $period)) / $spacing;

		if($level < -5) $level += 10;

		if($level >= 5) $level -= 10;

		$i = 0;

		while($i < 6 && $level >= self::CONTACTS[$i + 1]) $i++;
		$width = self::CONTACTS[$i + 1] - self::CONTACTS[$i];
		$fraction = ($level - self::CONTACTS[$i]) / $width;
		[$start, $end] = self::RAMPS[$i];
		$t = ($fraction - $start) / ($end - $start);
		// Comparisons clamp every ordinary value to [0,1] without a call. A zero or NaN goes through max() and
		// min(), which define the sign of zero and the handling of NaN.
		$t = $t >= 1.0 ? 1.0 : ($t > 0.0 ? $t : ($t < 0.0 ? 0.0 : max(0.0, min(1.0, $t))));
		// Noise::fade() written out, because the call costs more than the polynomial.
		$displacement = $width * $spacing * ($t * $t * $t * ($t * ($t * 6 - 15) + 10) - $fraction);

		return [
			$height + $strength * $displacement
			, 1 - $strength + $strength * 30 * $t * $t * ($t - 1) ** 2 / ($end - $start)
			, $displacement
		];
	}
}

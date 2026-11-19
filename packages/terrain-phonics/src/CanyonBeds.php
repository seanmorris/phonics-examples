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
	/** Rise per metre east and north, in world X/Z coordinates. */
	public const DIP = [.006, -.003];

	/**
	 * Remap a coarse elevation relative to the dipping beds into a ledge or talus slope.
	 * @param float $height Elevation above the bedding datum in metres.
	 * @param float $spacing Positive scale of the section in metres.
	 * @param float $strength Interpolation from the unmodified slope to the bed profile.
	 * @return array Remapped elevation and partial derivatives with respect to height and strength.
	 */
	public static function shape(float $height, float $spacing, float $strength): array
	{
		// Reduce in metres first so even submillimetre spacing cannot overflow height / spacing.
		$level = fmod($height, 10 * $spacing) / $spacing;
		if($level < -5) $level += 10;
		if($level >= 5) $level -= 10;
		$i = 0;
		while($i < 6 && $level >= self::CONTACTS[$i + 1]) $i++;
		$width = self::CONTACTS[$i + 1] - self::CONTACTS[$i];
		$fraction = ($level - self::CONTACTS[$i]) / $width;
		[$start, $end] = self::RAMPS[$i];
		$t = max(0.0, min(1.0, ($fraction - $start) / ($end - $start)));
		$displacement = $width * $spacing * (Noise::fade($t) - $fraction);
		return [
			$height + $strength * $displacement,
			1 - $strength + $strength * 30 * $t * $t * ($t - 1) ** 2 / ($end - $start),
			$displacement
		];
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Format simulation distances for player-facing messages. */
final class DisplayUnits
{
	public const FOOT = .3048, MILES_PER_HOUR = .44704, KM_PER_MILE = 1.609344;
	/** A knot in meters per second: one nautical mile, 1852 m, an hour. Flight speeds read in knots. */
	public const KNOT = 1852 / 3600;

	public static function feet(float $meters, int $precision = 1): string
	{
		$feet = $meters / self::FOOT;
		$rounded = round($feet, abs($feet) < 100 ? $precision : 0);
		return $rounded == 0 ? '0' : (string)$rounded;
	}
}

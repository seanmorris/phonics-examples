<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Format simulation distances for player-facing messages. */
final class DisplayUnits
{
	public static function feet(float $meters, int $precision = 1): string
	{
		$feet = $meters / .3048;
		$rounded = round($feet, abs($feet) < 100 ? $precision : 0);
		return $rounded == 0 ? '0' : (string)$rounded;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Vanilla 35 Hz gravity constants expressed in the engine's per-second velocity units. */
final class DoomGravity
{
	public const TICS_PER_SECOND = 35;
	public const ACCELERATION = 1225.0;

	/** Vanilla starts a zero-momentum fall with two tics of downward momentum. */
	public static function initialVelocity(float $acceleration, float $scale): float
	{
		return -2 * $acceleration * $scale / self::TICS_PER_SECOND;
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Solid reward box state shared by every monitor effect. */
final class Monitor
{
	public bool $broken = false;

	/**
	 * Describe one upright, bottom-anchored monitor in world space.
	 *
	 * @param float $x Bottom-center world X coordinate in pixels.
	 * @param float $y Bottom-center world Y coordinate in pixels.
	 * @param float $width Positive collision width in world pixels.
	 * @param float $height Positive collision height in world pixels.
	 * @param int $points Nonnegative score awarded to the player that breaks the monitor.
	 * @param int $brokenGid Optional global tile ID used for the persistent broken casing.
	 * @param int $explosionGid Optional global animated tile ID used for the pop effect.
	 * @param int $collisionLayer Nonnegative collision plane; zero collides on every plane.
	 * @return void Initializes an intact monitor.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width = 30
		, public float $height = 32
		, public int $points = 10
		, public int $brokenGid = 0
		, public int $explosionGid = 0
		, public int $collisionLayer = 0
	){
		if(!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)
			|| $width <= 0 || $height <= 0
		){
			throw new \InvalidArgumentException('Monitor position and bounds must be finite and positive');
		}

		if($points < 0 || $brokenGid < 0 || $explosionGid < 0 || $collisionLayer < 0)
		{
			throw new \InvalidArgumentException('Monitor rewards, sprite IDs, and collision layer are invalid');
		}
	}
}

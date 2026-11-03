<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Owner-tagged falling spike bomb or one of its short-lived outward fragments. */
final class SpikeBomb
{
	public float $age = 0;

	/**
	 * Create one boss projectile in world space.
	 *
	 * @param int $owner Live mini-boss entity that emitted this projectile.
	 * @param float $x Center X coordinate in world pixels.
	 * @param float $y Center Y coordinate in world pixels.
	 * @param float $vx Horizontal velocity in pixels per second.
	 * @param float $vy Vertical velocity in pixels per second.
	 * @param float $gravity Nonnegative downward acceleration in pixels per second squared.
	 * @param float $lifetime Positive total lifetime in seconds.
	 * @param float $radius Positive circular contact extent in world pixels.
	 * @param int $collisionLayer Nonnegative terrain plane combined with shared geometry.
	 * @param bool $fragment Whether the projectile is a terrain-ignoring explosion fragment.
	 * @return void Stores validated deterministic projectile motion.
	 */
	public function __construct(
		public int $owner
		, public float $x
		, public float $y
		, public float $vx
		, public float $vy
		, public float $gravity = 900
		, public float $lifetime = 4
		, public float $radius = 10
		, public int $collisionLayer = 1
		, public bool $fragment = false
	){
		foreach([$x, $y, $vx, $vy, $gravity, $lifetime, $radius] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Spike-bomb state must be finite');
			}
		}

		if($owner < 1 || $gravity < 0 || $lifetime <= 0 || $radius <= 0 || $collisionLayer < 0)
		{
			throw new \InvalidArgumentException('Spike-bomb ownership, bounds, and timing are invalid');
		}
	}
}

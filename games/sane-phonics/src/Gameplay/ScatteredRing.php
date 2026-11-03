<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Ballistic state, recovery delay, and expiry for one ring scattered by player damage. */
final class ScatteredRing
{
	public float $age = 0;

	/**
	 * Configure one recoverable or decorative ring in a damage burst.
	 *
	 * @param int $owner Entity whose inventory produced the ring.
	 * @param float $vx Initial horizontal velocity in pixels per second.
	 * @param float $vy Initial vertical velocity in pixels per second.
	 * @param float $gravity Nonnegative downward acceleration in pixels per second squared.
	 * @param float $launchDelay Nonnegative delay before ballistic motion begins in seconds.
	 * @param float $pickupDelay Nonnegative delay before a collector may recover the ring.
	 * @param float $lifetime Positive total lifetime including launch delay in seconds.
	 * @param float $restitution Collision-normal speed retained after a terrain bounce, from zero
	 * to one.
	 * @param int $collisionLayer Nonnegative terrain plane combined with shared collision geometry.
	 * @param bool $collectible Whether this visual can return one ring to a collector.
	 * @return void Initializes the ring at age zero.
	 */
	public function __construct(
		public int $owner
		, public float $vx
		, public float $vy
		, public float $gravity = 1440
		, public float $launchDelay = .1
		, public float $pickupDelay = .8
		, public float $lifetime = 6
		, public float $restitution = .75
		, public int $collisionLayer = 1
		, public bool $collectible = true
	){
		foreach([$vx, $vy, $gravity, $launchDelay, $pickupDelay, $lifetime, $restitution] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Scattered ring state must be finite');
			}
		}

		if($owner < 1 || $gravity < 0 || $launchDelay < 0 || $pickupDelay < 0 || $lifetime <= 0
			|| $restitution < 0 || $restitution > 1 || $collisionLayer < 0
		){
			throw new \InvalidArgumentException('Scattered ring ownership, timing, or collision is invalid');
		}
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Small owner-tagged projectile with delayed launch, gravity, and a bounded lifetime. */
final class BadnikProjectile
{
	public float $age = 0;

	/**
	 * Create one projectile in world space.
	 *
	 * @param int $owner Live or retired badnik entity that fired the projectile.
	 * @param float $x Center X coordinate in world pixels.
	 * @param float $y Center Y coordinate in world pixels.
	 * @param float $vx Horizontal launch velocity in pixels per second.
	 * @param float $vy Vertical launch velocity in pixels per second.
	 * @param float $gravity Nonnegative downward acceleration in pixels per second squared.
	 * @param float $delay Nonnegative time before velocity and gravity begin in seconds.
	 * @param float $lifetime Positive total lifetime including launch delay in seconds.
	 * @param float $radius Positive circular contact extent in world pixels.
	 * @param int $collisionLayer Nonnegative terrain plane combined with shared collision geometry.
	 * @param string $damageType Nonempty damage category used by shield immunity rules.
	 * @return void Stores deterministic projectile motion and ownership state.
	 */
	public function __construct(
		public int $owner
		, public float $x
		, public float $y
		, public float $vx
		, public float $vy
		, public float $gravity = 540
		, public float $delay = .05
		, public float $lifetime = 200 / 60
		, public float $radius = 4
		, public int $collisionLayer = 1
		, public string $damageType = 'projectile'
	){
		foreach([$x, $y, $vx, $vy, $gravity, $delay, $lifetime, $radius] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Projectile state must be finite');
			}
		}

		if($owner < 1 || $gravity < 0 || $delay < 0 || $lifetime <= 0 || $radius <= 0
			|| $collisionLayer < 0 || $damageType === ''
		){
			throw new \InvalidArgumentException('Projectile ownership, bounds, and timing are invalid');
		}
	}
}

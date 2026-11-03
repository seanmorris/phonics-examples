<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Rider-owned vehicle shot with continuous terrain and enemy collision history. */
final class VehicleProjectile
{
	public float $age = 0;
	public float $previousX, $previousY;

	/**
	 * Create one vehicle projectile in world space.
	 *
	 * @param int $owner Vehicle entity that emitted the shot.
	 * @param int $attacker Rider entity credited for defeated enemies.
	 * @param float $x Initial center X coordinate in pixels.
	 * @param float $y Initial center Y coordinate in pixels.
	 * @param float $vx Horizontal velocity in pixels per second.
	 * @param float $vy Vertical velocity in pixels per second.
	 * @param float $gravity Nonnegative downward acceleration in pixels per second squared.
	 * @param float $gravityDelay Nonnegative delay before gravity begins in seconds.
	 * @param float $lifetime Positive lifetime in seconds.
	 * @param float $radius Positive circular collision extent in pixels.
	 * @param int $collisionLayer Nonnegative terrain collision plane.
	 * @return void Retains a previous position for continuous target collision.
	 */
	public function __construct(
		public int $owner
		, public int $attacker
		, public float $x
		, public float $y
		, public float $vx
		, public float $vy
		, public float $gravity = 540
		, public float $gravityDelay = .1
		, public float $lifetime = 200 / 60
		, public float $radius = 4
		, public int $collisionLayer = 1
	){
		foreach([$x, $y, $vx, $vy, $gravity, $gravityDelay, $lifetime, $radius] as $value)
		{
			if(!is_finite($value)) throw new \InvalidArgumentException('Vehicle projectile state must be finite');
		}

		if($owner < 1 || $attacker < 1 || $gravity < 0 || $gravityDelay < 0 || $lifetime <= 0
			|| $radius <= 0 || $collisionLayer < 0
		){
			throw new \InvalidArgumentException('Vehicle projectile ownership, bounds, and timing are invalid');
		}

		$this->previousX = $x;
		$this->previousY = $y;
	}
}

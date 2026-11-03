<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Alternating patrol, firing schedule, and visible state for one CrabMeat badnik. */
final class CrabMeat implements BadnikBehavior
{
	public float $age = 0;
	public bool $moving = true;
	public string $animation = 'walking';
	public int $shots = 0;
	public int $initialDirection;
	public int $collisionLayer;

	/**
	 * Configure the moving-boxes-2-inspired CrabMeat cycle in engine units.
	 *
	 * @param int $direction Initial horizontal direction, normalized to minus or plus one.
	 * @param float $patrolBeat Positive duration of one alternating patrol leg in seconds.
	 * @param float $patrolPause Nonnegative pause at the end of each patrol leg in seconds.
	 * @param float $patrolSpeed Nonnegative walking speed in pixels per second.
	 * @param float $patrolOffset Nonnegative phase offset in seconds for staggering groups.
	 * @param float $shotTelegraph Phase ending the first shooting pose, in seconds.
	 * @param float $shotDelay Nonnegative delay between spawning and launching shots in seconds.
	 * @param float $projectileSpeed Nonnegative outward shot speed in pixels per second.
	 * @param float $projectileLift Positive initial upward shot speed in pixels per second.
	 * @param float $projectileGravity Nonnegative downward shot acceleration in pixels per second
	 * squared.
	 * @param float $projectileLifetime Positive maximum shot lifetime in seconds.
	 * @param int $collisionLayer Nonnegative terrain plane used by patrol probes and projectiles.
	 * @return void Initializes a walking crab at the requested point in its repeating schedule.
	 */
	public function __construct(
		public int $direction = 1
		, public float $patrolBeat = 1.5
		, public float $patrolPause = 25 / 60
		, public float $patrolSpeed = 15
		, public float $patrolOffset = 0
		, public float $shotTelegraph = 65 / 60
		, public float $shotDelay = .05
		, public float $projectileSpeed = 90
		, public float $projectileLift = 180
		, public float $projectileGravity = 540
		, public float $projectileLifetime = 200 / 60
		, int $collisionLayer = 1
	){
		$this->initialDirection = $this->direction = $direction < 0 ? -1 : 1;
		$this->collisionLayer = $collisionLayer;

		foreach([
			$patrolBeat
			, $patrolPause
			, $patrolSpeed
			, $patrolOffset
			, $shotTelegraph
			, $shotDelay
			, $projectileSpeed, $projectileLift, $projectileGravity, $projectileLifetime
		] as $value)
		{
			if(!is_finite($value) || $value < 0)
			{
				throw new \InvalidArgumentException('CrabMeat timing and movement must be finite and nonnegative');
			}
		}

		if($patrolBeat <= 0 || $patrolPause >= $patrolBeat || $shotTelegraph >= $patrolBeat
			|| $projectileLifetime <= 0 || $collisionLayer < 0
		){
			throw new \InvalidArgumentException('CrabMeat cycle phases and lifetime are out of range');
		}
	}
}

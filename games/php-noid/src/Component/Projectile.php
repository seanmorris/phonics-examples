<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS projectile state. */
final class Projectile
{
	public float $vx = 0;
	public float $vy;

	/**
	 * Initialize Projectile state and its owned services.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param string $kind Capsule action or projectile kind.
	 * @param float $speed Travel speed in world pixels per second.
	 * @param string $team Player weapons and lethal enemy attacks have separate collision rules.
	 * @return void Completes the operation.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public string $kind = 'laser'
		, public float $speed = 560
		, public string $team = 'player'
	){
		$this->vy = $team === 'player' ? -$speed : $speed;
	}
}

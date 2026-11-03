<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS capsule state. */
final class Capsule
{

	/**
	 * Initialize Capsule state and its owned services.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param string $kind Capsule action or projectile kind.
	 * @param float $speed Travel speed in world pixels per second.
	 * @return void Completes the operation.
	 */
	public function __construct(public float $x, public float $y, public string $kind, public float $speed = 95) {}
}

<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS enemy state. */
final class Enemy
{
	public float $clock = 0, $direction = 1;

	/**
	 * Initialize Enemy state and its owned services.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param string $family Ship family selecting movement and attack behavior.
	 * @param string $effect Effect applied by an enemy ball.
	 * @param float $interval Seconds between enemy emissions.
	 * @param int $hits Laser hits needed to destroy this ship; missiles inflict three.
	 * @param string $capsule Capsule dropped by a friendly Sympathizer.
	 * @return void Completes the operation.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public string $family = 'menacer'
		, public string $effect = 'copper'
		, public float $interval = 5
		, public int $hits = 3
		, public string $capsule = 'expand'
	){
	}
}

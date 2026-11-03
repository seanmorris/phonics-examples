<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS paddle state. */
final class Paddle
{
	public float $width = 84, $height = 14, $speed = 520, $cooldown = 0;
	/** @var array<string, float> Active capsule kinds and their remaining seconds. */
	public array $powers = [];
	/** @var array<string,float> Remaining HUD pickup flashes, independent of equipment timers. */
	public array $powerFlashes = [];
	public float $invisible = 0;
	public float $catchFlash = 0;
	public float $respawnProtection = 0;
	public float $baseWidth = 84;

	/**
	 * Initialize Paddle state and its owned services.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @return void Completes the operation.
	 */
	public function __construct(public float $x = 320, public float $y = 450) {}
}

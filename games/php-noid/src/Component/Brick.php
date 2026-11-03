<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Mutable ECS brick state. */
final class Brick
{

	/**
	 * Initialize Brick state and its owned services.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param float $width Width in pixels.
	 * @param float $height Height in pixels.
	 * @param int $hits Remaining hits before this brick breaks.
	 * @param bool $unbreakable Whether ordinary balls and weapons leave this brick intact.
	 * @param string $capsule Capsule export key dropped when the brick breaks.
	 * @param int $score Points awarded on destruction.
	 * @param string $material Normal, copper, silver, or green metallic surface.
	 * @return void Completes the operation.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width = 54
		, public float $height = 20
		, public int $hits = 1
		, public bool $unbreakable = false
		, public string $capsule = ''
		, public int $score = 100
		, public string $material = 'normal'
	){
	}
}

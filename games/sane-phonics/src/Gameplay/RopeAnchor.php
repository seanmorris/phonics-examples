<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Mutable suspension point, optionally following another ECS body's support position. */
final class RopeAnchor
{
	public bool $enabled = true;
	public float $vx = 0, $vy = 0;

	/**
	 * Create a fixed or entity-following rope anchor.
	 *
	 * @param float $x World X coordinate at the suspension point.
	 * @param float $y World Y coordinate at the suspension point.
	 * @param int|null $followEntity Optional Body, MovingPlatform, or BuoyantPlatform entity to follow.
	 * @param int $sourceId Original Tiled object ID, or zero for scripted anchors.
	 * @return void Stores validated coordinates and identity; callers may move static anchors directly.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public ?int $followEntity = null
		, public int $sourceId = 0
	){
		if(!is_finite($x) || !is_finite($y) || $sourceId < 0 || ($followEntity !== null && $followEntity < 1))
		{
			throw new \InvalidArgumentException('Invalid rope anchor coordinates or identity');
		}
	}
}

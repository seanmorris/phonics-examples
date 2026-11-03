<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Automatically grabbable handle at a Rope endpoint; its Y coordinate is the handle's bottom. */
final class GrapplePoint
{
	public int $entity = 0;

	/**
	 * Describe a grapple handle's contact region and map identity.
	 *
	 * @param float $width Positive handle contact width in world pixels.
	 * @param float $height Positive handle contact height above the rope endpoint.
	 * @param int $collisionLayer Nonnegative plane, with zero allowing riders on any plane.
	 * @param int $sourceId Original Tiled object ID, or zero for a scripted handle.
	 * @return void Stores validated bounds; Grapples assigns the owning entity on registration.
	 */
	public function __construct(
		public float $width = 28
		, public float $height = 16
		, public int $collisionLayer = 0
		, public int $sourceId = 0
	){
		if(!is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0
			|| $collisionLayer < 0 || $sourceId < 0
		){
			throw new \InvalidArgumentException('Invalid grapple bounds or identity');
		}
	}
}

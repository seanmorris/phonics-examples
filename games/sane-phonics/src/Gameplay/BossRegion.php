<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** A Tiled boss arena whose horizontal bounds lock actors and the camera while its target lives. */
final class BossRegion
{
	public bool $completed = false;

	/**
	 * Define one world-space boss arena.
	 *
	 * @param float $x Left arena edge in world pixels.
	 * @param float $y Top arena edge in world pixels.
	 * @param float $width Positive arena width in world pixels.
	 * @param float $height Positive activation-region height in world pixels.
	 * @param int $bossSourceId Tiled object ID whose application-defined target controls release.
	 * @param int|null $sourceId Tiled object ID for diagnostics and scripting.
	 * @param float $cameraAnchorY Vertical player position in the viewport from zero through one.
	 * @return void Stores validated bounds and target metadata in an incomplete state.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width
		, public float $height
		, public int $bossSourceId
		, public ?int $sourceId = null
		, public float $cameraAnchorY = .72
	){
		foreach([$x, $y, $width, $height, $cameraAnchorY] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Boss region properties must be finite');
			}
		}

		if($width <= 0 || $height <= 0 || $bossSourceId <= 0 || ($sourceId !== null && $sourceId <= 0)
			|| $cameraAnchorY < 0 || $cameraAnchorY > 1
		){
			throw new \InvalidArgumentException('Invalid boss region');
		}
	}

	/**
	 * Test whether a world point lies on or inside the activation rectangle.
	 *
	 * @param float $x World X coordinate.
	 * @param float $y World Y coordinate.
	 * @return bool True when the point lies within both axes of the region.
	 */
	public function contains(float $x, float $y): bool
	{
		return $x >= $this->x && $x <= $this->x + $this->width
			&& $y >= $this->y && $y <= $this->y + $this->height;
	}
}

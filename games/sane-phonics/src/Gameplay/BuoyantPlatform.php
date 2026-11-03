<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\WaterRegion;

/** A solid rectangular platform whose submerged fraction follows its density. */
final class BuoyantPlatform
{
	public float $velocityY = 0;
	public ?int $shape = null;
	public int $entity = 0;

	/**
	 * Define a floating platform using the bottom-anchor convention of Tiled tile objects.
	 *
	 * @param int $sourceId Tiled object ID retained for scripting and diagnostics.
	 * @param int $waterId Tiled water object ID supplying density and the moving surface.
	 * @param float $x Horizontal center in world pixels.
	 * @param float $y Bottom edge in world pixels.
	 * @param float $width Positive collision and sprite width in world pixels.
	 * @param float $height Positive collision and sprite height in world pixels.
	 * @param float $density Positive object density; values below water density float partly above
	 * the surface.
	 * @param bool $horizontalLock Whether gameplay code should retain the source X position.
	 * @param bool $oneWay Whether only downward contacts land on this platform.
	 * @param int $collisionLayer Nonnegative terrain plane selected by the platform.
	 * @param float $spring Positive vertical restoring acceleration coefficient.
	 * @param float $damping Nonnegative vertical velocity damping coefficient.
	 * @param float $maxSpeed Positive vertical speed cap in pixels per second.
	 * @return void Initializes a stationary platform without allocating its collider.
	 */
	public function __construct(
		public int $sourceId
		, public int $waterId
		, public float $x
		, public float $y
		, public float $width
		, public float $height
		, public float $density = .8
		, public bool $horizontalLock = true
		, public bool $oneWay = false
		, public int $collisionLayer = 0
		, public float $spring = 36
		, public float $damping = 12
		, public float $maxSpeed = 720
	){
		foreach([$x, $y, $width, $height, $density, $spring, $damping, $maxSpeed] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Buoyant platform values must be finite');
			}
		}

		if($sourceId < 1 || $waterId < 1 || $width <= 0 || $height <= 0 || $density <= 0
			|| $collisionLayer < 0 || $spring <= 0 || $damping < 0 || $maxSpeed <= 0
		){
			throw new \InvalidArgumentException('Invalid buoyant platform');
		}
	}

	/**
	 * Return the current top edge.
	 *
	 * @return float World-space top Y coordinate.
	 */
	public function top(): float
	{
		return $this->y - $this->height;
	}

	/**
	 * Compute the density equilibrium for the current water level.
	 *
	 * @param WaterRegion $water Region providing surface, bottom, and liquid density.
	 * @return float Desired bottom Y with the density fraction submerged, clamped to the pool.
	 */
	public function equilibrium(WaterRegion $water): float
	{
		$submerged = $this->height * min(1, $this->density / $water->density);

		return min($water->y + $water->height, $water->y + $submerged);
	}

	/**
	 * Build the current clockwise collision rectangle.
	 *
	 * @return list<array{0:float,1:float}> Four world-space vertices.
	 */
	public function points(): array
	{
		$left = $this->x - $this->width / 2;
		$right = $this->x + $this->width / 2;
		$top = $this->top();

		return [[$left,$top],[$right,$top],[$right,$this->y],[$left,$this->y]];
	}
}

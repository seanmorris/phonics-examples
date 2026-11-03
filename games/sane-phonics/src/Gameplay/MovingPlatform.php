<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** A suspended rectangular support, optionally driven by an oscillator or linked path. */
final class MovingPlatform
{
	public ?int $shape = null;
	public int $entity = 0;
	public float $settlement = 0;
	public float $velocityX = 0, $velocityY = 0;
	public bool $weighted = false;
	public float $originX, $originY;

	/**
	 * Define a suspended platform using a horizontal center and bottom anchor.
	 *
	 * @param float $x Horizontal center in world pixels.
	 * @param float $y Bottom edge in world pixels.
	 * @param float $width Positive collision width in pixels.
	 * @param float $height Positive collision height in pixels.
	 * @param bool $oneWay Whether actors can jump upward through the support.
	 * @param float $settle Signed displacement under a rider; zero disables settling.
	 * @param int $collisionLayer Shared plane zero or a positive collision plane.
	 * @param int $sourceId Optional Tiled object ID for scripting.
	 * @return void Creates an unregistered, stationary platform.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width = 96
		, public float $height = 16
		, public bool $oneWay = true
		, public float $settle = 0
		, public int $collisionLayer = 0
		, public int $sourceId = 0
	){
		foreach([$x, $y, $width, $height, $settle] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Platform values must be finite');
			}
		}

		if($width <= 0 || $height <= 0 || $collisionLayer < 0 || $sourceId < 0)
		{
			throw new \InvalidArgumentException('Invalid platform dimensions or identifiers');
		}

		$this->originX = $x;
		$this->originY = $y;
	}

	/**
	 * Locate the support surface.
	 *
	 * @return float Top edge in world pixels.
	 */
	public function top(): float
	{
		return $this->y - $this->height;
	}

	/**
	 * Build the current clockwise collision rectangle.
	 *
	 * @return list<array{float,float}> Four world-space vertices.
	 */
	public function points(): array
	{
		$left = $this->x - $this->width / 2;
		$right = $this->x + $this->width / 2;

		return [[$left, $this->top()], [$right, $this->top()], [$right, $this->y], [$left, $this->y]];
	}
}

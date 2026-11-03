<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;
use Phonics\Render\Sprite;

/** Region-supplied skateboard or forced dismount, with per-character restricted-exit history. */
final class VehicleRegion
{
	/** @var array<int,int|false> Supplied vehicle ID, or false after its restricted exit. */
	public array $spawned = [];

	/**
	 * Define a supply or drop rectangle in world pixels.
	 *
	 * @param float $x Left edge.
	 * @param float $y Top edge.
	 * @param float $width Positive horizontal extent.
	 * @param float $height Positive vertical extent.
	 * @param bool $restricted Retire supplied vehicles on exit and prevent repeated supply.
	 * @param bool $drop Force a transfer dismount instead of supplying a skateboard.
	 * @param Sprite|null $sprite Optional cloned artwork for each supplied vehicle.
	 * @param int $collisionLayer Shared zero plane or selected positive collision plane.
	 * @return void Initializes an empty per-character supply history.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width
		, public float $height
		, public bool $restricted = false
		, public bool $drop = false
		, public ?Sprite $sprite = null
		, public int $collisionLayer = 0
	){
		foreach([$x, $y, $width, $height] as $value)
		{
			if(!is_finite($value)) throw new \InvalidArgumentException('Invalid vehicle region');
		}

		if($width <= 0 || $height <= 0 || $collisionLayer < 0)
		{
			throw new \InvalidArgumentException('Invalid vehicle region dimensions or plane');
		}
	}

	/**
	 * Test a character's surface-contact point against this region and plane.
	 *
	 * @param Body $body Character or seated rider to inspect.
	 * @return bool Whether the support point lies inside the region rectangle.
	 */
	public function contains(Body $body): bool
	{
		return ($this->collisionLayer === 0 || $body->collisionLayer === $this->collisionLayer)
			&& $body->supportX() >= $this->x && $body->supportX() <= $this->x + $this->width
			&& $body->supportY() >= $this->y && $body->supportY() <= $this->y + $this->height;
	}

	/**
	 * Detect passage through a drop region even when a fast rider crosses it in one step.
	 *
	 * @param Body $body Current rider pose and collision plane.
	 * @param array{float,float} $previous Previous rider center before movement.
	 * @return bool True if the swept support point intersects the rectangle on its collision plane.
	 */
	public function crosses(Body $body, array $previous): bool
	{
		if($this->collisionLayer !== 0 && $body->collisionLayer !== $this->collisionLayer) return false;

		$x = $previous[0] + ($body->supportX() - $body->x);
		$y = $previous[1] + ($body->supportY() - $body->y);
		$start = 0;
		$end = 1;

		foreach([
			[$x, $body->supportX() - $x, $this->x, $this->width]
			, [$y, $body->supportY() - $y, $this->y, $this->height]
		] as [$point, $delta, $minimum, $size])
		{
			if(abs($delta) < 1e-9)
			{
				if($point < $minimum || $point > $minimum + $size) return false;

				continue;
			}

			$a = ($minimum - $point) / $delta;
			$b = ($minimum + $size - $point) / $delta;
			$start = max($start, min($a, $b));
			$end = min($end, max($a, $b));

			if($start > $end) return false;
		}

		return true;
	}
}

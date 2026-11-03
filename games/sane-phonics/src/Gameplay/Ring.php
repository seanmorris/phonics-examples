<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** A nonsolid collectible ring with a circular world-space pickup region. */
final class Ring
{

	/**
	 * Describe one ring pickup and its player rewards.
	 *
	 * @param float $x World X coordinate at the center of the ring.
	 * @param float $y World Y coordinate at the center of the ring.
	 * @param float $radius Positive pickup radius in world pixels.
	 * @param int $value Positive number added to a collector's ring count.
	 * @param int $points Nonnegative score awarded with the ring.
	 * @return void Stores validated pickup geometry and rewards.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $radius = 10
		, public int $value = 1
		, public int $points = 0
	){
		if(!is_finite($x) || !is_finite($y) || !is_finite($radius) || $radius <= 0)
		{
			throw new \InvalidArgumentException('Ring coordinates must be finite and its radius must be positive');
		}

		if($value < 1 || $points < 0)
		{
			throw new \InvalidArgumentException('Ring value must be positive and points cannot be negative');
		}
	}
}

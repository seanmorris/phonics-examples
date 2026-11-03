<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Shared position, contact bounds, reward, and defeat art for a poppable enemy. */
final class Badnik
{

	/**
	 * Describe a badnik independently of its species-specific movement component.
	 *
	 * @param float $x Bottom-center world X coordinate in pixels.
	 * @param float $y Bottom-center world Y coordinate in pixels.
	 * @param float $width Positive contact width in world pixels.
	 * @param float $height Positive contact height above the bottom anchor in world pixels.
	 * @param int $points Nonnegative score awarded when an attacking player defeats the badnik.
	 * @param int $explosionGid Optional global animated tile ID used after the badnik pops.
	 * @return void Stores reusable contact state without selecting movement behavior.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width = 32
		, public float $height = 32
		, public int $points = 100
		, public int $explosionGid = 0
	){
		if(!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height)
			|| $width <= 0 || $height <= 0
		){
			throw new \InvalidArgumentException('Badnik position and bounds must be finite and positive');
		}

		if($points < 0 || $explosionGid < 0)
		{
			throw new \InvalidArgumentException('Badnik rewards and sprite IDs must be nonnegative');
		}
	}
}

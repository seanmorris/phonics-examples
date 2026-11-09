<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use DoomPhonics\Game\WorldPoint;
use Phonics\Effects\Lifetime;

/** Short-lived world-space beam rendered as a depth-tested screen-space ribbon. */
final class BeamEffect extends Lifetime
{
	public function __construct(
		public WorldPoint $from
		, public WorldPoint $to
		, float $duration = .75
		, public float $width = 8
	){
		if($duration <= 0 || !is_finite($duration))
		throw new \InvalidArgumentException('Beam duration must be finite and positive');

		if($width <= 0 || !is_finite($width))
		throw new \InvalidArgumentException('Beam width must be finite and positive');

		parent::__construct($duration);
	}

	public function age(): float { return $this->age; }

	/** Catch up to host fade progress without reviving an already fading beam. */
	public function synchronizeAge(float $age): void
	{
		if($age < 0 || !is_finite($age))
		throw new \InvalidArgumentException('Beam age must be finite and non-negative');

		$this->synchronize($age);
	}
}

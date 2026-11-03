<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use DoomPhonics\Game\WorldPoint;

/** Short-lived world-space beam rendered as a depth-tested screen-space ribbon. */
final class BeamEffect
{
	private float $previousAge = 0;
	private float $age = 0;

	public function __construct(
		public WorldPoint $from
		, public WorldPoint $to
		, public float $duration = .75
		, public float $width = 8
	){
		if($duration <= 0 || !is_finite($duration))
		throw new \InvalidArgumentException('Beam duration must be finite and positive');

		if($width <= 0 || !is_finite($width))
		throw new \InvalidArgumentException('Beam width must be finite and positive');
	}

	public function advance(float $seconds): void
	{
		$this->previousAge = $this->age;
		$this->age = min($this->duration, $this->age + max(0, $seconds));
	}

	public function age(): float { return $this->age; }

	/** Catch up to host fade progress without reviving an already fading beam. */
	public function synchronizeAge(float $age): void
	{
		if($age < 0 || !is_finite($age))
		throw new \InvalidArgumentException('Beam age must be finite and non-negative');

		$this->age = min($this->duration, max($this->age, $age));
		$this->previousAge = $this->age;
	}

	/** Remaining width fraction, interpolated between fixed simulation updates. */
	public function remaining(float $alpha = 1): float
	{
		$alpha = max(0.0, min(1.0, $alpha));
		$age = $this->previousAge + ($this->age - $this->previousAge) * $alpha;

		return max(0.0, 1.0 - $age / $this->duration);
	}

	public function expired(): bool { return $this->age >= $this->duration; }
}

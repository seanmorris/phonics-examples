<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Immutable-by-convention position in WAD world space. */
final class WorldPoint
{

	public function __construct(public float $x, public float $y, public float $z)
	{
		if(!is_finite($x) || !is_finite($y) || !is_finite($z))
		throw new \InvalidArgumentException('World point coordinates must be finite');
	}

	public function interpolate(self $to, float $amount): self
	{
		$amount = max(0.0, min(1.0, $amount));

		return new self(
			$this->x + ($to->x - $this->x) * $amount
			, $this->y + ($to->y - $this->y) * $amount
			, $this->z + ($to->z - $this->z) * $amount
		);
	}
}

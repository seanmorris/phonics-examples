<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Selected actor or static surface reached by a three-dimensional hitscan ray. */
final class HitscanImpact
{

	public function __construct(
		public float $distance
		, public float $x
		, public float $y
		, public float $z
		, public ?int $target = null
		, public ?ProjectileImpact $geometry = null
		, public ?WorldPoint $origin = null
	){
	}

	public function hit(): bool { return $this->target !== null || $this->geometry !== null; }
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Ownership, damage, and lifetime for a DECORATE projectile actor. */
final class Projectile
{
	public bool $escapedSky = false;

	public function __construct(
		public int $owner
		, public int $damage
		, public float $ttl = 12
		, public bool $active = true
		, public float $splash = 0
	){
	}

	/** Leave finite map collision while the sprite recedes toward the renderer's far limit. */
	public function escapeIntoSky(): void { $this->escapedSky = true; }
}

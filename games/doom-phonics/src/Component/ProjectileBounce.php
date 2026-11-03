<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** DECORATE projectile bounce policy and its per-entity runtime state. */
final class ProjectileBounce
{
	public int $count = 0;
	public bool $resting = false;

	public function __construct(
		public string $type
		, public float $floorFactor = .7
		, public float $wallFactor = .75
		, public int $maximum = -1
	){
		if($type === '') throw new \InvalidArgumentException('Projectile bounce type cannot be empty');

		if($floorFactor < 0 || $wallFactor < 0)
		throw new \InvalidArgumentException('Projectile bounce factors cannot be negative');

		if($maximum < -1) throw new \InvalidArgumentException('Projectile bounce count must be -1 or greater');
	}

	/** Record one reflected surface contact. */
	public function contact(): void { $this->count++; }

	/** Report whether the actor should detonate before its next movement step. */
	public function exhausted(): bool { return $this->maximum >= 0 && $this->count >= $this->maximum; }
}

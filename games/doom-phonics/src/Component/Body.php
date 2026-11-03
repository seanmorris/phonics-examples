<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Doom body: square actor footprint, with radius retained for radial wall and attack tests. */
final class Body
{

	public function __construct(
		public float $radius
		, public float $height
		, public bool $solid = false
		, public bool $shootable = false
		, public bool $missile = false
		, public bool $grounded = true
	){
	}
}

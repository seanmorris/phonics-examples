<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Immutable map-space origin used by DECORATE respawn actions. */
final class SpawnPoint
{

	public function __construct(
		public float $x
		, public float $y
		, public float $z
		, public float $angle
	){
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Earliest horizontal contact found during one normalized movement sweep. */
final class MovementContact
{

	public function __construct(
		public float $time
		, public float $normalX
		, public float $normalY
		, public ?int $entity = null
	){
	}
}

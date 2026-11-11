<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** A map-authored destination for the persistent player; it does not create or move a player. */
final class SpawnPoint
{

	/**
	 * Store authored map coordinates; the game applies map and layer placement when entering.
	 * @param float $x Horizontal map-local destination.
	 * @param float $y Vertical map-local destination.
	 * @return void Records the authored destination without moving any entities.
	 */
	public function __construct(public float $x, public float $y) {}
}

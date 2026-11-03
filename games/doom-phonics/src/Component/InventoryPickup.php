<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Marks a map actor whose DECORATE definition can enter a player's inventory. */
final class InventoryPickup
{

	public function __construct(
		public string $actorName
		, public int $amount = 1
		, public bool $dropped = false
	){
	}
}

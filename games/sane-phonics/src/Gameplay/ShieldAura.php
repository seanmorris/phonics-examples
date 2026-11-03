<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Links one separately rendered shield aura entity to its player entity. */
final class ShieldAura
{

	/**
	 * Identify the player whose body and active shield drive this aura.
	 *
	 * @param int $player Positive live player entity ID at creation time.
	 * @return void Stores the relationship without owning the player.
	 */
	public function __construct(public int $player)
	{
		if($player < 1)
		{
			throw new \InvalidArgumentException('Shield aura player must be a positive entity ID');
		}
	}
}

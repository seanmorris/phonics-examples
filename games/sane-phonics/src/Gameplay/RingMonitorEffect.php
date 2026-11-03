<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;

/** Monitor reward that adds a configurable number of rings to a player's counter. */
final class RingMonitorEffect implements MonitorEffect
{

	/**
	 * Configure the ring count granted by this monitor.
	 *
	 * @param int $rings Positive number of rings awarded on the first pop.
	 * @return void Stores the immutable reward amount.
	 */
	public function __construct(public int $rings = 10)
	{
		if($rings < 1)
		{
			throw new \InvalidArgumentException('Ring monitor rewards must be positive');
		}
	}

	/**
	 * Add rings when the player owns a RingCounter component.
	 *
	 * @param Registry $registry Registry containing the player and monitor entities.
	 * @param int $player Live player entity receiving the reward.
	 * @param Monitor $monitor Broken source monitor, unused by the ring reward.
	 * @return void Leaves players without a ring counter unchanged.
	 */
	public function apply(Registry $registry, int $player, Monitor $monitor): void
	{
		if($registry->has($player, RingCounter::class))
		{
			$registry->get($player, RingCounter::class)->rings += $this->rings;
		}
	}
}

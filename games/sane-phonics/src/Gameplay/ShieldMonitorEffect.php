<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;

/** Monitor reward that grants one persistent or temporary player shield. */
final class ShieldMonitorEffect implements MonitorEffect
{

	/**
	 * Configure the shield granted when a monitor breaks.
	 *
	 * @param string $type One of the Shield type constants.
	 * @param float $starDuration Positive star duration in seconds, ignored for other types.
	 * @param Shields|null $shields Optional visual and ability manager for the current level.
	 * @return void Validates reward state before the effect can be attached to an entity.
	 */
	public function __construct(
		public string $type
		, public float $starDuration = 26
		, private ?Shields $shields = null
	){
		new Shield($type, $starDuration);
	}

	/**
	 * Grant the configured shield to a live player.
	 *
	 * @param Registry $registry Registry containing the player and monitor entities.
	 * @param int $player Live player entity receiving the reward.
	 * @param Monitor $monitor Broken source monitor, unused by the shield reward.
	 * @return void Adds a headless Shield component or delegates to the active Shields manager.
	 */
	public function apply(Registry $registry, int $player, Monitor $monitor): void
	{
		if($this->shields !== null)
		{
			$this->shields->grant($player, $this->type, $this->starDuration);

			return;
		}

		$shield = $registry->has($player, Shield::class)
			? $registry->get($player, Shield::class)
			: new Shield;

		if(!$registry->has($player, Shield::class))
		{
			$registry->add($player, $shield);
		}

		$shield->grant($this->type, $this->starDuration);
	}
}

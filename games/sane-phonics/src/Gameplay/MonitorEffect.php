<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;

/** Reward behavior applied once when a player breaks a monitor. */
interface MonitorEffect
{

	/**
	 * Apply this reward to the player responsible for a monitor pop.
	 *
	 * @param Registry $registry Registry containing the player and its gameplay components.
	 * @param int $player Live player entity that broke the monitor.
	 * @param Monitor $monitor Broken monitor supplying world position and common reward metadata.
	 * @return void Mutates effect-specific player state exactly once.
	 */
	public function apply(Registry $registry, int $player, Monitor $monitor): void;
}

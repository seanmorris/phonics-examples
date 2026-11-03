<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\ViewInput;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;

/** Samples host devices only for the player slot owned by this browser. */
final class PlayerCommandSystem
{
	private Query $players;

	public function __construct(private Controls $controls)
	{
		$this->players = Query::all(PlayerCommand::class, ViewInput::class);
	}

	public function update(Registry $registry, float $dt): void
	{
		foreach($registry->query($this->players) as $entity)
		{
			if($registry->has($entity, NetworkPlayer::class) && !$registry->get($entity, NetworkPlayer::class)->local)
			continue;

			if($registry->has($entity, HitPoints::class)
				&& $registry->get($entity, HitPoints::class)->current <= 0
			){
				$registry->get($entity, PlayerCommand::class)->release();
				$registry->get($entity, ViewInput::class)->release();

				continue;
			}

			$registry->get($entity, PlayerCommand::class)->sample(
				$this->controls
				, $registry->get($entity, ViewInput::class)
			);
		}
	}
}

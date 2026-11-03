<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Game\PlayerState;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Advances timed player powers and palette counters at the original 35 Hz rate. */
final class PlayerEffectSystem
{
	private float $ticRemainder = 0;
	private Query $players;

	public function __construct()
	{
		$this->players = Query::all(PlayerState::class);
	}

	public function update(Registry $registry, float $dt): void
	{
		$elapsed = max(0, $dt);
		$this->ticRemainder += $elapsed * 35;
		$tics = (int)floor($this->ticRemainder + 1e-9);

		if($tics > 0) $this->ticRemainder -= $tics;

		foreach($registry->query($this->players) as $entity)
		{
			$player = $registry->get($entity, PlayerState::class);

			if($tics > 0) $player->advanceMessage($tics);

			$dead = $registry->has($entity, HitPoints::class)
				&& $registry->get($entity, HitPoints::class)->current <= 0;

			if($dead) $player->advanceDeath($elapsed);

			foreach($player->powers as $name => $remaining)
			{
				$remaining -= $elapsed;

				if($remaining <= 0) unset($player->powers[$name]);
				else $player->powers[$name] = $remaining;
			}

			if($registry->has($entity, ViewEffects::class))
			{
				$effects = $registry->get($entity, ViewEffects::class);

				if($dead) $effects->recordDeath();

				if($tics > 0) $effects->advance($tics);
			}
		}
	}
}

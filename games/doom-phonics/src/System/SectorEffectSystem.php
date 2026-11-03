<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\FaceDirection;
use DoomPhonics\Definition\LanguageCatalog;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Classic 35 Hz animated lights, damaging floors, secrets, and damaging exits. */
final class SectorEffectSystem
{
	private float $remainder = 0;
	private int $levelTic = 0;
	private int $random = 1;
	/** @var array<int,int> */
	private array $high = [], $low = [], $timer = [], $phase = [], $glowDirection = [];
	private LanguageCatalog $language;

	/**
	 * Create the sector effect system.
	 *
	 * @param Level $level Active level.
	 * @param LineSpecials $specials Line specials, used for exit floors.
	 * @param ?LanguageCatalog $language Message text.
	 * @param bool $authority Whether this peer applies floor damage; online guests receive it from the host.
	 */
	public function __construct(
		public Level $level
		, public LineSpecials $specials
		, ?LanguageCatalog $language = null
		, public bool $authority = true
	){
		$this->language = $language ?? LanguageCatalog::documents([]);

		foreach($level->sectors as $sector)
		{
			$this->high[$sector->index] = $sector->light;
			$neighbors = $level->neighbors($sector->index);
			$this->low[$sector->index] = $neighbors
				? min(array_map(static fn($neighbor): int => $neighbor->light, $neighbors))
				: max(0, $sector->light - 64);

			$this->phase[$sector->index] = 1;
			$this->glowDirection[$sector->index] = -1;
			$special = $this->special($sector->special);
			$this->timer[$sector->index] = in_array($special, [12,13], true) ? 1 : (($this->nextRandom() & 7) + 1);
		}
	}

	public function update(Registry $registry, float $dt): void
	{
		$this->remainder += max(0, $dt) * 35;
		$tics = min(8, (int)floor($this->remainder + 1e-9));

		if($tics < 1)return;

		$this->remainder -= $tics;

		for($i = 0; $i < $tics; $i++)
		{
			$this->levelTic++;
			$this->lights();
			$this->players($registry);
		}
	}

	private function lights(): void
	{
		foreach($this->level->sectors as $sector)
		{
			$id = $sector->index;
			$special = $this->special($sector->special);

			if($special === 8)
			{
				$next = $sector->light + $this->glowDirection[$id] * 8;

				if($next <= $this->low[$id])
				{
					$next = $this->low[$id];
					$this->glowDirection[$id] = 1;
				}
				elseif($next >= $this->high[$id])
				{
					$next = $this->high[$id];
					$this->glowDirection[$id] = -1;
				}

				$sector->setLight($next);
				continue;
			}

			if(!in_array($special, [1,2,3,12,13], true))continue;

			if(--$this->timer[$id] > 0)continue;

			$this->phase[$id] = 1 - $this->phase[$id];

			if($special === 1)
			{
				if($this->phase[$id])
				{
					$sector->setLight($this->high[$id]);
					$this->timer[$id] = ($this->nextRandom() & 63) + 1;
				}
				else
				{
					$sector->setLight($this->low[$id]);
					$this->timer[$id] = ($this->nextRandom() & 7) + 1;
				}

				continue;
			}

			if($this->phase[$id])
			{
				$sector->setLight($this->high[$id]);
				$this->timer[$id] = 5;
			}
			else
			{
				$sector->setLight($this->low[$id]);
				$this->timer[$id] = in_array($special, [2,13], true)?15:35;
			}
		}
	}

	private function players(Registry $registry): void
	{
		foreach($registry->query(
			Query::all(PlayerStart::class, PlayerState::class, Transform::class, HitPoints::class, ActorState::class)
		) as $entity
		){
			$player = $registry->get($entity, PlayerState::class);
			$pose = $registry->get($entity, Transform::class);
			$sector = $this->level->sectorAt($pose->x, $pose->y);
			$special = $this->special($sector->special);

			$onFloor = abs($pose->z - $sector->floorZ($pose->x, $pose->y)) <= .1;
			if(!$onFloor) $special = 0;
			$height = $registry->has($entity, Body::class) ? $registry->get($entity, Body::class)->height : 56;
			foreach($sector->volumes as $volume)
			{
				$touches = $volume->solid()
					? abs($pose->z - $volume->top($pose->x, $pose->y)) <= .1
					: $volume->overlaps($pose->x, $pose->y, $pose->z, $height);
				$modelSpecial = $this->special($volume->model->special);
				if($touches && in_array($modelSpecial, [5,7,16], true)) $special = $modelSpecial;
			}
			if(!$onFloor && !$special) continue;

			if($special === 9)
			{
				$sector->setSpecial(0);
				$player->secrets++;
				$player->showMessage($this->language->text('SECRETMESSAGE', 'A SECRET IS REVEALED'));
				continue;
			}

			if($special === 11)
			{
				$player->godMode = false;
				unset($player->powers['Invulnerable']);

				if($registry->get($entity, HitPoints::class)->current <= 10)
				{
					$player->exited = true;

					if($this->specials->onExit)($this->specials->onExit)(false);

					continue;
				}
			}

			if(($this->levelTic & 31) !== 0)continue;

			$damage = match($special){7 => 5,5 => 10,16,11 => 20,default => 0};

			if(!$damage)continue;

			$suited = isset($player->powers['IronFeet']);

			if($suited && ($special !== 16 || ($this->nextRandom() & 255) >= 5))continue;

			$this->damage($registry, $entity, $damage, $special === 11);
		}
	}

	private function damage(Registry $registry, int $entity, int $damage, bool $exit): void
	{
		if(!$this->authority) return;

		$player = $registry->get($entity, PlayerState::class);
		$hp = $registry->get($entity, HitPoints::class);

		// A corpse can remain on a damaging floor indefinitely. Do not keep subtracting
		// health or re-enter its first death frame on every 32-tic damage pulse.
		if($hp->current <= 0) return;

		if(($player->godMode || isset($player->powers['Invulnerable'])) && !$exit)return;

		$absorb = min($player->armor, (int)floor($damage * $player->armorClass / 100));
		$player->armor -= $absorb;
		$inflicted = $damage - $absorb;
		$hp->current -= $inflicted;

		if($inflicted > 0 && $registry->has($entity, ViewEffects::class))
		$registry->get($entity, ViewEffects::class)->recordDamage($inflicted);

		if($inflicted > 0 && $registry->has($entity, StatusFace::class))
		$registry->get($entity, StatusFace::class)->recordDamage($inflicted, FaceDirection::front());

		if($exit && $hp->current <= 10)
		{
			$player->exited = true;

			if($this->specials->onExit)($this->specials->onExit)(false);

			return;
		}

		if($hp->current <= 0)
		{
			$player->beginDeath();

			if($registry->has($entity, ViewEffects::class))
			$registry->get($entity, ViewEffects::class)->recordDeath();

			if($registry->has($entity, Body::class))
			$registry->get($entity, Body::class)->shootable = false;

			$state = $registry->get($entity, ActorState::class);

			if($state->definition->state('Death'))$state->enter('Death');
		}
	}

	private function nextRandom(): int
	{
		// PHP-WASM uses 32-bit integers. Split the ANSI LCG multiplication into 16-bit limbs so
		// every intermediate remains in range while preserving the exact modulo-2^31 sequence.
		$low = $this->random & 0xffff;
		$high = ($this->random >> 16) & 0x7fff;
		$lowProduct = $low * 20077 + 12345;
		$upper = ($low * 16838 + $high * 20077 + ($lowProduct >> 16)) & 0x7fff;
		$this->random = ($upper << 16) | ($lowProduct & 0xffff);

		return ($this->random >> 16) & 255;
	}

	private function special(int $number): int
	{
		if($this->level->map->format !== 'HEXEN') return $number;

		return match($number) { 65 => 1, 66 => 2, 67 => 3, 72 => 8, default => 0 };
	}
}

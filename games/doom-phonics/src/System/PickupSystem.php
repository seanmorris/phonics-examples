<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Definition\WeaponAmmo;
use DoomPhonics\Definition\LanguageCatalog;
use DoomPhonics\Game\PlayerState;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Network\Component\NetworkIdentity;

/** Applies DECORATE inventory semantics when a player overlaps a pickup actor. */
final class PickupSystem
{
	private Query $players;
	private LanguageCatalog $language;

	public function __construct(
		public ActorCatalog $actors
		, public bool $authoritative = true
		, ?LanguageCatalog $language = null
	){
		$this->players = Query::all(PlayerStart::class, PlayerState::class, Transform::class, HitPoints::class);
		$this->language = $language ?? LanguageCatalog::documents([]);
	}

	public function update(Registry $registry, float $dt): void
	{
		$pickups = $registry->query(Query::all(InventoryPickup::class, Transform::class));

		foreach($registry->query($this->players) as $playerEntity)
		{
			$pose = $registry->get($playerEntity, Transform::class);
			$player = $registry->get($playerEntity, PlayerState::class);
			$health = $registry->get($playerEntity, HitPoints::class);

			foreach($pickups as $entity)
			{
				if(!$registry->alive($entity) || $entity === $playerEntity
					|| !$registry->has($entity, InventoryPickup::class)
					|| (!$this->authoritative && $registry->has($entity, NetworkIdentity::class))
				) continue;

				$pickupPose = $registry->get($entity, Transform::class);
				$radius = $registry->has($entity, Body::class) ? $registry->get($entity, Body::class)->radius : 20;

				if(hypot($pose->x - $pickupPose->x, $pose->y - $pickupPose->y) > $radius + 16 || abs(
					$pose->z - $pickupPose->z
				) > 56
				)
				continue;

				$pickup = $registry->get($entity, InventoryPickup::class);
				$definition = $this->actors->named($pickup->actorName);
				$newWeapon = $definition && $this->actors->isA($definition, 'Weapon')
					&& empty($player->weapons[$definition->name]);

				if(!$definition || !$this->apply($definition, $pickup, $player, $health)) continue;

				// Remove collectability now so two players cannot claim one pickup in the
				// same tick and network snapshots can retire it without a duplicate claim.
				$registry->remove($entity, InventoryPickup::class);

				if($newWeapon && $registry->has($playerEntity, StatusFace::class))
				$registry->get($playerEntity, StatusFace::class)->recordWeaponPickup();

				if($definition->hasFlag('COUNTITEM')) $player->items++;

				$message = (string)$definition->value('Inventory.PickupMessage', $definition->name);
				$player->showMessage($this->language->resolve($message));

				if($registry->has($playerEntity, ViewEffects::class))
				$registry->get($playerEntity, ViewEffects::class)->recordPickup();

				$registry->defer(static function(Registry $r) use ($entity): void {
					if($r->alive($entity)) $r->destroy($entity);
				});
			}
		}
	}

	private function apply(
		ActorDefinition $definition
		, InventoryPickup $pickup
		, PlayerState $player
		, HitPoints $health
	): bool {
		$amount = $pickup->amount;

		if($this->actors->isA($definition, 'Health'))
		{
			$maximum = (int)$definition->value('Inventory.MaxAmount', 0) ?: $health->maximum;

			if($health->current >= $maximum && !$definition->hasFlag('INVENTORY.ALWAYSPICKUP')) return false;

			$health->current = min($maximum, $health->current + $amount);

			return true;
		}

		if($this->actors->isA($definition, 'BasicArmorPickup') || $this->actors->isA($definition, 'BasicArmorBonus'))
		{
			$save = (int)$definition->value('Armor.SaveAmount', 0);
			$maximum = (int)$definition->value('Armor.MaxSaveAmount', $save);

			if($this->actors->isA($definition, 'BasicArmorPickup'))
			{
				if($player->armor >= $save) return false;

				$player->armor = $save;
			}
			else $player->armor = min($maximum, $player->armor + $save);

			$player->armorClass = (int)round((float)$definition->value('Armor.SavePercent', 0));

			return true;
		}

		if($this->actors->isA($definition, 'Ammo'))
		{
			// Derived pickups supply their own amount to the base ammo class's inventory pool.
			$ammo = WeaponAmmo::pool($this->actors, $definition->name) ?? $definition;

			return $this->giveAmmo($player, $ammo->name, $amount, (int)$ammo->value('Inventory.MaxAmount', 0));
		}

		if($this->actors->isA($definition, 'Weapon'))
		{
			$new = empty($player->weapons[$definition->name]);
			$player->weapons[$definition->name] = true;
			$ammo = false;

			foreach([1,2] as $channel)
			{
				$type = WeaponAmmo::type($definition, $channel);
				$give = WeaponAmmo::give($definition, $channel);

				if($type === '' || $give <= 0) continue;

				if($pickup->dropped) $give = max(1, intdiv($give, 2));

				$pool = WeaponAmmo::pool($this->actors, $type);
				$name = $pool?->name ?? $type;

				$maximum = (int)($pool?->value(
					'Inventory.MaxAmount'
					, $player->ammoMaximum[$name] ?? 0
				) ?? ($player->ammoMaximum[$name] ?? 0));

				$ammo = $this->giveAmmo($player, $name, $give, $maximum) || $ammo;
			}

			if($new) $player->requestWeapon($definition->name);

			return $new || $ammo;
		}

		if($this->actors->isA($definition, 'Key'))
		{
			$new = empty($player->keys[$definition->name]);
			$player->keys[$definition->name] = true;

			return $new;
		}

		if($definition->name === 'Backpack')
		{
			foreach($player->ammoMaximum as $type => $maximum)
			$player->ammoMaximum[$type] = min($maximum * 2, $maximum + 300);

			foreach([
				'Clip' => 10
				, 'Shell' => 4
				, 'RocketAmmo' => 1
				, 'Cell' => 20
			] as $type => $give) $this->giveAmmo($player, $type, $give, $player->ammoMaximum[$type]);

			return true;
		}

		if($definition->name === 'Berserk')
		{
			$health->current = max($health->current, 100);
			$player->powers['Strength'] = PHP_FLOAT_MAX;
			$player->requestWeapon('Fist');

			return true;
		}

		if($definition->name === 'Soulsphere')
		{
			$health->current = min(200, $health->current + 100);

			return true;
		}

		if($definition->name === 'Allmap')
		{
			$player->powers['Allmap'] = PHP_FLOAT_MAX;

			return true;
		}

		if($this->actors->isA($definition, 'PowerupGiver'))
		{
			$type = (string)$definition->value('Powerup.Type', $definition->name);
			$player->powers[$type] = max($player->powers[$type] ?? 0, $type === 'IronFeet' ? 60 : 30);

			return true;
		}

		return $definition->hasFlag('INVENTORY.ALWAYSPICKUP');
	}

	private function giveAmmo(PlayerState $player, string $type, int $amount, int $maximum): bool
	{
		$current = $player->ammo[$type] ?? 0;
		$maximum = max($maximum, $player->ammoMaximum[$type] ?? 0);

		if($maximum <= 0 || $current >= $maximum) return false;

		$player->ammo[$type] = min($maximum, $current + $amount);
		$player->ammoMaximum[$type] = $maximum;

		return true;
	}
}

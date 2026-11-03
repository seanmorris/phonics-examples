<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Resolves the equivalent classic and numbered DECORATE weapon-ammo properties. */
final class WeaponAmmo
{

	public static function type(ActorDefinition $weapon, int $channel = 1): string
	{
		if($channel !== 1 && $channel !== 2)
		throw new \InvalidArgumentException('Weapon ammo channels are 1 or 2');

		$value = $channel === 1
			? $weapon->value('Weapon.AmmoType', $weapon->value('Weapon.AmmoType1', ''))
			: $weapon->value('Weapon.AmmoType2', '');

		return is_string($value) ? $value : '';
	}

	public static function use(ActorDefinition $weapon, int $channel = 1): int
	{
		if($channel !== 1 && $channel !== 2)
		throw new \InvalidArgumentException('Weapon ammo channels are 1 or 2');

		return max(
			0
			, (int)($channel === 1
				? $weapon->value('Weapon.AmmoUse', $weapon->value('Weapon.AmmoUse1', 0))
				: $weapon->value('Weapon.AmmoUse2', 0)
			)
		);
	}

	public static function give(ActorDefinition $weapon, int $channel = 1): int
	{
		if($channel !== 1 && $channel !== 2)
		throw new \InvalidArgumentException('Weapon ammo channels are 1 or 2');

		return max(
			0
			, (int)($channel === 1
				? $weapon->value('Weapon.AmmoGive', $weapon->value('Weapon.AmmoGive1', 0))
				: $weapon->value('Weapon.AmmoGive2', 0)
			)
		);
	}

	/** @return list<string> Distinct ammo inventory names used by either fire mode. */
	public static function types(ActorDefinition $weapon): array
	{
		$result = [];

		foreach([self::type($weapon),self::type($weapon, 2)] as $type)
		if($type !== '' && !isset($result[strtolower($type)])) $result[strtolower($type)] = $type;

		return array_values($result);
	}

	/** Resolve a pickup or weapon ammo name to the inventory pool directly derived from Ammo. */
	public static function pool(ActorCatalog $actors, string $type): ?ActorDefinition
	{
		$ammo = $actors->named($type);

		if(!$ammo || !$actors->isA($ammo, 'Ammo')) return null;

		while($ammo->parent !== null && strcasecmp($ammo->parent, 'Ammo') !== 0)
		{
			$parent = $actors->named($ammo->parent);

			if(!$parent) break;

			$ammo = $parent;
		}

		return $ammo;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Effective player weapon banks derived from one resolved WAD content stack. */
final class WeaponSlots
{

	/** @param array<int,list<string>> $slots Canonical actor names in selection order. */
	private function __construct(private array $slots) { ksort($this->slots); }

	/**
	 * Build banks with Player.WeaponSlot declarations taking precedence over each weapon's
	 * Weapon.SlotNumber fallback, then append KEYCONF declarations. A weapon keeps its first
	 * case-insensitive assignment across all three sources. The optional predicate can hide
	 * actors whose assets are absent.
	 *
	 * @param callable(ActorDefinition):bool|null $available
	 * @param array<string,string> $keyconf Layered KEYCONF documents in WAD precedence order.
	 */
	public static function fromCatalog(
		ActorCatalog $actors
		, ?callable $available = null
		, array $keyconf = []
	): self {
		$slots = [];
		$assigned = [];
		$accept = $available ?? static fn(ActorDefinition $actor): bool => true;
		$player = $actors->named('DoomPlayer');

		foreach($player?->values('Player.WeaponSlot') ?? [] as $declaration)
		{
			$slot = (int)array_shift($declaration);

			if($slot < 0 || $slot > 9) continue;

			foreach($declaration as $name)
			{
				if(!is_string($name) || $name === '') continue;

				$key = strtolower($name);
				$assigned[$key] = true;
				$definition = $actors->named($name);

				if(!$definition || !$actors->isA($definition, 'Weapon') || !$accept($definition)) continue;

				self::append($slots, $slot, $definition->name);
			}
		}

		foreach($actors->resolved() as $definition)
		{
			$key = strtolower($definition->name);

			if(isset($assigned[$key]) || !$actors->isA($definition, 'Weapon')) continue;

			$value = $definition->value('Weapon.SlotNumber');

			if(!is_int($value) && !is_float($value) && !is_string($value)) continue;

			$slot = (int)$value;

			if($slot < 0 || $slot > 9 || !$accept($definition)) continue;

			self::append($slots, $slot, $definition->name);
		}

		foreach(KeyConfParser::weaponSlots($keyconf) as $command)
		{
			$slot = $command['slot'];

			foreach($command['weapons'] as $name)
			{
				$definition = $actors->named($name);

				if(!$definition || !$actors->isA($definition, 'Weapon') || !$accept($definition)) continue;

				if(self::contains($slots, $definition->name)) continue;

				self::append($slots, $slot, $definition->name);
			}
		}

		$slots = array_filter($slots, static fn(array $weapons): bool => $weapons !== []);

		return new self($slots);
	}

	/** @return list<string> Weapons bound to one number key in declarative order. */
	public function slot(int $slot): array { return $this->slots[$slot] ?? []; }

	/** @return array<int,list<string>> Every nonempty number-key bank. */
	public function all(): array { return $this->slots; }

	/** @return list<string> Unique available weapons across every bank. */
	public function weapons(): array
	{
		$result = [];
		$seen = [];

		foreach($this->slots as $weapons)
		foreach($weapons as $weapon)
		if(!isset($seen[strtolower($weapon)]))
		{
			$seen[strtolower($weapon)] = true;
			$result[] = $weapon;
		}

		return $result;
	}

	/**
	 * Select the first owned weapon when entering a bank, then cycle its owned entries.
	 *
	 * @param array<string,bool> $owned Player inventory keyed by actor name.
	 */
	public function select(int $slot, string $current, array $owned): ?string
	{
		$inventory = [];

		foreach($owned as $name => $hasWeapon)
		if($hasWeapon) $inventory[strtolower($name)] = true;

		$weapons = array_values(
			array_filter($this->slot($slot), static fn(string $weapon): bool => isset($inventory[strtolower($weapon)]))
		);

		if(!$weapons) return null;

		foreach($weapons as $index => $weapon)
		if(strcasecmp($weapon, $current) === 0) return $weapons[($index + 1) % count($weapons)];

		return $weapons[0];
	}

	/** @param array<string,bool> $owned Move through all owned stack-available weapons. */
	public function step(string $current, array $owned, int $direction): ?string
	{
		if($direction === 0) return null;

		$inventory = [];

		foreach($owned as $name => $hasWeapon)
		if($hasWeapon) $inventory[strtolower($name)] = true;

		$weapons = array_values(
			array_filter($this->weapons(), static fn(string $weapon): bool => isset($inventory[strtolower($weapon)]))
		);

		if(!$weapons) return null;

		$index = 0;

		foreach($weapons as $candidate => $weapon)
		if(strcasecmp($weapon, $current) === 0)
		{
			$index = $candidate;
			break;
		}

		$count = count($weapons);
		$index = (($index + ($direction > 0 ? 1 : -1)) % $count + $count) % $count;

		return $weapons[$index];
	}

	/** @param array<int,list<string>> $slots */
	private static function append(array &$slots, int $slot, string $weapon): void
	{
		foreach($slots[$slot] ?? [] as $existing)
		if(strcasecmp($existing, $weapon) === 0) return;

		$slots[$slot][] = $weapon;
	}

	/** @param array<int,list<string>> $slots */
	private static function contains(array $slots, string $weapon): bool
	{
		foreach($slots as $weapons)
		foreach($weapons as $existing)
		if(strcasecmp($existing, $weapon) === 0) return true;

		return false;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\CheatCommand;
use DoomPhonics\Game\CheatResult;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\WeaponAmmo;
use DoomPhonics\Definition\WeaponSlots;
use DoomPhonics\Definition\LanguageCatalog;
use Phonics\Ecs\Registry;

/** Applies recognized cheats through the same typed player state used by ordinary pickups and combat. */
final class CheatSystem
{
	private const WEAPONS = [
		'Fist'
		, 'Chainsaw'
		, 'Pistol'
		, 'Shotgun'
		, 'SuperShotgun'
		, 'Chaingun'
		, 'RocketLauncher'
		, 'PlasmaRifle'
		, 'BFG9000'
	];
	private const KEYS = ['BlueCard','YellowCard','RedCard','BlueSkull','YellowSkull','RedSkull'];
	private const POWER_SECONDS = [
		'Invulnerable' => 30.0
		, 'Strength' => PHP_FLOAT_MAX
		, 'Invisibility' => 60.0
		, 'IronFeet' => 60.0
		, 'Allmap' => PHP_FLOAT_MAX
		, 'LightAmp' => 120.0
	];
	/** @var list<string> */
	private array $weapons;
	/** @var array<string,int> Active stack ammo pool name to maximum carried amount. */
	private array $ammoMaximum = [];
	private bool $oopsAllGibs = false;
	/**
	 * @var array{weapons:array<string,bool>,ammo:array<string,int>,infiniteAmmo:array<string,bool>,weapon:string}|null
	 */
	private ?array $oopsLoadout = null;
	private LanguageCatalog $language;

	public function __construct(
		?WeaponSlots $slots = null
		, ?ActorCatalog $actors = null
		, ?LanguageCatalog $language = null
	){
		$this->language = $language ?? LanguageCatalog::documents([]);
		$this->configure($slots, $actors, $language);
	}

	/** Refresh stack-dependent weapons without replacing the session's active cheat state. */
	public function configure(
		?WeaponSlots $slots = null
		, ?ActorCatalog $actors = null
		, ?LanguageCatalog $language = null
	): void {
		if($language !== null) $this->language = $language;

		$this->weapons = array_values(array_unique([
			...self::WEAPONS
			, ...($slots?->weapons() ?? [])
		]));

		$this->ammoMaximum = [];

		if($actors)
		{
			// IDFA/IDKFA promise all ammo in the active game, including mod-defined
			// helper pools that are manipulated with inventory actions rather than
			// named directly by a weapon's AmmoType property.
			foreach($actors->resolved() as $definition)
			{
				if(!$actors->isA($definition, 'Ammo')) continue;

				$pool = WeaponAmmo::pool($actors, $definition->name);

				if(!$pool) continue;

				$maximum = max(0, (int)$pool->value('Inventory.MaxAmount', 0));

				if($maximum > 0)
				$this->ammoMaximum[$pool->name] = max($this->ammoMaximum[$pool->name] ?? 0, $maximum);
			}
		}

		if($this->railgun() === null)
		{
			$this->oopsAllGibs = false;
			$this->oopsLoadout = null;
		}
	}

	/** The weapon currently replacing gun-bearing monster attacks, if any. */
	public function monsterWeapon(): ?string { return $this->oopsAllGibs ? 'Railgun' : null; }

	public function oopsAllGibsEnabled(): bool { return $this->oopsAllGibs; }

	/** Reapply session-owned cheat rules after a map load or player respawn. */
	public function applyActive(Registry $registry, int $entity): bool
	{
		if(!$this->oopsAllGibs) return false;

		if(!$registry->has($entity, PlayerState::class))
		throw new \InvalidArgumentException('Active cheats require a player entity');

		$railgun = $this->railgun();

		if($railgun === null)
		{
			$this->oopsAllGibs = false;
			$this->oopsLoadout = null;

			return false;
		}

		$this->equipOopsAllGibs($registry->get($entity, PlayerState::class), $railgun);

		return true;
	}

	public function apply(CheatCommand $command, Registry $registry, int $entity): CheatResult
	{
		if(!$registry->has($entity, PlayerState::class) || !$registry->has($entity, HitPoints::class))
		throw new \InvalidArgumentException('Cheats require a live player entity');

		$player = $registry->get($entity, PlayerState::class);
		$health = $registry->get($entity, HitPoints::class);

		switch($command->type)
		{
			case CheatCommand::GOD:
				$player->godMode = !$player->godMode;

				if($player->godMode) $health->current = max($health->current, min(100, $health->maximum));

				$player->showMessage(
					$this->language->text(
						$player->godMode ? 'STSTR_DQDON' : 'STSTR_DQDOFF'
						, 'DEGREELESSNESS MODE ' . ($player->godMode ? 'ON' : 'OFF')
					)
				);

				break;

			case CheatCommand::ARSENAL:
				$this->arsenal($player);
				$player->showMessage($this->language->text('STSTR_FAADDED', 'AMMO (NO KEYS) ADDED'));
				break;

			case CheatCommand::ARSENAL_AND_KEYS:
				$this->arsenal($player);

				foreach(self::KEYS as $key) $player->keys[$key] = true;
				$player->showMessage($this->language->text('STSTR_KFAADDED', 'VERY HAPPY AMMO ADDED'));
				break;

			case CheatCommand::NO_CLIP:
				$player->noClip = !$player->noClip;

				$player->showMessage(
					$this->language->text(
						$player->noClip ? 'STSTR_NCON' : 'STSTR_NCOFF'
						, 'NO CLIPPING MODE ' . ($player->noClip ? 'ON' : 'OFF')
					)
				);

				break;

			case CheatCommand::BEHOLD:
				$player->showMessage(
					$this->language->text('STSTR_BEHOLD', 'INvuln, Str, Invis, Rad, Allmap, or Lite-amp')
				);

				break;

			case CheatCommand::POWER:
				$this->power($player, $health, (string)$command->argument);
				break;

			case CheatCommand::CHOPPERS:
				$player->weapons['Chainsaw'] = true;
				$player->requestWeapon('Chainsaw');
				$player->powers['Invulnerable'] = self::POWER_SECONDS['Invulnerable'];
				$player->showMessage($this->language->text('STSTR_CHOPPERS', "... DOESN'T SUCK - GM"));
				break;

			case CheatCommand::POSITION:
				if($registry->has($entity, Transform::class))
				{
					$pose = $registry->get($entity, Transform::class);
					$angle = fmod(rad2deg($pose->angle) + 360, 360);
					$player->showMessage(sprintf('X %.0f Y %.0f Z %.0f A %.0f', $pose->x, $pose->y, $pose->z, $angle));
				}

				break;

			case CheatCommand::WARP:
				$map = (string)$command->argument;
				$player->showMessage('WARPING TO ' . $map);

				return CheatResult::warp($map);

			case CheatCommand::OOPS_ALL_GIBS:
				$this->oopsAllGibs($player);
				break;

			default:
				throw new \UnexpectedValueException('Unsupported cheat command ' . $command->type);
		}

		return CheatResult::applied();
	}

	private function oopsAllGibs(PlayerState $player): void
	{
		if($this->oopsAllGibs)
		{
			$this->oopsAllGibs = false;
			$loadout = $this->oopsLoadout;
			$this->oopsLoadout = null;

			if($loadout !== null)
			{
				$player->weapons = $loadout['weapons'];
				$player->ammo = $loadout['ammo'];
				$player->infiniteAmmo = $loadout['infiniteAmmo'];
				$player->pendingWeapon = null;
				$player->requestWeapon($loadout['weapon']);
			}
			else $player->infiniteAmmo = [];

			$player->showMessage('OOPS! ALL GIBS OFF');

			return;
		}

		$railgun = $this->railgun();

		if($railgun === null)
		{
			$player->showMessage('RAILGUN NOT AVAILABLE');

			return;
		}

		$this->oopsLoadout = [
			'weapons' => $player->weapons
			, 'ammo' => $player->ammo
			, 'infiniteAmmo' => $player->infiniteAmmo
			, 'weapon' => $player->pendingWeapon ?? $player->weapon
		];

		$this->oopsAllGibs = true;
		$this->equipOopsAllGibs($player, $railgun);
		$player->showMessage('OOPS! ALL GIBS');
	}

	private function equipOopsAllGibs(PlayerState $player, string $railgun): void
	{
		$player->weapons = [$railgun => true];
		$player->requestWeapon($railgun);
		$player->infiniteAmmo = ['Cell' => true];
		$player->ammo['Cell'] = $player->ammoMaximum['Cell'] ?? PHP_INT_MAX;
	}

	private function railgun(): ?string
	{
		foreach($this->weapons as $weapon)
		if(strcasecmp($weapon, 'Railgun') === 0) return $weapon;

		return null;
	}

	private function arsenal(PlayerState $player): void
	{
		foreach($this->weapons as $weapon) $player->weapons[$weapon] = true;

		foreach($this->ammoMaximum as $type => $maximum)
		$player->ammoMaximum[$type] = max($player->ammoMaximum[$type] ?? 0, $maximum);

		foreach($player->ammoMaximum as $type => $maximum) $player->ammo[$type] = $maximum;
		$player->armor = 200;
		$player->armorClass = 50;
	}

	private function power(PlayerState $player, HitPoints $health, string $name): void
	{
		if(!isset(self::POWER_SECONDS[$name]))
		throw new \UnexpectedValueException('Unsupported cheat power ' . $name);

		if(isset($player->powers[$name]))
		{
			unset($player->powers[$name]);
			$player->showMessage(strtoupper($name) . ' OFF');

			return;
		}

		$player->powers[$name] = self::POWER_SECONDS[$name];

		if($name === 'Strength')
		{
			$health->current = max($health->current, min(100, $health->maximum));
			$player->requestWeapon('Fist');
		}

		$player->showMessage(strtoupper($name) . ' ON');
	}
}

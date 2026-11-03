<?php
declare(strict_types=1);
namespace DoomPhonics\System;
use DoomPhonics\Input\DoomActions;

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Audio\NullSoundEmitter;
use DoomPhonics\Audio\SoundCatalog;
use DoomPhonics\Audio\SoundCue;
use DoomPhonics\Audio\SoundEmitter;
use DoomPhonics\Audio\SoundEvent;
use DoomPhonics\Audio\SoundListener;
use DoomPhonics\Audio\SoundSource;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\ChargeAttack;
use DoomPhonics\Component\FloorClip;
use DoomPhonics\Component\Gravity;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\ProjectileBounce;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\SpawnPoint;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\TransientEffect;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Component\WeaponOwner;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Definition\WeaponAmmo;
use DoomPhonics\Definition\WeaponSlots;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\DamageEvent;
use DoomPhonics\Game\ActorDropper;
use DoomPhonics\Game\BloodEffects;
use DoomPhonics\Game\DamageResolver;
use DoomPhonics\Game\DoomGravity;
use DoomPhonics\Game\EnemyAwareness;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\HitscanImpact;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\ProjectileTracer;
use DoomPhonics\Game\ProjectileImpact;
use DoomPhonics\Game\WorldPoint;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;

/** DECORATE action primitives for E1 weapons, monsters, missiles, pain, and death. */
final class CombatSystem
{
	private Query $players;
	private Query $projectiles;
	private Query $damageable;
	private Query $charges;
	private SoundEmitter $sound;
	private SoundCatalog $sounds;
	private ProjectileTracer $projectileTracer;
	private DamageResolver $damageResolver;
	private WeaponSlots $weaponSlots;
	private CheatSystem $cheats;
	private BloodEffects $blood;
	private EnemyAwareness $awareness;

	public function __construct(
		public Level $level
		, public ActorCatalog $actors
		, public ActorFactory $factory
		, public Collision $collision
		, public Controls $controls
		, public ActionDispatcher $actions
		, public ?LineSpecials $specials = null
		, ?SoundEmitter $sound = null
		, ?SoundCatalog $sounds = null
		, public float $gravity = DoomGravity::ACCELERATION
		, ?DamageResolver $damageResolver = null
		, ?WeaponSlots $weaponSlots = null
		, public bool $simulateProjectiles = true
		, public bool $simulateBeams = true
		, ?CheatSystem $cheats = null
		, ?BloodEffects $blood = null
		, ?EnemyAwareness $awareness = null
	){
		$this->players = Query::all(PlayerStart::class, PlayerState::class, Transform::class, HitPoints::class);
		$this->projectiles = Query::all(Projectile::class, Transform::class, Velocity::class, ActorState::class);
		$this->damageable = Query::all(Transform::class, Body::class, HitPoints::class, ActorState::class);
		$this->charges = Query::all(ChargeAttack::class, Transform::class, Velocity::class, Body::class);
		$this->sound = $sound ?? new NullSoundEmitter;
		$this->sounds = $sounds ?? new SoundCatalog;
		$this->projectileTracer = new ProjectileTracer($level);
		$this->damageResolver = $damageResolver ?? new DamageResolver(new ActorDropper($level, $factory));
		$this->weaponSlots = $weaponSlots ?? WeaponSlots::fromCatalog($actors);
		$this->cheats = $cheats ?? new CheatSystem($this->weaponSlots, $actors);
		$this->blood = $blood ?? new BloodEffects($level, $actors, $factory);
		$this->awareness = $awareness ?? new EnemyAwareness($level);
		$this->registerActions();
	}

	public function update(Registry $registry, float $dt): void
	{
		foreach($registry->query($this->players) as $entity)
		{
			$player = $registry->get($entity, PlayerState::class);

			if($registry->get($entity, HitPoints::class)->current <= 0)
			{
				$this->unequip($registry, $entity, $player);

				if($registry->has($entity, PlayerCommand::class))
				$registry->get($entity, PlayerCommand::class)->release();

				if($registry->has($entity, ViewInput::class))
				$registry->get($entity, ViewInput::class)->release();

				continue;
			}

			$this->selectWeapon($registry, $entity, $player);

			if($player->weaponEntity === null || !$registry->alive($player->weaponEntity))
			{
				$this->equip($registry, $entity, $player);
				continue;
			}

			$this->updateWeaponSwitch($registry, $entity, $player);
		}

		foreach($registry->query($this->charges) as $entity) $this->moveCharge($registry, $entity, $dt);

		foreach($registry->query($this->projectiles) as $entity) $this->moveProjectile($registry, $entity, $dt);

		foreach($registry->query(RemoteProjectile::class) as $entity)
		{
			$replica = $registry->get($entity, RemoteProjectile::class);
			$label = $registry->get($entity, ActorState::class)->label;

			if($label !== $replica->soundLabel)
			{
				if(strcasecmp($label, 'Death') === 0) $this->actorSound($entity, $registry, 'DeathSound');
				elseif($replica->soundLabel === null) $this->actorSound($entity, $registry, 'SeeSound');

				$replica->soundLabel = $label;
			}
		}
	}

	private function registerActions(): void
	{

		$register = fn(string $name, callable $callback) => $this->actions->register($name, $callback);

		$register(
			'A_WeaponReady'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->weaponReady($entity, $registry)
		);

		$register(
			'A_ReFire'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->weaponReady($entity, $registry)
		);

		$register(
			'A_Lower'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->lowerWeapon($entity, $registry)
		);

		$register(
			'A_Raise'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->raiseWeapon($entity, $registry)
		);

		$pistol = fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
			$entity
			, $registry
			, 1
			, 5
			, 1
			, 2048
			, 0
			, 'weapons/pistol'
		);

		$shotgun = fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
			$entity
			, $registry
			, 7
			, 5
			, 6
			, 2048
			, 0
			, 'weapons/shotgf'
		);

		$superShotgun = fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
			$entity
			, $registry
			, 20
			, 5
			, 11
			, 2048
			, 0
			, 'weapons/sshotf'
		);

		$chaingun = fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
			$entity
			, $registry
			, 1
			, 5
			, 5
			, 2048
			, strtoupper($frame->frame) === 'B' ? 1 : 0
			, 'weapons/chngun'
		);

		$register('A_FirePistol', $pistol);
		$register('A_FireShotgun', $shotgun);
		$register('A_FireShotgun2', $superShotgun);
		$register('A_FireCGun', $chaingun);

		$register(
			'A_FireBullets'
			, function(int $entity, StateFrame $frame, Registry $registry): void {
				$spread = (float)($frame->arguments[0] ?? 0);
				$verticalSpread = (float)($frame->arguments[1] ?? 0);
				$pellets = max(1, (int)($frame->arguments[2] ?? 1));
				$damage = max(0, (int)($frame->arguments[3] ?? 5));
				$puffArgument = $frame->arguments[4] ?? 'BulletPuff';
				$puff = $puffArgument === 0 || $puffArgument === 0.0
					? 'BulletPuff'
					: (string)$puffArgument;

				$flags = (int)($frame->arguments[5] ?? 1);
				$range = (float)($frame->arguments[6] ?? 0);
				$attackSound = $registry->has($entity, ActorState::class)
					? $registry->get($entity, ActorState::class)->definition->value('AttackSound')
					: null;

				$this->playerHitscan(
					$entity
					, $registry
					, $pellets
					, $damage
					, $spread
					, $range > 0 ? $range : 2048
					, null
					, is_string($attackSound) && $attackSound !== '' ? $attackSound : null
					, $puff
					, $verticalSpread
					, ($flags & 1) !== 0
				);
			}
		);

		$register(
			'A_PlaySound'
			, function(int $entity, StateFrame $frame, Registry $registry): void {
				$logical = (string)($frame->arguments[0] ?? '');

				if($logical !== '') $this->playLogicalSound($entity, $registry, $logical);
			}
		);

		$register(
			'A_PlayWeaponSound'
			, function(int $entity, StateFrame $frame, Registry $registry): void {
				$logical = (string)($frame->arguments[0] ?? '');

				if($logical !== '') $this->playLogicalSound($entity, $registry, $logical);
			}
		);

		$register(
			'A_JumpIfInventory'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->jumpIfInventory(
				$entity
				, $frame
				, $registry
			)
		);

		$register(
			'A_JumpIfNoAmmo'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->jumpIfNoAmmo($entity, $frame, $registry)
		);

		$register(
			'A_GiveInventory'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->changeInventory(
				$entity
				, $frame
				, $registry
				, 1
			)
		);

		$register(
			'A_TakeInventory'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->changeInventory(
				$entity
				, $frame
				, $registry
				, -1
			)
		);

		foreach(['A_CheckForReload','A_ResetReloadCounter'] as $name)
		$register($name, static function(int $entity, StateFrame $frame, Registry $registry): void {});

		$register(
			'A_Punch'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerPunch($entity, $registry)
		);

		$register(
			'A_Saw'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
				$entity
				, $registry
				, 1
				, 8
				, 3
				, 72
				, null
				, 'weapons/sawfull'
			)
		);

		$register(
			'A_FireMissile'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'Rocket'
			)
		);

		$register(
			'A_FirePlasma'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'PlasmaBall'
				, mt_rand(0, 1)
			)
		);

		$register(
			'A_FireBFG'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'BFGBall'
			)
		);

		$register(
			'A_FireSTGrenade'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'Grenade'
			)
		);

		$register(
			'A_FireCustomMissile'
			, function(int $entity, StateFrame $frame, Registry $registry): void {
				$projectile = (string)($frame->arguments[0] ?? '');

				if($projectile === '') return;

				$useAmmo = $frame->arguments[2] ?? 1;
				$useAmmo = !in_array(strtolower((string)$useAmmo), ['0','false','no'], true);

				$this->playerMissile(
					$entity
					, $registry
					, $projectile
					, null
					, $useAmmo
					, (float)($frame->arguments[1] ?? 0)
					, (float)($frame->arguments[3] ?? 0)
					, (float)($frame->arguments[4] ?? 0)
					, (float)($frame->arguments[6] ?? 0)
				);
			}
		);

		$register(
			'A_FireRailgun'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->fireRailgun($entity, $registry)
		);

		$register(
			'A_GunFlash'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->gunFlashFromFrame(
				$entity
				, $frame
				, $registry
			)
		);

		$register(
			'A_Light0'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->weaponLight($entity, $registry, 0)
		);

		$register(
			'A_Light1'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->weaponLight($entity, $registry, 1)
		);

		$register(
			'A_Light2'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->weaponLight($entity, $registry, 2)
		);

		$register(
			'A_BFGSound'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/bfgf'
			)
		);

		$register(
			'A_BFGSpray'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->bfgSpray($entity, $registry)
		);

		$register('A_Look', fn(int $entity, StateFrame $frame, Registry $registry) => $this->look($entity, $registry));

		$register(
			'A_Chase'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->chase($entity, $registry)
		);

		$register(
			'A_FaceTarget'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->faceTarget($entity, $registry)
		);

		$register(
			'A_PosAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterHitscan($entity, $registry, 1)
		);

		$register(
			'A_SPosAttackUseAtkSound'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterHitscan($entity, $registry, 3)
		);

		$register(
			'A_CPosAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterHitscan($entity, $registry, 1)
		);

		$register(
			'A_TroopAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterAttack(
				$entity
				, $registry
				, 'DoomImpBall'
				, 3
			)
		);

		$register(
			'A_SargAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterMelee($entity, $registry, 10)
		);

		$register(
			'A_BruisAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterAttack(
				$entity
				, $registry
				, 'BaronBall'
				, 8
			)
		);

		$register(
			'A_HeadAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterAttack(
				$entity
				, $registry
				, 'CacodemonBall'
				, 10
			)
		);

		$register(
			'A_CyberAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->monsterProjectile(
				$entity
				, $registry
				, 'Rocket'
			)
		);

		$register(
			'A_SkullAttack'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->skullAttack($entity, $registry)
		);

		$register(
			'A_SpidRefire'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->spiderRefire($entity, $registry)
		);

		$register(
			'A_CPosRefire'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->spiderRefire($entity, $registry)
		);

		$register(
			'A_Pain'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->actorSound(
				$entity
				, $registry
				, 'PainSound'
			)
		);

		$register(
			'A_Scream'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->actorSound(
				$entity
				, $registry
				, 'DeathSound'
			)
		);

		$register(
			'A_PlayerScream'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->actorSound(
				$entity
				, $registry
				, 'DeathSound'
			)
		);

		$register(
			'A_XScream'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'misc/gibbed'
			)
		);

		$register(
			'A_Respawn'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->respawn($entity, $registry)
		);

		$register(
			'A_NoBlocking'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->removeBlocking($entity, $registry)
		);

		$register(
			'A_BarrelDestroy'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->removeBlocking($entity, $registry)
		);

		$register(
			'A_Explode'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->explode($entity, $registry)
		);

		$register(
			'A_Detonate'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->explode($entity, $registry)
		);

		$register(
			'A_SetFloorClip'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->setFloorClip($entity, $registry)
		);

		$register(
			'A_UnSetFloorClip'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->unsetFloorClip($entity, $registry)
		);

		$register(
			'A_BossDeath'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->bossDeath($entity, $registry)
		);

		// The reference renderer used these names for hard-coded weapon timelines. Keep them as
		// aliases over the same DECORATE/ECS primitives so imported definitions do not need a
		// second combat implementation.
		$register('A_PISTOL_FIRE', $pistol);
		$register('A_SHOTGUN_FIRE', $shotgun);
		$register('A_SUPERSHOTGUN_FIRE', $superShotgun);
		$register('A_CHAINGUN_FIRE', $chaingun);

		$register(
			'A_PUNCH_MELEE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerPunch($entity, $registry)
		);

		$register(
			'A_SAW_INIT'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/sawup'
			)
		);

		$register(
			'A_SAW_IDLE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/sawidle'
			)
		);

		$register(
			'A_SAW_MELEE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
				$entity
				, $registry
				, 1
				, 8
				, 3
				, 72
				, null
				, 'weapons/sawfull'
			)
		);

		$register(
			'A_SAW_MELEE_SILENT'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerHitscan(
				$entity
				, $registry
				, 1
				, 8
				, 3
				, 72
			)
		);

		$register(
			'A_SUPERSHOTGUN_RELOAD'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/shotgr'
			)
		);

		$register(
			'A_ROCKET_FIRE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'Rocket'
				, 0
			)
		);

		$register(
			'A_PLASMA_FIRE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'PlasmaBall'
				, mt_rand(0, 1)
			)
		);

		$register(
			'A_BFG9K_FIRE_START'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/bfgf'
			)
		);

		$register(
			'A_BFG9K_FIRE_LAUNCH'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'BFGBall'
				, 0
			)
		);

		$register(
			'A_GRENADE_FIRE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'Grenade'
				, 0
			)
		);

		$register(
			'A_RAILGUN_FIRE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->fireRailgun($entity, $registry)
		);

		$register(
			'A_BFG10K_IDLE'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/bfg10kidle'
			)
		);

		$register(
			'A_BFG10K_FIRE_START'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playLogicalSound(
				$entity
				, $registry
				, 'weapons/bfg10k'
			)
		);

		$register(
			'A_BFG10K_FIRE_LAUNCH'
			, fn(int $entity, StateFrame $frame, Registry $registry) => $this->playerMissile(
				$entity
				, $registry
				, 'BFGBall'
				, 0
			)
		);
	}

	private function equip(Registry $registry, int $owner, PlayerState $player): void
	{
		$this->unequip($registry, $owner, $player);

		$definition = $this->actors->named($player->weapon) ?? $this->actors->named('Pistol');

		if(!$definition) throw new \UnexpectedValueException('DECORATE has no player weapon ' . $player->weapon);

		$player->weaponEntity = $registry->create(new ActorState($definition, 'Ready'), new WeaponOwner($owner));
	}

	private function unequip(Registry $registry, int $owner, PlayerState $player): void
	{
		if($player->weaponEntity !== null)
		{
			$this->clearWeaponOverlays($registry, $player->weaponEntity);

			if($registry->alive($player->weaponEntity)) $registry->destroy($player->weaponEntity);

			$player->weaponEntity = null;
		}

		if($registry->has($owner, ViewLighting::class))
		$registry->get($owner, ViewLighting::class)->clearWeaponFlash();
	}

	private function selectWeapon(Registry $registry, int $entity, PlayerState $player): void
	{
		$current = $player->pendingWeapon ?? $player->weapon;
		$command = $registry->has($entity, PlayerCommand::class)
			? $registry->get($entity, PlayerCommand::class)
			: null;

		$view = $registry->has($entity, ViewInput::class)
			? $registry->get($entity, ViewInput::class)
			: null;

		$requestedSlot = $command ? $command->consumeWeaponSlot() : $view?->consumeWeaponSlot();

		if($requestedSlot === null && !$command)
		foreach(array_keys($this->weaponSlots->all()) as $slot)
		if($this->controls->pressed(DoomActions::SLOTS[$slot]))
		{
			$requestedSlot = $slot;
			break;
		}

		if($requestedSlot !== null)
		{
			$weapon = $this->weaponSlots->select($requestedSlot, $current, $player->weapons);

			if($weapon !== null) $player->requestWeapon($weapon);
		}

		$steps = $command?->consumeWeaponSteps() ?? $view?->consumeWeaponSteps() ?? 0;

		if(!$command)
		$steps += (int)$this->controls->pressed(DoomActions::NEXT_WEAPON) - (int)$this->controls->pressed(
			DoomActions::PREVIOUS_WEAPON
		);

		for($step = 0; $step < abs($steps); $step++)
		{
			$weapon = $this->weaponSlots->step($current, $player->weapons, $steps);

			if($weapon === null) break;

			$current = $weapon;
			$player->requestWeapon($weapon);
		}
	}

	private function updateWeaponSwitch(Registry $registry, int $owner, PlayerState $player): void
	{
		$weapon = $player->weaponEntity;

		if($weapon === null || !$registry->has($weapon, ActorState::class)) return;

		$state = $registry->get($weapon, ActorState::class);
		$current = $state->definition->name;

		// Keep direct PlayerState assignments as a compatibility request rather than snapping.
		if($player->pendingWeapon === null && strcasecmp($current, $player->weapon) !== 0)
		{
			$player->pendingWeapon = $player->weapon;
			$player->weapon = $current;
		}

		if($player->pendingWeapon === null)
		{
			if(strcasecmp($state->label, 'Deselect') === 0) $state->enter('Select');

			return;
		}

		if(strcasecmp($player->pendingWeapon, $current) === 0)
		{
			$player->pendingWeapon = null;

			return;
		}

		if(strcasecmp($state->label, 'Deselect') === 0) return;

		$this->clearWeaponOverlays($registry, $weapon);

		if($state->definition->state('Deselect')) $state->enter('Deselect');
		else $this->finishWeaponSwitch($weapon, $registry);
	}

	private function lowerWeapon(int $weapon, Registry $registry): void
	{
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$owner = $registry->get($weapon, WeaponOwner::class);
		$owner->offsetY = min(96, $owner->offsetY + 6);

		if($owner->offsetY >= 96) $this->finishWeaponSwitch($weapon, $registry);
	}

	private function raiseWeapon(int $weapon, Registry $registry): void
	{
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$owner = $registry->get($weapon, WeaponOwner::class);
		$owner->offsetY = max(0, $owner->offsetY - 6);

		if($owner->offsetY <= 0) $registry->get($weapon, ActorState::class)->enter('Ready');
	}

	private function finishWeaponSwitch(int $weapon, Registry $registry): void
	{
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$owner = $registry->get($weapon, WeaponOwner::class);

		if(!$registry->alive($owner->entity) || !$registry->has($owner->entity, PlayerState::class)) return;

		$player = $registry->get($owner->entity, PlayerState::class);
		$name = $player->pendingWeapon ?? $player->weapon;
		$definition = $this->actors->named($name);

		if(!$definition || !$this->actors->isA($definition, 'Weapon'))
		throw new \UnexpectedValueException('DECORATE has no player weapon ' . $name);

		$this->clearWeaponOverlays($registry, $weapon);
		$player->weapon = $definition->name;
		$player->pendingWeapon = null;
		$state = $registry->get($weapon, ActorState::class);
		$state->definition = $definition;
		$owner->offsetY = 96;
		$state->enter($definition->state('Select') ? 'Select' : 'Ready');

		if(!$definition->state('Select')) $owner->offsetY = 0;
	}

	private function clearWeaponOverlays(Registry $registry, int $weapon): void
	{
		foreach($registry->query(WeaponOverlay::class) as $entity)
		if($registry->get($entity, WeaponOverlay::class)->weaponEntity === $weapon)
		$registry->destroy($entity);
	}

	private function weaponReady(int $weapon, Registry $registry): void
	{
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$state = $registry->get($weapon, ActorState::class);
		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner) || !$registry->has($owner, PlayerState::class)) return;

		$command = $registry->has($owner, PlayerCommand::class)
			? $registry->get($owner, PlayerCommand::class)
			: null;

		$view = $registry->has($owner, ViewInput::class) ? $registry->get($owner, ViewInput::class) : null;
		$fire = $command?->fire ?? ($this->controls->down(DoomActions::FIRE) || ($view?->fire ?? false));
		$altFire = $command?->altFire ?? ($this->controls->down(DoomActions::ALT_FIRE) || ($view?->altFire ?? false));
		$label = strtolower($state->label);

		// A_ReFire remains on the mode whose state is already active.
		if($label === 'fire') $altFire = false;
		elseif($label === 'altfire') $fire = false;

		if($altFire && $state->definition->state('AltFire'))
		{
			if($state->definition->hasFlag('ammo_optional')
				|| $this->hasAmmo($registry->get($owner, PlayerState::class), $state->definition, 2)
			){
				$this->awareness->alert($registry, $owner, $owner);
				$state->enter('AltFire');
			}

			return;
		}

		if(!$fire || !$state->definition->state('Fire')) return;

		if($state->definition->hasFlag('ammo_optional')
			|| $this->hasAmmo($registry->get($owner, PlayerState::class), $state->definition)
		){
			$this->awareness->alert($registry, $owner, $owner);
			$state->enter('Fire');
		}
	}

	private function hasAmmo(PlayerState $player, ActorDefinition $weapon, int $channel = 1): bool
	{
		$type = WeaponAmmo::type($weapon, $channel);
		$type = WeaponAmmo::pool($this->actors, $type)?->name ?? $type;
		$use = WeaponAmmo::use($weapon, $channel);

		return $type === '' || $use === 0 || isset($player->infiniteAmmo[$type])
			|| ($player->ammo[$type] ?? 0) >= $use;
	}

	private function consumeAmmo(PlayerState $player, ActorDefinition $weapon, int $channel = 1): bool
	{
		if(!$this->hasAmmo($player, $weapon, $channel)) return false;

		$type = WeaponAmmo::type($weapon, $channel);
		$type = WeaponAmmo::pool($this->actors, $type)?->name ?? $type;
		$use = WeaponAmmo::use($weapon, $channel);

		if($type !== '' && $use > 0 && !isset($player->infiniteAmmo[$type])) $player->ammo[$type] -= $use;

		return true;
	}

	private function jumpIfInventory(int $entity, StateFrame $frame, Registry $registry): void
	{
		$owner = $this->actionOwner($entity, $registry);
		$name = (string)($frame->arguments[0] ?? '');
		$amount = max(1, (int)($frame->arguments[1] ?? 1));
		$offset = (int)($frame->arguments[2] ?? 0);

		if($owner === null || $name === '' || $offset === 0 || !$registry->has($owner, PlayerState::class)
			|| !$registry->has($entity, ActorState::class)
			|| $this->inventoryAmount($registry->get($owner, PlayerState::class), $name) < $amount
		)
		return;

		$state = $registry->get($entity, ActorState::class);
		$state->frame = max(0, $state->frame + $offset);
		$state->ticks = 0;
		$state->entered = false;
	}

	private function jumpIfNoAmmo(int $entity, StateFrame $frame, Registry $registry): void
	{
		$weapon = $this->actionWeapon($entity, $registry);
		$offset = (int)($frame->arguments[0] ?? 0);

		if($weapon === null || $offset === 0) return;

		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner) || !$registry->has($owner, PlayerState::class)) return;

		$state = $registry->get($entity, ActorState::class);

		if($this->hasAmmo(
			$registry->get($owner, PlayerState::class)
			, $state->definition
			, $this->ammoChannel($entity, $registry)
		)) return;

		$state->frame = max(0, $state->frame + $offset);
		$state->ticks = 0;
		$state->entered = false;
	}

	private function changeInventory(int $entity, StateFrame $frame, Registry $registry, int $direction): void
	{
		$owner = $this->actionOwner($entity, $registry);
		$name = (string)($frame->arguments[0] ?? '');
		$amount = max(0, (int)($frame->arguments[1] ?? 1));

		if($owner === null || $name === '' || $amount === 0 || !$registry->has($owner, PlayerState::class)) return;

		$player = $registry->get($owner, PlayerState::class);
		$definition = $this->actors->named($name);
		$pool = WeaponAmmo::pool($this->actors, $name);

		if($pool !== null)
		{
			$key = $this->inventoryKey($player->ammo, $pool->name) ?? $pool->name;
			$maximum = max(0, $player->ammoMaximum[$key] ?? (int)$pool->value('Inventory.MaxAmount', 0));
			$player->ammo[$key] = max(0, min($maximum, ($player->ammo[$key] ?? 0) + $direction * $amount));

			return;
		}

		if($definition !== null && $this->actors->isA($definition, 'Weapon'))
		{
			$key = $this->inventoryKey($player->weapons, $definition->name) ?? $definition->name;

			if($direction > 0) $player->weapons[$key] = true;
			else unset($player->weapons[$key]);

			return;
		}

		$key = $this->inventoryKey($player->inventory, $definition?->name ?? $name) ?? ($definition?->name ?? $name);
		$maximum = max(1, (int)($definition?->value('Inventory.MaxAmount', 1) ?? 1));
		$player->inventory[$key] = max(0, min($maximum, ($player->inventory[$key] ?? 0) + $direction * $amount));
	}

	private function inventoryAmount(PlayerState $player, string $name): int
	{
		$pool = WeaponAmmo::pool($this->actors, $name);

		if($pool !== null)
		{
			$key = $this->inventoryKey($player->ammo, $pool->name);

			return $key === null ? 0 : max(0, (int)$player->ammo[$key]);
		}

		$definition = $this->actors->named($name);

		if($definition !== null && $this->actors->isA($definition, 'Weapon'))
		{
			$key = $this->inventoryKey($player->weapons, $definition->name);

			return $key !== null && !empty($player->weapons[$key]) ? 1 : 0;
		}

		$key = $this->inventoryKey($player->inventory, $definition?->name ?? $name);

		return $key === null ? 0 : max(0, (int)$player->inventory[$key]);
	}

	/** @param array<string,mixed> $inventory */
	private function inventoryKey(array $inventory, string $name): ?string
	{
		foreach(array_keys($inventory) as $key)
		if(strcasecmp((string)$key, $name) === 0) return (string)$key;

		return null;
	}

	private function gunFlashFromFrame(int $weapon, StateFrame $frame, Registry $registry): void
	{
		$state = $registry->get($weapon, ActorState::class);
		$default = strtolower($state->label) === 'altfire' ? 'AltFlash' : 'Flash';
		$label = isset($frame->arguments[0]) ? (string)$frame->arguments[0] : $default;

		if($label === '') $label = $default;

		$flags = $frame->arguments[1] ?? 0;
		$animateOwner = !($flags === 'GFF_NOEXTCHANGE' || $flags === 1 || $flags === '1');

		$this->gunFlash($weapon, $registry, $label, 0, $animateOwner);
	}

	private function gunFlash(
		int $weapon
		, Registry $registry
		, string $label = 'Flash'
		, int $offset = 0
		, bool $animateOwner = true
	): void {
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner)) return;

		$weaponState = $registry->get($weapon, ActorState::class);
		$sequence = $weaponState->definition->state($label);

		if(!$sequence || !isset($sequence->frames[$offset])) return;

		foreach($registry->query(WeaponOverlay::class) as $entity)
		{
			$overlay = $registry->get($entity, WeaponOverlay::class);

			if($overlay->weaponEntity === $weapon)
			$registry->get($entity, ActorState::class)->stopped = true;
		}

		$flash = new ActorState($weaponState->definition, $label);
		$flash->enter($label, $offset);
		$registry->create($flash, new WeaponOverlay($owner, $weapon));

		if($animateOwner && $registry->has($owner, ActorState::class)
			&& $registry->get($owner, ActorState::class)->definition->state('Missile')
		)
		$registry->get($owner, ActorState::class)->enter('Missile');
	}

	private function weaponLight(int $entity, Registry $registry, int $steps): void
	{
		$owner = $this->actionOwner($entity, $registry);

		if($owner === null || !$registry->has($owner, ViewLighting::class)) return;

		$lighting = $registry->get($owner, ViewLighting::class);

		if($steps <= 0) $lighting->clearWeaponFlash();
		elseif($steps === 1) $lighting->useWeaponFlash();
		else $lighting->useBrightWeaponFlash();
	}

	private function playerHitscan(
		int $actionEntity
		, Registry $registry
		, int $pellets
		, int $damage
		, float $spread
		, float $range = 2048
		, ?int $flashOffset = null
		, ?string $sound = null
		, string $puff = 'BulletPuff'
		, float $verticalSpread = 0
		, bool $useAmmo = true
	): void {
		$weapon = $this->actionWeapon($actionEntity, $registry);

		if($weapon === null) return;

		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner)) return;

		$state = $registry->get($weapon, ActorState::class);
		$player = $registry->get($owner, PlayerState::class);

		if($useAmmo && !$this->consumeAmmo($player, $state->definition, $this->ammoChannel($actionEntity, $registry)))
		{
			$state->enter('Ready');

			return;
		}

		if($flashOffset !== null) $this->gunFlash($weapon, $registry, 'Flash', $flashOffset);

		if($sound !== null) $this->playLogicalSound($weapon, $registry, $sound);

		$pose = $registry->get($owner, Transform::class);
		$aimSlope = $registry->has($owner, ViewInput::class)
			? $registry->get($owner, ViewInput::class)->aimSlope()
			: 0.0;

		for($i = 0; $i < $pellets; $i++)
		{
			$spreadRange = (int)round($spread * 100);
			$verticalRange = (int)round($verticalSpread * 100);
			$offset = $pellets === 1 || $spreadRange <= 0
				? 0.0
				: deg2rad(mt_rand(-$spreadRange, $spreadRange) / 100);

			$slopeOffset = $pellets === 1 || $verticalSpread <= 0
				? 0.0
				: tan(deg2rad(mt_rand(-$verticalRange, $verticalRange) / 100));

			$impact = $this->hitscanResult(
				$registry
				, $owner
				, $pose->x
				, $pose->y
				, $pose->z + 41
				, $pose->angle + $offset
				, $range
				, $aimSlope + $slopeOffset
			);

			if($impact->target !== null)
			{
				if($this->usesPuff($registry, $impact->target))
				$this->spawnPuff($registry, $puff, $impact, $pose);

				$this->damage(
					$registry
					, $impact->target
					, $owner
					, $damage * mt_rand(1, 3)
					, null
					, true
					, $impact
					, $weapon
				);
			}
			elseif($impact->geometry !== null && !$impact->geometry->escapesIntoSky())
			{
				$this->spawnPuff($registry, $puff, $impact, $pose);

				if($impact->geometry->line !== null && $this->specials)
				$this->specials->shoot($impact->geometry->line, $impact->geometry->sector, $player, $pose);
			}
		}
	}

	private function fireRailgun(int $weapon, Registry $registry): void
	{
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner)) return;

		$state = $registry->get($weapon, ActorState::class);
		$player = $registry->get($owner, PlayerState::class);

		if(!$this->consumeAmmo($player, $state->definition, $this->ammoChannel($weapon, $registry)))
		{
			$state->enter('Ready');

			return;
		}

		$this->gunFlash($weapon, $registry, 'Flash', 0);
		$sound = $registry->has($weapon, ActorState::class)
			? $registry->get($weapon, ActorState::class)->definition->value('AttackSound')
			: null;

		if(is_string($sound) && $sound !== '') $this->playLogicalSound($weapon, $registry, $sound);

		// Guests predict weapon feedback; the host resolves the rail and publishes its beam.
		if(!$this->simulateBeams) return;

		$pose = $registry->get($owner, Transform::class);
		$slope = $registry->has($owner, ViewInput::class)
			? $registry->get($owner, ViewInput::class)->aimSlope()
			: 0.0;

		$range = 6400.0;
		$originZ = $pose->z + 41;
		$trace = $this->hitscanTrace($pose->x, $pose->y, $originZ, $pose->angle, $range, $slope);
		$end = $trace['surface'];
		$targets = $this->hitscanActorImpacts($registry, $owner, $trace['path']['segments'], $range, $end->distance);

		foreach($targets as $targetHit)
		{
			$impact = $targetHit['impact'];
			$target = $impact->target;

			if($target === null) continue;

			if($this->usesPuff($registry, $target))
			$this->spawnPuff($registry, 'BulletPuff', $impact, $targetHit['source']);

			// The reference rail passes through actors and applies one fixed 100-point hit.
			$this->damage($registry, $target, $owner, 100, null, true, $impact, $weapon);
		}

		if($end->geometry !== null && !$end->geometry->escapesIntoSky())
		{
			$this->spawnPuff($registry, 'BulletPuff', $end, $pose);

			if($end->geometry->line !== null && $this->specials)
			$this->specials->shoot($end->geometry->line, $end->geometry->sector, $player, $pose);
		}

		// The reference line begins eight units below its view camera. Preserve that
		// relationship on the first segment; later segments begin at the portal exit.
		$this->spawnBeamPath($registry, $trace['path']['segments'], 8);
	}

	private function playerMissile(
		int $actionEntity
		, Registry $registry
		, string $projectile
		, ?int $flashOffset = null
		, bool $useAmmo = true
		, float $angleOffset = 0
		, float $sideOffset = 0
		, float $heightOffset = 0
		, float $pitchOffset = 0
	): void {
		$weapon = $this->actionWeapon($actionEntity, $registry);

		if($weapon === null) return;

		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner)) return;

		$state = $registry->get($weapon, ActorState::class);
		$player = $registry->get($owner, PlayerState::class);

		if($useAmmo && !$this->consumeAmmo($player, $state->definition, $this->ammoChannel($actionEntity, $registry)))
		{
			$state->enter('Ready');

			return;
		}

		if($flashOffset !== null) $this->gunFlash($weapon, $registry, 'Flash', $flashOffset);

		$pose = $registry->get($owner, Transform::class);
		$aimPitch = $registry->has($owner, ViewInput::class)
			? $registry->get($owner, ViewInput::class)->pitch
			: 0.0;

		$angle = $pose->angle + deg2rad($angleOffset);
		$sideAngle = $pose->angle - M_PI / 2;

		$this->spawnProjectile(
			$registry
			, $owner
			, $projectile
			, $pose->x + cos($sideAngle) * $sideOffset
			, $pose->y + sin($sideAngle) * $sideOffset
			, $pose->z + 32 + $heightOffset
			, $angle
			, null
			, tan($aimPitch + deg2rad($pitchOffset))
		);
	}

	private function playerPunch(int $weapon, Registry $registry): void
	{
		if(!$registry->has($weapon, WeaponOwner::class)) return;

		$owner = $registry->get($weapon, WeaponOwner::class)->entity;

		if(!$registry->alive($owner) || !$registry->has($owner, PlayerState::class)) return;

		$player = $registry->get($owner, PlayerState::class);
		$damage = isset($player->powers['Strength']) ? 100 : 10;
		$this->playerHitscan($weapon, $registry, 1, $damage, 6, 64);
	}

	/** Doom checks at most two active player slots per A_Look call. */
	private function lookForPlayer(int $entity, Registry $registry, bool $allAround): ?int
	{
		if(!$registry->has($entity, MonsterBrain::class) || !$registry->has($entity, Transform::class)) return null;

		$players = $registry->query($this->players);

		usort(
			$players
			, static fn(int $a, int $b): int => $registry->get($a, PlayerStart::class)->number <=> $registry->get(
				$b
				, PlayerStart::class
			)->number
		);

		$count = count($players);

		if($count === 0) return null;

		$brain = $registry->get($entity, MonsterBrain::class);
		$source = $registry->get($entity, Transform::class);
		$checks = min(2, $count);

		for($attempt = 0; $attempt < $checks; $attempt++)
		{
			$index = $brain->lastLook % $count;
			$player = $players[$index];
			$brain->lastLook = ($index + 1) % $count;

			if(!$this->validTarget($registry, $player)) continue;

			$target = $registry->get($player, Transform::class);

			if(!$this->visible($source, $target)) continue;

			if(!$allAround)
			{
				$angle = atan2($target->y - $source->y, $target->x - $source->x);
				$delta = atan2(sin($angle - $source->angle), cos($angle - $source->angle));
				$distance = hypot($target->x - $source->x, $target->y - $source->y);

				if(abs($delta) > M_PI / 2 && $distance > 64) continue;
			}

			return $player;
		}

		return null;
	}

	private function validTarget(Registry $registry, int $target): bool
	{
		return $registry->alive($target)
			&& $registry->has($target, Transform::class)
			&& $registry->has($target, HitPoints::class)
			&& $registry->get($target, HitPoints::class)->current > 0
			&& (!$registry->has($target, Body::class) || $registry->get($target, Body::class)->shootable);
	}

	private function wake(int $entity, int $target, Registry $registry, bool $sightSound): void
	{
		$brain = $registry->get($entity, MonsterBrain::class);
		$brain->target = $target;
		$brain->awake = true;

		if($sightSound) $this->actorSound($entity, $registry, 'SeeSound');

		$state = $registry->get($entity, ActorState::class);

		if($state->definition->state('See')) $state->enter('See');
	}

	private function look(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, MonsterBrain::class) || !$registry->has($entity, Transform::class)) return;

		$pose = $registry->get($entity, Transform::class);
		$brain = $registry->get($entity, MonsterBrain::class);
		$brain->threshold = 0;
		$heard = $this->awareness->target($this->level->sectorAt($pose->x, $pose->y)->index);

		if($heard !== null && $this->validTarget($registry, $heard))
		{
			$brain->target = $heard;

			if(!$brain->ambush || $this->visible($pose, $registry->get($heard, Transform::class)))
			{
				$this->wake($entity, $heard, $registry, true);

				return;
			}
		}

		$player = $this->lookForPlayer($entity, $registry, false);

		if($player !== null) $this->wake($entity, $player, $registry, true);
	}

	private function chase(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, MonsterBrain::class) || !$registry->has($entity, Body::class))return;

		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->threshold > 0) $brain->threshold--;

		if($brain->target === null || !$this->validTarget($registry, $brain->target))
		{
			$brain->threshold = 0;
			$player = $this->lookForPlayer($entity, $registry, true);

			if($player !== null)
			{
				$brain->target = $player;
				$brain->awake = true;

				return;
			}

			$brain->target = null;
			$brain->awake = false;
			$registry->get($entity, ActorState::class)->enter('Spawn');

			return;
		}

		$pose = $registry->get($entity, Transform::class);
		$target = $registry->get($brain->target, Transform::class);
		$dx = $target->x - $pose->x;
		$dy = $target->y - $pose->y;
		$distance = hypot($dx, $dy);
		$pose->angle = atan2($dy, $dx);
		$state = $registry->get($entity, ActorState::class);
		$melee = (float)$state->definition->value('MeleeRange', 44) + 20;
		$visible = $this->visible($pose, $target);

		if(mt_rand(0, 255) < 3) $this->actorSound($entity, $registry, 'ActiveSound');

		if($distance < $melee && $visible && $state->definition->state('Melee'))
		{
			$state->enter('Melee');

			return;
		}

		if($distance < 768 && $visible && mt_rand(0, 7) === 0 && $state->definition->state('Missile'))
		{
			$state->enter('Missile');

			return;
		}

		$speed = (float)$state->definition->value('Speed', 8);

		if($distance > 0)
		$this->collision->move(
			$pose
			, $registry->get($entity, Body::class)
			, $dx / $distance * $speed
			, $dy / $distance * $speed
			, $entity
		);
	}

	private function faceTarget(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, MonsterBrain::class))return;

		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target))return;

		$a = $registry->get($entity, Transform::class);
		$b = $registry->get($brain->target, Transform::class);
		$a->angle = atan2($b->y - $a->y, $b->x - $a->x);
	}

	private function monsterHitscan(int $entity, Registry $registry, int $pellets): void
	{
		$this->faceTarget($entity, $registry);
		$pose = $registry->get($entity, Transform::class);
		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target)) return;

		if(strcasecmp($this->cheats->monsterWeapon() ?? '', 'Railgun') === 0)
		{
			$this->monsterRailgun($entity, $registry);

			return;
		}

		$aim = $this->aimAngle($registry, $brain->target, $pose);

		$this->actorSound($entity, $registry, 'AttackSound');

		$targetPose = $registry->get($brain->target, Transform::class);
		$targetBody = $registry->has($brain->target, Body::class)
			? $registry->get($brain->target, Body::class)
			: null;

		$distance = max(1.0, hypot($targetPose->x - $pose->x, $targetPose->y - $pose->y));
		$slope = ($targetPose->z + ($targetBody?->height ?? 56) * .5 - ($pose->z + 32)) / $distance;

		for($i = 0; $i < $pellets; $i++)
		{
			$impact = $this->hitscanResult(
				$registry
				, $entity
				, $pose->x
				, $pose->y
				, $pose->z + 32
				, $aim + deg2rad(mt_rand(-6, 6))
				, 2048
				, $slope
			);

			if($impact->target !== null)
			{
				if($this->usesPuff($registry, $impact->target))
				$this->spawnPuff($registry, 'BulletPuff', $impact, $pose);

				$this->damage($registry, $impact->target, $entity, 3 * mt_rand(1, 5), null, true, $impact, $entity);
			}
			elseif($impact->geometry !== null && !$impact->geometry->escapesIntoSky())
			$this->spawnPuff($registry, 'BulletPuff', $impact, $pose);
		}
	}

	/** Replace a gun-bearing monster's ordinary hitscan with the active Railgun primitive. */
	private function monsterRailgun(int $entity, Registry $registry): void
	{
		$definition = $this->actors->named('Railgun');
		$brain = $registry->get($entity, MonsterBrain::class);

		if(!$definition || $brain->target === null || !$registry->alive($brain->target)) return;

		$sound = $definition->value('AttackSound');

		if(is_string($sound) && $sound !== '') $this->playLogicalSound($entity, $registry, $sound);

		if(!$this->simulateBeams) return;

		$pose = $registry->get($entity, Transform::class);
		$targetPose = $registry->get($brain->target, Transform::class);
		$targetBody = $registry->has($brain->target, Body::class)
			? $registry->get($brain->target, Body::class)
			: null;

		$angle = $this->aimAngle($registry, $brain->target, $pose);
		$distance = max(1.0, hypot($targetPose->x - $pose->x, $targetPose->y - $pose->y));
		$originZ = $pose->z + 32;
		$slope = ($targetPose->z + ($targetBody?->height ?? 56) * .5 - $originZ) / $distance;
		$range = 6400.0;
		$trace = $this->hitscanTrace($pose->x, $pose->y, $originZ, $angle, $range, $slope);
		$end = $trace['surface'];
		$targets = $this->hitscanActorImpacts($registry, $entity, $trace['path']['segments'], $range, $end->distance);

		foreach($targets as $targetHit)
		{
			$impact = $targetHit['impact'];
			$target = $impact->target;

			if($target === null) continue;

			if($this->usesPuff($registry, $target))
			$this->spawnPuff($registry, 'BulletPuff', $impact, $targetHit['source']);

			$this->damage($registry, $target, $entity, 100, null, true, $impact, $entity);
		}

		if($end->geometry !== null && !$end->geometry->escapesIntoSky())
		$this->spawnPuff($registry, 'BulletPuff', $end, $pose);

		$this->spawnBeamPath($registry, $trace['path']['segments'], 8);
	}

	private function monsterMelee(int $entity, Registry $registry, int $base): void
	{
		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target))return;

		$a = $registry->get($entity, Transform::class);
		$b = $registry->get($brain->target, Transform::class);

		if(hypot($a->x - $b->x, $a->y - $b->y) <= 72)
		{
			$this->actorSound($entity, $registry, 'AttackSound');
			$this->damage($registry, $brain->target, $entity, $base * mt_rand(1, 3));
		}
	}

	private function monsterAttack(int $entity, Registry $registry, string $projectile, int $melee): void
	{
		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target))return;

		$a = $registry->get($entity, Transform::class);
		$b = $registry->get($brain->target, Transform::class);
		$distance = hypot($a->x - $b->x, $a->y - $b->y);

		if($distance <= 72)
		{
			$this->actorSound($entity, $registry, 'AttackSound');
			$this->damage($registry, $brain->target, $entity, $melee * mt_rand(1, 8));

			return;
		}

		$this->spawnProjectile(
			$registry
			, $entity
			, $projectile
			, $a->x
			, $a->y
			, $a->z + 32
			, $this->aimAngle($registry, $brain->target, $a)
			, $b
		);
	}

	private function monsterProjectile(int $entity, Registry $registry, string $projectile): void
	{
		if(!$registry->has($entity, MonsterBrain::class)) return;

		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target)) return;

		$source = $registry->get($entity, Transform::class);
		$target = $registry->get($brain->target, Transform::class);

		$this->spawnProjectile(
			$registry
			, $entity
			, $projectile
			, $source->x
			, $source->y
			, $source->z + 32
			, $this->aimAngle($registry, $brain->target, $source)
			, $target
		);
	}

	private function skullAttack(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, MonsterBrain::class)) return;

		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target))
		{
			$registry->get($entity, ActorState::class)->enter('Spawn');

			return;
		}

		$pose = $registry->get($entity, Transform::class);
		$target = $registry->get($brain->target, Transform::class);
		$distance = max(1, hypot($target->x - $pose->x, $target->y - $pose->y));
		$speed = 20 * 35;
		$angle = $this->aimAngle($registry, $brain->target, $pose);
		$pose->angle = $angle;
		$velocity = $registry->get($entity, Velocity::class);
		$velocity->x = cos($angle) * $speed;
		$velocity->y = sin($angle) * $speed;
		$velocity->z = (($target->z + 28) - $pose->z) / $distance * $speed;
		$damage = (int)$registry->get($entity, ActorState::class)->definition->value('Damage', 3);
		$registry->add($entity, new ChargeAttack($brain->target, $damage));
		$this->actorSound($entity, $registry, 'AttackSound');
	}

	/** Shadow/invisibility perturbs monster aim by the same angular range as the classic random offset. */
	private function aimAngle(Registry $registry, int $target, Transform $source): float
	{
		$targetPose = $registry->get($target, Transform::class);
		$angle = atan2($targetPose->y - $source->y, $targetPose->x - $source->x);

		if($registry->has($target, PlayerState::class)
			&& isset($registry->get($target, PlayerState::class)->powers['Invisibility'])
		)
		$angle += (mt_rand(0, 255) - mt_rand(0, 255)) * M_PI / 2048;

		return $angle;
	}

	private function moveCharge(Registry $registry, int $entity, float $dt): void
	{
		$charge = $registry->get($entity, ChargeAttack::class);

		if(!$registry->alive($charge->target))
		{
			$this->stopCharge($entity, $registry);

			return;
		}

		$pose = $registry->get($entity, Transform::class);
		$velocity = $registry->get($entity, Velocity::class);
		$body = $registry->get($entity, Body::class);
		$motion = $this->collision->moveAndSlide($pose, $body, $velocity->x * $dt, $velocity->y * $dt, $entity);
		$blocked = $motion['collided'];
		$pose->z += $velocity->z * $dt;
		$targetPose = $registry->get($charge->target, Transform::class);
		$targetBody = $registry->get($charge->target, Body::class);
		$touching = hypot($pose->x - $targetPose->x, $pose->y - $targetPose->y)
			<= $body->radius + $targetBody->radius;

		$vertical = $pose->z + $body->height >= $targetPose->z
			&& $pose->z <= $targetPose->z + $targetBody->height;

		if(($touching && $vertical) || in_array($charge->target, $motion['actors'], true))
		{
			$this->damage($registry, $charge->target, $entity, $charge->damage * mt_rand(1, 8));
			$this->stopCharge($entity, $registry);
		}
		elseif($blocked) $this->stopCharge($entity, $registry);
	}

	private function stopCharge(int $entity, Registry $registry): void
	{
		$velocity = $registry->get($entity, Velocity::class);
		$velocity->x = $velocity->y = $velocity->z = 0;
		$registry->remove($entity, ChargeAttack::class);
		$state = $registry->get($entity, ActorState::class);

		if($state->definition->state('See')) $state->enter('See');
	}

	private function spiderRefire(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, MonsterBrain::class)) return;

		$brain = $registry->get($entity, MonsterBrain::class);

		if($brain->target === null || !$registry->alive($brain->target))
		{
			$registry->get($entity, ActorState::class)->enter('See');

			return;
		}

		$this->faceTarget($entity, $registry);
		$source = $registry->get($entity, Transform::class);
		$target = $registry->get($brain->target, Transform::class);

		if(mt_rand(0, 255) < 10 || !$this->visible($source, $target))
		$registry->get($entity, ActorState::class)->enter('See');
	}

	private function spawnProjectile(
		Registry $registry
		, int $owner
		, string $name
		, float $x
		, float $y
		, float $z
		, float $angle
		, ?Transform $target
		, float $aimSlope = 0
	): ?int {
		if(!$this->simulateProjectiles) return null;

		$definition = $this->actors->named($name);

		if(!$definition) return null;

		$sector = $this->level->sectorAt($x, $y);

		$entity = $this->factory->spawn(
			$registry
			, $definition
			, ['x' => $x, 'y' => $y, 'z' => 0, 'angle' => rad2deg($angle), 'type' => 0, 'index' => -1, 'flags' => []]
			, ['floor' => $sector->floor, 'ceiling' => $sector->ceiling]
		);

		$pose = $registry->get($entity, Transform::class);
		$pose->x = $x;
		$pose->y = $y;
		$pose->z = $z;
		$pose->angle = $angle;
		$pose->beginStep();
		$speed = (float)$definition->value('Speed', 10) * 35;
		$verticalSpeed = $aimSlope * $speed;

		if($target)
		{
			$distance = max(1, hypot($target->x - $x, $target->y - $y));
			$verticalSpeed = (($target->z + 28) - $z) / $distance * $speed;
		}

		$velocity = $registry->get($entity, Velocity::class);
		$velocity->x = cos($angle) * $speed;
		$velocity->y = sin($angle) * $speed;
		$velocity->z = $verticalSpeed;
		$damage = (int)$definition->value('Damage', 3);
		$splash = $definition->hasFlag('DEHEXPLOSION')
			? (float)$definition->value('ExplosionDamage', 128)
			: 0.0;

		$registry->add($entity, new Projectile($owner, $damage, 12, true, $splash));
		$this->actorSound($entity, $registry, 'SeeSound');

		return $entity;
	}

	private function moveProjectile(Registry $registry, int $entity, float $dt): void
	{
		$projectile = $registry->get($entity, Projectile::class);
		$projectile->ttl -= $dt;
		$state = $registry->get($entity, ActorState::class);

		if($projectile->ttl <= 0)
		{
			if($projectile->escapedSky)
			$this->retireProjectile($registry, $entity);
			elseif($projectile->active && $state->definition->state('Death'))
			{
				$projectile->ttl = 1;

				$this->impactProjectile(
					$registry
					, $entity
					, $projectile
					, $state
					, $registry->get($entity, Velocity::class)
				);
			}
			else $this->retireProjectile($registry, $entity);

			return;
		}

		if(!$projectile->active && $state->stopped)
		{
			$this->retireProjectile($registry, $entity);

			return;
		}

		if(!$projectile->active)return;

		$pose = $registry->get($entity, Transform::class);
		$velocity = $registry->get($entity, Velocity::class);
		$bounce = $registry->has($entity, ProjectileBounce::class)
			? $registry->get($entity, ProjectileBounce::class)
			: null;

		if($bounce?->exhausted())
		{
			$this->impactProjectile($registry, $entity, $projectile, $state, $velocity);

			return;
		}

		if($bounce?->resting)
		{
			$floor = $this->level->sectorAt($pose->x, $pose->y)->floor;

			if(abs($pose->z - $floor) <= .05)
			{
				$pose->z = $floor;
				$velocity->z = 0;
			}
			else $bounce->resting = false;
		}

		if(!($bounce?->resting ?? false) && $registry->has($entity, Gravity::class))
		$velocity->z -= $this->gravity * $registry->get($entity, Gravity::class)->scale * max(0, $dt);

		$dx = $velocity->x * $dt;
		$dy = $velocity->y * $dt;
		$dz = $velocity->z * $dt;
		$body = $registry->get($entity, Body::class);

		if($projectile->escapedSky)
		{
			$this->placeProjectile($pose, $dx, $dy, $dz, 1.0);

			return;
		}

		$path = $this->projectileTracer->tracePath($pose, $body, $dx, $dy, $dz);
		$impact = $path['impact'];
		$target = null;
		$targetFraction = INF;
		$targetPoint = null;
		$targetOrigin = null;
		$targetPortalCount = 0;

		foreach($path['segments'] as $segment)
		{
			$segmentPose = new Transform($segment['x'], $segment['y'], $segment['z'], $pose->angle);

			foreach($registry->query($this->damageable) as $other)
			{
				if($other === $entity || $other === $projectile->owner) continue;

				$otherBody = $registry->get($other, Body::class);

				if(!$otherBody->shootable) continue;

				$fraction = $this->projectileContact(
					$segmentPose
					, $body
					, $segment['dx']
					, $segment['dy']
					, $segment['dz']
					, $registry->get($other, Transform::class)
					, $otherBody
				);

				if($fraction === null) continue;

				$total = $segment['from'] + ($segment['to'] - $segment['from']) * $fraction;

				if($total < $targetFraction)
				{
					$target = $other;
					$targetFraction = $total;
					$targetPortalCount = $segment['portalCount'];
					$targetOrigin = new WorldPoint($segment['x'], $segment['y'], $segment['z']);
					$targetPoint = [
						$segment['x'] + $segment['dx'] * $fraction
						, $segment['y'] + $segment['dy'] * $fraction
						, $segment['z'] + $segment['dz'] * $fraction
					];
				}
			}
		}

		if($target !== null && $targetPoint !== null && (!$impact || $targetFraction <= $impact->fraction))
		{
			$this->applyProjectilePortals($pose, $velocity, $body, $path['portals'], $targetPortalCount);
			[$pose->x,$pose->y,$pose->z] = $targetPoint;
			$bloodImpact = new HitscanImpact(0, $pose->x, $pose->y, $pose->z, $target, null, $targetOrigin);

			$this->damage(
				$registry
				, $target
				, $projectile->owner
				, $projectile->damage * mt_rand(1, 8)
				, $entity
				, true
				, $bloodImpact
				, $entity
			);

			$this->impactProjectile($registry, $entity, $projectile, $state, $velocity);

			return;
		}

		if($impact)
		{
			$this->applyProjectilePortals($pose, $velocity, $body, $path['portals'], count($path['portals']));
			$pose->x = $impact->x;
			$pose->y = $impact->y;
			$pose->z = $impact->z;

			if($impact->escapesIntoSky())
			{
				$projectile->escapeIntoSky();
				$remaining = max(0.0, 1.0 - $impact->fraction);
				$this->placeProjectile($pose, $velocity->x * $dt, $velocity->y * $dt, $velocity->z * $dt, $remaining);

				return;
			}

			if($bounce)
			{
				$this->bounceProjectile(
					$registry
					, $entity
					, $projectile
					, $state
					, $pose
					, $velocity
					, $bounce
					, $impact
					, $dt
				);

				return;
			}

			$this->impactProjectile($registry, $entity, $projectile, $state, $velocity);

			return;
		}

		$this->applyProjectilePortals($pose, $velocity, $body, $path['portals'], count($path['portals']));
		$last = $path['segments'][array_key_last($path['segments'])];
		$pose->x = $last['x'] + $last['dx'];
		$pose->y = $last['y'] + $last['dy'];
		$pose->z = $last['z'] + $last['dz'];

		if($bounce?->resting)
		{
			$floor = $this->level->sectorAt($pose->x, $pose->y)->floor;

			if(abs($pose->z - $floor) > .05) $bounce->resting = false;
			else $pose->z = $floor;
		}
	}

	/** Rotate projectile interpolation and velocity through portals already crossed. */
	private function applyProjectilePortals(
		Transform $pose
		, Velocity $velocity
		, Body $body
		, array $portals
		, int $count
	): void {
		for($index = 0; $index < $count; $index++)
		{
			$portal = $portals[$index];
			[$pose->previousX,$pose->previousY] = $portal->point($pose->previousX, $pose->previousY);
			$pose->previousZ = $portal->bodyZ($pose->previousZ, $body->height, $this->level);
			$pose->angle = $portal->angle($pose->angle);
			[$velocity->x,$velocity->y] = $portal->vector($velocity->x, $velocity->y);
		}
	}

	private function bounceProjectile(
		Registry $registry
		, int $entity
		, Projectile $projectile
		, ActorState $state
		, Transform $pose
		, Velocity $velocity
		, ProjectileBounce $bounce
		, ProjectileImpact $impact
		, float $dt
	): void {
		$bounce->contact();

		if($impact->surface === ProjectileImpact::WALL && $impact->line !== null)
		{
			$line = $this->level->map->linedefs[$impact->line];
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];
			$lineX = (float)$b['x'] - (float)$a['x'];
			$lineY = (float)$b['y'] - (float)$a['y'];
			$length = hypot($lineX, $lineY);

			if($length <= 1e-9)
			{
				$this->impactProjectile($registry, $entity, $projectile, $state, $velocity);

				return;
			}

			$normalX = -$lineY / $length;
			$normalY = $lineX / $length;
			$dot = $velocity->x * $normalX + $velocity->y * $normalY;
			$velocity->x = ($velocity->x - 2 * $dot * $normalX) * $bounce->wallFactor;
			$velocity->y = ($velocity->y - 2 * $dot * $normalY) * $bounce->wallFactor;
			$speed = hypot($velocity->x, $velocity->y);

			if($speed > 1e-9)
			{
				$pose->x += $velocity->x / $speed * .01;
				$pose->y += $velocity->y / $speed * .01;
				$pose->angle = atan2($velocity->y, $velocity->x);
			}
		}
		elseif($impact->surface === ProjectileImpact::FLOOR)
		{
			$upward = abs($velocity->z) * $bounce->floorFactor;
			$gravityStep = $registry->has($entity, Gravity::class)
				? $this->gravity * $registry->get($entity, Gravity::class)->scale * max(0, $dt)
				: 0.0;

			if($upward <= max(.5, $gravityStep * 1.5))
			{
				$velocity->z = 0;
				$bounce->resting = true;
			}
			else
			{
				$velocity->z = $upward;
				$pose->z += .01;
				$bounce->resting = false;
			}
		}
		elseif($impact->surface === ProjectileImpact::CEILING)
		{
			$velocity->z = -abs($velocity->z) * $bounce->floorFactor;
			$pose->z -= .01;
			$bounce->resting = false;
		}

		$this->actorSound($entity, $registry, 'BounceSound');
	}

	private function impactProjectile(
		Registry $registry
		, int $entity
		, Projectile $projectile
		, ActorState $state
		, Velocity $velocity
	): void {
		$projectile->active = false;
		$velocity->x = $velocity->y = $velocity->z = 0;
		$this->actorSound($entity, $registry, 'DeathSound');

		if($state->definition->state('Death')) $state->enter('Death');
		else
		{
			$projectile->ttl = 0;
			$this->retireProjectile($registry, $entity);
		}
	}

	private function retireProjectile(Registry $registry, int $entity): void
	{
		$registry->defer(static function(Registry $registry) use ($entity): void {
			if($registry->alive($entity)) $registry->destroy($entity);
		});
	}

	private function placeProjectile(
		Transform $pose
		, float $dx
		, float $dy
		, float $dz
		, float $fraction
	): void {
		$pose->x += $dx * $fraction;
		$pose->y += $dy * $fraction;
		$pose->z += $dz * $fraction;
	}

	private function projectileContact(
		Transform $projectile
		, Body $projectileBody
		, float $dx
		, float $dy
		, float $dz
		, Transform $target
		, Body $targetBody
	): ?float {
		$relativeX = $projectile->x - $target->x;
		$relativeY = $projectile->y - $target->y;
		$radius = $projectileBody->radius + $targetBody->radius;
		$a = $dx * $dx + $dy * $dy;
		$horizontalFrom = 0.0;
		$horizontalTo = 1.0;

		if($a < 1e-12)
		{
			if($relativeX * $relativeX + $relativeY * $relativeY > $radius * $radius) return null;
		}
		else
		{
			$b = 2 * ($relativeX * $dx + $relativeY * $dy);
			$c = $relativeX * $relativeX + $relativeY * $relativeY - $radius * $radius;
			$discriminant = $b * $b - 4 * $a * $c;

			if($discriminant < 0) return null;

			$root = sqrt($discriminant);
			$horizontalFrom = max(0.0, (-$b - $root) / (2 * $a));
			$horizontalTo = min(1.0, (-$b + $root) / (2 * $a));

			if($horizontalFrom > $horizontalTo) return null;
		}

		$minimumZ = $target->z - $projectileBody->height;
		$maximumZ = $target->z + $targetBody->height;
		$verticalFrom = 0.0;
		$verticalTo = 1.0;

		if(abs($dz) < 1e-12)
		{
			if($projectile->z < $minimumZ || $projectile->z > $maximumZ) return null;
		}
		else
		{
			$a = ($minimumZ - $projectile->z) / $dz;
			$b = ($maximumZ - $projectile->z) / $dz;
			$verticalFrom = max(0.0, min($a, $b));
			$verticalTo = min(1.0, max($a, $b));

			if($verticalFrom > $verticalTo) return null;
		}

		$contact = max($horizontalFrom, $verticalFrom);

		return $contact <= min($horizontalTo, $verticalTo) ? $contact : null;
	}

	private function hitscanTarget(
		Registry $registry
		, int $shooter
		, float $x
		, float $y
		, float $z
		, float $angle
		, float $range
		, float $slope = 0
	): ?int {
		return $this->hitscanResult($registry, $shooter, $x, $y, $z, $angle, $range, $slope)->target;
	}

	private function hitscanResult(
		Registry $registry
		, int $shooter
		, float $x
		, float $y
		, float $z
		, float $angle
		, float $range
		, float $slope = 0
	): HitscanImpact {
		$trace = $this->hitscanTrace($x, $y, $z, $angle, $range, $slope);

		$targets = $this->hitscanActorImpacts(
			$registry
			, $shooter
			, $trace['path']['segments']
			, $range
			, $trace['surface']->distance
		);

		return $targets[0]['impact'] ?? $trace['surface'];
	}

	/**
	 * @return array{
	 *   path:array{
	 *     segments:list<array{x:float,y:float,z:float,dx:float,dy:float,dz:float,from:float,to:float,portalCount:int}>,
	 *     impact:?ProjectileImpact,
	 *     portals:list<\DoomPhonics\Game\CameraPortalLink>
	 *   },
	 *   surface:HitscanImpact
	 * }
	 */
	private function hitscanTrace(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $range
		, float $slope = 0
	): array {
		$dx = cos($angle);
		$dy = sin($angle);
		$ray = new Transform($x, $y, $z, $angle);
		$path = $this->projectileTracer->tracePath($ray, new Body(0, 0), $dx * $range, $dy * $range, $slope * $range);
		$geometry = $path['impact'];
		$segment = $path['segments'][array_key_last($path['segments'])];
		$origin = new WorldPoint($segment['x'], $segment['y'], $segment['z']);

		if($geometry)
		$surface = new HitscanImpact(
			$geometry->fraction * $range
			, $geometry->x
			, $geometry->y
			, $geometry->z
			, null
			, $geometry
			, $origin
		);
		else
		{
			$surface = new HitscanImpact(
				$segment['to'] * $range
				, $segment['x'] + $segment['dx']
				, $segment['y'] + $segment['dy']
				, $segment['z'] + $segment['dz']
				, null
				, null
				, $origin
			);
		}

		return ['path' => $path,'surface' => $surface];
	}

	/**
	 * Find each shootable actor on a transformed ray, once, ordered by total travel.
	 *
	 * @param list<array{
	 *   x:float,y:float,z:float,dx:float,dy:float,dz:float,from:float,to:float,portalCount:int
	 * }> $segments
	 * @return list<array{impact:HitscanImpact,source:Transform}>
	 */
	private function hitscanActorImpacts(
		Registry $registry
		, int $shooter
		, array $segments
		, float $range
		, float $limit
	): array {
		$targets = [];

		foreach($registry->query($this->damageable) as $entity)
		{
			if($entity === $shooter) continue;

			$body = $registry->get($entity, Body::class);

			if(!$body->shootable) continue;

			$target = $registry->get($entity, Transform::class);

			foreach($segments as $segment)
			{
				$length = hypot($segment['dx'], $segment['dy']);

				if($length <= 1e-9) continue;

				$dx = $segment['dx'] / $length;
				$dy = $segment['dy'] / $length;
				$slope = $segment['dz'] / $length;

				$distance = $this->rayActorDistance(
					$segment['x']
					, $segment['y']
					, $segment['z']
					, $dx
					, $dy
					, $slope
					, $target
					, $body
				);

				if($distance === null || $distance > $length + 1e-7) continue;

				$total = $segment['from'] * $range + $distance;

				if($total >= $limit || isset($targets[$entity])) continue;

				$targets[$entity] = [
					'impact' => new HitscanImpact(
						$total
						, $segment['x'] + $dx * $distance
						, $segment['y'] + $dy * $distance
						, $segment['z'] + $slope * $distance
						, $entity
						, null
						, new WorldPoint($segment['x'], $segment['y'], $segment['z'])
					)
					, 'source' => new Transform($segment['x'], $segment['y'], $segment['z'])
				];

				break;
			}
		}

		usort(
			$targets
			, static fn(array $left, array $right): int => $left['impact']->distance <=> $right['impact']->distance
		);

		return array_values($targets);
	}

	/**
	 * Render a rail as one beam per portal-space segment so it never spans the void
	 * between a portal entrance and its destination.
	 *
	 * @param list<array{
	 *   x:float,y:float,z:float,dx:float,dy:float,dz:float,from:float,to:float,portalCount:int
	 * }> $segments
	 */
	private function spawnBeamPath(Registry $registry, array $segments, float $firstZOffset): void
	{
		$first = true;

		foreach($segments as $segment)
		{
			if(abs($segment['dx']) + abs($segment['dy']) + abs($segment['dz']) <= 1e-9) continue;

			$registry->create(
				new BeamEffect(
					new WorldPoint($segment['x'], $segment['y'], $segment['z'] - ($first ? $firstZOffset : 0))
					, new WorldPoint(
						$segment['x'] + $segment['dx']
						, $segment['y'] + $segment['dy']
						, $segment['z'] + $segment['dz']
					)
					, .75
					, 8
				)
			);

			$first = false;
		}
	}

	private function rayActorDistance(
		float $x
		, float $y
		, float $z
		, float $dx
		, float $dy
		, float $slope
		, Transform $pose
		, Body $body
	): ?float {
		$offsetX = $x - $pose->x;
		$offsetY = $y - $pose->y;
		$along = $offsetX * $dx + $offsetY * $dy;
		$discriminant = $along * $along
			- ($offsetX * $offsetX + $offsetY * $offsetY - $body->radius * $body->radius);

		if($discriminant < 0) return null;

		$root = sqrt($discriminant);
		$entry = -$along - $root;
		$exit = -$along + $root;

		if($exit <= 1e-5) return null;

		$entry = max(1e-5, $entry);

		if(abs($slope) < 1e-12)
		{
			if($z < $pose->z || $z > $pose->z + $body->height) return null;
		}
		else
		{
			$bottom = ($pose->z - $z) / $slope;
			$top = ($pose->z + $body->height - $z) / $slope;
			$entry = max($entry, min($bottom, $top));
			$exit = min($exit, max($bottom, $top));
		}

		return $entry <= $exit ? $entry : null;
	}

	private function usesPuff(Registry $registry, int $target): bool
	{
		return $registry->has($target, ActorState::class)
			&& $registry->get($target, ActorState::class)->definition->hasFlag('NOBLOOD');
	}

	private function spawnPuff(
		Registry $registry
		, string $name
		, HitscanImpact $impact
		, Transform $source
	): ?int {
		$name = trim($name);

		if($name === '' || strcasecmp($name, 'none') === 0) return null;

		$definition = $this->actors->named($name);

		// Puff actors are optional impact effects. Older DECORATE commonly passes 0 for
		// the default class, and partially supported WADs may name an unavailable custom
		// puff. Preserve the hit and use the ordinary visual when the catalog permits it.
		if(!$definition && strcasecmp($name, 'BulletPuff') !== 0)
		$definition = $this->actors->named('BulletPuff');

		if(!$definition) return null;

		$sector = $impact->geometry
			? $this->level->sectors[$impact->geometry->sector]
			: $this->level->sectorAt($impact->x, $impact->y);

		if($impact->origin)
		$source = new Transform($impact->origin->x, $impact->origin->y, $impact->origin->z);

		$angle = atan2($source->y - $impact->y, $source->x - $impact->x);

		$entity = $this->factory->spawn(
			$registry
			, $definition
			, [
				'x' => $impact->x
				, 'y' => $impact->y
				, 'z' => $impact->z - $sector->floor
				, 'angle' => rad2deg($angle)
				, 'type' => 0
				, 'index' => -1
				, 'flags' => []
			]
			, ['floor' => $sector->floor, 'ceiling' => $sector->ceiling]
		);

		$pose = $registry->get($entity, Transform::class);
		$pose->x = $pose->previousX = $impact->x;
		$pose->y = $pose->previousY = $impact->y;
		$pose->z = $pose->previousZ = $impact->z;
		$pose->angle = $angle;
		$velocity = $registry->get($entity, Velocity::class);
		$velocity->z = (float)$definition->value('VSpeed', 0) * 35;

		if($impact->geometry?->line !== null)
		{
			// The reference particle leaves a wall along its surface normal. Using the
			// shot ray here makes oblique impacts appear to slide away from their mark.
			[$normalX,$normalY] = $this->wallNormal($impact->geometry->line, $impact, $source);
			$velocity->x = $normalX * 7;
			$velocity->y = $normalY * 7;
		}

		$registry->add($entity, new TransientEffect);

		return $entity;
	}

	/** @return array{float,float} Unit wall normal facing the hitscan originator. */
	private function wallNormal(int $lineId, HitscanImpact $impact, Transform $source): array
	{
		$line = $this->level->map->linedefs[$lineId];
		$from = $this->level->map->vertices[$line['from']];
		$to = $this->level->map->vertices[$line['to']];
		$dx = (float)$to['x'] - $from['x'];
		$dy = (float)$to['y'] - $from['y'];
		$length = hypot($dx, $dy);

		if($length <= 1e-9)
		{
			$angle = atan2($source->y - $impact->y, $source->x - $impact->x);

			return [cos($angle),sin($angle)];
		}

		$normalX = $dy / $length;
		$normalY = -$dx / $length;
		$towardSource = ($source->x - $impact->x) * $normalX + ($source->y - $impact->y) * $normalY;

		if($towardSource < 0) return [-$normalX,-$normalY];

		return [$normalX,$normalY];
	}

	private function blockingDistance(float $x, float $y, float $z, float $dx, float $dy, float $range): float
	{
		return $this->blockingHit($x, $y, $z, $dx, $dy, $range)[0];
	}

	/** @return array{float,?int} */
	private function blockingHit(float $x, float $y, float $z, float $dx, float $dy, float $range): array
	{
		$current = $this->level->sectorAt($x, $y)->index;

		foreach($this->level->spatial->cast($x, $y, $dx, $dy, $range) as $hit)
		{
			$sides = $this->level->sides($hit['line'], $current);

			if($sides['front'] !== $current)continue;

			if($sides['back'] === null)return [$hit['distance'],$hit['line']];

			$front = $this->level->sectors[$current];
			$back = $this->level->sectors[$sides['back']];

			if($z < max($front->floor, $back->floor) || $z > min($front->ceiling, $back->ceiling))
			return [$hit['distance'],$hit['line']];

			$current = $back->index;
		}

		return [$range,null];
	}

	private function visible(Transform $a, Transform $b): bool
	{
		$dx = $b->x - $a->x;
		$dy = $b->y - $a->y;
		$distance = hypot($dx, $dy);

		if($distance < .001)return true;
		$hit = (new ProjectileTracer($this->level, true))->trace(
			new Transform($a->x, $a->y, $a->z + 32), new Body(0, 0), $dx, $dy, $b->z - $a->z
		);
		if($hit && $hit->fraction < 1 - .5 / $distance) return false;

		return $this->blockingDistance(
			$a->x
			, $a->y
			, $a->z + 32
			, $dx / $distance
			, $dy / $distance
			, $distance
		) >= $distance - .5;
	}

	private function damage(
		Registry $registry
		, int $target
		, int $source
		, int $damage
		, ?int $inflictor = null
		, bool $thrust = true
		, ?HitscanImpact $impact = null
		, ?int $attack = null
	): bool {
		$changed = $this->damageResolver->apply(
			$registry
			, new DamageEvent($target, $source, $damage, $inflictor, $thrust)
		);

		if(!$changed || !$this->blood->bleeds($registry, $target)) return $changed;

		$effectSource = $attack ?? $inflictor ?? $source;

		if(!$registry->alive($effectSource) || !$registry->has($effectSource, Transform::class))
		$effectSource = $source;

		if(!$registry->alive($effectSource) || !$registry->has($effectSource, Transform::class)) return $changed;

		$sourcePose = $registry->get($effectSource, Transform::class);

		if($impact?->origin)
		$sourcePose = new Transform($impact->origin->x, $impact->origin->y, $impact->origin->z);

		$this->blood->spawn(
			$registry
			, $target
			, $impact ?? $this->bloodImpact($registry, $target, $sourcePose)
			, $sourcePose
			, $attack ?? $inflictor ?? $source
		);

		return $changed;
	}

	/** Place non-hitscan blood on the side of the victim facing the damage source. */
	private function bloodImpact(Registry $registry, int $target, Transform $source): HitscanImpact
	{
		$pose = $registry->get($target, Transform::class);
		$body = $registry->has($target, Body::class) ? $registry->get($target, Body::class) : null;
		$dx = $source->x - $pose->x;
		$dy = $source->y - $pose->y;
		$distance = hypot($dx, $dy);
		$radius = $body?->radius ?? 0;

		if($distance > 1e-7)
		{
			$dx /= $distance;
			$dy /= $distance;
		}
		else
		{
			$dx = cos($pose->angle);
			$dy = sin($pose->angle);
		}

		return new HitscanImpact(
			0
			, $pose->x + $dx * $radius
			, $pose->y + $dy * $radius
			, $pose->z + ($body?->height ?? 0) * .55
			, $target
		);
	}

	private function explode(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, Transform::class)) return;

		$pose = $registry->get($entity, Transform::class);
		$definition = $registry->get($entity, ActorState::class)->definition;
		$projectile = $registry->has($entity, Projectile::class)
			? $registry->get($entity, Projectile::class)
			: null;

		$radius = $projectile && $projectile->splash > 0
			? $projectile->splash
			: (float)$definition->value('ExplosionDamage', 128);

		$source = $projectile?->owner ?? $entity;

		if($registry->has($entity, Velocity::class))
		{
			$velocity = $registry->get($entity, Velocity::class);
			$velocity->x = $velocity->y = $velocity->z = 0;
		}

		foreach($registry->query($this->damageable) as $target)
		{
			if($target === $entity) continue;

			$targetPose = $registry->get($target, Transform::class);
			$body = $registry->get($target, Body::class);

			if(!$body->shootable) continue;

			$dx = $targetPose->x - $pose->x;
			$dy = $targetPose->y - $pose->y;
			$distance = max(0, hypot($dx, $dy) - $body->radius);
			$damage = (int)ceil($radius - $distance);

			if($damage <= 0 || !$this->visible($pose, $targetPose)) continue;

			$this->damage($registry, $target, $source, $damage, $entity, false);

			if($registry->has($target, Velocity::class) && $distance > .001)
			{
				$velocity = $registry->get($target, Velocity::class);
				$impulse = 140 * (1 - min(1, $distance / $radius));
				$velocity->x += $dx / $distance * $impulse;
				$velocity->y += $dy / $distance * $impulse;
			}
		}
	}

	private function bfgSpray(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, Projectile::class) || !$registry->has($entity, Transform::class)) return;

		$owner = $registry->get($entity, Projectile::class)->owner;

		if(!$registry->alive($owner) || !$registry->has($owner, Transform::class)) return;

		$pose = $registry->get($entity, Transform::class);
		$ownerPose = $registry->get($owner, Transform::class);

		for($ray = 0; $ray < 40; $ray++)
		{
			$angle = $ownerPose->angle - M_PI / 4 + (M_PI / 2) * $ray / 39;
			$target = $this->hitscanTarget($registry, $owner, $pose->x, $pose->y, $pose->z, $angle, 1024);

			if($target !== null) $this->damage($registry, $target, $owner, 15 * mt_rand(1, 8), $entity);
		}
	}

	private function respawn(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, SpawnPoint::class)) return;

		$origin = $registry->get($entity, SpawnPoint::class);
		$pose = $registry->get($entity, Transform::class);
		$pose->x = $pose->previousX = $origin->x;
		$pose->y = $pose->previousY = $origin->y;
		$pose->z = $pose->previousZ = $origin->z;
		$pose->angle = $origin->angle;
		$state = $registry->get($entity, ActorState::class);

		if($registry->has($entity, HitPoints::class))
		{
			$health = $registry->get($entity, HitPoints::class);
			$health->current = $health->maximum;
		}

		if($registry->has($entity, Body::class))
		{
			$body = $registry->get($entity, Body::class);
			$body->solid = $state->definition->hasFlag('SOLID');
			$body->shootable = $state->definition->hasFlag('SHOOTABLE');
			$body->grounded = true;
		}

		if($registry->has($entity, MonsterBrain::class))
		{
			$brain = $registry->get($entity, MonsterBrain::class);
			$brain->target = null;
			$brain->awake = false;
			$brain->threshold = 0;
		}

		if($registry->has($entity, FloorClip::class)) $registry->remove($entity, FloorClip::class);

		if($registry->has($entity, ChargeAttack::class)) $registry->remove($entity, ChargeAttack::class);

		$state->enter('Spawn');
	}

	private function removeBlocking(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, Body::class)) return;

		$body = $registry->get($entity, Body::class);
		$body->solid = false;
		$body->shootable = false;
	}

	private function setFloorClip(int $entity, Registry $registry): void
	{
		if(!$registry->has($entity, FloorClip::class)) $registry->add($entity, new FloorClip);
	}

	private function unsetFloorClip(int $entity, Registry $registry): void
	{
		if($registry->has($entity, FloorClip::class)) $registry->remove($entity, FloorClip::class);
	}

	private function actorSound(int $entity, Registry $registry, string $property): void
	{
		if(!$registry->has($entity, ActorState::class)) return;

		$logical = $registry->get($entity, ActorState::class)->definition->value($property);

		if(is_string($logical) && $logical !== '') $this->playLogicalSound($entity, $registry, $logical);
	}

	private function playLogicalSound(int $entity, Registry $registry, string $logical): void
	{
		$lump = $this->sounds->resolve($logical, mt_rand());

		if($lump === null) return;

		$sourceEntity = $this->actionOwner($entity, $registry) ?? $entity;

		if(!$registry->alive($sourceEntity) || !$registry->has($sourceEntity, Transform::class)) return;

		$pose = $registry->get($sourceEntity, Transform::class);
		$listener = null;

		foreach($registry->query($this->players) as $player)
		{
			if($registry->has($player, NetworkPlayer::class)
				&& !$registry->get($player, NetworkPlayer::class)->local
			) continue;

			$view = $registry->get($player, Transform::class);
			$listener = new SoundListener($view->x, $view->y, $view->angle);
			break;
		}

		$this->sound->play(new SoundEvent(SoundCue::fromLump($lump), new SoundSource($pose->x, $pose->y), $listener));
	}

	private function actionOwner(int $entity, Registry $registry): ?int
	{
		if($registry->has($entity, WeaponOwner::class))
		return $registry->get($entity, WeaponOwner::class)->entity;

		if($registry->has($entity, WeaponOverlay::class))
		return $registry->get($entity, WeaponOverlay::class)->playerEntity;

		return null;
	}

	/** Resolve a weapon action dispatched either by its psprite or by a flash overlay. */
	private function actionWeapon(int $entity, Registry $registry): ?int
	{
		if($registry->has($entity, WeaponOwner::class)) return $entity;

		if(!$registry->has($entity, WeaponOverlay::class)) return null;

		$weapon = $registry->get($entity, WeaponOverlay::class)->weaponEntity;

		return $registry->alive($weapon)
			&& $registry->has($weapon, WeaponOwner::class)
			&& $registry->has($weapon, ActorState::class)
			? $weapon
			: null;
	}

	/** Select Weapon.AmmoType2/AmmoUse2 for actions dispatched from alternate-fire states. */
	private function ammoChannel(int $entity, Registry $registry): int
	{
		if(!$registry->has($entity, ActorState::class)) return 1;

		return str_starts_with(strtolower($registry->get($entity, ActorState::class)->label), 'alt') ? 2 : 1;
	}

	private function bossDeath(int $entity, Registry $registry): void
	{
		foreach($registry->query(Query::all(MonsterBrain::class, HitPoints::class, ActorState::class)) as $other)
		if($other !== $entity && $registry->get(
			$other
			, ActorState::class
		)->definition->name === 'BaronOfHell' && $registry->get($other, HitPoints::class)->current > 0
		)
		return;

		if($this->specials)
		{
			$this->specials->lowerBossFloor(666);

			return;
		}

		foreach($this->level->tags[666] ?? [] as $sector)
		{
			$state = $this->level->sectors[$sector];
			$state->moveFloor($this->level->lowestNeighborFloor($sector), 35);
		}
	}
}

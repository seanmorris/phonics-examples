<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\RemoteBeam;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\PortalInterpolation;
use DoomPhonics\Game\WorldPoint;
use Phonics\Ecs\Registry;
use Phonics\Network\AuthorityOwner;
use Phonics\Network\BrowserBridge;
use Phonics\Network\CallbackComponentCodec;
use Phonics\Network\Component\NetworkAuthority;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\Component\NetworkPrefab;
use Phonics\Network\InputCommand;
use Phonics\Network\NetworkEntityAllocator;
use Phonics\Network\NetworkEntityId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\ReplicationCallbacks;
use Phonics\Network\ReplicationPolicy;
use Phonics\Network\ReplicationRegistry;
use Phonics\Network\ReplicationScope;
use Phonics\Network\ReplicationSession;
use Phonics\Network\SimulationTick;

/** Doom-owned codecs and player command mapping over neutral Phonics replication. */
final class EpisodeNetworkSystem
{
	private NetworkEntityAllocator $entities;
	private ReplicationSession $session;
	private ReplicationScope $scope;
	private ?PortalInterpolation $portalInterpolation;

	public function __construct(
		private Registry $world
		, public DoomMatch $match
		, string $scope
		, ActorCatalog $actors
		, ?BrowserBridge $bridge = null
		, ?Level $level = null
	){
		$match->replication = $this;
		$this->entities = new NetworkEntityAllocator;
		$this->scope = new ReplicationScope($scope);
		$this->portalInterpolation = $level ? new PortalInterpolation($level) : null;
		$replication = self::replication($actors);
		$this->bindPlayers($this->scope);

		$this->session = new ReplicationSession(
			$world
			, $replication
			, $this->entities
			, $bridge ?? new BrowserBridge
			, $match->network
			, new ReplicationCallbacks(
				fn(PlayerSlot $slot, SimulationTick $tick): array => $this->command($slot)->actions()
				, function(InputCommand $command): void {
					$entity = $this->player($command->player);
					$view = $this->world->has($entity, ViewInput::class)
						? $this->world->get($entity, ViewInput::class)
						: null;

					$this->world->get($entity, PlayerCommand::class)->apply(
						$command->actions()
						, $view
						, !$this->world->get($entity, NetworkPlayer::class)->local
					);
				}
			)
			, 2
			, 70
			, [$this,'validCommand']
			, $match->clock
			, $this->scope
		);
	}

	public function receive(Registry $registry, float $dt): void
	{
		$this->session->beforeStep($registry, $dt);

		if(!$this->portalInterpolation) return;

		foreach($registry->query(RemoteProjectile::class) as $entity)
		{
			if(!$registry->has($entity, Transform::class) || !$registry->has($entity, ActorState::class)) continue;

			$state = $registry->get($entity, ActorState::class);

			$this->portalInterpolation->restore(
				$registry->get($entity, Transform::class)
				, max(0.0, (float)$state->definition->value('Height', 0))
			);
		}
	}

	public function send(Registry $registry, float $dt): void
	{
		if($this->match->network->host()) $this->bindEffects();

		$this->session->afterStep($registry, $dt);
	}

	public function close(): void
	{
		if($this->match->replication === $this) $this->match->replication = null;
		$this->session->clear();
	}

	/** Pump the authoritative recovery barrier without advancing world simulation. */
	public function recover(string $id, bool $connected): bool
	{
		$this->session->beginRecovery($id);
		foreach($this->world->query(PlayerCommand::class) as $entity)
		{
			$command = $this->world->get($entity, PlayerCommand::class);
			$command->apply(['pitch' => $command->pitch]);
			if($this->world->has($entity, ViewInput::class)) $this->world->get($entity, ViewInput::class)->release();
		}
		$complete = $this->session->recover($connected);
		if($complete)
		foreach($this->world->query(Transform::class) as $entity) $this->world->get($entity, Transform::class)->beginStep();
		return $complete;
	}

	public function validCommand(InputCommand $command): bool
	{
		$allowed = ['forward','strafe','turn','yaw','pitch','fire','altFire','use','weaponSlot','weaponSteps'];

		if(array_diff(array_keys($command->actions()), $allowed)) return false;

		try
		{
			(new PlayerCommand)->apply($command->actions());
		}
		catch(\Throwable $error)
		{
			return false;
		}

		return true;
	}

	/**
	 * Build the Doom component and prefab replication profile.
	 *
	 * @param ?ActorCatalog $actors Active WAD definitions used to restore actor animation cursors.
	 * @return ReplicationRegistry A fresh registry containing the Doom network schemas.
	 */
	public static function replication(?ActorCatalog $actors = null): ReplicationRegistry
	{
		$result = new ReplicationRegistry;

		$ownerPolicy = new ReplicationPolicy(
			ReplicationPolicy::AUTHORITY_OWNER
			, ReplicationPolicy::DELIVERY_REALTIME
			, ReplicationPolicy::INTERPOLATE_LINEAR
			, 100
		);

		$result->register(new CallbackComponentCodec(
			'doom.transform'
			, 1
			, Transform::class
			, static fn(Transform $value): array => [
				'x' => self::coordinate($value->x)
				, 'y' => self::coordinate($value->y)
				, 'z' => self::coordinate($value->z)
				, 'angle' => round($value->angle, 6)
			]
			, static function(array $value, ?Transform $current): Transform {
				$x = (float)$value['x'];
				$y = (float)$value['y'];
				$z = (float)$value['z'];
				$angle = (float)$value['angle'];

				return $current
					? $current->reconcile($x, $y, $z, $angle)
					: new Transform($x, $y, $z, $angle);
			}
			, $ownerPolicy
		));

		$result->register(new CallbackComponentCodec(
			'doom.velocity'
			, 1
			, Velocity::class
			, static fn(Velocity $value): array => [
				'x' => self::coordinate($value->x)
				, 'y' => self::coordinate($value->y)
				, 'z' => self::coordinate($value->z)
			]
			, static function(array $value, ?Velocity $current): Velocity {
				$velocity = $current ?? new Velocity;
				$velocity->x = (float)$value['x'];
				$velocity->y = (float)$value['y'];
				$velocity->z = (float)$value['z'];

				return $velocity;
			}
			, $ownerPolicy
		));

		$result->register(new CallbackComponentCodec(
			'doom.health'
			, 1
			, HitPoints::class
			, static fn(HitPoints $value): array => [
				'current' => $value->current
				, 'maximum' => $value->maximum
				, 'painChance' => $value->painChance
			]
			, static fn(array $value): HitPoints => new HitPoints(
				(int)$value['current']
				, (int)$value['maximum']
				, (int)$value['painChance']
			)
		));

		$result->register(new CallbackComponentCodec(
			'doom.inventory-pickup'
			, 1
			, InventoryPickup::class
			, static fn(InventoryPickup $value): array => [
				'actor' => $value->actorName
				, 'amount' => $value->amount
				, 'dropped' => $value->dropped
			]
			, static fn(array $value): InventoryPickup => new InventoryPickup(
				(string)$value['actor']
				, (int)$value['amount']
				, (bool)$value['dropped']
			)
		));

		$result->register(
			new CallbackComponentCodec(
				'doom.player-state'
				, 1
				, PlayerState::class
				, static fn(PlayerState $value): array => self::playerState($value)
				, static fn(array $value, ?PlayerState $current): PlayerState => self::restorePlayer($value, $current)
			)
		);

		$result->register(new CallbackComponentCodec(
			'doom.beam'
			, 1
			, BeamEffect::class
			, static fn(BeamEffect $value): array => [
				'from' => array_map([self::class, 'coordinate'], [$value->from->x, $value->from->y, $value->from->z])
				, 'to' => array_map([self::class, 'coordinate'], [$value->to->x, $value->to->y, $value->to->z])
				, 'duration' => $value->duration
				, 'width' => $value->width
				, 'age' => $value->age()
			]
			, static function(array $value, ?BeamEffect $current): BeamEffect {
				$beam = new BeamEffect(
					new WorldPoint(...$value['from'])
					, new WorldPoint(...$value['to'])
					, (float)$value['duration']
					, (float)$value['width']
				);

				if($current)
				{
					$current->from = $beam->from;
					$current->to = $beam->to;
					$current->duration = $beam->duration;
					$current->width = $beam->width;
					$beam = $current;
				}

				$beam->synchronizeAge((float)$value['age']);

				return $beam;
			}
		));

		$result->prefab('doom.beam', static fn(Registry $registry): int => $registry->create(new RemoteBeam));
		$result->prefab('doom.pickup', static fn(Registry $registry): int => $registry->create(new MapThing(-1, 0)));

		$result->prefab(
			'doom.player'
			, static function(): int {
				throw new \LogicException('Doom player snapshots require a deterministic map-owned slot binding');
			}
		);

		if($actors)
		{
			$result->register(new CallbackComponentCodec(
				'doom.actor-state'
				, 1
				, ActorState::class
				, static fn(ActorState $value): array => [
					'definition' => $value->definition->name
					, 'label' => $value->label
					, 'frame' => $value->frame
					, 'ticks' => $value->ticks
					, 'entered' => $value->entered
					, 'stopped' => $value->stopped
				]
				, static function(array $value, ?ActorState $current) use ($actors): ActorState {
					$definition = $actors->named((string)$value['definition']);
					$label = (string)$value['label'];
					$frame = (int)$value['frame'];

					if(!$definition || !$definition->state($label) || $frame < 0)
					throw new \UnexpectedValueException('Unknown replicated Doom actor state');

					$state = $current ?? new ActorState($definition, $label);
					$state->definition = $definition;
					$state->label = $label;
					$state->frame = $frame;
					$state->ticks = (float)$value['ticks'];
					$state->entered = (bool)$value['entered'];
					$state->stopped = (bool)$value['stopped'];

					return $state;
				}
				, new ReplicationPolicy(ReplicationPolicy::AUTHORITY_OWNER)
			));

			// No Body or Projectile component: collision and damage belong to the host.
			$result->prefab(
				'doom.projectile'
				, static fn(Registry $registry): int => $registry->create(new RemoteProjectile, new MapThing(-1, 0))
			);
		}

		return $result;
	}

	private function bindEffects(): void
	{
		foreach($this->entities->bindings() as $value => $entity)
		if(!$this->world->alive($entity)) $this->entities->retire(new NetworkEntityId($value));

		foreach([Projectile::class => 'doom.projectile', BeamEffect::class => 'doom.beam'] as $type => $prefab)
		foreach($this->world->query($type) as $entity)
		{
			$this->bindEffect($entity, $prefab);
		}

		foreach($this->world->query(InventoryPickup::class) as $entity)
		{
			if(!$this->world->has($entity, MapThing::class)
				|| $this->world->get($entity, MapThing::class)->index >= 0
			) continue;

			$this->bindEffect($entity, 'doom.pickup');
		}
	}

	private function bindEffect(int $entity, string $prefab): void
	{
		if($this->world->has($entity, NetworkIdentity::class)) return;

		$id = $this->entities->allocate($entity);
		$this->world->add($entity, new NetworkIdentity($id, $this->scope));
		$this->world->add($entity, new NetworkPrefab($prefab));
	}

	private function bindPlayers(ReplicationScope $scope): void
	{
		$players = $this->world->query(NetworkPlayer::class);

		usort(
			$players
			, fn(int $left, int $right): int => $this->world->get($left, NetworkPlayer::class)->slot->value() <=> $this
				->world->get($right, NetworkPlayer::class)->slot->value()
		);

		if(count($players) !== 2) throw new \LogicException('Doom network profile requires exactly two players');

		foreach($players as $entity)
		{
			$player = $this->world->get($entity, NetworkPlayer::class);
			$id = new NetworkEntityId($player->slot->value());
			$this->entities->bind($id, $entity);
			$this->world->add($entity, new NetworkIdentity($id, $scope));
			$this->world->add($entity, new NetworkPrefab('doom.player'));
			$this->world->add($entity, new NetworkAuthority(AuthorityOwner::peer($player->peer)));
		}
	}

	private function player(PlayerSlot $slot): int
	{
		foreach($this->world->query(NetworkPlayer::class) as $entity)
		if($this->world->get($entity, NetworkPlayer::class)->slot->equals($slot)) return $entity;

		throw new \OutOfBoundsException("No Doom player entity owns slot $slot");
	}

	private function command(PlayerSlot $slot): PlayerCommand
	{
		return $this->world->get($this->player($slot), PlayerCommand::class);
	}

	private static function coordinate(float $value): float { return round($value * 256) / 256; }

	/** @return array<string,mixed> */
	private static function playerState(PlayerState $value): array
	{
		return [
			'ammo' => $value->ammo
			, 'ammoMaximum' => $value->ammoMaximum
			, 'infiniteAmmo' => $value->infiniteAmmo
			, 'weapons' => $value->weapons
			, 'keys' => $value->keys
			, 'inventory' => $value->inventory
			, 'powers' => $value->powers
			, 'weapon' => $value->weapon
			, 'pendingWeapon' => $value->pendingWeapon
			, 'armor' => $value->armor
			, 'armorClass' => $value->armorClass
			, 'kills' => $value->kills
			, 'items' => $value->items
			, 'secrets' => $value->secrets
			, 'godMode' => $value->godMode
			, 'noClip' => $value->noClip
			, 'exited' => $value->exited
			, 'secretExit' => $value->secretExit
			, 'reactionTics' => $value->reactionTics
			, 'message' => $value->message
			, 'messageTics' => $value->messageTics
			, 'deathTime' => $value->deathTime
			, 'viewHeight' => $value->viewHeight
			, 'previousViewHeight' => $value->previousViewHeight
		];
	}

	/** @param array<string,mixed> $value */
	private static function restorePlayer(array $value, ?PlayerState $current): PlayerState
	{
		$previousDeathTime = $current?->deathTime;
		$previousViewHeight = $current?->viewHeight ?? PlayerState::VIEW_HEIGHT;
		$previousViewStart = $current?->previousViewHeight ?? PlayerState::VIEW_HEIGHT;
		$player = $current ?? new PlayerState;

		foreach(['ammo','ammoMaximum','weapons','keys','powers'] as $property)
		{
			if(!is_array($value[$property] ?? null)) throw new \UnexpectedValueException("Invalid player $property");

			$player->$property = $value[$property];
		}

		$player->inventory = is_array($value['inventory'] ?? null) ? $value['inventory'] : [];

		$player->infiniteAmmo = is_array($value['infiniteAmmo'] ?? null) ? $value['infiniteAmmo'] : [];

		$player->weapon = (string)($value['weapon'] ?? 'Pistol');
		$pending = $value['pendingWeapon'] ?? null;
		$player->pendingWeapon = is_string($pending) && $pending !== '' ? $pending : null;

		foreach(['armor','armorClass','kills','items','secrets','reactionTics'] as $property)
		$player->$property = (int)($value[$property] ?? 0);

		foreach(['godMode','noClip','exited','secretExit'] as $property)
		$player->$property = (bool)($value[$property] ?? false);

		$player->message = substr((string)($value['message'] ?? ''), 0, 160);
		$player->messageTics = $player->message === ''
			? 0
			: max(0, min(PlayerState::MESSAGE_TICS, (int)($value['messageTics'] ?? PlayerState::MESSAGE_TICS)));

		$deathTime = $value['deathTime'] ?? null;
		$viewHeight = is_numeric($value['viewHeight'] ?? null)
			? max(PlayerState::DEATH_VIEW_HEIGHT, min(PlayerState::VIEW_HEIGHT, (float)$value['viewHeight']))
			: PlayerState::VIEW_HEIGHT;

		$viewStart = is_numeric($value['previousViewHeight'] ?? null)
			? max(PlayerState::DEATH_VIEW_HEIGHT, min(PlayerState::VIEW_HEIGHT, (float)$value['previousViewHeight']))
			: $viewHeight;

		if(is_numeric($deathTime))
		{
			$player->deathTime = max(0, (float)$deathTime, $previousDeathTime ?? 0);
			$player->viewHeight = $previousDeathTime === null
				? $viewHeight
				: min($previousViewHeight, $viewHeight);

			$player->previousViewHeight = $previousDeathTime === null
				? max($player->viewHeight, $viewStart)
				: max($player->viewHeight, min($previousViewStart, $viewStart));
		}
		else
		{
			$player->deathTime = null;
			$player->viewHeight = $viewHeight;
			$player->previousViewHeight = $viewStart;
		}

		return $player;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\RemoteActor;
use DoomPhonics\Component\RemoteBeam;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\FaceDirection;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerRespawn;
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
use Phonics\Network\ReliableEvent;
use Phonics\Network\ReplicationCallbacks;
use Phonics\Network\ReplicationPolicy;
use Phonics\Network\ReplicationRegistry;
use Phonics\Network\ReplicationScope;
use Phonics\Network\ReplicationSession;
use Phonics\Network\SimulationTick;

/** Doom-owned codecs and player command mapping over neutral Phonics replication. */
final class EpisodeNetworkSystem
{
	/** Map-spawned actors use network IDs offset by their thing index, so both peers bind them alike. */
	private const MAP_ACTOR_IDS = 1000;

	/** A corrected pose this far from the predicted one jumps there instead of gliding. */
	private const SNAP_DISTANCE = 64.0;

	/** One step that moves the player this far went through a teleporter. */
	private const TELEPORT_DISTANCE = 128.0;

	/** Steps a teleport or respawn may wait for the host to confirm it before corrections resume regardless. */
	private const DISCONTINUITY_STEPS = 70;

	private NetworkEntityAllocator $entities;
	private ReplicationSession $session;
	private ReplicationScope $scope;
	private ?PortalInterpolation $portalInterpolation;
	private ?Level $level;
	private int $respawns = 0;

	/** Guest's own health after the last step, so a host-applied hit can show its local feedback. */
	private ?int $health = null;

	/** Moves this peer's player through replayed commands without side effects; see PlayerMovementSystem::replay(). */
	private ?\Closure $replay;

	/** Sequence of the newest local command, the one this step simulates. */
	private int $sequence = 0;

	/** @var array<int,float> Heading each local command's step moved along, by command sequence. */
	private array $headings = [];

	/** Local command whose step teleported or respawned this peer's player, until the host has applied it. */
	private ?int $discontinuity = null;

	/** @var ?array{float,float} This peer's player position before this step's movement. */
	private ?array $stepStart = null;

	public function __construct(
		private Registry $world
		, public DoomMatch $match
		, string $scope
		, ActorCatalog $actors
		, ?BrowserBridge $bridge = null
		, ?Level $level = null
		, ?\Closure $replay = null
	){
		$match->replication = $this;
		$this->replay = $replay;
		$this->entities = new NetworkEntityAllocator;
		$this->scope = new ReplicationScope($scope);
		$this->level = $level;
		$this->portalInterpolation = $level ? new PortalInterpolation($level) : null;
		$replication = self::replication($actors);
		$this->bindPlayers($this->scope);
		$this->bindActors();

		$this->session = new ReplicationSession(
			$world
			, $replication
			, $this->entities
			, $bridge ?? new BrowserBridge
			, $match->network
			, new ReplicationCallbacks(
				fn(PlayerSlot $slot, SimulationTick $tick): array => $this->command($slot)->actions()
				, function(InputCommand $command): void {
					if($command->player->equals($this->match->network->localSlot))
					$this->sequence = $command->sequence->value();

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
				, fn(ReliableEvent $event) => $this->event($event)
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

		if(!$this->match->network->host())
		{
			$this->present($registry);
			$this->reconcile($registry, $dt);
		}

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
		else $this->recordStep($registry);

		$this->session->afterStep($registry, $dt);
	}

	/**
	 * Host: respawn dead players who press Use or Fire once the death delay has passed.
	 *
	 * A dead player's peer samples a fresh Use or Fire press as the command's Use pulse, so a held button never
	 * counts and network jitter cannot fake a press.
	 *
	 * The host resets the health and inventory it owns, then announces the spot in a reliable event so that each peer
	 * places the player, including the peer that owns the player's pose.
	 *
	 * @param Registry $registry Network world.
	 * @param float $dt Fixed step in seconds.
	 * @return void
	 */
	public function respawn(Registry $registry, float $dt): void
	{
		if(!$this->match->network->host() || !$this->level) return;

		$players = $registry->query(NetworkPlayer::class);

		foreach($players as $entity)
		{
			if(!$registry->has($entity, PlayerCommand::class) || !$registry->has($entity, HitPoints::class)) continue;

			if($registry->get($entity, HitPoints::class)->current > 0) continue;

			// A dead player's Use only ever asks to respawn, so an early press cannot linger past the delay.
			$slot = $registry->get($entity, NetworkPlayer::class)->slot;
			$use = $registry->get($entity, PlayerCommand::class)->consumeUse();

			if(!$use || !$registry->get($entity, PlayerState::class)->canRestart()) continue;

			$occupied = [];

			foreach($players as $other)
			if($other !== $entity && $registry->get($other, HitPoints::class)->current > 0)
			{
				$pose = $registry->get($other, Transform::class);
				$occupied[] = [$pose->x, $pose->y];
			}

			$spot = PlayerRespawn::spot($this->level, $this->match->mode, $slot, $occupied);
			$health = $registry->get($entity, HitPoints::class);
			$health->current = $health->maximum;
			$registry->add($entity, PlayerRespawn::reborn($registry->get($entity, PlayerState::class)));

			$this->session->emit(new ReliableEvent(
				'respawn:' . $slot->value() . ':' . ++$this->respawns
				, $this->session->tick()
				, 'doom.respawn'
				, ['slot' => $slot->value()] + $spot
			));
		}
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
			'doom.actor'
			, static fn(Registry $registry): int => $registry->create(new RemoteActor, new MapThing(-1, 0))
		);

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

		// Monsters the host spawns during play, such as a pain elemental's lost souls.
		foreach($this->world->query(MonsterBrain::class) as $entity) $this->bindEffect($entity, 'doom.actor');

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

	/** Apply a host gameplay event on this peer. */
	private function event(ReliableEvent $event): void
	{
		$payload = $event->payload;

		if($event->type !== 'doom.respawn') return;

		foreach(['x','y','z','angle'] as $axis)
		if(!is_numeric($payload[$axis] ?? null) || !is_finite((float)$payload[$axis]))
		throw new \UnexpectedValueException('Respawn event has an invalid spot');

		$entity = $this->player(new PlayerSlot((int)($payload['slot'] ?? 0)));

		PlayerRespawn::place(
			$this->world
			, $entity
			, [
				'x' => (float)$payload['x']
				, 'y' => (float)$payload['y']
				, 'z' => (float)$payload['z']
				, 'angle' => (float)$payload['angle']
			]
		);

		if($this->match->network->host()) return;

		// The host's result reaches the new spot only once it applies a command this peer sends from now on.
		if($this->world->get($entity, NetworkPlayer::class)->local) $this->discontinuity = $this->sequence + 1;

		// Mirror the host-owned reset until the next snapshot confirms it, so the player is not shown dead meanwhile.
		$health = $this->world->get($entity, HitPoints::class);
		$health->current = $health->maximum;
		$this->world->add($entity, PlayerRespawn::reborn($this->world->get($entity, PlayerState::class)));
	}

	/**
	 * Guest: rewind this peer's player to the host's latest result and replay the newer local commands over it.
	 *
	 * This is Zandronum's client prediction. The guest moves its own player at once from its own commands, and the
	 * host moves its copy from the same commands when they arrive. Whatever only the host knows, such as damage
	 * pushback, a monster in the way, or a door, appears in the host's result. Replaying the commands the host had
	 * not applied yet keeps the guest's lead, so it ends where the host will put it.
	 *
	 * A small difference glides over one step; a large one snaps. A teleport or respawn waits until the host has
	 * applied the command that caused it, since a replay never crosses teleport lines.
	 */
	private function reconcile(Registry $registry, float $dt): void
	{
		$entity = $this->player($this->match->network->localSlot);
		$correction = $this->session->correction($entity);

		if($correction && $this->replay && $this->alive($registry, $entity) && $this->confirmed($correction->acknowledged->value()))
		{
			$authority = $correction->component(Transform::class);
			$motion = $correction->component(Velocity::class);
			$steps = [];

			foreach($correction->commands as $command)
			{
				$sequence = $command->sequence->value();

				// This step's own command runs in the movement system after this.
				if($sequence >= $this->sequence) break;

				if(!isset($this->headings[$sequence]))
				{
					$steps = null;
					break;
				}

				$steps[] = ['actions' => $command->actions(), 'angle' => $this->headings[$sequence]];
			}

			if($authority && $motion && $steps !== null) $this->correct($registry, $entity, $authority, $motion, $steps, $dt);
		}

		$pose = $registry->get($entity, Transform::class);
		$this->stepStart = [$pose->x, $pose->y];
	}

	/**
	 * Move this peer's player to the host's result plus a replay, keeping its own facing.
	 *
	 * @param list<array{actions: array<string,mixed>, angle: float}> $steps
	 */
	private function correct(
		Registry $registry
		, int $entity
		, Transform $authority
		, Velocity $motion
		, array $steps
		, float $dt
	): void {
		$pose = $registry->get($entity, Transform::class);
		$velocity = $registry->get($entity, Velocity::class);
		$predicted = [$pose->x, $pose->y, $pose->z];
		$pose->x = $authority->x;
		$pose->y = $authority->y;
		$pose->z = $authority->z;
		$velocity->x = $motion->x;
		$velocity->y = $motion->y;
		$velocity->z = $motion->z;
		($this->replay)($registry, $entity, $steps, $dt);
		$corrected = [$pose->x, $pose->y, $pose->z];
		[$pose->x, $pose->y, $pose->z] = $predicted;
		$error = hypot(hypot($corrected[0] - $predicted[0], $corrected[1] - $predicted[1]), $corrected[2] - $predicted[2]);

		if($error < 1 / 64) return;

		if($error <= self::SNAP_DISTANCE)
		{
			$pose->reconcile($corrected[0], $corrected[1], $corrected[2], $pose->angle);

			return;
		}

		[$pose->x, $pose->y, $pose->z] = $corrected;
		$pose->previousX = $pose->x;
		$pose->previousY = $pose->y;
		$pose->previousZ = $pose->z;
	}

	private function alive(Registry $registry, int $entity): bool
	{
		return $registry->has($entity, HitPoints::class) && $registry->get($entity, HitPoints::class)->current > 0
			&& $registry->has($entity, Velocity::class);
	}

	/** Whether the host's result includes this peer's latest teleport or respawn. */
	private function confirmed(int $acknowledged): bool
	{
		if($this->discontinuity === null) return true;

		if($acknowledged < $this->discontinuity && $this->sequence - $this->discontinuity < self::DISCONTINUITY_STEPS)
		return false;

		$this->discontinuity = null;

		return true;
	}

	/** Guest: remember this step's heading for replays, and notice a teleport. */
	private function recordStep(Registry $registry): void
	{
		$entity = $this->player($this->match->network->localSlot);
		$pose = $registry->get($entity, Transform::class);
		$this->headings[$this->sequence] = $pose->angle;

		foreach(array_keys($this->headings) as $sequence)
		if($sequence <= $this->sequence - 256) unset($this->headings[$sequence]);
		else break;

		if($this->stepStart
			&& hypot($pose->x - $this->stepStart[0], $pose->y - $this->stepStart[1]) > self::TELEPORT_DISTANCE
		) $this->discontinuity = $this->sequence;
	}

	/**
	 * Guest: present host-owned state that arrived this step.
	 *
	 * Remote actors take collision from their replicated health, since the host runs their death actions. This peer's
	 * own player shows the flash, status face, pain, and death of a hit the host resolved.
	 */
	private function present(Registry $registry): void
	{
		foreach($registry->query(RemoteActor::class) as $entity)
		{
			if(!$registry->has($entity, Body::class) || !$registry->has($entity, ActorState::class)) continue;

			$alive = !$registry->has($entity, HitPoints::class) || $registry->get($entity, HitPoints::class)->current > 0;
			$definition = $registry->get($entity, ActorState::class)->definition;
			$body = $registry->get($entity, Body::class);
			$body->solid = $alive && $definition->hasFlag('SOLID');
			$body->shootable = $alive && $definition->hasFlag('SHOOTABLE');
		}

		$entity = $this->player($this->match->network->localSlot);

		if(!$registry->has($entity, HitPoints::class)) return;

		$health = $registry->get($entity, HitPoints::class);
		$before = $this->health;
		$this->health = $health->current;

		if($before === null || $health->current >= $before || $before <= 0) return;

		$damage = $before - $health->current;

		if($registry->has($entity, ViewEffects::class)) $registry->get($entity, ViewEffects::class)->recordDamage($damage);

		if($registry->has($entity, StatusFace::class))
		$registry->get($entity, StatusFace::class)->recordDamage($damage, FaceDirection::front());

		if(!$registry->has($entity, ActorState::class)) return;

		// The owner's actor state drives its own pain and death sounds through ActorStateSystem.
		$state = $registry->get($entity, ActorState::class);

		if($health->current <= 0)
		{
			$label = $health->current <= -$health->maximum && $state->definition->state('XDeath') ? 'XDeath' : 'Death';

			if($state->definition->state($label)) $state->enter($label);
		}
		elseif($state->definition->state('Pain') && mt_rand(0, 255) < $health->painChance) $state->enter('Pain');
	}

	/** Bind map-spawned monsters and shootable things alike on both peers; guests only present them. */
	private function bindActors(): void
	{
		$host = $this->match->network->host();

		foreach($this->world->query(MapThing::class) as $entity)
		{
			$index = $this->world->get($entity, MapThing::class)->index;

			if($index < 0 || !$this->world->has($entity, ActorState::class)
				|| $this->world->has($entity, NetworkPlayer::class)
				|| (!$this->world->has($entity, MonsterBrain::class) && !$this->world->has($entity, HitPoints::class))
			) continue;

			$id = new NetworkEntityId(self::MAP_ACTOR_IDS + $index);
			$this->entities->bind($id, $entity);
			$this->world->add($entity, new NetworkIdentity($id, $this->scope));
			$this->world->add($entity, new NetworkPrefab('doom.actor'));

			if(!$host) $this->world->add($entity, new RemoteActor);
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

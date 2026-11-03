<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\RemoteActor;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\DamageEvent;
use DoomPhonics\Game\DamageResolver;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\Level;
use DoomPhonics\System\ActorStateSystem;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeNetworkSystem;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use Phonics\Network\BrowserBridge;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\MatchContext;
use Phonics\Network\Packet;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\Protocol;
use Phonics\Network\SimulationTick;

// Online, the host simulates every monster and resolves all damage. Each peer used to run its own copy of the map's
// monsters, so a guest saw imps throw fireballs from where the host's imps were not, and died to monsters it had
// already killed.
(static function() use ($addonDefinitions, $stack): void {
	$actors = clone $addonDefinitions->actors;
	$factory = new ActorFactory($actors);
	$map = $stack->map('E1M1');
	$level = new Level($map);
	$queues = ['host' => [], 'guest' => []];
	$worlds = $sessions = [];
	$protocol = new Protocol;
	$sequences = ['host' => 0, 'guest' => 0];

	foreach(['host', 'guest'] as $side)
	{
		$slot = $side === 'host' ? 1 : 2;
		$other = $side === 'host' ? 'guest' : 'host';

		$match = new DoomMatch(new MatchContext(
			$side
			, new PlayerSlot($slot)
			, new PlayerSlot(3 - $slot)
			, new PeerId($side)
			, new PeerId($other)
			, 'sha256-AAAA='
			, ['settings' => ['mode' => 'deathmatch']]
		));

		$world = $worlds[$side] = new Registry;

		// Receiving ECS IDs need not agree with the host's local IDs.
		if($side === 'guest') for($index = 0; $index < 7; $index++) $world->create();

		$factory->populate($world, $map, 2, true, $match);

		$bridge = new BrowserBridge(
			static function(int $limit) use ($side, &$queues): string {
				return json_encode(array_splice($queues[$side], 0, $limit), JSON_THROW_ON_ERROR);
			}
			, static function(string $channel, int $family, int $tick, int $schema, string $json)
			use ($side, $slot, $other, $protocol, &$queues, &$sequences): bool {
				$packet = new Packet(
					$channel
					, $family
					, 0
					, 1
					, $slot
					, ++$sequences[$side]
					, new SimulationTick($tick)
					, 0
					, 0
					, $schema
					, json_decode($json, true, 64, JSON_THROW_ON_ERROR)
				);

				$queues[$other][] = ['channel' => $channel, 'data' => base64_encode($protocol->encode($packet))];

				return true;
			}
		);

		$sessions[$side] = new EpisodeNetworkSystem($world, $match, 'episode', $actors, $bridge, $level);
	}

	$step = static function(int $count = 1) use ($sessions, $worlds): void {
		for(; $count > 0; $count--)
		foreach(['host', 'guest'] as $side)
		{
			$sessions[$side]->receive($worlds[$side], 1 / 35);
			$sessions[$side]->send($worlds[$side], 1 / 35);
		}
	};

	$host = $worlds['host'];
	$guest = $worlds['guest'];

	$byId = static function(Registry $world): array {
		$result = [];

		foreach($world->query(NetworkIdentity::class) as $entity)
		if($world->has($entity, MapThing::class) && $world->get($entity, MapThing::class)->index >= 0
			&& !$world->has($entity, NetworkPlayer::class)
		)
		$result[$world->get($entity, NetworkIdentity::class)->id->value()] = $entity;

		ksort($result);

		return $result;
	};

	$hostActors = $byId($host);
	$guestActors = $byId($guest);
	$monsters = array_filter($hostActors, static fn(int $entity): bool => $host->has($entity, MonsterBrain::class));

	verifyWad(
		count($monsters) >= 5 && array_keys($hostActors) === array_keys($guestActors)
			&& !array_filter($hostActors, static fn(int $entity): bool => $host->has($entity, RemoteActor::class))
			&& count(array_filter($guestActors, static fn(int $entity): bool => $guest->has($entity, RemoteActor::class)))
				=== count($guestActors)
		, 'both peers bind the same map monsters and shootable things; only the guest presents them'
	);

	// The host moves one monster and kills another; the guest shows both without simulating either.
	[$walkerId, $victimId] = array_slice(array_keys($monsters), 0, 2);
	$walker = $host->get($hostActors[$walkerId], Transform::class);
	$walker->x += 48;
	$victim = $hostActors[$victimId];
	$host->get($victim, HitPoints::class)->current = 0;
	$host->get($victim, ActorState::class)->enter('Death');
	$host->get($victim, Body::class)->solid = $host->get($victim, Body::class)->shootable = false;
	$step(4);

	$guestVictim = $guestActors[$victimId];

	verifyWad(
		$guest->get($guestActors[$walkerId], Transform::class)->x === $walker->x
			&& $guest->get($guestVictim, HitPoints::class)->current === 0
			&& $guest->get($guestVictim, ActorState::class)->label === 'Death'
			&& !$guest->get($guestVictim, Body::class)->solid && !$guest->get($guestVictim, Body::class)->shootable
		, 'the guest shows the host-moved monster and the host-killed one, whose corpse no longer blocks'
	);

	$guestWalker = $guest->get($guestActors[$walkerId], ActorState::class);
	$frame = [$guestWalker->label, $guestWalker->frame, $guestWalker->ticks];
	(new ActorStateSystem(new ActionDispatcher))->update($guest, 2);

	verifyWad(
		[$guestWalker->label, $guestWalker->frame, $guestWalker->ticks] === $frame
		, 'the guest runs no state, AI, or attack actions for a host-simulated monster'
	);

	// A guest's own shot changes nothing; the host decides its effect.
	$guestCombat = new CombatSystem(
		$level
		, $actors
		, $factory
		, new Collision($level)
		, new Controls
		, new ActionDispatcher
		, resolveDamage: false
	);

	$live = $guestActors[$walkerId];
	$healthBefore = $guest->get($live, HitPoints::class)->current;
	$damage = new ReflectionMethod($guestCombat, 'damage');
	$damage->invoke($guestCombat, $guest, $live, $live, 1000);

	verifyWad(
		$guest->get($live, HitPoints::class)->current === $healthBefore && $guest->get($live, ActorState::class)->label
			=== $frame[0]
		, 'guest-side damage leaves health and state to the host'
	);

	// The host's pushback on the guest reaches the guest, which predicts its own movement.
	$player = static function(Registry $world, int $slot): int {
		foreach($world->query(NetworkPlayer::class) as $entity)
		if($world->get($entity, NetworkPlayer::class)->slot->value() === $slot) return $entity;

		throw new RuntimeException("No player in slot $slot");
	};

	$hostCopy = $player($host, 2);
	$self = $player($guest, 2);
	$resolver = new DamageResolver(null, static fn(int $entity, float $x, float $y) => $sessions['host']->thrust($entity, $x, $y));
	$hostVelocity = $host->get($hostCopy, Velocity::class);
	$before = [$hostVelocity->x, $hostVelocity->y];
	$health = $host->get($hostCopy, HitPoints::class);
	$healthBefore = $health->current;
	$resolver->apply($host, new DamageEvent($hostCopy, $player($host, 1), 20, null, true));
	$pushed = [$hostVelocity->x - $before[0], $hostVelocity->y - $before[1]];
	$ownVelocity = $guest->get($self, Velocity::class);
	$ownBefore = [$ownVelocity->x, $ownVelocity->y];
	$step(4);

	verifyWad(
		hypot(...$pushed) > 1 && abs($ownVelocity->x - $ownBefore[0] - $pushed[0]) < 1e-6
			&& abs($ownVelocity->y - $ownBefore[1] - $pushed[1]) < 1e-6
		, 'the guest adds the same pushback the host gave its copy'
	);

	verifyWad(
		$guest->get($self, HitPoints::class)->current === $healthBefore - 20 && $health->current === $healthBefore - 20
			&& $guest->get($self, ViewEffects::class)->damageTics() > 0
		, 'the guest takes the host-resolved hit and shows its damage flash'
	);
})();

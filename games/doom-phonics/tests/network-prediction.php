<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\DamageEvent;
use DoomPhonics\Game\DamageResolver;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Input\DoomActions;
use DoomPhonics\System\EpisodeNetworkSystem;
use DoomPhonics\System\PlayerCommandSystem;
use DoomPhonics\System\PlayerEffectSystem;
use DoomPhonics\System\PlayerMovementSystem;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use Phonics\Network\BrowserBridge;
use Phonics\Network\MatchContext;
use Phonics\Network\Packet;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\Protocol;
use Phonics\Network\SimulationTick;

// A guest predicts its own movement and replays it over each host result, as Zandronum's clients do. Without that,
// anything only the host simulated, such as damage pushback, left the two copies of the guest apart for good.
(static function() use ($addonDefinitions, $stack): void {
	$actors = clone $addonDefinitions->actors;
	$factory = new ActorFactory($actors);
	$map = $stack->map('E1M1');
	$queues = ['host' => [], 'guest' => []];
	$worlds = $sessions = $movement = $controls = $samplers = [];
	$protocol = new Protocol;
	$sequences = ['host' => 0, 'guest' => 0];
	// Packets take three steps each way, so the host applies a guest command several steps after the guest ran it.
	$latency = 3;
	$time = 0;

	foreach(['host', 'guest'] as $side)
	{
		$slot = $side === 'host' ? 1 : 2;
		$other = $side === 'host' ? 'guest' : 'host';
		$level = new Level($map);

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
		$factory->populate($world, $map, 2, true, $match);

		foreach($world->query(NetworkPlayer::class) as $entity)
		if(!$world->has($entity, ViewInput::class)) $world->add($entity, new ViewInput);

		$controls[$side] = new Controls;
		$samplers[$side] = new PlayerCommandSystem($controls[$side]);

		// The host moves every player from commands; the guest moves only its own.
		$movement[$side] = new PlayerMovementSystem(
			new Collision($level)
			, new LineSpecials($level)
			, $controls[$side]
			, simulateRemotePlayers: $side === 'host'
		);

		$bridge = new BrowserBridge(
			static function(int $limit) use ($side, &$queues, &$time): string {
				$ready = [];

				while($queues[$side] && $queues[$side][0]['at'] <= $time && count($ready) < $limit)
				{
					['channel' => $channel, 'data' => $data] = array_shift($queues[$side]);
					$ready[] = ['channel' => $channel, 'data' => $data];
				}

				return json_encode($ready, JSON_THROW_ON_ERROR);
			}
			, static function(string $channel, int $family, int $tick, int $schema, string $json)
			use ($side, $slot, $other, $protocol, $latency, &$queues, &$sequences, &$time): bool {
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

				$queues[$other][] = [
					'at' => $time + $latency
					, 'channel' => $channel
					, 'data' => base64_encode($protocol->encode($packet))
				];

				return true;
			}
		);

		$sessions[$side] = new EpisodeNetworkSystem(
			$world
			, $match
			, 'episode'
			, $actors
			, $bridge
			, $level
			, Closure::fromCallable([$movement[$side], 'replay'])
		);
	}

	$effects = new PlayerEffectSystem;
	$hooks = ['host' => null, 'guest' => null];

	// The production order: sample, exchange, death timers, respawn, begin the step, move, then publish.
	$step = static function(int $count = 1, array $held = [])
	use ($sessions, $worlds, $controls, $samplers, $effects, $movement, &$hooks, &$time): void {
		for(; $count > 0; $count--, $time++)
		foreach(['host', 'guest'] as $side)
		{
			$world = $worlds[$side];
			$controls[$side]->set($held[$side] ?? []);
			$samplers[$side]->update($world, 1 / 35);
			$sessions[$side]->receive($world, 1 / 35);
			$effects->update($world, 1 / 35);
			$sessions[$side]->respawn($world, 1 / 35);

			foreach($world->query(NetworkPlayer::class) as $entity) $world->get($entity, Transform::class)->beginStep();

			$movement[$side]->update($world, 1 / 35);

			if($hooks[$side]) ($hooks[$side])($world);

			$sessions[$side]->send($world, 1 / 35);
		}
	};

	$player = static function(Registry $world, int $slot): int {
		foreach($world->query(NetworkPlayer::class) as $entity)
		if($world->get($entity, NetworkPlayer::class)->slot->value() === $slot) return $entity;

		throw new RuntimeException("No player in slot $slot");
	};

	$host = $worlds['host'];
	$guest = $worlds['guest'];
	$self = $guest->get($player($guest, 2), Transform::class);
	$copy = $host->get($player($host, 2), Transform::class);
	$apart = static fn(): float => hypot(hypot($self->x - $copy->x, $self->y - $copy->y), $self->z - $copy->z);
	$start = [$self->x, $self->y];

	// The host copy trails by the command still in flight, so compare once friction has stopped both.
	$step(20, ['guest' => [DoomActions::FORWARD => 1]]);
	$step(100);

	verifyWad(
		hypot($self->x - $start[0], $self->y - $start[1]) > 100 && $apart() < .05
		, 'the guest walks on its own prediction and stops where the host stops its copy'
	);

	// The host pushes its copy of the guest. Only the host knows about the hit.
	$before = [$self->x, $self->y];
	$host->get($player($host, 2), HitPoints::class)->current = 10000;
	(new DamageResolver)->apply($host, new DamageEvent($player($host, 2), $player($host, 1), 40, null, true));
	$step(100);

	verifyWad(
		hypot($self->x - $before[0], $self->y - $before[1]) > 4 && $apart() < .05
		, 'host-applied pushback reaches the guest through reconciliation'
	);

	// A guest whose prediction drifted is brought back to the host's result.
	$self->x += 30;
	$self->previousX = $self->x;
	$step(12);
	verifyWad($apart() < .05, 'a drifted guest prediction converges on the host result');

	// A teleport, here stood in for by weapon slot 7, moves both copies on their own steps. Neither sends a
	// correction that drags the guest back to where it was while the host has not applied that command yet.
	$destination = null;

	foreach(ActorFactory::playerStarts($map) as $thing)
	if((int)$thing['type'] === 11 && hypot((float)$thing['x'] - $self->x, (float)$thing['y'] - $self->y) > 256)
	{
		$destination = $thing;
		break;
	}

	$teleport = static function(Registry $world, int $entity) use ($destination, $map): void {
		$pose = $world->get($entity, Transform::class);
		$pose->x = $pose->previousX = (float)$destination['x'];
		$pose->y = $pose->previousY = (float)$destination['y'];
		$pose->z = $pose->previousZ = (float)ActorFactory::spawnPlanes($map, $destination, new Level($map))['floor'];
		$velocity = $world->get($entity, Velocity::class);
		$velocity->x = $velocity->y = $velocity->z = 0;
	};

	$hooks['host'] = static function(Registry $world) use ($player, $teleport): void {
		$entity = $player($world, 2);

		if($world->get($entity, PlayerCommand::class)->consumeWeaponSlot() === 7) $teleport($world, $entity);
	};

	$hooks['guest'] = static function(Registry $world) use ($player, $teleport): void {
		$entity = $player($world, 2);

		if($world->get($entity, PlayerCommand::class)->consumeWeaponSlot() === 7) $teleport($world, $entity);
	};

	$step(1, ['guest' => [DoomActions::SLOTS[7] => 1]]);
	$arrived = [$self->x, $self->y];
	$farthest = 0.0;

	for($index = 0; $index < 20; $index++)
	{
		$step();
		$farthest = max($farthest, hypot($self->x - $arrived[0], $self->y - $arrived[1]));
	}

	verifyWad(
		$destination !== null && $arrived === [(float)$destination['x'], (float)$destination['y']] && $farthest < 1
			&& $apart() < .05
		, 'a teleport the host has yet to apply is not undone by the corrections in between'
	);

	$hooks = ['host' => null, 'guest' => null];

	// The host kills and later respawns the guest; corrections then follow the respawned player, not the corpse.
	$health = $host->get($player($host, 2), HitPoints::class);
	$health->current = 0;
	$host->get($player($host, 2), PlayerState::class)->beginDeath();
	$step(45);
	$step(1, ['guest' => [DoomActions::USE => 1]]);
	$step(20);

	verifyWad(
		$health->current > 0 && $guest->get($player($guest, 2), HitPoints::class)->current > 0 && $apart() < .05
			&& in_array(
				[$self->x, $self->y]
				, array_map(
					static fn(array $thing): array => [(float)$thing['x'], (float)$thing['y']]
					, array_filter(ActorFactory::playerStarts($map), static fn(array $thing): bool => (int)$thing['type'] === 11)
				)
				, true
			)
		, 'a respawned guest stays at its deathmatch start and matches the host copy'
	);
})();

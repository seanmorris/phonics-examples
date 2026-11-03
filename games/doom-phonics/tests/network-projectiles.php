<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Audio\NullSoundEmitter;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\ProjectileBounce;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\SectorMover;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\System\ActorStateSystem;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeNetworkSystem;
use DoomPhonics\System\PickupSystem;
use DoomPhonics\System\SectorMotionSystem;
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

(static function() use ($addonDefinitions, $stack): void {
	$actors = clone $addonDefinitions->actors;

	$custom = DecorateParser::catalog([
		'network-test.dec' => <<<'DEC'
ACTOR NetworkTestMissile : Rocket
{
  Speed 12
  Alpha 0.5
}
DEC
	]);

	foreach($custom->all() as $definition) $actors->add($definition);
	$factory = new ActorFactory($actors);
	$queues = ['host' => [], 'guest' => []];
	$worlds = $sessions = [];
	$protocol = new Protocol;
	$dropFirst = true;
	$sequences = ['host' => 0, 'guest' => 0];

	foreach(['host', 'guest'] as $side)
	{
		$slot = $side === 'host' ? 1 : 2;
		$other = $side === 'host' ? 'guest' : 'host';

		$match = new DoomMatch(
			new MatchContext(
				$side
				, new PlayerSlot($slot)
				, new PlayerSlot(3 - $slot)
				, new PeerId($side)
				, new PeerId($other)
				, 'sha256-AAAA='
				, ['settings' => ['mode' => 'cooperative']]
			)
		);

		$world = $worlds[$side] = new Registry;

		// Receiving ECS IDs need not agree with the host's local IDs.
		if($side === 'guest') for($index = 0; $index < 5; $index++) $world->create();

		$factory->populate($world, $stack->map('E1M1'), 2, true, $match);

		$bridge = new BrowserBridge(
			static function(int $limit) use ($side, &$queues): string {
				return json_encode(array_splice($queues[$side], 0, $limit), JSON_THROW_ON_ERROR);
			}
			, static function(
				string $channel
				, int $family
				, int $tick
				, int $schema
				, string $json
			)
			use ($side, $slot, $other, $protocol, &$queues, &$sequences, &$dropFirst): bool {
				if($side === 'host' && $dropFirst)
				{
					$dropFirst = false;

					return true;
				}

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

		$sessions[$side] = new EpisodeNetworkSystem($world, $match, 'episode', $actors, $bridge);
	}

	$step = static function() use ($sessions, $worlds): void {
		foreach(['host', 'guest'] as $side)
		{
			$sessions[$side]->receive($worlds[$side], 1 / 35);
			$sessions[$side]->send($worlds[$side], 1 / 35);
		}
	};

	$host = $worlds['host'];
	$guest = $worlds['guest'];
	$state = new ActorState($actors->named('Rocket'));
	$pose = new Transform(3440, -3420, 32, .5);

	$rocket = $host->create(
		$state
		, $pose
		, new Velocity(700, 0, 0)
		, new Projectile($host->query(NetworkPlayer::class)[0], 20)
	);

	$step();
	verifyWad($guest->query(RemoteProjectile::class) === [], 'lost first rocket snapshot does not invent a replica');
	$step();
	$step();
	$replicas = $guest->query(RemoteProjectile::class);
	verifyWad(count($replicas) === 1, 'later snapshot recovers a missed rocket spawn exactly once');
	$replica = $replicas[0];
	$identity = $host->get($rocket, NetworkIdentity::class)->id;
	$remotePose = $guest->get($replica, Transform::class);
	$remoteState = $guest->get($replica, ActorState::class);

	verifyWad(
		$guest->get($replica, NetworkIdentity::class)->id->equals($identity) && $remoteState->current()
			?->sprite === 'MISL' && $remotePose->previousX === $pose->x && $remotePose->previousZ === $pose->z
		, 'rocket spawn resolves the shared identity and starts interpolation at the muzzle'
	);

	verifyWad(
		!$guest->has($replica, Body::class) && !$guest->has($replica, Projectile::class)
		, 'remote rocket cannot run local collision or projectile damage'
	);

	$pose->x += 40;
	$state->enter('Death');
	$step();
	$step();

	verifyWad(
		count($guest->query(RemoteProjectile::class)) === 1 && $guest->get(
			$replica
			, ActorState::class
		) === $remoteState && $guest->get($replica, Transform::class) === $remotePose
			&& $remoteState->label === 'Death' && $remotePose->x === $pose->x
		, 'rocket deltas update renderer-cached state and pose without duplicating the entity'
	);

	$actions = new ActionDispatcher;
	$explosions = 0;
	$actions->register('A_Explode', static function() use (&$explosions): void { $explosions++; });

	(new ActorStateSystem($actions))->update($guest, 1);

	verifyWad(
		$explosions === 0 && !$remoteState->entered && $remoteState->frame === 0
		, 'replicated explosion frames never dispatch damage or advance independently'
	);

	$host->destroy($rocket);
	$step();
	$step();

	verifyWad(
		!$guest->alive($replica) && $guest->query(RemoteProjectile::class) === []
		, 'host projectile retirement removes the remote sprite'
	);

	$dropSector = (new Level($stack->map('E1M1')))->sectorAt(3440, -3420);

	$drop = $factory->spawn(
		$host
		, $actors->named('Clip')
		, ['x' => 3440, 'y' => -3420, 'z' => 0, 'angle' => 0, 'index' => -1, 'flags' => []]
		, [
			'floor' => $dropSector->floorZ(3440, -3420)
			, 'ceiling' => $dropSector->ceilingZ(3440, -3420)
		]
	);

	$hostDrop = $host->get($drop, InventoryPickup::class);
	$hostDrop->amount = 5;
	$hostDrop->dropped = true;
	$step();
	$step();

	$remoteDrops = array_values(
		array_filter(
			$guest->query(InventoryPickup::class)
			, static fn(int $entity): bool => $guest->has($entity, NetworkIdentity::class) && $guest->has(
				$entity
				, MapThing::class
			) && $guest->get($entity, MapThing::class)->index === -1
		)
	);

	verifyWad(count($remoteDrops) === 1, 'host-created enemy drop appears once on the guest');
	$remoteDrop = $remoteDrops[0];
	$guestDrop = $guest->get($remoteDrop, InventoryPickup::class);

	verifyWad(
		$guestDrop->actorName === 'Clip' && $guestDrop->amount === 5 && $guestDrop->dropped
			&& $guest->get($remoteDrop, ActorState::class)->definition->name === 'Clip'
		, 'enemy drop replication preserves its DECORATE actor and dropped amount'
	);

	$guestDropPose = $guest->get($remoteDrop, Transform::class);
	$guestPlayerState = new PlayerState;
	$guestPlayerState->ammo['Clip'] = 0;

	$guestPlayer = $guest->create(
		new PlayerStart(99)
		, $guestPlayerState
		, new Transform($guestDropPose->x, $guestDropPose->y, $guestDropPose->z)
		, new HitPoints(100, 100)
	);

	(new PickupSystem($actors, false))->update($guest, 1 / 35);
	$guest->flush();

	verifyWad(
		$guest->alive($remoteDrop) && $guest->has($remoteDrop, InventoryPickup::class)
		, 'guest prediction cannot consume a host-owned enemy drop'
	);

	$host->destroy($drop);
	$step();
	$step();
	verifyWad(!$guest->alive($remoteDrop), 'host enemy-drop pickup retires its guest replica');

	// Exercise every installed missile definition through real collision and state systems.
	$level = new Level($stack->map('E1M1'));
	$combat = new CombatSystem($level, $actors, $factory, new Collision($level), new Controls, new ActionDispatcher);
	$hostStates = new ActorStateSystem(new ActionDispatcher);
	$remoteActions = new ActionDispatcher;
	$remoteStates = new ActorStateSystem($remoteActions);
	$damageActions = 0;

	foreach(['A_Explode', 'A_BFGSpray', 'A_Detonate'] as $action)
	$remoteActions->register($action, static function() use (&$damageActions): void { $damageActions++; });

	foreach($host->query(Body::class) as $entity) $host->get($entity, Body::class)->shootable = false;
	$names = [];
	$floor = $level->sectorAt(3440, -3420);

	foreach($actors->resolved() as $definition)
	{
		if(!$definition->hasFlag('MISSILE')) continue;

		$names[] = $name = $definition->name;
		$height = min(32, $floor->ceiling - $floor->floor - (float)$definition->value('Height', 16) - 1);

		$entity = $factory->spawn(
			$host
			, $definition
			, ['x' => 3440, 'y' => -3420, 'z' => $height]
			, ['floor' => $floor->floor, 'ceiling' => $floor->ceiling]
		);

		$host->add($entity, new Projectile(0, (int)$definition->value('Damage', 20)));
		$host->get($entity, Velocity::class)->z = -70;
		$frames = $heights = $remoteIds = [];
		$bounced = false;
		$bounceTick = null;
		$presentationOnly = true;

		for($tick = 0; $tick < 120; $tick++)
		{
			$combat->update($host, 1 / 35);
			$hostStates->update($host, 1 / 35);
			$host->flush();

			if($host->alive($entity) && $host->has($entity, ProjectileBounce::class)
				&& !$bounced && $host->get($entity, ProjectileBounce::class)->count > 0
			){
				$bounced = $host->get($entity, Velocity::class)->z > 0;
				$bounceTick = $tick;
			}

			// Allow the bounce to reach the guest, then exercise the normal fuse path.
			if($bounceTick !== null && $tick === $bounceTick + 4)
			$host->get($entity, Projectile::class)->ttl = 0;

			$step();

			foreach($guest->query(RemoteProjectile::class) as $remote)
			{
				$remoteState = $guest->get($remote, ActorState::class);
				$remoteIds[$guest->get($remote, NetworkIdentity::class)->id->value()] = true;
				$frames[$remoteState->label][$remoteState->frame] = $remoteState->current()?->sprite;
				$heights[] = $guest->get($remote, Transform::class)->z;
				$presentationOnly = $presentationOnly && $remoteState->definition->name === $name
					&& !$guest->has($remote, Body::class) && !$guest->has($remote, Projectile::class);
			}

			$remoteStates->update($guest, 1 / 35);
			$guest->flush();
		}

		verifyWad(count($remoteIds) === 1 && $presentationOnly, "$name has one presentation-only remote identity");

		verifyWad(
			in_array($definition->state('Spawn')->frames[0]->sprite, $frames['Spawn'] ?? [], true)
				&& count(array_unique($heights)) > 2
			, "$name replicates its flight sprite and trajectory"
		);

		if($death = $definition->state('Death'))
		{
			// Some impacts, such as BloodSplatter, have only one animation frame.
			verifyWad(
				count(array_filter($frames['Death'] ?? [])) >= min(2, count($death->frames))
				, "$name replicates its impact animation"
			);
		}

		verifyWad(
			!$host->alive($entity) && $guest->query(RemoteProjectile::class) === []
			, "$name retires on both peers after impact"
		);

		if($name === 'Grenade')
		{
			$remoteRose = false;

			foreach($heights as $index => $height)
			if($index > 0 && $height > $heights[$index - 1]) $remoteRose = true;
			verifyWad($bounced && $remoteRose, 'remote grenade visibly follows the host floor bounce');
		}
	}

	verifyWad(
		array_diff(
			[
				'Rocket'
				, 'PlasmaBall'
				, 'BFGBall'
				, 'Grenade'
				, 'DoomImpBall'
				, 'BaronBall'
				, 'CacodemonBall'
				, 'ArachnotronPlasma'
				, 'FatShot'
				, 'RevenantTracer'
				, 'NetworkTestMissile'
			]
			, $names
		) === []
		, 'network coverage includes all built-in missiles, the grenade add-on and a custom inherited missile'
	);

	verifyWad($damageActions === 0, 'remote explosions and BFG spray never dispatch duplicate damage');

	$grounded = new Transform(3440, -3420, $floor->floor);
	$ordinary = new Transform(3440, -3420, $floor->floor);
	$guest->create($grounded, new ActorState($actors->named('Grenade')), new RemoteProjectile);
	$guest->create($ordinary, new ActorState($actors->named('DoomImp')));
	$guest->create(SectorMover::platform($floor->index, $floor->floor + 8, 35));
	(new SectorMotionSystem($level, new NullSoundEmitter))->update($guest, 1 / 35);

	verifyWad(
		$ordinary->z === $grounded->z + 1 && $ordinary->z === $floor->floor
		, 'moving floors carry ordinary actors without moving a replicated projectile ahead of the host'
	);

	foreach($sessions as $session) $session->close();
})();

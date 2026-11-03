<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\RemoteBeam;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\TransientEffect;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Component\WeaponOwner;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\StateFrame;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\WorldPoint;
use DoomPhonics\System\BeamEffectSystem;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\EpisodeNetworkSystem;
use DoomPhonics\Tests\EpisodeMovement;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\Component\NetworkPrefab;
use Phonics\Network\NetworkEntityAllocator;
use Phonics\Network\NetworkEntityId;
use Phonics\Network\ReplicationScope;
use Phonics\Network\SimulationTick;
use Phonics\Network\SnapshotApplier;
use Phonics\Network\SnapshotBuilder;
use Phonics\Network\SnapshotSequence;

(static function() use ($addonDefinitions, $stack): void {
	$profile = EpisodeNetworkSystem::replication();
	$host = new Registry;
	$guest = new Registry;
	$guest->create();
	$builder = new SnapshotBuilder($profile);
	$applier = new SnapshotApplier($profile, new NetworkEntityAllocator);
	$system = new BeamEffectSystem;

	// Custom beam dimensions use the same profile as the railgun's .75-second ribbon.
	$beam = new BeamEffect(new WorldPoint(3440, -3420, 33), new WorldPoint(3500, -3400, 41), 1, 12);

	$entity = $host->create(
		$beam
		, new NetworkIdentity(new NetworkEntityId(3), new ReplicationScope('episode'))
		, new NetworkPrefab('doom.beam')
	);

	$missed = $builder->capture($host, new SnapshotSequence(1), new SimulationTick(0));
	$system->update($host, .25);
	$spawn = $builder->capture($host, new SnapshotSequence(2), new SimulationTick(8));
	$applier->apply($guest, $spawn);
	$replicas = $guest->query(BeamEffect::class);
	verifyWad(count($replicas) === 1, 'later beam snapshot recovers a missed spawn exactly once');
	$replica = $replicas[0];
	$remote = $guest->get($replica, BeamEffect::class);

	verifyWad(
		$guest->has($replica, RemoteBeam::class)
			&& $guest->get($replica, NetworkIdentity::class)->id->value() === 3
			&& $remote->from == $beam->from && $remote->to == $beam->to
			&& $remote->duration === 1.0 && $remote->width === 12.0
		, 'beam replica retains host identity, endpoints and custom dimensions'
	);

	verifyWad(
		$remote->remaining(0) === .75 && $remote->remaining(1) === .75
		, 'late beam spawn starts at host fade progress instead of full width'
	);

	$system->update($guest, .1);
	$remaining = $remote->remaining();
	verifyWad($remaining < .75 && $remote->remaining(.5) > $remaining, 'remote beam fades between host snapshots');
	$system->update($host, .05);
	$delta = $builder->capture($host, new SnapshotSequence(3), new SimulationTick(10), $spawn->sequence);
	$applier->apply($guest, $delta);
	$applier->apply($guest, $missed);
	$applier->apply($guest, $delta);

	verifyWad(
		$guest->query(BeamEffect::class) === [$replica] && $guest->get($replica, BeamEffect::class) === $remote
			&& $remote->remaining(0) <= $remaining && $remote->remaining(1) <= $remaining
		, 'delayed, reordered and duplicate beam snapshots never restart fading or duplicate the entity'
	);

	$system->update($guest, 1);
	$guest->flush();

	verifyWad(
		$guest->alive($replica) && $remote->remaining() === 0.0
		, 'locally expired beam keeps its host-owned identity'
	);

	$system->update($host, .1);
	$late = $builder->capture($host, new SnapshotSequence(4), new SimulationTick(14), $delta->sequence);
	$applier->apply($guest, $late);

	verifyWad(
		$remote->remaining(0) === 0.0 && $remote->remaining(1) === 0.0
		, 'late beam update cannot revive an expired ribbon'
	);

	$system->update($host, 1);
	$host->flush();
	$despawn = $builder->capture($host, new SnapshotSequence(5), new SimulationTick(49), $late->sequence);
	$applier->apply($guest, $despawn);
	$applier->apply($guest, $late);

	verifyWad(
		!$host->alive($entity) && !$guest->alive($replica)
		, 'host beam despawn retires both peers despite stale traffic'
	);

	$actors = $addonDefinitions->actors;
	$factory = new ActorFactory($actors);
	$level = new Level($stack->map('E1M1'));
	$floor = $level->sectorAt(3440, -3420)->floor;

	foreach(['A_FireRailgun', 'A_RAILGUN_FIRE'] as $action)
	foreach([true, false] as $authoritative)
	{
		$world = new Registry;
		$player = new PlayerState;
		$player->ammo['Cell'] = 20;
		$shooter = $world->create($player, new Transform(3440, -3420, $floor, 0));
		$health = new HitPoints(1000, 1000);

		$world->create(
			$health
			, new ActorState($actors->named('BaronOfHell'))
			, new Transform(3448, -3420, $floor)
			, new Body(2, 56, shootable: true)
		);

		$weapon = $world->create(new ActorState($actors->named('Railgun')), new WeaponOwner($shooter));
		$actions = new ActionDispatcher;

		new CombatSystem(
			$level
			, $actors
			, $factory
			, new Collision($level)
			, new Controls
			, $actions
			, simulateBeams: $authoritative
		);

		$actions->dispatch($weapon, new StateFrame('RLGG', 'E', 12, false, $action), $world);

		verifyWad(
			count($world->query(BeamEffect::class)) === ($authoritative ? 1 : 0)
				&& $health->current === ($authoritative ? 900 : 1000)
			, "$action resolves rail damage and creates its beam only on the host"
		);

		verifyWad(
			$player->ammo['Cell'] === 10 && count($world->query(WeaponOverlay::class)) === 1
			, "$action preserves immediate weapon feedback on both peers"
		);

		if(!$authoritative)
		verifyWad($world->query(TransientEffect::class) === [], "$action does not predict duplicate rail impact puffs");
	}

	$episode = new EpisodeMovement(new WadSource($stack));
	$world = $episode->scope->registry;
	$owned = $world->create(new RemoteBeam, new BeamEffect($beam->from, $beam->to));
	$episode->scope->close();
	verifyWad(!$world->alive($owned), 'closing the map removes beam replicas while awaiting host despawn');
})();

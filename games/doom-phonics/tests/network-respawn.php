<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewEffects;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PlayerRespawn;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\System\EpisodeNetworkSystem;
use Phonics\Ecs\Registry;
use Phonics\Network\BrowserBridge;
use Phonics\Network\MatchContext;
use Phonics\Network\Packet;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\Protocol;
use Phonics\Network\SimulationTick;

// An online death respawns the player in the running level: the host resets what it owns and every peer places the
// player at a deathmatch start. Restarting the map on one peer used to leave the host's dead copy in charge.
(static function() use ($addonDefinitions, $stack): void {
	$actors = clone $addonDefinitions->actors;
	$factory = new ActorFactory($actors);
	$map = $stack->map('E1M1');
	$queues = ['host' => [], 'guest' => []];
	$worlds = $sessions = [];
	$protocol = new Protocol;
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
				, ['settings' => ['mode' => 'deathmatch']]
			)
		);

		$world = $worlds[$side] = new Registry;
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

		$sessions[$side] = new EpisodeNetworkSystem($world, $match, 'episode', $actors, $bridge, new Level($map));
	}

	$step = static function(int $count = 1) use ($sessions, $worlds): void {
		for(; $count > 0; $count--)
		foreach(['host', 'guest'] as $side)
		{
			$sessions[$side]->receive($worlds[$side], 1 / 35);
			$sessions[$side]->respawn($worlds[$side], 1 / 35);
			$sessions[$side]->send($worlds[$side], 1 / 35);
		}
	};

	$player = static function(Registry $world, int $slot): int {
		foreach($world->query(NetworkPlayer::class) as $entity)
		if($world->get($entity, NetworkPlayer::class)->slot->value() === $slot) return $entity;

		throw new RuntimeException("No player in slot $slot");
	};

	$host = $worlds['host'];
	$guest = $worlds['guest'];
	$step(3);

	$deathmatchStarts = array_map(
		static fn(array $thing): string => $thing['x'] . ',' . $thing['y']
		, array_filter(ActorFactory::playerStarts($map), static fn(array $thing): bool => (int)$thing['type'] === 11)
	);

	verifyWad(count($deathmatchStarts) > 1, 'E1M1 has deathmatch starts to respawn at');

	// The host kills the guest, as DamageResolver does, after the guest picked up a shotgun and scored.
	$hostCopy = $player($host, 2);
	$dying = $host->get($hostCopy, PlayerState::class);
	$dying->kills = 3;
	$dying->weapons['Shotgun'] = true;
	$dying->ammo['Shell'] = 20;
	$dying->armor = 50;
	$host->get($hostCopy, HitPoints::class)->current = 0;
	$host->get($hostCopy, Body::class)->shootable = false;
	$dying->beginDeath();
	$self = $player($guest, 2);
	// The guest owns its pose: it died a little way from its start, so the respawn has to move it.
	$guest->get($self, Transform::class)->x += 40;
	$guest->get($self, ActorState::class)->enter('Death');
	$guest->get($self, ViewEffects::class)->recordDeath();
	$corpse = clone $guest->get($self, Transform::class);
	$step(2);

	verifyWad(
		$guest->get($self, HitPoints::class)->current === 0 && $guest->get($self, PlayerState::class)->deathTime !== null
		, 'the guest learns of its death from the host-owned health and player state'
	);

	// Use during the death delay is consumed rather than saved for later.
	$guestCommand = $guest->get($self, PlayerCommand::class);
	$guestCommand->apply(['use' => true]);
	$step();
	$guestCommand->apply(['use' => false]);
	$step(2);
	verifyWad($host->get($hostCopy, HitPoints::class)->current === 0, 'Use before the death delay does not respawn');

	$dying->advanceDeath(PlayerState::DEATH_RESTART_DELAY + .1);
	$step(2);
	verifyWad($host->get($hostCopy, HitPoints::class)->current === 0, 'an earlier Use press does not respawn later');

	$guestCommand->apply(['use' => true]);
	$step();
	$guestCommand->apply(['use' => false]);
	$step(3);

	$reborn = $host->get($hostCopy, PlayerState::class);
	$health = $host->get($hostCopy, HitPoints::class);

	verifyWad(
		$health->current === $health->maximum && $health->maximum > 0 && $reborn->deathTime === null
			&& $reborn->weapons === ['Fist' => true, 'Pistol' => true] && $reborn->weapon === 'Pistol'
			&& $reborn->ammo['Clip'] === 50 && $reborn->ammo['Shell'] === 0 && $reborn->armor === 0
			&& $reborn->kills === 3
		, 'the host respawns the guest with a fresh loadout, keeping its tally'
	);

	$pose = $guest->get($self, Transform::class);
	$hostPose = $guest->get($player($guest, 1), Transform::class);

	verifyWad(
		in_array($pose->x . ',' . $pose->y, $deathmatchStarts, true)
			&& hypot($pose->x - $hostPose->x, $pose->y - $hostPose->y) >= PlayerRespawn::CLEARANCE
			&& $pose->previousX === $pose->x && $pose->previousY === $pose->y
			&& ($pose->x !== $corpse->x || $pose->y !== $corpse->y)
		, 'the guest places itself at a clear deathmatch start without sweeping across the map'
	);

	verifyWad(
		$guest->get($self, HitPoints::class)->current === $health->maximum
			&& $guest->get($self, PlayerState::class)->deathTime === null
			&& $guest->get($self, ActorState::class)->label === 'Spawn'
			&& $guest->get($self, ViewEffects::class)->damageTics() === 0
		, 'the guest sees itself alive, out of the death tint, and in its spawn state'
	);

	verifyWad(
		$host->get($hostCopy, Body::class)->shootable && $host->get($hostCopy, Body::class)->solid
			&& $host->get($hostCopy, Transform::class)->x === $pose->x
		, 'the host makes the respawned guest solid and shootable at the same start'
	);

	// A Fire button held through death respawns only after it is released and pressed again.
	$hostSelf = $player($host, 1);
	$hostCommand = $host->get($hostSelf, PlayerCommand::class);
	$hostCommand->apply(['fire' => true]);
	$step();
	$host->get($hostSelf, HitPoints::class)->current = 0;
	$host->get($hostSelf, PlayerState::class)->advanceDeath(PlayerState::DEATH_RESTART_DELAY + .1);
	$step(2);
	verifyWad($host->get($hostSelf, HitPoints::class)->current === 0, 'Fire held through death does not respawn');

	$hostCommand->apply(['fire' => false]);
	$step();
	$hostCommand->apply(['fire' => true]);
	$step(3);

	verifyWad(
		$host->get($hostSelf, HitPoints::class)->current > 0
			&& $guest->get($player($guest, 1), HitPoints::class)->current > 0
			&& in_array(
				$guest->get($player($guest, 1), Transform::class)->x . ',' . $guest->get(
					$player($guest, 1)
					, Transform::class
				)->y
				, $deathmatchStarts
				, true
			)
		, 'a fresh Fire press respawns the host, and the guest sees it at a deathmatch start'
	);
})();

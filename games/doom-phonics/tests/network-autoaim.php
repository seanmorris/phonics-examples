<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\Level;
use DoomPhonics\System\EpisodeNetworkSystem;
use DoomPhonics\System\PlayerCommandSystem;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use Phonics\Network\BrowserBridge;
use Phonics\Network\CommandSequence;
use Phonics\Network\InputCommand;
use Phonics\Network\MatchContext;
use Phonics\Network\Packet;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;
use Phonics\Network\Protocol;
use Phonics\Network\SimulationTick;

// The host resolves every attack, so each peer's Vertical Autoaim choice must reach it inside that peer's commands:
// the host aims a guest's shots by the guest's option and its own by its own, even when the two differ.
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
				, ['settings' => ['mode' => 'cooperative']]
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

	$settings = ['host' => new GameSettings, 'guest' => new GameSettings];
	$samplers = [
		'host' => new PlayerCommandSystem(new Controls, $settings['host'])
		, 'guest' => new PlayerCommandSystem(new Controls, $settings['guest'])
	];

	foreach($worlds as $world)
	foreach($world->query(NetworkPlayer::class) as $entity)
	if(!$world->has($entity, ViewInput::class)) $world->add($entity, new ViewInput);

	$step = static function(int $count) use ($sessions, $worlds, $samplers): void {
		for(; $count > 0; $count--)
		foreach(['host', 'guest'] as $side)
		{
			$samplers[$side]->update($worlds[$side], 1 / 35);
			$sessions[$side]->receive($worlds[$side], 1 / 35);
			$sessions[$side]->send($worlds[$side], 1 / 35);
		}
	};

	$autoAim = static function(Registry $world, int $slot): bool {
		foreach($world->query(NetworkPlayer::class) as $entity)
		if($world->get($entity, NetworkPlayer::class)->slot->value() === $slot)
		return $world->get($entity, PlayerCommand::class)->autoAim;

		throw new RuntimeException("No player in slot $slot");
	};

	$settings['guest']->setAutoAim(true);
	$step(4);

	verifyWad(
		$autoAim($worlds['host'], 2) && !$autoAim($worlds['host'], 1) && $autoAim($worlds['guest'], 2)
		, "the host applies a guest's Vertical Autoaim choice to the guest's commands only"
	);

	$settings['guest']->setAutoAim(false);
	$settings['host']->setAutoAim(true);
	$step(4);

	verifyWad(
		!$autoAim($worlds['host'], 2) && $autoAim($worlds['host'], 1)
		, 'changing either option mid-match reaches the host on the next commands'
	);

	$withAim = new InputCommand(
		new PlayerSlot(2)
		, new SimulationTick(1)
		, new CommandSequence(1)
		, (new PlayerCommand)->actions()
	);

	verifyWad($sessions['host']->validCommand($withAim), 'the host accepts the autoAim field in peer commands');
})();

<?php
declare(strict_types=1);

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadDefinitions;
use DoomPhonics\Component\InventoryPickup;
use DoomPhonics\Component\MapThing;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\DoomMatch;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Game\MultiplayerMode;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Tests\PortMap;
use Phonics\Ecs\Registry;
use Phonics\Network\MatchContext;
use Phonics\Network\PeerId;
use Phonics\Network\PlayerSlot;

$modeSettings = new GameSettings;
$modeFactory = new ActorFactory((new WadDefinitions)->actors);

foreach([
	'singleplayer' => [16.0,40.0]
	, 'cooperative' => [16.0,72.0]
	, 'deathmatch' => [32.0,88.0]
] as $mode => [$spawnX,$pickupX])
{
	$modeSettings->chooseMode($mode);
	$world = new Registry;
	$result = $modeFactory->populate($world, PortMap::map(), mode: $modeSettings->multiplayerMode());
	$players = iterator_to_array($world->query(PlayerState::class));
	$pickups = iterator_to_array($world->query(InventoryPickup::class));
	verifyWad($modeSettings->mode() === $mode && !$result['missing'], "$mode is a supported local game mode");

	verifyWad(
		count($players) === 1 && count($world->query(PlayerStart::class)) === 1
		, "$mode creates only the occupied local player"
	);

	verifyWad(
		count($world->query(NetworkPlayer::class)) === 0 && count($world->query(PlayerCommand::class)) === 0
		, "local $mode uses direct input without requiring a connected peer"
	);

	verifyWad($world->get($players[0], Transform::class)->x === $spawnX, "$mode selects the appropriate start markers");

	verifyWad(
		count($pickups) === 1 && $world->get($pickups[0], Transform::class)->x === $pickupX
		, "$mode honors Hexen mode flags and the selected difficulty"
	);
}

try
{
	$modeSettings->chooseMode('skulltag');
	verifyWad(false, 'unsupported objective mode must be rejected');
}
catch(InvalidArgumentException)
{
	verifyWad($modeSettings->mode() === 'deathmatch', 'invalid mode leaves the previous rules intact');
}

$teamMap = PortMap::map();
$teamMap->things = array_values(array_filter($teamMap->things, static fn(array $thing): bool => $thing['type'] !== 1));

// D2ST9's team markers omit the deathmatch flag; marker placement still applies to arena starts.
foreach($teamMap->things as &$thing)
if(in_array($thing['type'], [5080,5081,5082], true)) $thing['flags']['deathmatch'] = false;
unset($thing);
$singleWorld = new Registry;
$modeFactory->populate($singleWorld, $teamMap);

verifyWad(
	count($singleWorld->query(PlayerState::class)) === 0
	, 'single player does not silently change modes on a team-only map'
);

foreach(['cooperative', 'deathmatch'] as $mode)
{
	$localWorld = new Registry;
	$modeFactory->populate($localWorld, $teamMap, mode: MultiplayerMode::from($mode));
	$localPlayer = $localWorld->query(PlayerState::class)[0];
	verifyWad($localWorld->get($localPlayer, MapThing::class)->type === 5080, "local $mode supports team-only arenas");

	foreach(['host', 'guest'] as $role)
	{
		$world = new Registry;
		$host = $role === 'host';

		$match = new DoomMatch(new MatchContext(
			$role
			, new PlayerSlot($host ? 1 : 2)
			, new PlayerSlot($host ? 2 : 1)
			, new PeerId($role)
			, new PeerId($host ? 'guest' : 'host')
			, 'sha256-AAAA='
			, [
				'settings' => ['mode' => $mode]
			]
		));

		$modeFactory->populate($world, $teamMap, match: $match);
		$players = iterator_to_array($world->query(NetworkPlayer::class));

		verifyWad(
			count($players) === 2 && count($world->query(PlayerState::class)) === 2
			, "$mode $role creates exactly the two assigned players"
		);

		verifyWad(
			array_map(static fn(int $entity): float => $world->get($entity, Transform::class)->x, $players) === [
				32.0
				, 64.0
			]
			, "$mode $role agrees on distinct bases despite grouped team markers"
		);

		verifyWad(
			$world->get($players[$host ? 0 : 1], NetworkPlayer::class)->local
			, "$mode $role retains camera ownership"
		);
	}
}

$arenaMap = PortMap::map();
$arenaMap->things[] = array_replace($arenaMap->things[0], ['type' => 11, 'x' => 100]);
$arenaWorld = new Registry;
$modeFactory->populate($arenaWorld, $arenaMap, mode: MultiplayerMode::from('deathmatch'));

verifyWad(
	$arenaWorld->get($arenaWorld->query(PlayerState::class)[0], Transform::class)->x === 100.0
	, 'deathmatch prefers ordinary arena starts over team or campaign starts'
);

$doomMap = PortMap::map();
$doomMap->format = 'DOOM';
$doomMap->things = [$doomMap->things[0]];

foreach([[40, false, true], [72, true, true], [88, true, false]] as [$x,$multiplayer,$medium])
$doomMap->things[] = [
	'index' => $x
	, 'type' => 2010
	, 'x' => $x
	, 'y' => 64
	, 'flags' => ['multiplayer' => $multiplayer, 'medium' => $medium]
];

foreach(['singleplayer' => [40.0], 'cooperative' => [40.0,72.0], 'deathmatch' => [40.0,72.0]] as $mode => $expected)
{
	$modeSettings->chooseMode($mode);
	$world = new Registry;
	$modeFactory->populate($world, $doomMap, mode: $modeSettings->multiplayerMode());
	$pickups = iterator_to_array($world->query(InventoryPickup::class));

	verifyWad(
		array_map(static fn(int $entity): float => $world->get($entity, Transform::class)->x, $pickups) === $expected
		, "Doom $mode includes normal things and respects multiplayer-only and difficulty bits"
	);
}

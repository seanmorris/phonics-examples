<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__, 3) . '/autoload.php';

use DoomPhonics\Adapter\ActorFactory;
use DoomPhonics\Adapter\WadAdapter;
use DoomPhonics\Adapter\WadLevel;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Component\PlayerCommand;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\ViewInput;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Component\WeaponOwner;
use DoomPhonics\Definition\ActionDispatcher;
use DoomPhonics\Definition\KeyConfParser;
use DoomPhonics\Game\CheatCommand;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\System\CheatSystem;
use DoomPhonics\System\CombatSystem;
use DoomPhonics\System\ActorStateSystem;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Input\Controls;
use Phonics\World\AdapterContext;
use Phonics\World\ScopeContext;
use Phonics\World\SourceDefinition;

$root = dirname(__DIR__, 3);
$addonPath = $argv[1] ?? $root . '/wrw.wad';
$iwadPath = $argv[2] ?? dirname(__DIR__) . '/demo/DOOM1.WAD';
$checks = 0;

$verify = static function(bool $condition, string $message) use (&$checks): void {
	$checks++;

	if(!$condition) throw new RuntimeException($message);
};

if(!is_file($addonPath))
throw new RuntimeException(
	"WRW stress WAD not found: $addonPath\nUsage: php " . __FILE__ . ' /path/to/wrw.wad [/path/to/base.wad]'
);

$addonBytes = file_get_contents($addonPath);

if(!is_string($addonBytes)) throw new RuntimeException("Cannot read $addonPath");

$addon = new Archive($addonBytes, $addonPath);
$keyconf = [];

foreach($addon->lumps('KEYCONF') as $lump) $keyconf[$lump->identity()] = $lump->bytes();

$commands = KeyConfParser::weaponSlots($keyconf);
$declaredWeapons = [];

foreach($commands as $command) foreach($command['weapons'] as $weapon) $declaredWeapons[] = $weapon;

$verify(count($commands) === 8, 'WRW KEYCONF retains all eight setslot declarations');

$verify(
	count($declaredWeapons) === 58
		&& count(array_unique(array_map('strtolower', $declaredWeapons))) === 58
	, 'WRW KEYCONF retains 58 distinct weapon names'
);

$definition = new SourceDefinition('wrw', '@doom/wad', [
	['url' => $iwadPath, 'role' => 'iwad']
	, ['url' => $addonPath, 'role' => 'pwad']
]);

$source = (new WadAdapter)->open(
	$definition
	, new AdapterContext(null, static function(string $url): string {
		$bytes = file_get_contents($url);

		if(!is_string($bytes)) throw new RuntimeException("Cannot read $url");

		return $bytes;
	})
);

$descriptor = iterator_to_array($source->catalog())[0] ?? null;

if($descriptor === null) throw new RuntimeException("Base WAD has no maps: $iwadPath");

$registry = new Registry;
$active = $source->activate($descriptor->id, new ScopeContext('wrw', $descriptor, $registry, new Scheduler($registry)));
$content = $active->content();

if(!$content instanceof WadLevel) throw new RuntimeException('WRW adapter returned the wrong content type');

$verify(count($content->actors->resolved()) >= 406, 'WRW resolves its complete actor inheritance catalog');

foreach($declaredWeapons as $weapon)
$verify($content->actors->named($weapon) !== null, "WRW KEYCONF weapon $weapon has a DECORATE definition");

$availableWeapons = $content->weapons->weapons();

$verify(
	count($availableWeapons) === count(array_unique(array_map('strtolower', $availableWeapons)))
	, 'WRW effective weapon banks contain no duplicate actor names'
);

$verify(
	array_slice($content->weapons->slot(1), 0, 2) === ['Fist','Chainsaw']
		&& in_array('Gauntlet', $content->weapons->slot(1), true)
		&& $content->weapons->slot(2)[0] === 'Pistol'
		&& in_array('COPGun', $content->weapons->slot(2), true)
	, 'WRW KEYCONF appends its weapons after the existing Doom slot assignments'
);

$cheatRegistry = new Registry;
$cheatPlayerEntity = $cheatRegistry->create(new PlayerState, new HitPoints(100, 100));
$cheats = new CheatSystem($content->weapons, $content->actors);
$cheats->apply(CheatCommand::named(CheatCommand::ARSENAL_AND_KEYS), $cheatRegistry, $cheatPlayerEntity);
$cheatPlayer = $cheatRegistry->get($cheatPlayerEntity, PlayerState::class);

foreach($declaredWeapons as $weapon)
$verify(!empty($cheatPlayer->weapons[$weapon]), "IDKFA grants WRW weapon $weapon");

$customAmmo = [
	'AutoShotgunClip'
	, 'BarnicleAmmo'
	, 'BSGShell'
	, 'Crystal'
	, 'D2C_PipebombAmmo'
	, 'Darkmana'
	, 'Ember'
	, 'Grenades'
	, 'IncendShells'
	, 'MagnumBullets'
	, 'Mines'
	, 'MiniRockets'
	, 'Orbs'
	, 'PoisonRecipient'
	, 'PowerMissile','ProjectileWeaponCharge','BoltCheck','UACProtoEn'
];

foreach($customAmmo as $type)
$verify(
	isset($cheatPlayer->ammo[$type], $cheatPlayer->ammoMaximum[$type])
		&& $cheatPlayer->ammo[$type] === $cheatPlayer->ammoMaximum[$type]
		&& $cheatPlayer->ammo[$type] > 0
	, "IDKFA fills WRW custom ammo pool $type"
);

$verify(
	$content->weapons->select(4, 'Chaingun', $cheatPlayer->weapons) === 'FireWand'
		&& $content->weapons->select(5, 'RocketLauncher', $cheatPlayer->weapons) === 'MultiProjectileWeapon'
	, 'IDKFA inventory can cycle into appended KEYCONF weapons through their stacked number slots'
);

$actions = new ActionDispatcher;
$factory = new ActorFactory($content->actors);

$combat = new CombatSystem(
	$content->level
	, $content->actors
	, $factory
	, new Collision($content->level)
	, new Controls
	, $actions
	, new LineSpecials($content->level)
	, weaponSlots: $content->weapons
);

$switchRegistry = new Registry;
$switchPlayer = new PlayerState;
$switchCommand = new PlayerCommand;

$switchEntity = $switchRegistry->create(
	$switchPlayer
	, new HitPoints(100, 100)
	, new PlayerStart
	, new Transform(0, 0, 0)
	, $switchCommand
	, new ViewInput
);

$cheats->apply(CheatCommand::named(CheatCommand::ARSENAL_AND_KEYS), $switchRegistry, $switchEntity);
$states = new ActorStateSystem($actions);
$combat->update($switchRegistry, 1 / 35);

foreach([1,2] as $press)
{
	$switchCommand->apply(['weaponSlot' => 4]);
	$combat->update($switchRegistry, 1 / 35);

	for($tick = 0; $tick < 80; $tick++)
	{
		$states->update($switchRegistry, 1 / 35);
		$switchRegistry->flush();
		$combat->update($switchRegistry, 1 / 35);
		$switchRegistry->flush();
	}
}

$verify(
	$switchPlayer->weapon === 'FireWand'
		&& $switchPlayer->pendingWeapon === null
		&& $switchPlayer->ammo['Ember'] === $switchPlayer->ammoMaximum['Ember']
	, 'IDKFA can complete a runtime switch from a stock weapon into a stacked WRW weapon'
);

$walter = $content->actors->named('WalterPPK');
$playerDefinition = $content->actors->numbered(1);
$targetDefinition = $content->actors->named('BaronOfHell');
$playerThing = null;

foreach($content->level->map->things as $thing)
if((int)($thing['type'] ?? 0) === 1)
{
	$playerThing = $thing;
	break;
}

$verify(
	$walter !== null && $playerDefinition !== null && $targetDefinition !== null && $playerThing !== null
	, 'WRW Walter PPK firing fixture resolves its weapon, player, target, and map start'
);

$walterRegistry = new Registry;
$startSector = $content->level->sectorAt((float)$playerThing['x'], (float)$playerThing['y']);

$walterPlayer = $factory->spawn(
	$walterRegistry
	, $playerDefinition
	, $playerThing + ['index' => -1, 'flags' => []]
	, ['floor' => $startSector->floor, 'ceiling' => $startSector->ceiling]
);

$walterRegistry->add($walterPlayer, new PlayerCommand);
$playerPose = $walterRegistry->get($walterPlayer, Transform::class);
$targetX = $playerPose->x + cos($playerPose->angle) * 32;
$targetY = $playerPose->y + sin($playerPose->angle) * 32;
$targetSector = $content->level->sectorAt($targetX, $targetY);

$walterTarget = $factory->spawn($walterRegistry, $targetDefinition, [
	'x' => $targetX
	, 'y' => $targetY
	, 'z' => 0
	, 'angle' => 0
	, 'type' => $targetDefinition->editorNumber ?? 3003
	, 'index' => -1
	, 'flags' => []
], ['floor' => $targetSector->floor, 'ceiling' => $targetSector->ceiling]);

$walterPlayerState = $walterRegistry->get($walterPlayer, PlayerState::class);
$walterPlayerState->ammo['Clip'] = 100;
$walterWeapon = $walterRegistry->create(new \DoomPhonics\Component\ActorState($walter), new WeaponOwner($walterPlayer));

$walterOverlay = $walterRegistry->create(
	new \DoomPhonics\Component\ActorState($walter, 'Flash')
	, new WeaponOverlay($walterPlayer, $walterWeapon)
);

$walterHealth = $walterRegistry->get($walterTarget, HitPoints::class);
$walterHealthBefore = $walterHealth->current;
$walterFlash = $walter->state('Flash')?->frames[0] ?? null;

$verify($walterFlash?->action === 'A_FireBullets', 'Walter PPK delegates its shot to the Flash overlay');
$actions->dispatch($walterOverlay, $walterFlash, $walterRegistry);

$verify(
	$walterHealth->current < $walterHealthBefore && $walterPlayerState->ammo['Clip'] === 98
	, 'Walter PPK Flash overlay applies hitscan damage through its owning weapon'
);

$cop = $content->actors->named('COPGun');
$verify($cop !== null, 'WRW COP Derringer definition is available');

$copWeapon = $walterRegistry->create(
	new \DoomPhonics\Component\ActorState($cop, 'Ready')
	, new WeaponOwner($walterPlayer)
);

$copState = $walterRegistry->get($copWeapon, \DoomPhonics\Component\ActorState::class);
$copCommand = $walterRegistry->get($walterPlayer, PlayerCommand::class);
$copCommand->apply(['altFire' => true]);
$actions->dispatch($copWeapon, $cop->state('Ready')->frames[0], $walterRegistry);
$verify($copState->label === 'AltFire', 'Alt Fire enters the COP Derringer reload state');

$findFrame = static function($sequence, string $action, ?string $item = null): array {
	foreach($sequence?->frames ?? [] as $index => $frame)
	if(strcasecmp((string)$frame->action, $action) === 0
		&& ($item === null || strcasecmp((string)($frame->arguments[0] ?? ''), $item) === 0)
	)
	return [$index,$frame];

	throw new RuntimeException("Missing $action($item) frame");
};

$walterPlayerState->ammo['Shell'] = 1;
[$shellJumpIndex,$shellJump] = $findFrame($cop->state('AltFire'), 'A_JumpIfInventory', 'Shell');
$copState->enter('AltFire', $shellJumpIndex);
$actions->dispatch($copWeapon, $shellJump, $walterRegistry);
$verify($copState->frame === $shellJumpIndex + 2, 'COP reload branches when reserve shells are available');

[$takeShellIndex,$takeShell] = $findFrame($cop->state('AltFire'), 'A_TakeInventory', 'Shell');
$copState->enter('AltFire', $takeShellIndex);
$actions->dispatch($copWeapon, $takeShell, $walterRegistry);
[$giveClipIndex,$giveClip] = $findFrame($cop->state('AltFire'), 'A_GiveInventory', 'COPgunInClip');
$copState->enter('AltFire', $giveClipIndex);
$actions->dispatch($copWeapon, $giveClip, $walterRegistry);

$verify(
	$walterPlayerState->ammo['Shell'] === 0 && ($walterPlayerState->inventory['COPgunInClip'] ?? 0) === 1
	, 'COP reload transfers a reserve shell into its internal clip'
);

[$clipJumpIndex,$clipJump] = $findFrame($cop->state('Fire'), 'A_JumpIfInventory', 'COPgunInClip');
$copState->enter('Fire', $clipJumpIndex);
$actions->dispatch($copWeapon, $clipJump, $walterRegistry);
$verify($copState->frame === $clipJumpIndex + 2, 'COP primary fire branches into its shot when the clip is loaded');

[$takeClipIndex,$takeClip] = $findFrame($cop->state('Fire'), 'A_TakeInventory', 'COPgunInClip');
$copState->enter('Fire', $takeClipIndex);
$actions->dispatch($copWeapon, $takeClip, $walterRegistry);
[$copShotIndex,$copShot] = $findFrame($cop->state('Fire'), 'A_FireBullets');
$copState->enter('Fire', $copShotIndex);
$copHealthBefore = $walterHealth->current;
$actions->dispatch($copWeapon, $copShot, $walterRegistry);

$verify(
	$walterHealth->current < $copHealthBefore && $walterPlayerState->ammo['Shell'] === 0
		&& ($walterPlayerState->inventory['COPgunInClip'] ?? 0) === 0
	, 'COP Derringer fires from its internal clip without charging reserve shells twice'
);

$fireWand = $content->actors->named('FireWand');
$verify($fireWand !== null, 'WRW Fire Wand definition is available');

$fireWandWeapon = $walterRegistry->create(
	new \DoomPhonics\Component\ActorState($fireWand, 'Fire')
	, new WeaponOwner($walterPlayer)
);

[$fireWandShotIndex,$fireWandShot] = $findFrame($fireWand->state('Fire'), 'A_FireCustomMissile');

$walterRegistry->get($fireWandWeapon, \DoomPhonics\Component\ActorState::class)
	->enter('Fire', $fireWandShotIndex);

$walterPlayerState->ammoMaximum['Ember'] = 200;
$walterPlayerState->ammo['Ember'] = 2;
$projectilesBefore = $walterRegistry->query(\DoomPhonics\Component\Projectile::class);
$actions->dispatch($fireWandWeapon, $fireWandShot, $walterRegistry);

$spawnedProjectiles = array_values(
	array_diff($walterRegistry->query(\DoomPhonics\Component\Projectile::class), $projectilesBefore)
);

$fireShot = $spawnedProjectiles[0] ?? null;

$verify(
	$fireShot !== null
		&& $walterRegistry->get($fireShot, \DoomPhonics\Component\ActorState::class)->definition->name === 'FireShot'
		&& $walterPlayerState->ammo['Ember'] === 1
	, 'Fire Wand custom missile spawns FireShot and consumes one Ember'
);

$freeze = $content->actors->named('FreezeThrower');
$verify($freeze !== null, 'WRW secondary-ammo fixture resolves Freeze Thrower');

$freezeWeapon = $walterRegistry->create(
	new \DoomPhonics\Component\ActorState($freeze, 'Ready')
	, new WeaponOwner($walterPlayer)
);

$freezeState = $walterRegistry->get($freezeWeapon, \DoomPhonics\Component\ActorState::class);
$walterPlayerState->ammo['Cell'] = 1;
$actions->dispatch($freezeWeapon, $freeze->state('Ready')->frames[0], $walterRegistry);
$verify($freezeState->label === 'Ready', 'Alt Fire refuses a weapon with insufficient AmmoUse2 reserve');
$walterPlayerState->ammo['Cell'] = 2;
$actions->dispatch($freezeWeapon, $freeze->state('Ready')->frames[0], $walterRegistry);
$verify($freezeState->label === 'AltFire', 'Alt Fire readiness uses Weapon.AmmoType2 and Weapon.AmmoUse2');

[$noAmmoIndex,$noAmmo] = $findFrame($freeze->state('AltFire'), 'A_JumpIfNoAmmo');
$walterPlayerState->ammo['Cell'] = 1;
$freezeState->enter('AltFire', $noAmmoIndex);
$actions->dispatch($freezeWeapon, $noAmmo, $walterRegistry);
$verify($freezeState->frame === $noAmmoIndex + 1, 'A_JumpIfNoAmmo follows the active AltFire ammo channel');

$usedActions = [];

foreach($content->actors->resolved() as $actor)
foreach($actor->states as $sequence)
foreach($sequence->frames as $frame)
if($frame->action !== null) $usedActions[strtolower($frame->action)] = $frame->action;

$unsupported = array_values(array_filter($usedActions, static fn(string $action): bool => !$actions->handles($action)));

$verify(count($usedActions) >= 131, 'WRW DECORATE retains its full action-function surface');

echo "$checks WRW stress checks passed; "
	. count($usedActions) . ' actions parsed, '
	. (count($usedActions) - count($unsupported)) . ' native, '
	. count($unsupported) . " declarative-only.\n";

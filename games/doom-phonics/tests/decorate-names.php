<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadSource;
use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Definition\WeaponSlots;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\World\ScopeContext;

// ZDoom reads actor names as script tokens: add-ons such as the Omega Weapon Pack declare `2704Ball`,
// `40mmGrenadeLauncher`, and `Doom2.5SSG`, and spread their actors over many DECORATE lumps in one WAD.
(static function() use ($archive): void {
	$actors = DecorateParser::catalog([
		'token-names.dec' => <<<'DECORATE'
ACTOR Weapon {}
ACTOR PlasmaBall {}
ACTOR SuperShotgun : Weapon {}
ACTOR 2704Ball : PlasmaBall { Speed 25 }
ACTOR 2704Child:2704Ball {}
ACTOR 40mmGrenadeLauncher : Weapon 29010 { Weapon.SlotNumber 5 }
ACTOR Doom2.5SSG : SuperShotgun Replaces SuperShotgun {}
DECORATE
	]);

	verifyWad(
		$actors->named('2704Ball')?->parent === 'PlasmaBall'
			&& $actors->named('2704Ball')->value('Speed') === 25
			&& $actors->named('2704Child')?->parent === '2704Ball'
			&& $actors->isA('2704Child', 'PlasmaBall')
			&& $actors->numbered(29010)?->name === '40mmGrenadeLauncher'
			&& $actors->named('Doom2.5SSG')?->parent === 'SuperShotgun'
			&& $actors->named('Doom2') === null
		, 'DECORATE actor and parent names may start with digits or contain periods'
	);

	$slots = WeaponSlots::fromCatalog($actors, null, [
		'token.wad#KEYCONF' => "setslot 3 Doom2.5SSG\n"
	]);

	verifyWad(
		$slots->slot(3) === ['Doom2.5SSG'] && $slots->slot(5) === ['40mmGrenadeLauncher']
		, 'KEYCONF and Weapon.SlotNumber both bank weapons with token names'
	);

	$lumps = [
		['DECORATE', "ACTOR 2704Ball : PlasmaBall { Speed 25 }\nACTOR StackedGun : Pistol { Weapon.SlotNumber 9 }\n"]
		, ['KEYCONF', "setslot 9 StackedGun\n"]
		, ['DECORATE', "ACTOR 2704Ball2 : 2704Ball { Speed 30 }\nACTOR StackedGun : Pistol { Weapon.SlotNumber 8 }\n"]
		, ['DECORATE', "ACTOR 40mmGrenadeLauncher : Weapon 29010 {}\n"]
	];

	$data = $directory = '';
	$offset = 12;

	foreach($lumps as [$name, $bytes])
	{
		$data .= $bytes;
		$directory .= pack('V2a8', $offset, strlen($bytes), $name);
		$offset += strlen($bytes);
	}

	$wad = 'PWAD' . pack('V2', count($lumps), 12 + strlen($data)) . $data . $directory;
	$source = new WadSource(new Stack($archive, new Archive($wad, 'memory:stacked-decorate.wad')));
	$registry = new Registry;
	$scope = new ScopeContext('stacked-decorate', iterator_to_array($source->catalog())[0], $registry, new Scheduler($registry));
	$stacked = $source->activate('E1M1', $scope)->content()->actors;

	verifyWad(
		$stacked->named('2704Ball')?->value('Speed') === 25
			&& $stacked->named('2704Ball2')?->value('Speed') === 30
			&& $stacked->isA('2704Ball2', 'PlasmaBall')
			&& $stacked->numbered(29010)?->name === '40mmGrenadeLauncher'
			&& $stacked->named('StackedGun')?->value('Weapon.SlotNumber') === 8
			&& $stacked->named('Pistol') !== null
		, 'every DECORATE lump in one WAD stacks over the built-in actors, later lumps replacing earlier ones'
	);
})();

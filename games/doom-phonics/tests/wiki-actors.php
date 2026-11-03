<?php
declare(strict_types=1);

use DoomPhonics\Adapter\WadDefinitions;
use DoomPhonics\Definition\DecorateParser;

// $actors is the pre-import catalog from run.php. The generated bundle must only
// fill gaps, and later WAD/sidecar definitions must keep their usual precedence.
$wikiPath = dirname(__DIR__) . '/resources/wiki-actors.dec';
$wikiCatalog = DecorateParser::catalog([$wikiPath => file_get_contents($wikiPath)]);
$wikiNames = [];

foreach($wikiCatalog->all() as $wikiActor)
{
	verifyWad($actors->named($wikiActor->name) === null, "wiki import preserves existing actor {$wikiActor->name}");
	verifyWad(!isset($wikiNames[strtolower($wikiActor->name)]), "wiki actor {$wikiActor->name} is unique");
	$wikiNames[strtolower($wikiActor->name)] = true;
}

$wikiDefinitions = new WadDefinitions;

foreach([
	64 => ['Archvile',700]
	, 65 => ['ChaingunGuy',70]
	, 66 => ['Revenant',300]
	, 67 => ['Fatso',600]
	, 68 => ['Arachnotron',500]
	, 69 => ['HellKnight',500]
	, 71 => ['PainElemental',400]
	, 72 => ['CommanderKeen',100]
	, 84 => ['WolfensteinSS',50]
] as $wikiNumber => [$wikiName,$wikiHealth])
{
	$wikiActor = $wikiDefinitions->actors->numbered($wikiNumber);
	verifyWad($wikiActor?->name === $wikiName, "wiki editor number $wikiNumber resolves $wikiName");
	verifyWad($wikiActor?->value('Health') === $wikiHealth, "$wikiName retains its health");
	verifyWad((bool)$wikiActor?->state('Spawn')?->frames, "$wikiName has spawn frames");
	verifyWad((bool)$wikiActor?->state('Death')?->frames, "$wikiName has death frames");
}

verifyWad(
	$wikiDefinitions->actors->named('HellKnight')->value('Height') === $actors->named('BaronOfHell')->value('Height')
	, 'imported Hell Knight inherits the existing Baron definition'
);

foreach(['FatShot','ArachnotronPlasma','RevenantTracer'] as $wikiProjectile)
{
	$wikiActor = $wikiDefinitions->actors->named($wikiProjectile);
	verifyWad($wikiActor?->hasFlag('MISSILE') === true, "$wikiProjectile retains projectile flags");
	verifyWad((bool)$wikiActor?->state('Death')?->frames, "$wikiProjectile retains impact frames");
}

$wikiAddonDefinitions = new WadDefinitions([
	'Skulltag actors' => file_get_contents(dirname(__DIR__) . '/demo/SKULLTAG_ACTORS.lmp')
	, 'Skulltag weapons' => file_get_contents(dirname(__DIR__) . '/demo/SKULLTAG-WEAPONS.DEC')
]);

verifyWad(
	$wikiAddonDefinitions->actors->named('Hectebus')->state('See') !== null
	, 'Hectebus inherits missing Mancubus states'
);

verifyWad(
	$wikiAddonDefinitions->actors->named('PowerTurbo')->value('Powerup.Duration') === -45
	, 'Skulltag power inherits the wiki parent duration'
);

verifyWad(
	$wikiAddonDefinitions->actors->named('GrenadeLauncher')->source === 'Skulltag weapons'
	, 'weapon sidecar still overrides the Skulltag lump'
);

$wikiOverride = new WadDefinitions(['custom' => "ACTOR ChaingunGuy 65\n{\n Health 123\n}"]);
verifyWad($wikiOverride->actors->numbered(65)->value('Health') === 123, 'user DECORATE overrides imported defaults');

$wikiSources = json_decode(
	file_get_contents(dirname(__DIR__) . '/resources/wiki-actors.sources.json')
	, true
	, 512
	, JSON_THROW_ON_ERROR
);

verifyWad(count($wikiSources['actors']) === count($wikiCatalog->all()), 'each imported actor has provenance');

foreach($wikiSources['actors'] as $wikiSource)
{
	verifyWad(isset($wikiNames[strtolower($wikiSource['name'])]), 'provenance names an imported actor');

	verifyWad(
		str_contains($wikiSource['url'], 'oldid=' . $wikiSource['revision'])
		, 'provenance pins the wiki revision'
	);
}

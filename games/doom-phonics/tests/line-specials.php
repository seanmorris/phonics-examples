<?php
declare(strict_types=1);

use DoomPhonics\Component\Transform;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Tests\PortMap;

// A wall switch behind an eight-unit decorative sector border, like MAP01's raised switches.
// Author the geometry from our two-room fixture so CI does not need a commercial IWAD.

$useMap = static function(int $action = 11): \DoomPhonics\Map\MapData {
	$map = PortMap::map();
	$map->format = 'DOOM';
	$map->linedefs[0]['action'] = 0;
	$map->linedefs[5]['action'] = $action;
	$map->linedefs[5]['tag'] = 2;
	$map->sidedefs[6]['middle'] = 'SW1COMP';

	foreach([2,3] as $vertex) $map->vertices[$vertex]['x'] = 56;

	foreach([4,5] as $vertex) $map->vertices[$vertex]['x'] = 64;
	$map->nodes[0]['x'] = 56;
	$map->sectors[1]['floor'] = 0;
	$map->sectors[1]['ceiling'] = 128;

	return $map;
};

$useLevel = new Level($useMap());
$useSpecials = new LineSpecials($useLevel);
$usePlayer = new PlayerState;
$usePose = new Transform(32, 64);

verifyWad(
	$useLevel->sectorAt(32, 64)->index === 0 && $useLevel->sides(5)['front'] === 1
	, 'use fixture starts across the sector border'
);

verifyWad(
	$useSpecials->use($usePose, $usePlayer) && $usePlayer->exited
	, 'use reaches a front-facing switch across an open sector border'
);

verifyWad($useLevel->sideTextures[6]['middle'] === 'SW2COMP', 'use changes the reached switch texture');
verifyWad(!$useSpecials->use($usePose, $usePlayer), 'one-shot switch cannot activate twice');

foreach([
	'out of range' => new Transform(-1, 64)
	, 'facing away' => new Transform(32, 64, 0, M_PI)
	, 'back side' => new Transform(80, 64, 0, M_PI)
] as $reason => $pose)
{
	$specials = new LineSpecials(new Level($useMap()));
	$player = new PlayerState;
	verifyWad(!$specials->use($pose, $player) && !$player->exited, "switch rejects use from $reason");
}

foreach(['one-sided wall', 'closed portal'] as $obstruction)
{
	$map = $useMap();

	if($obstruction === 'one-sided wall') $map->linedefs[0]['left'] = -1;
	else $map->sectors[1]['ceiling'] = 0;

	$specials = new LineSpecials(new Level($map));
	$player = new PlayerState;
	verifyWad(!$specials->use($usePose, $player) && !$player->exited, "use stops at a $obstruction before the switch");
}

// Action 102 lowers to the highest neighboring floor at one map unit per Doom tic.
$lowerMap = $useMap(102);
$lowerMap->sectors[1]['floor'] = 64;
$lowerMap->sectors[0]['floor'] = 16;
$lowerMap->sectors[] = array_replace($lowerMap->sectors[0], ['index' => 2, 'floor' => 0, 'tag' => 0]);
$lowerMap->neighbors[1][] = 2;
$lowerLevel = new Level($lowerMap);
$lowerSpecials = new LineSpecials($lowerLevel);
$lowerPlayer = new PlayerState;
verifyWad(!$lowerSpecials->activate(5, 'walk', 0, $lowerPlayer), 'action 102 requires use rather than crossing');
verifyWad($lowerSpecials->use($usePose, $lowerPlayer), 'action 102 activates across the decorative sector');
verifyWad($lowerLevel->sideTextures[6]['middle'] === 'SW2COMP', 'action 102 animates its switch');
$lowerSpecials->update(1 / 35);
verifyWad($lowerLevel->sectors[1]->floor === 63.0, 'action 102 lowers one unit per tic');

for($tic = 1; $tic < 60; $tic++) $lowerSpecials->update(1 / 35);

verifyWad(
	$lowerLevel->sectors[1]->floor === 16.0 && $lowerLevel->sectors[1]->floorMover === null
	, 'action 102 stops at the highest neighboring floor and releases its mover'
);

verifyWad(
	$lowerLevel->sectors[0]->floor === 16.0 && $lowerLevel->sectors[2]->floor === 0.0
	, 'action 102 leaves untagged sectors alone'
);

verifyWad(
	!$lowerSpecials->use($usePose, $lowerPlayer) && $lowerLevel->sideTextures[6]['middle'] === 'SW2COMP'
	, 'action 102 remains consumed after motion finishes'
);

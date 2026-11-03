<?php
declare(strict_types=1);

use DoomPhonics\Archive\Stack;
use DoomPhonics\Game\Level;
use DoomPhonics\Graphics\IndexedAtlas;
use DoomPhonics\Graphics\IndexedImage;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Render\MapMeshBuilder;
use DoomPhonics\Tests\PortMap;

// A one-pixel patch lets overlapping frame names and WAD precedence select
// visibly different content without depending on commercial sprite artwork.

$spritePatch = static fn(int $index): string => pack('v4V', 1, 1, 0, 0, 12)
	. "\0\1\0" . chr($index) . "\0\xff";

$spriteBase = PortMap::archive([
	'S_START' => ''
	, 'TESTB2A8' => $spritePatch(1)
	, 'TESTA8' => $spritePatch(2)
	, 'ZEROA8' => $spritePatch(3)
	, 'ZEROA0' => $spritePatch(4)
	, 'DUALA1A1' => $spritePatch(5)
	, 'ROTAB4' => $spritePatch(6)
	, 'ROTAB2' => $spritePatch(7)
	, 'MIRRC1D0' => $spritePatch(8)
	, 'S_END' => ''
]);

$spriteOverlay = PortMap::archive([
	'SS_START' => ''
	, 'TESTB2A8' => $spritePatch(9)
	, 'SS_END' => ''
]);

$spriteCatalog = new TextureCatalog(new Stack($spriteBase, $spriteOverlay));
$paired = $spriteCatalog->sprite('test', 'a', 8);

verifyWad(
	$paired['name'] === 'TESTB2A8' && $paired['flipped'] && $paired['image']->pixels === "\x09"
	, 'sprite index preserves first paired-frame match and later WAD lump override'
);

verifyWad(
	$spriteCatalog->sprite('TEST', 'B', 2)['image'] === $paired['image']
		&& !$spriteCatalog->sprite('TEST', 'B', 2)['flipped']
	, 'both frame pairs share the decoded image with their own orientation'
);

verifyWad(
	!$spriteCatalog->sprite('DUAL', 'A', 1)['flipped']
	, 'primary pair wins when both pairs name the same rotation'
);

foreach(range(0, 8) as $rotation)
{
	verifyWad(
		$spriteCatalog->sprite('ZERO', 'A', $rotation)['name'] === 'ZEROA0'
		, 'rotation zero wins over directional sprites'
	);

	verifyWad(
		$spriteCatalog->sprite('MIRR', 'D', $rotation)['flipped']
		, 'mirrored rotation zero applies to every viewpoint'
	);
}

verifyWad(
	$spriteCatalog->sprite('ROTA', 'B', 0)['name'] === 'ROTAB2'
	, 'unspecified rotation chooses the lowest available direction'
);

verifyWad(
	$spriteCatalog->sprite('ROTA', 'B', 4)['name'] === 'ROTAB4'
	, 'specified rotation chooses its directional frame'
);

foreach(range(0, 1) as $repeat)
verifyWad(
	$spriteCatalog->sprite('NONE', 'A', 0) === null && $spriteCatalog->sprite('ROTA', 'B', 3) === null
	, 'missing sprites and missing directions remain absent on cached lookups'
);

$allBytes = implode('', array_map('chr', range(0, 255)));
$allImage = new IndexedImage(256, 1, $allBytes, strrev($allBytes));
$interleaved = $allImage->indexAlphaBytes();

foreach(range(0, 255) as $index)
verifyWad(
	ord($interleaved[$index * 2]) === $index && ord($interleaved[$index * 2 + 1]) === 255 - $index
	, 'RG8 conversion preserves every palette and alpha byte'
);

$packed = IndexedAtlas::pack([
	'picture:first' => new IndexedImage(2, 2, "\1\2\3\4", "\0\xff\x80\xff")
	, 'picture:wide' => new IndexedImage(57, 1, str_repeat("\x30", 57), str_repeat("\xff", 57))
	, 'picture:tall' => new IndexedImage(1, 3, "\5\6\7", "\xff\0\x80")
], 64
);

// First shelf: a two-row patch and a one-row patch. Second shelf: a tall patch.
// All unused cells, including the two-pixel shelf gaps, must stay transparent.
$blankRow = str_repeat("\0", 128);
$expected = $blankRow
	. "\0\0\1\0\2\xff" . str_repeat("\0", 4) . str_repeat("\x30\xff", 57) . str_repeat("\0", 4)
	. "\0\0\3\x80\4\xff" . str_repeat("\0", 122)
	. $blankRow . $blankRow
	. "\0\0\5\xff" . str_repeat("\0", 124)
	. "\0\0\6\0" . str_repeat("\0", 124)
	. "\0\0\7\x80" . str_repeat("\0", 124)
	. $blankRow;

verifyWad(
	$packed->width === 64 && $packed->height === 9 && $packed->pixels === $expected
	, 'atlas copies complete rows with exact transparency and shelf padding'
);

verifyWad(
	$packed->id('PICTURE', 'WIDE') === 1 && $packed->id('picture', 'tall') === 2
		&& $packed->rects[2]['x'] === 1 && $packed->rects[2]['y'] === 5
	, 'atlas IDs and rectangle positions match row packing'
);

$emptyAtlas = IndexedAtlas::pack([]);

verifyWad(
	$emptyAtlas->pixels === str_repeat("\0", $emptyAtlas->width * $emptyAtlas->height * 2)
	, 'empty atlas retains a transparent buffer'
);

// D2ST9 sector 505 names the wall texture LITE3 on both planes. Keep a
// non-square texture so this also checks that the flat path preserves dimensions.

$planePatch = static fn(string $columns): string => pack('v4V2', 2, 3, 0, 0, 16, 24)
	. "\0\3\0" . substr($columns, 0, 3) . "\0\xff"
	. "\0\3\0" . substr($columns, 3, 3) . "\0\xff";

$planeTexture = static fn(string $name): string => pack('a8VvvVv', $name, 0, 2, 3, 0, 1)
	. pack('v5', 0, 0, 0, 1, 0);

$planeBase = PortMap::archive([
	'PNAMES' => pack('Va8', 1, 'LIGHT')
	, 'TEXTURE1' => pack('V3', 2, 12, 44) . $planeTexture('LITE3') . $planeTexture('SHARED')
	, 'P_START' => ''
	, 'LIGHT' => $planePatch("\1\2\3\4\5\6")
	, 'P_END' => ''
	, 'F_START' => ''
	, 'SHARED' => str_repeat("\x20", 4096)
	, 'F_END' => ''
]);

$planeOverride = PortMap::archive([
	'P_START' => ''
	, 'LIGHT' => $planePatch("\7\10\11\12\13\14")
	, 'P_END' => ''
	, 'F_START' => ''
	, 'SHARED' => str_repeat("\x30", 4096)
	, 'F_END' => ''
]);

$planeCatalog = new TextureCatalog(new Stack($planeBase, $planeOverride));
$wallPlane = $planeCatalog->flat('lite3');

verifyWad(
	$wallPlane !== null && $wallPlane === $planeCatalog->texture('LITE3')
	, 'floor and ceiling lookup falls back to a wall texture and shares its decoded image'
);

verifyWad(
	$wallPlane->width === 2 && $wallPlane->height === 3 && $wallPlane->pixels === "\7\12\10\13\11\14" && $wallPlane
		->alpha === str_repeat("\xff", 6)
	, 'wall texture used on a plane retains its dimensions, pixels, alpha, and WAD overrides'
);

verifyWad(
	$planeCatalog->flat('SHARED')->pixels === str_repeat("\x30", 4096) && $planeCatalog->texture('SHARED')
		->pixels === $wallPlane->pixels
	, 'real flats take priority over same-name wall textures without changing wall lookup'
);

verifyWad(
	$planeCatalog->flat('ABSENT') === null && $planeCatalog->flat('ABSENT') === null
	, 'unresolved plane names stay missing without recursive fallback'
);

$planeMap = PortMap::map();
$planeMap->sectors[0]['floorTexture'] = $planeMap->sectors[0]['ceilingTexture'] = 'LITE3';
$planeMap->sectors[1]['floorTexture'] = 'SHARED';
$planeMap->sectors[1]['ceilingTexture'] = 'F_SKY1';
$planeLevel = new Level($planeMap);
$planeAtlas = IndexedAtlas::forLevel($planeLevel, $planeCatalog);
$planeMesh = (new MapMeshBuilder($planeLevel, $planeAtlas))->build();
$planeId = $planeAtlas->id('flat', 'LITE3');

verifyWad(
	$planeId >= 0 && $planeAtlas->rects[$planeId]['width'] === 2 && $planeAtlas->rects[$planeId]['height'] === 3
	, 'wall-backed floor and ceiling material reaches the plane atlas'
);

foreach([0,1] as $plane)
{
	$planeSpans = array_filter(
		$planeMesh->planeSpans
		, static fn(array $span): bool => $span['sector'] === 0 && $span['plane'] === $plane
	);

	verifyWad(count($planeSpans) === 2, "wall-backed plane $plane emits its complete pair of triangles");
}

verifyWad(
	!array_filter($planeMesh->planeSpans, static fn(array $span): bool => $span['sector'] === 1 && $span['plane'] === 1)
	, 'sky ceilings remain open when resolving wall-backed plane textures'
);

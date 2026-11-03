<?php
declare(strict_types=1);

use Phonics\Render\BitmapFont;
use Phonics\Tiled\Loader;

$directory = dirname(__DIR__);
$palette = [
	'2,5,13'
	, '4,9,20'
	, '7,13,27'
	, '10,19,36'
	, '14,26,45'
	, '19,34,56'
	, '25,43,66'
	, '32,52,77'
];

for($stage = 1; $stage <= 25; $stage++)
{
	$map = (new Loader)->map($directory . '/worlds/stage-' . sprintf('%02d', $stage) . '.tmj');
	$background = $map->layer('Background');
	$path = $background->image;
	$name = basename($path);
	$size = getimagesize($path);
	expect($size[0] === 640 && $size[1] === 480, "$name uses the playfield resolution");
	$image = imagecreatefrompng($path);
	$colors = [];
	$opaque = true;
	$indices = [];

	for($y = 0; $y < 480; $y++)
	for($x = 0; $x < 640; $x++) $indices[imagecolorat($image, $x, $y)] = true;

	foreach(array_keys($indices) as $index)
	{
		$color = imagecolorsforindex($image, $index);
		$key = $color['red'] . ',' . $color['green'] . ',' . $color['blue'];
		$colors[$key] = true;
		$opaque = $opaque && $color['alpha'] === 0;
	}

	imagedestroy($image);
	expect($opaque, "$name is opaque");
	expect(count($colors) >= 4 && count($colors) <= 8, "$name retains a useful eight-color maximum");
	expect(array_diff(array_keys($colors), $palette) === [], "$name stays inside the contrast palette");
	$map = (new Loader)->map($directory . '/worlds/stage-' . sprintf('%02d', $stage) . '.tmj');
	$background = $map->layer('Background');
	expect(str_ends_with($background->image ?? '', '/assets/backgrounds/' . $name), "$name is its Tiled image layer");
}

$font = getimagesize($directory . '/assets/font.png');
expect($font[0] === 192 && $font[1] === 80, 'HUD font is a fixed sprite atlas');
$sdlFont = getimagesize($directory . '/assets/font-sdl.png');
expect($sdlFont[0] === 96 && $sdlFont[1] === 40, 'SDL HUD font keeps one source texel per design pixel');
$metadata = json_decode(file_get_contents($directory . '/assets/font.json'), true, 512, JSON_THROW_ON_ERROR);
expect($metadata['tileWidth'] === 12 && $metadata['tileHeight'] === 16, 'HUD font declares fixed sprite cells');
expect(count($metadata['characters']) === 74, 'HUD font covers the game text and symbols');
$bitmap = new BitmapFont(new Loader, $directory . '/assets/font.tsj', [$directory . '/assets/hud.tsj']);
expect($bitmap->lineHeight === 8.0, 'HUD font exposes resolution-independent logical metrics');
expect($bitmap->map->tilesets[1]['firstgid'] === 75, 'HUD rail follows the font in one SDL atlas');
$hud = getimagesize($directory . '/assets/hud-rail.png');
expect($hud[0] === 230 && $hud[1] === 480, 'HUD rail fills the cropped playfield side console');

<?php
declare(strict_types=1);

// Reuse only the hand-edited backdrop; the trail and terrain belong to this generated demo.
$dashMap = json_decode(file_get_contents(__DIR__ . '/assets/platformer.tmj'), true, 512, JSON_THROW_ON_ERROR);
$dashMap['width'] = $width = 40;
$dashMap['height'] = $height = 18;
$dashMap['nextlayerid'] = 23;
$dashMap['nextobjectid'] = 2;

$dashMap['layers'] = array_values(array_filter(
	$dashMap['layers']
	, /**
	 * Retain the common parallax image layers without copying source gameplay objects.
	 *
	 * @param array<string,mixed> $layer Decoded Tiled layer.
	 * @return bool True for shared backdrop image layers.
	 */
	static fn(array $layer): bool => $layer['type'] === 'imagelayer'
));

$ground = $trail = array_fill(0, $width * $height, 0);

for($y = 14; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$ground[$y * $width + $x] = 1;
	}
}

// The first arc is reachable with a jump from spawn. The second has a wider continuation gap.
foreach([
	[3, 10], [4, 10], [5, 9], [6, 8], [7, 7], [8, 6], [9, 6]
	, [10, 6], [11, 7], [12, 8], [13, 9], [14, 10], [15, 10]
	, [21, 10], [22, 10], [23, 9], [24, 8], [26, 6], [27, 6]
	, [28, 6], [29, 7], [30, 8], [31, 9], [32, 10], [33, 10]
] as [$x, $y])
{
	$trail[$y * $width + $x] = 5;
}

$defaults = ['visible' => true, 'opacity' => 1, 'x' => 0, 'y' => 0];

foreach(['Terrain' => $ground, 'Ring trails' => $trail] as $name => $data)
{
	$dashMap['layers'][] = [
		'id' => $name === 'Terrain' ? 20 : 21
		, 'name' => $name
		, 'type' => 'tilelayer'
		, 'width' => $width
		, 'height' => $height
		, 'data' => $data
	] + $defaults;
}

$dashMap['layers'][] = [
	'id' => 22
	, 'name' => 'Objects'
	, 'type' => 'objectgroup'
	, 'objects' => [['id' => 1, 'class' => 'Player', 'x' => 80, 'y' => 100, 'point' => true]]
] + $defaults;

file_put_contents(
	__DIR__ . '/assets/light-dash.tmj'
	, json_encode($dashMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

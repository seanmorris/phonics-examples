<?php
declare(strict_types=1);

// A standalone, repeatable platform course; existing hand-edited maps are only read.
$map = json_decode(file_get_contents(__DIR__ . '/assets/platformer.tmj'), true, 512, JSON_THROW_ON_ERROR);
$width = 64;
$height = 22;
$map['width'] = $width;
$map['height'] = $height;
$map['nextobjectid'] = 208;
$map['nextlayerid'] = 23;

$map['layers'] = array_values(array_filter(
	$map['layers']
	, /**
	 * Retain the source map's shared parallax backdrop.
	 *
	 * @param array<string,mixed> $layer Decoded Tiled layer.
	 * @return bool Whether the layer is a background image strip.
	 */
	static fn($layer) => $layer['type'] === 'imagelayer'
));

// Keep GIDs contiguous so a Tiled save does not renumber the platform objects.
$sourceTiles = json_decode(file_get_contents(__DIR__ . '/assets/tiles.tsj'), true, 512, JSON_THROW_ON_ERROR);
$firstGid = 1 + $sourceTiles['tilecount'];
$map['tilesets'][] = ['firstgid' => $firstGid, 'source' => 'platforms.tsj'];
$defaults = ['visible' => true, 'opacity' => 1, 'x' => 0, 'y' => 0];
$floor = array_fill(0, $width * $height, 0);

for($y = 14; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$floor[$y * $width + $x] = 1;
	}
}

$map['layers'][] = [
	'id' => 20
	, 'name' => 'Terrain'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $floor
] + $defaults;

/**
 * Convert named demo object properties into Tiled's typed property records.
 *
 * @param array<string,mixed> $values Properties keyed by their editor names.
 * @return list<array<string,mixed>> Tiled records including object-reference types.
 */
function platformDemoProperties(array $values): array
{
	$result = [];

	foreach($values as $name => $value)
	{
		$result[] = [
			'name' => $name
			, 'type' => in_array($name, ['path', 'ridePath', 'next'], true) ? 'object' : 'float'
			, 'value' => $value
		];
	}

	return $result;
}

$objects = [['id' => 1, 'class' => 'Player', 'x' => 80, 'y' => 100, 'point' => true]];

foreach([
	[100, 'Floating support: settles under a rider', 'FloatingPlatform', 32, 368, 0, ['settle' => 8]]
	, [101, 'Squine horizontal shuttle', 'MovingPlatform', 480, 320, 1, ['oscillateX' => 160]]
	, [102, 'Squine elevator', 'MovingPlatform', 640, 416, 1, ['oscillateY' => 128]]
	, [
		103, 'Elliptical float', 'MovingPlatform', 920, 304, 1
		, ['oscillateX' => 64, 'oscillateY' => 48, 'timeX' => 120, 'timeY' => 120]
	]
	, [104, 'Linked path: four corners', 'PathPlatform', 1200, 352, 2, ['path' => 200, 'pathSpeed' => 2]]
	, [105, 'Ride to activate', 'PathPlatform', 1712, 400, 2, ['ridePath' => 204, 'pathSpeed' => 2, 'settle' => 4]]
] as [$id, $name, $class, $x, $y, $gid, $properties])
{
	$objects[] = [
		'id' => $id
		, 'name' => $name
		, 'class' => $class
		, 'x' => $x
		, 'y' => $y
		, 'width' => 96
		, 'height' => 16
		, 'gid' => $firstGid + $gid
		, 'rotation' => 0
		, 'visible' => true
		, 'properties' => platformDemoProperties($properties)
	];
}

foreach([
	[200, 1248, 352, 201]
	, [201, 1504, 352, 202]
	, [202, 1504, 224, 203]
	, [203, 1248, 224, 200]
	, [204, 1760, 400, 205]
	, [205, 1760, 240, 206]
	, [206, 1952, 240, 207]
	, [207, 1952, 400, 204]
] as [$id, $x, $y, $next])
{
	$objects[] = [
		'id' => $id
		, 'name' => 'Route node ' . $id
		, 'class' => 'PathNode'
		, 'x' => $x
		, 'y' => $y
		, 'point' => true
		, 'visible' => true
		, 'properties' => platformDemoProperties(['next' => $next])
	];
}

$map['layers'][] = [
	'id' => 21
	, 'name' => 'Platforms and routes'
	, 'type' => 'objectgroup'
	, 'draworder' => 'topdown'
	, 'objects' => $objects
] + $defaults;

$rings = array_fill(0, $width * $height, 0);

foreach([[2, 9], [11, 8], [21, 7], [30, 6], [40, 5], [46, 5], [55, 5], [60, 5]] as [$x, $y])
{
	$rings[$y * $width + $x] = 5;
}

$map['layers'][] = [
	'id' => 22
	, 'name' => 'Rings'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $rings
] + $defaults;

$tileset = [
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'name' => 'platforms'
	, 'tilewidth' => 96
	, 'tileheight' => 16
	, 'columns' => 3
	, 'tilecount' => 3
	, 'image' => 'platforms.png'
	, 'imagewidth' => 288
	, 'imageheight' => 16
	, 'tiles' => [
		['id' => 0, 'class' => 'floating-platform']
		, ['id' => 1, 'class' => 'moving-platform']
		, ['id' => 2, 'class' => 'path-platform']
	]
];

foreach(['platforms.tmj' => $map, 'platforms.tsj' => $tileset] as $name => $data)
{
	file_put_contents(
		__DIR__ . '/assets/' . $name
		, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);
}

$colors = [[119, 216, 175, 255], [114, 182, 244, 255], [244, 189, 89, 255]];
$bytes = '';

for($y = 0; $y < 16; $y++)
{
	$bytes .= "\0";

	for($x = 0; $x < 288; $x++)
	{
		$local = $x % 96;
		$color = $colors[intdiv($x, 96)];

		if($y >= 4)
		{
			$color = [48, 67, 80, 255];

			if($y === 4 || $y === 14 || $local === 0 || $local === 95)
			{
				$color = [27, 38, 51, 255];
			}
			elseif(($local % 24 >= 9 && $local % 24 <= 13) && $y >= 7 && $y <= 10)
			{
				$color = $colors[intdiv($x, 96)];
			}
		}

		$bytes .= pack('C*', ...$color);
	}
}

/**
 * Encode a PNG chunk with its big-endian length and CRC.
 *
 * @param string $type Four-character PNG chunk name.
 * @param string $data Binary chunk payload.
 * @return string Complete PNG chunk bytes.
 */
function platformPngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

file_put_contents(
	__DIR__ . '/assets/platforms.png'
	, "\x89PNG\r\n\x1a\n" . platformPngChunk('IHDR', pack('NNC5', 288, 16, 8, 6, 0, 0, 0))
		. platformPngChunk('IDAT', gzcompress($bytes)) . platformPngChunk('IEND', '')
);

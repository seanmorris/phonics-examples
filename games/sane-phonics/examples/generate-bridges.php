<?php
declare(strict_types=1);

// Generate a flat-ended flexible-span course without modifying the source platformer map.
$map = json_decode(file_get_contents(__DIR__ . '/assets/platformer.tmj'), true, 512, JSON_THROW_ON_ERROR);
$width = 64;
$height = 22;
$map['width'] = $width;
$map['height'] = $height;
$map['nextobjectid'] = 104;
$map['nextlayerid'] = 23;

$map['layers'] = array_values(array_filter(
	$map['layers']
	, /**
	 * Retain only shared image backdrops from the source scene.
	 *
	 * @param array<string,mixed> $layer Decoded Tiled layer.
	 * @return bool Whether this is a background image layer.
	 */
	static fn($layer) => $layer['type'] === 'imagelayer'
));

$sourceTiles = json_decode(file_get_contents(__DIR__ . '/assets/tiles.tsj'), true, 512, JSON_THROW_ON_ERROR);
$firstGid = 1 + $sourceTiles['tilecount'];
$map['tilesets'][] = ['firstgid' => $firstGid, 'source' => 'bridges.tsj'];
$defaults = ['visible' => true, 'opacity' => 1, 'x' => 0, 'y' => 0];
$floorY = 14;
$floor = array_fill(0, $width * $height, 0);
$gaps = [[8, 20], [24, 34], [38, 50]];

for($y = $floorY; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$gap = false;

		foreach($gaps as [$left, $right])
		{
			$gap = $gap || ($x >= $left && $x < $right);
		}

		$floor[$y * $width + $x] = $gap ? 0 : 1;
	}
}

$map['layers'][] = [
	'id' => 20
	, 'name' => 'Terrain with bridge openings'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $floor
] + $defaults;

/**
 * Encode named settings as Tiled property records.
 *
 * @param array<string,int|float|bool> $values Property values keyed by editor name.
 * @return list<array{name:string,type:string,value:int|float|bool}> Tiled property records.
 */
function bridgeDemoProperties(array $values): array
{
	$result = [];

	foreach($values as $name => $value)
	{
		$result[] = [
			'name' => $name
			, 'type' => is_bool($value) ? 'bool' : (is_int($value) ? 'int' : 'float')
			, 'value' => $value
		];
	}

	return $result;
}

$objects = [
	['id' => 1, 'class' => 'Player', 'x' => 96, 'y' => 430, 'point' => true]
];

foreach([
	[100, 'Long bridge: several loads add', 'BendableBridge', 256, 384, 0, 48]
	, [101, 'Short stiff bridge', 'BendableBridge', 768, 320, 0, 24]
	, [102, 'Bendable grinding rail', 'BendableRail', 1216, 384, 1, 36]
] as [$id, $name, $class, $x, $span, $tile, $droop])
{
	$objects[] = [
		'id' => $id
		, 'name' => $name
		, 'class' => $class
		, 'x' => $x
		, 'y' => $floorY * 32 + 16
		, 'width' => $span
		, 'height' => 16
		, 'gid' => $firstGid + $tile
		, 'rotation' => 0
		, 'visible' => true
		, 'properties' => bridgeDemoProperties([
			'droop' => $droop
			, 'segmentWidth' => 16
			, 'maxLoads' => 4
		])
	];
}

$map['layers'][] = [
	'id' => 21
	, 'name' => 'Flexible spans'
	, 'type' => 'objectgroup'
	, 'draworder' => 'topdown'
	, 'objects' => $objects
] + $defaults;

$rings = array_fill(0, $width * $height, 0);

foreach([[3, 11], [10, 11], [14, 12], [18, 11], [27, 11], [31, 12], [41, 11], [45, 12], [49, 11]] as [$x, $y])
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
	, 'name' => 'bridges'
	, 'tilewidth' => 16
	, 'tileheight' => 16
	, 'columns' => 2
	, 'tilecount' => 2
	, 'image' => 'bridges.png'
	, 'imagewidth' => 32
	, 'imageheight' => 16
	, 'tiles' => [
		['id' => 0, 'class' => 'bridge-plank']
		, ['id' => 1, 'class' => 'bridge-rail']
	]
];

foreach(['bridges.tmj' => $map, 'bridges.tsj' => $tileset] as $name => $data)
{
	file_put_contents(
		__DIR__ . '/assets/' . $name
		, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);
}

$bytes = '';

for($y = 0; $y < 16; $y++)
{
	$bytes .= "\0";

	for($x = 0; $x < 32; $x++)
	{
		$local = $x % 16;

		if($x < 16)
		{
			$color = $y < 4 ? [220, 171, 82, 255] : [104, 62, 43, 255];

			if($y === 0 || $y === 3 || $local === 0 || $local === 15)
			{
				$color = [55, 35, 31, 255];
			}
			elseif(($local === 4 || $local === 11) && $y > 5)
			{
				$color = [232, 194, 105, 255];
			}
		}
		else
		{
			$color = [0, 0, 0, 0];

			if($y >= 2 && $y <= 5)
			{
				$color = $y === 2 || $y === 5 ? [24, 31, 42, 255] : [126, 210, 232, 255];
			}
			elseif(($local === 1 || $local === 14) && $y > 5)
			{
				$color = [41, 50, 67, 255];
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
function bridgePngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

file_put_contents(
	__DIR__ . '/assets/bridges.png'
	, "\x89PNG\r\n\x1a\n" . bridgePngChunk('IHDR', pack('NNC5', 32, 16, 8, 6, 0, 0, 0))
		. bridgePngChunk('IDAT', gzcompress($bytes)) . bridgePngChunk('IEND', '')
);

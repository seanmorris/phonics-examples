<?php
declare(strict_types=1);

// Fixed ropes, a squine suspension, and a linked-route suspension share the common backdrop.
$grappleMap = json_decode(file_get_contents(__DIR__ . '/assets/light-dash.tmj'), true, 512, JSON_THROW_ON_ERROR);
$grappleMap['width'] = $width = 48;
$grappleMap['height'] = $height = 18;
$grappleMap['nextobjectid'] = 204;
$grappleMap['nextlayerid'] = 23;

$grappleMap['layers'] = array_values(array_filter(
	$grappleMap['layers']
	, /**
	 * Retain backdrop images without modifying the source course.
	 *
	 * @param array<string,mixed> $layer Decoded Tiled layer.
	 * @return bool Whether this layer supplies shared parallax artwork.
	 */
	static fn(array $layer): bool => $layer['type'] === 'imagelayer'
));

$ground = array_fill(0, $width * $height, 0);

for($y = 14; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$ground[$y * $width + $x] = 1;
	}
}

$defaults = ['visible' => true, 'opacity' => 1, 'x' => 0, 'y' => 0];
$grappleMap['layers'][] = [
	'id' => 20
	, 'name' => 'Terrain'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $ground
] + $defaults;

$sourceTiles = json_decode(file_get_contents(__DIR__ . '/assets/tiles.tsj'), true, 512, JSON_THROW_ON_ERROR);
$platformGid = $sourceTiles['tilecount'] + 1;
$grappleGid = $platformGid + 3;
$grappleMap['tilesets'][] = ['firstgid' => $platformGid, 'source' => 'platforms.tsj'];
$grappleMap['tilesets'][] = ['firstgid' => $grappleGid, 'source' => 'grapples.tsj'];

/**
 * Encode demo properties using object-reference and boolean types understood by Tiled.
 *
 * @param array<string,mixed> $values Editor property names and values.
 * @return list<array<string,mixed>> Typed property records.
 */
function grappleDemoProperties(array $values): array
{
	$result = [];

	foreach($values as $name => $value)
	{
		$result[] = [
			'name' => $name
			, 'type' => in_array($name, ['tiedTo', 'path', 'next'], true)
				? 'object'
				: (is_bool($value) ? 'bool' : 'float')
			, 'value' => $value
		];
	}

	return $result;
}

$objects = [['id' => 1, 'class' => 'Player', 'x' => 80, 'y' => 100, 'point' => true]];

foreach([[100, 128, 160], [101, 400, 128]] as [$id, $x, $y])
{
	$objects[] = [
		'id' => $id
		, 'class' => 'RopeAnchor'
		, 'name' => 'Fixed suspension'
		, 'x' => $x
		, 'y' => $y
		, 'point' => true
	];
}

foreach([
	[102, 720, 160, 'MovingPlatform', ['oscillateX' => 96]]
	, [103, 1120, 144, 'PathPlatform', ['path' => 200, 'pathSpeed' => 1]]
] as [$id, $x, $y, $class, $properties])
{
	$objects[] = [
		'id' => $id
		, 'class' => $class
		, 'name' => 'Moving suspension'
		, 'x' => $x - 48
		, 'y' => $y
		, 'width' => 96
		, 'height' => 16
		, 'gid' => $platformGid + ($id === 102 ? 1 : 2)
		, 'properties' => grappleDemoProperties($properties)
	];
}

foreach([
	[10, 100, 128, 352, 192, false, 'Jump to grab; Left / Right swing; Jump releases']
	, [11, 101, 472, 288, 176, false, 'Angled rope']
	, [12, 102, 720, 336, 176, true, 'No-swing shuttle']
	, [13, 103, 1120, 320, 176, false, 'Path-driven suspension']
] as [$id, $anchor, $x, $y, $length, $noSwing, $name])
{
	$objects[] = [
		'id' => $id
		, 'class' => 'GrapplePoint'
		, 'name' => $name
		, 'x' => $x - 14
		, 'y' => $y
		, 'width' => 28
		, 'height' => 16
		, 'gid' => $grappleGid
		, 'properties' => grappleDemoProperties(['tiedTo' => $anchor, 'ropeLength' => $length, 'noSwing' => $noSwing])
	];
}

foreach([[200, 1120, 144, 201], [201, 1360, 144, 202], [202, 1360, 224, 203], [203, 1120, 224, 200]] as [
	$id
	, $x
	, $y
	, $next
]){
	$objects[] = [
		'id' => $id
		, 'class' => 'PathNode'
		, 'x' => $x
		, 'y' => $y
		, 'point' => true
		, 'properties' => grappleDemoProperties(['next' => $next])
	];
}

$grappleMap['layers'][] = [
	'id' => 21
	, 'name' => 'Ropes and suspensions'
	, 'type' => 'objectgroup'
	, 'objects' => $objects
]
	+ $defaults;

$tileset = [
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'name' => 'grapples'
	, 'tilewidth' => 28
	, 'tileheight' => 16
	, 'columns' => 0
	, 'tilecount' => 3
	, 'tiles' => [
		[
			'id' => 0
			, 'class' => 'grapple-handle'
			, 'image' => 'grapple-handle.png'
			, 'imagewidth' => 28
			, 'imageheight' => 16
		]
		, ['id' => 1, 'class' => 'rope-segment', 'image' => 'rope-segment.png', 'imagewidth' => 6, 'imageheight' => 8]
		, ['id' => 2, 'class' => 'rope-anchor', 'image' => 'rope-anchor.png', 'imagewidth' => 16, 'imageheight' => 16]
	]
];

foreach(['grapples.tmj' => $grappleMap, 'grapples.tsj' => $tileset] as $name => $data)
{
	file_put_contents(
		__DIR__ . '/assets/' . $name
		, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);
}

/**
 * Encode one generated artwork PNG chunk.
 *
 * @param string $type Four-letter chunk type.
 * @param string $data Unencoded chunk payload.
 * @return string Big-endian length, type, bytes, and checksum.
 */
function grapplePngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

foreach([
	'grapple-handle' => [28, 16]
	, 'rope-segment' => [6, 8]
	, 'rope-anchor' => [16, 16]
] as $name => [$width, $height])
{
	$bytes = '';

	for($y = 0; $y < $height; $y++)
	{
		$bytes .= "\0";

		for($x = 0; $x < $width; $x++)
		{
			$color = [0, 0, 0, 0];

			if($name === 'grapple-handle')
			{
				if(($x >= 11 && $x <= 16 && $y <= 5) || ($x >= 2 && $x <= 25 && $y >= 5 && $y <= 13))
				{
					$color = $y === 5 || $y === 13 || $x === 2 || $x === 25 ? [70, 49, 25, 255] : [242, 183, 58, 255];

					if($x > 7 && $x < 20 && $y > 7 && $y < 11)
					{
						$color = [0, 0, 0, 0];
					}
				}
			}
			elseif($name === 'rope-segment')
			{
				if(($x >= 1 && $x <= 4) && ($y === 0 || $y === 7 || $x === 1 || $x === 4))
				{
					$color = $x <= 2 ? [203, 223, 231, 255] : [71, 97, 116, 255];
				}
			}
			elseif(hypot($x - 7.5, $y - 7.5) < 7)
			{
				$color = hypot($x - 7.5, $y - 7.5) < 3 ? [242, 183, 58, 255] : [71, 97, 116, 255];
			}

			$bytes .= pack('C*', ...$color);
		}
	}

	file_put_contents(
		__DIR__ . '/assets/' . $name . '.png'
		, "\x89PNG\r\n\x1a\n" . grapplePngChunk('IHDR', pack('NNC5', $width, $height, 8, 6, 0, 0, 0))
			. grapplePngChunk('IDAT', gzcompress($bytes)) . grapplePngChunk('IEND', '')
	);
}

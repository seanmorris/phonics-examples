<?php
declare(strict_types=1);

// Reuse the rail course's quarter bends and loop; keep the original map untouched.
$vehicleMap = json_decode(file_get_contents(__DIR__ . '/assets/grinding.tmj'), true, 512, JSON_THROW_ON_ERROR);
$vehicleTiles = json_decode(file_get_contents(__DIR__ . '/assets/grinding.tsj'), true, 512, JSON_THROW_ON_ERROR);
$vehicleGid = $vehicleMap['tilesets'][1]['firstgid'] + $vehicleTiles['tilecount'];
$vehicleMap['tilesets'][] = ['firstgid' => $vehicleGid, 'source' => 'vehicles.tsj'];
$objectId = 1;
$layerId = 1;

foreach($vehicleMap['layers'] as &$layer)
{
	$layer['id'] = $layerId++;

	foreach($layer['objects'] ?? [] as $key => $object)
	{
		$object['id'] = $objectId++;

		if(($object['name'] ?? '') === 'Entry rail')
		{
			$object['name'] = 'Boarding ground';
			$object['class'] = '';
			$object['properties'][] = ['name' => 'solid', 'type' => 'bool', 'value' => true];
		}

		$layer['objects'][$key] = $object;
	}
}

unset($layer);
$objects = [['id' => $objectId++, 'class' => 'Player', 'x' => 80, 'y' => 100, 'point' => true]];

foreach([80, 280, 2100] as $x)
{
	$objects[] = [
		'id' => $objectId++
		, 'class' => 'Skateboard'
		, 'name' => 'Jump onto the board to ride'
		, 'x' => $x - 32
		, 'y' => 448
		, 'width' => 64
		, 'height' => 12
		, 'gid' => $vehicleGid
	];
}

$vehicleMap['layers'][] = [
	'id' => $layerId++
	, 'name' => 'Skateboards'
	, 'type' => 'objectgroup'
	, 'objects' => $objects
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$rings = $paving = array_fill(0, $vehicleMap['width'] * $vehicleMap['height'], 0);

for($x = 0; $x < 10; $x++)
for($y = 14; $y < 20; $y++) $paving[$y * $vehicleMap['width'] + $x] = 1;

foreach([[5, 12], [7, 10], [9, 9], [11, 10], [13, 12], [25, 4], [27, 4], [29, 4], [42, 12], [61, 12]] as [$x, $y])
{
	$rings[$y * $vehicleMap['width'] + $x] = 5;
}

foreach(['Boarding ground art' => $paving, 'Trick rings' => $rings] as $name => $data)
{
	$vehicleMap['layers'][] = [
		'id' => $layerId++
		, 'name' => $name
		, 'type' => 'tilelayer'
		, 'width' => $vehicleMap['width']
		, 'height' => $vehicleMap['height']
		, 'data' => $data
		, 'opacity' => 1
		, 'visible' => true
		, 'x' => 0
		, 'y' => 0
		, 'properties' => [['name' => 'collision', 'type' => 'bool', 'value' => false]]
	];
}

$vehicleMap['nextobjectid'] = $objectId;
$vehicleMap['nextlayerid'] = $layerId;
$tileset = [
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'name' => 'vehicles'
	, 'tilewidth' => 64
	, 'tileheight' => 12
	, 'columns' => 0
	, 'tilecount' => 1
	, 'tiles' => [
		['id' => 0, 'class' => 'skateboard', 'image' => 'skateboard.png', 'imagewidth' => 64, 'imageheight' => 12]
	]
];

foreach(['vehicles.tmj' => $vehicleMap, 'vehicles.tsj' => $tileset] as $name => $data)
{
	file_put_contents(
		__DIR__ . '/assets/' . $name
		, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);
}

/**
 * Encode a generated skateboard PNG chunk.
 *
 * @param string $type Four-letter PNG chunk type.
 * @param string $data Unencoded chunk bytes.
 * @return string Length, type, payload, and checksum in PNG byte order.
 */
function vehiclePngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

$bytes = '';

for($y = 0; $y < 12; $y++)
{
	$bytes .= "\0";

	for($x = 0; $x < 64; $x++)
	{
		$color = [0, 0, 0, 0];

		if($x >= 2 && $x <= 61 && $y <= 3)
		{
			$color = $y === 0 ? [36, 42, 60, 255] : [242, 158, 48, 255];

			if($y === 2 && $x > 8 && $x < 55) $color = [39, 191, 192, 255];
		}

		if(($x >= 1 && $x <= 6 || $x >= 57 && $x <= 62) && $y < 3) $color = [242, 158, 48, 255];

		foreach([13, 50] as $wheel)
		{
			if(abs($x - $wheel) <= 1 && $y >= 5 && $y <= 8) $color = [109, 122, 139, 255];

			if(hypot($x - $wheel, $y - 8) < 3.5) $color = [235, 240, 246, 255];

			if(hypot($x - $wheel, $y - 8) < 1.5) $color = [57, 65, 87, 255];
		}

		$bytes .= pack('C*', ...$color);
	}
}

file_put_contents(
	__DIR__ . '/assets/skateboard.png'
	, "\x89PNG\r\n\x1a\n" . vehiclePngChunk('IHDR', pack('NNC5', 64, 12, 8, 6, 0, 0, 0))
		. vehiclePngChunk('IDAT', gzcompress($bytes)) . vehiclePngChunk('IEND', '')
);

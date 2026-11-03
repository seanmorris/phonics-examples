<?php
declare(strict_types=1);

/**
 * Encode one PNG chunk for the generated aircraft artwork.
 *
 * @param string $type Four-character PNG chunk type.
 * @param string $data Raw chunk payload.
 * @return string Encoded length, type, payload, and checksum.
 */
function tornadoPngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/**
 * Generate one original aircraft state as a transparent RGBA PNG.
 *
 * @param string $path Destination path.
 * @param string $state Ground, flight, gear, thrust, or crashed presentation.
 * @return void Writes a deterministic ninety-six by forty-eight image.
 */
function tornadoPng(string $path, string $state): void
{
	$bytes = '';

	for($y = 0; $y < 48; $y++)
	{
		$bytes .= "\0";

		for($x = 0; $x < 96; $x++)
		{
			// Author the canonical state facing right. Vehicle rendering mirrors this artwork when
			// the chassis faces left, matching the orientation expected by every other vehicle.
			$sourceX = 95 - $x;
			$color = [0, 0, 0, 0];
			$dark = $state === 'crashed' ? [76, 66, 70, 255] : [53, 67, 92, 255];
			$red = $state === 'crashed' ? [145, 74, 68, 255] : [221, 65, 71, 255];
			$cream = [246, 226, 164, 255];
			$blue = [89, 191, 226, 255];

			if($sourceX >= 14 && $sourceX <= 82 && $y >= 20 && $y <= 34
				&& hypot(($sourceX - 50) / 42, ($y - 27) / 10) <= 1
			){
				$color = $y < 24 ? $cream : $red;
			}

			if($sourceX >= 34 && $sourceX <= 61 && $y >= 12 && $y <= 23
				&& hypot(($sourceX - 48) / 16, ($y - 21) / 11) <= 1
			){
				$color = $blue;
			}

			if($sourceX >= 37 && $sourceX <= 58 && $y >= 18 && $y <= 20) $color = $dark;

			if($sourceX >= 42 && $sourceX <= 70 && $y >= 31 && $y <= 38) $color = $red;

			if($sourceX >= 71 && $sourceX <= 86 && $y >= 11 && $y <= 29) $color = $red;

			if($sourceX >= 76 && $sourceX <= 89 && $y >= 9 && $y <= 13) $color = $cream;

			if($sourceX >= 5 && $sourceX <= 17 && $y >= 23 && $y <= 32) $color = $dark;

			$propeller = ($state === 'flight' || $state === 'thrust') ? 3 : 1;

			if(abs($sourceX - 8) <= $propeller && $y >= 10 && $y <= 42) $color = [215, 229, 236, 210];

			if($sourceX >= 8 && $sourceX <= 14 && $y >= 25 && $y <= 28) $color = $cream;

			if(in_array($state, ['ground', 'gear'], true))
			{
				if(($sourceX >= 27 && $sourceX <= 30 || $sourceX >= 68 && $sourceX <= 71)
					&& $y >= 34 && $y <= 41
				) $color = $dark;

				if((hypot($sourceX - 28, $y - 42) < 4 || hypot($sourceX - 70, $y - 42) < 4))
				{
					$color = [30, 34, 45, 255];
				}
			}

			if($state === 'thrust' && $sourceX >= 83 && $y >= 23 && $y <= 31)
			{
				$length = 95 - $sourceX;
				$color = abs($y - 27) <= max(1, $length / 4)
					? ($sourceX > 90 ? [255, 103, 48, 230] : [255, 226, 103, 255])
					: $color;
			}

			if($state === 'crashed' && (($sourceX + $y) % 13 === 0 || ($sourceX - $y) % 17 === 0)
				&& $sourceX >= 20 && $sourceX <= 80 && $y >= 14 && $y <= 37
			){
				$color = [32, 35, 43, 255];
			}

			$bytes .= pack('C*', ...$color);
		}
	}

	file_put_contents(
		$path
		, "\x89PNG\r\n\x1a\n" . tornadoPngChunk('IHDR', pack('NNC5', 96, 48, 8, 6, 0, 0, 0))
			. tornadoPngChunk('IDAT', gzcompress($bytes)) . tornadoPngChunk('IEND', '')
	);
}

$assets = __DIR__ . '/assets/';
$states = [
	'tornado' => 'ground'
	, 'tornado-flight' => 'flight'
	, 'tornado-gear' => 'gear'
	, 'tornado-thrust' => 'thrust'
	, 'tornado-crashed' => 'crashed'
];

$tiles = [];

foreach($states as $class => $state)
{
	$name = $class . '.png';
	tornadoPng($assets . $name, $state);
	$tiles[] = [
		'id' => count($tiles)
		, 'class' => $class
		, 'image' => $name
		, 'imagewidth' => 96
		, 'imageheight' => 48
	];
}

$tileset = [
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'name' => 'tornado'
	, 'tilewidth' => 96
	, 'tileheight' => 48
	, 'columns' => 0
	, 'tilecount' => count($tiles)
	, 'tiles' => $tiles
];

$base = json_decode(file_get_contents($assets . 'platformer.tmj'), true, 512, JSON_THROW_ON_ERROR);
$backgrounds = [];

foreach($base['layers'] as $layer)
{
	if($layer['type'] !== 'imagelayer') continue;

	$layer['id'] = count($backgrounds) + 1;
	$backgrounds[] = $layer;
}

$width = 320;
$height = 96;
$terrain = $rings = array_fill(0, $width * $height, 0);

/**
 * Fill an inclusive tile rectangle inside one generated layer.
 *
 * @param array<int,int> $data Tile GID layer updated by reference.
 * @param int $width Map width in tiles.
 * @param int $x1 Inclusive left tile.
 * @param int $y1 Inclusive top tile.
 * @param int $x2 Inclusive right tile.
 * @param int $y2 Inclusive bottom tile.
 * @param int $gid Tile GID to place.
 * @return void Clips and fills the requested rectangle.
 */
function tornadoRect(array &$data, int $width, int $x1, int $y1, int $x2, int $y2, int $gid): void
{
	for($y = max(0, $y1); $y <= min(95, $y2); $y++)
	for($x = max(0, $x1); $x <= min(319, $x2); $x++) $data[$y * $width + $x] = $gid;
}

// A ten-thousand-pixel terrain-following course. The four-tile ring clearance keeps the reference
// 128-pixel downward sensor engaged while the stepped ridges leave enough horizontal distance for
// its damped pitch and ten-pixel-per-frame flight cap. There is deliberately no ceiling.
tornadoRect($terrain, $width, 0, 88, 319, 95, 1);
$profile = [
	[0, 48, 84]       // takeoff runway
	, [49, 63, 88]    // open-sky leveling stretch
	, [64, 71, 86]
	, [72, 79, 84]
	, [80, 87, 82]
	, [88, 95, 80]    // first sensor ridge
	, [96, 103, 82]
	, [104, 111, 84]
	, [112, 119, 86]
	, [120, 147, 88]  // recovery valley
	, [148, 157, 86]
	, [158, 167, 84]
	, [168, 177, 82]
	, [178, 187, 80]
	, [188, 197, 78]  // broad high point reached by gradual pitch changes
	, [198, 207, 80]
	, [208, 217, 82]
	, [218, 227, 84]
	, [228, 239, 86]
	, [240, 255, 88]  // second recovery valley
	, [256, 319, 84]  // approach and landing runway
];

foreach($profile as [$left, $right, $top])
{
	if($top < 88) tornadoRect($terrain, $width, $left, $top, $right, 87, 1);
}

for($x = 12; $x <= 316; $x += 4)
{
	$ground = 88;

	foreach($profile as [$left, $right, $top])
	{
		if($x >= $left && $x <= $right)
		{
			$ground = $top;
			break;
		}
	}

	$rings[($ground - 4) * $width + $x] = 5;
}

$layers = $backgrounds;
$layers[] = [
	'id' => 7
	, 'name' => 'Flight terrain'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $terrain
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$layers[] = [
	'id' => 8
	, 'name' => 'Flight route rings'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $rings
	, 'properties' => [['name' => 'collision', 'type' => 'bool', 'value' => false]]
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$layers[] = [
	'id' => 9
	, 'name' => 'Aircraft and player'
	, 'type' => 'objectgroup'
	, 'objects' => [
		['id' => 1, 'class' => 'Player', 'x' => 176, 'y' => 2580, 'point' => true]
		, [
			'id' => 2
			, 'class' => 'Tornado'
			, 'name' => 'Tornado flight trainer'
			, 'x' => 128
			, 'y' => 2688
			, 'width' => 96
			, 'height' => 48
			, 'gid' => 38
			, 'properties' => [
				['name' => 'lockedIn', 'type' => 'bool', 'value' => true]
				, ['name' => 'seatHeight', 'type' => 'float', 'value' => 14]
				, ['name' => 'seatForward', 'type' => 'float', 'value' => 0]
			]
		]
	]
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$map = [
	'type' => 'map'
	, 'version' => '1.10'
	, 'orientation' => 'orthogonal'
	, 'renderorder' => 'right-down'
	, 'width' => $width
	, 'height' => $height
	, 'tilewidth' => 32
	, 'tileheight' => 32
	, 'infinite' => false
	, 'tilesets' => [
		['firstgid' => 1, 'source' => 'tiles.tsj']
		, ['firstgid' => 38, 'source' => 'tornado.tsj']
	]
	, 'layers' => $layers
	, 'nextobjectid' => 3
	, 'nextlayerid' => 10
];

foreach(['tornado.tsj' => $tileset, 'tornado.tmj' => $map] as $name => $data)
{
	file_put_contents($assets . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

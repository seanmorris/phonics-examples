<?php
declare(strict_types=1);

/** Encode one deterministic PNG chunk. */
function eggWalkerPngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/**
 * Generate original right-facing EggWalker artwork for one observable state.
 *
 * @param string $path Destination PNG path.
 * @param string $state Standing, walk-a, walk-b, crouching, falling, boosting, punch, or shooting.
 * @param bool $front Whether to emit the hull/front-leg plane instead of the chair/rear-leg plane.
 * @return void Writes a deterministic ninety-six pixel square RGBA image.
 */
function eggWalkerPng(string $path, string $state, bool $front): void
{
	$bytes = '';
	$crouch = $state === 'crouching';
	$falling = in_array($state, ['falling', 'boosting'], true);
	$bodyTop = $crouch ? 43 : 29;
	$bodyBottom = $crouch ? 72 : 60;
	$legTop = $crouch ? 66 : 56;
	$frontShift = $state === 'walk-a' ? 7 : ($state === 'walk-b' ? -4 : 0);
	$backShift = $state === 'walk-a' ? -5 : ($state === 'walk-b' ? 6 : 0);
	$dark = [43, 48, 62, 255];
	$shadow = [92, 39, 49, 255];
	$red = [211, 55, 67, 255];
	$cream = [246, 219, 147, 255];
	$glass = [89, 191, 226, 225];
	$steel = [173, 187, 195, 255];

	for($y = 0; $y < 96; $y++)
	{
		$bytes .= "\0";

		for($x = 0; $x < 96; $x++)
		{
			$color = [0, 0, 0, 0];

			$ellipse = static fn(float $cx, float $cy, float $rx, float $ry): bool =>
				hypot(($x - $cx) / $rx, ($y - $cy) / $ry) <= 1;

			// Back leg and foot.
			$backX = 31 + $backShift;
			$frontX = 62 + $frontShift;
			$legBottom = $falling ? 87 : 91;

			if(!$front && abs($x - $backX) <= 6 && $y >= $legTop && $y <= $legBottom) $color = $shadow;

			if(!$front && abs($x - $backX) <= 10 && $y >= $legBottom - 4 && $y <= $legBottom) $color = $dark;

			// Chair and cockpit sit behind the foreground hull, centered on the rider anchor.
			if(!$front && $x >= 35 && $x <= 57 && $y >= $bodyTop - 2 && $y <= $bodyTop + 8) $color = $dark;

			if(!$front && $ellipse(46, $bodyTop - 11, 17, 18) && $y <= $bodyTop + 2) $color = $glass;

			if(!$front && $x >= 31 && $x <= 61 && $y >= $bodyTop - 1 && $y <= $bodyTop + 3) $color = $cream;

			// Recoiling top cannon.
			$gunLeft = $state === 'shooting' ? 48 : 52;

			if(!$front && $x >= $gunLeft && $x <= 87 && $y >= $bodyTop - 14 && $y <= $bodyTop - 7) $color = $dark;

			if(!$front && $x >= 80 && $x <= 91 && $y >= $bodyTop - 16 && $y <= $bodyTop - 5) $color = $steel;

			// Main armored hull.
			if($front && $ellipse(48, ($bodyTop + $bodyBottom) / 2, 33, ($bodyBottom - $bodyTop) / 2)
				&& $y >= $bodyTop && $y <= $bodyBottom
			) $color = $red;

			if($front && $x >= 20 && $x <= 75 && $y >= $bodyTop && $y <= $bodyTop + 5) $color = $cream;

			if($front && $x >= 31 && $x <= 64 && $y >= $bodyBottom - 5 && $y <= $bodyBottom) $color = $shadow;

			// Arm, chain, and glove. Punch extends to the edge of the authored frame.
			if($front && $x >= 69 && $x <= 79 && $y >= $bodyTop + 10 && $y <= $bodyTop + 20) $color = $dark;

			if($front && $state === 'punch' && $x >= 74 && $x <= 92 && $y >= $bodyTop + 13 && $y <= $bodyTop + 16)
			{
				$color = ($x % 6 < 3) ? $steel : $dark;
			}

			if($front && $state === 'punch' && $x >= 87 && $x <= 95 && $y >= $bodyTop + 8 && $y <= $bodyTop + 21)
			{
				$color = $cream;
			}

			// Front leg and foot remain in front of the hull.
			if($front && abs($x - $frontX) <= 6 && $y >= $legTop && $y <= $legBottom) $color = $red;

			if($front && abs($x - $frontX) <= 10 && $y >= $legBottom - 4 && $y <= $legBottom) $color = $dark;

			if($falling)
			{
				if(!$front && abs($x - $backX) <= 5 && $y >= 83 && $y <= 94) $color = $shadow;

				if($front && abs($x - $frontX) <= 5 && $y >= 80 && $y <= 92) $color = $red;
			}

			if($state === 'boosting' && $y >= 88)
			{
				$boosterX = $front ? $frontX : $backX;

				if(abs($x - $boosterX) <= max(1, intdiv(99 - $y, 2)))
				{
					$color = $y < 92 ? [255, 226, 103, 255] : [255, 103, 48, 230];
				}
			}

			if(!$front && $state === 'shooting' && $x >= 91 && $y >= $bodyTop - 18 && $y <= $bodyTop - 3)
			{
				$color = abs($y - ($bodyTop - 10)) <= 95 - $x ? [255, 226, 103, 255] : $color;
			}

			$bytes .= pack('C*', ...$color);
		}
	}

	file_put_contents(
		$path
		, "\x89PNG\r\n\x1a\n" . eggWalkerPngChunk('IHDR', pack('NNC5', 96, 96, 8, 6, 0, 0, 0))
			. eggWalkerPngChunk('IDAT', gzcompress($bytes)) . eggWalkerPngChunk('IEND', '')
	);
}

/** Generate the small cannon projectile used by the vehicle sprite system. */
function eggWalkerShotPng(string $path): void
{
	$bytes = '';

	for($y = 0; $y < 8; $y++)
	{
		$bytes .= "\0";

		for($x = 0; $x < 8; $x++)
		{
			$distance = hypot($x - 3.5, $y - 3.5);
			$color = $distance <= 2.2
				? [255, 226, 103, 255]
				: ($distance <= 3.5 ? [255, 103, 48, 230] : [0, 0, 0, 0]);

			$bytes .= pack('C*', ...$color);
		}
	}

	file_put_contents(
		$path
		, "\x89PNG\r\n\x1a\n" . eggWalkerPngChunk('IHDR', pack('NNC5', 8, 8, 8, 6, 0, 0, 0))
			. eggWalkerPngChunk('IDAT', gzcompress($bytes)) . eggWalkerPngChunk('IEND', '')
	);
}

$assets = __DIR__ . '/assets/';
$states = [
	['egg-walker', 'standing']
	, ['egg-walker-walking', 'walk-a']
	, ['', 'walk-b']
	, ['egg-walker-crouching', 'crouching']
	, ['egg-walker-falling', 'falling']
	, ['egg-walker-boosting', 'boosting']
	, ['egg-walker-punch', 'punch']
	, ['egg-walker-shooting', 'shooting']
];

$tiles = [];
$stateTiles = [];

foreach($states as [$class, $state])
{
	foreach([false, true] as $front)
	{
		$name = 'egg-walker-' . $state . ($front ? '-front' : '') . '.png';
		eggWalkerPng($assets . $name, $state, $front);
		$tile = [
			'id' => count($tiles)
			, 'image' => $name
			, 'imagewidth' => 96
			, 'imageheight' => 96
		];

		$stateTiles[$state][$front ? 'front' : 'behind'] = $tile['id'];

		if($class !== '') $tile['class'] = $class . ($front ? '-front' : '');

		$tiles[] = $tile;
	}
}

foreach(['behind', 'front'] as $plane)
{
	$id = $stateTiles['walk-a'][$plane];
	$tiles[$id]['animation'] = [
		['tileid' => $id, 'duration' => 300]
		, ['tileid' => $stateTiles['walk-b'][$plane], 'duration' => 300]
	];
}

eggWalkerShotPng($assets . 'egg-walker-shot.png');
$tiles[] = [
	'id' => count($tiles)
	, 'class' => 'egg-walker-shot'
	, 'image' => 'egg-walker-shot.png'
	, 'imagewidth' => 8
	, 'imageheight' => 8
];

$tileset = [
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'name' => 'egg-walker'
	, 'tilewidth' => 96
	, 'tileheight' => 96
	, 'columns' => 0
	, 'tilecount' => count($tiles)
	, 'tiles' => $tiles
];

$base = json_decode(file_get_contents($assets . 'platformer.tmj'), true, 512, JSON_THROW_ON_ERROR);
$layers = [];

foreach($base['layers'] as $layer)
{
	if($layer['type'] !== 'imagelayer') continue;

	$layer['id'] = count($layers) + 1;
	$layers[] = $layer;
}

$width = 160;
$height = 24;
$terrain = $rings = array_fill(0, $width * $height, 0);

/** Fill one inclusive tile rectangle in a generated layer. */
function eggWalkerRect(array &$data, int $width, int $x1, int $y1, int $x2, int $y2, int $gid): void
{
	for($y = max(0, $y1); $y <= min(23, $y2); $y++)
	for($x = max(0, $x1); $x <= min(159, $x2); $x++) $data[$y * $width + $x] = $gid;
}

eggWalkerRect($terrain, $width, 0, 20, 62, 23, 1);
eggWalkerRect($terrain, $width, 67, 20, 159, 23, 1);
eggWalkerRect($terrain, $width, 27, 13, 45, 16, 1); // crouch-height tunnel
eggWalkerRect($terrain, $width, 84, 19, 88, 19, 1);
eggWalkerRect($terrain, $width, 104, 18, 108, 19, 1);
eggWalkerRect($terrain, $width, 124, 16, 128, 19, 1);

foreach([[8,17], [18,17], [51,17], [60,17], [72,17], [82,16], [96,17], [116,15], [140,17]] as [$x, $y])
{
	$rings[$y * $width + $x] = 5;
}

$layers[] = [
	'id' => 7
	, 'name' => 'Walker terrain'
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
	, 'name' => 'Walker route rings'
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
	, 'name' => 'Walker, rider, and targets'
	, 'type' => 'objectgroup'
	, 'objects' => [
		['id' => 1, 'class' => 'Player', 'x' => 144, 'y' => 520, 'point' => true]
		, [
			'id' => 2
			, 'class' => 'EggWalker'
			, 'name' => 'EggWalker trainer'
			, 'x' => 96
			, 'y' => 640
			, 'width' => 96
			, 'height' => 96
			, 'gid' => 38
			, 'properties' => [
				['name' => 'seatHeight', 'type' => 'float', 'value' => 48]
				, ['name' => 'seatForward', 'type' => 'float', 'value' => 0]
			]
		]
		, [
			'id' => 3
			, 'class' => 'CrabMeat'
			, 'x' => 3360
			, 'y' => 576
			, 'properties' => [
				['name' => 'direction', 'type' => 'int', 'value' => -1]
				, ['name' => 'patrolBeat', 'type' => 'float', 'value' => 90]
				, ['name' => 'patrolPause', 'type' => 'float', 'value' => 25]
			]
		]
		, [
			'id' => 4
			, 'class' => 'CrabMeat'
			, 'x' => 4000
			, 'y' => 512
			, 'properties' => [['name' => 'direction', 'type' => 'int', 'value' => -1]]
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
		, ['firstgid' => 38, 'source' => 'egg-walker.tsj']
	]
	, 'layers' => $layers
	, 'nextobjectid' => 5
	, 'nextlayerid' => 10
];

foreach(['egg-walker.tsj' => $tileset, 'egg-walker.tmj' => $map] as $name => $data)
{
	file_put_contents($assets . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

<?php
declare(strict_types=1);

// PHP-Noid's HUD face is intentionally wider and more squared than Sane-Phonics' font.
// Each source bit becomes a 2x2 opaque pixel in a transparent 12x16 sprite cell.
$glyphs = [
	'A' => ['01110','10001','10001','11111','10001','10001','10001']
	, 'B' => ['11110','10001','10001','11110','10001','10001','11110']
	, 'C' => ['01111','10000','10000','10000','10000','10000','01111']
	, 'D' => ['11110','10001','10001','10001','10001','10001','11110']
	, 'E' => ['11111','10000','10000','11110','10000','10000','11111']
	, 'F' => ['11111','10000','10000','11110','10000','10000','10000']
	, 'G' => ['01111','10000','10000','10111','10001','10001','01111']
	, 'H' => ['10001','10001','10001','11111','10001','10001','10001']
	, 'I' => ['11111','00100','00100','00100','00100','00100','11111']
	, 'J' => ['00111','00010','00010','00010','10010','10010','01100']
	, 'K' => ['10001','10010','10100','11000','10100','10010','10001']
	, 'L' => ['10000','10000','10000','10000','10000','10000','11111']
	, 'M' => ['10001','11011','10101','10101','10001','10001','10001']
	, 'N' => ['10001','11001','10101','10011','10001','10001','10001']
	, 'O' => ['01110','10001','10001','10001','10001','10001','01110']
	, 'P' => ['11110','10001','10001','11110','10000','10000','10000']
	, 'Q' => ['01110','10001','10001','10001','10101','10010','01101']
	, 'R' => ['11110','10001','10001','11110','10100','10010','10001']
	, 'S' => ['01111','10000','10000','01110','00001','00001','11110']
	, 'T' => ['11111','00100','00100','00100','00100','00100','00100']
	, 'U' => ['10001','10001','10001','10001','10001','10001','01110']
	, 'V' => ['10001','10001','10001','10001','10001','01010','00100']
	, 'W' => ['10001','10001','10001','10101','10101','10101','01010']
	, 'X' => ['10001','10001','01010','00100','01010','10001','10001']
	, 'Y' => ['10001','10001','01010','00100','00100','00100','00100']
	, 'Z' => ['11111','00001','00010','00100','01000','10000','11111']
	, '0' => ['01110','10001','10011','10101','11001','10001','01110']
	, '1' => ['00100','01100','00100','00100','00100','00100','01110']
	, '2' => ['01110','10001','00001','00010','00100','01000','11111']
	, '3' => ['11110','00001','00001','01110','00001','00001','11110']
	, '4' => ['00010','00110','01010','10010','11111','00010','00010']
	, '5' => ['11111','10000','10000','11110','00001','00001','11110']
	, '6' => ['01110','10000','10000','11110','10001','10001','01110']
	, '7' => ['11111','00001','00010','00100','01000','01000','01000']
	, '8' => ['01110','10001','10001','01110','10001','10001','01110']
	, '9' => ['01110','10001','10001','01111','00001','00001','01110']
	, '.' => ['00000','00000','00000','00000','00000','00110','00110']
	, ',' => ['00000','00000','00000','00000','00110','00110','00100']
	, ':' => ['00000','00110','00110','00000','00110','00110','00000']
	, ';' => ['00000','00110','00110','00000','00110','00110','00100']
	, '!' => ['00100','00100','00100','00100','00100','00000','00100']
	, '?' => ['01110','10001','00001','00010','00100','00000','00100']
	, '+' => ['00000','00100','00100','11111','00100','00100','00000']
	, '-' => ['00000','00000','00000','11111','00000','00000','00000']
	, '/' => ['00001','00010','00010','00100','01000','01000','10000']
	, '\\' => ['10000','01000','01000','00100','00010','00010','00001']
	, '(' => ['00010','00100','01000','01000','01000','00100','00010']
	, ')' => ['01000','00100','00010','00010','00010','00100','01000']
	, '[' => ['01110','01000','01000','01000','01000','01000','01110']
	, ']' => ['01110','00010','00010','00010','00010','00010','01110']
	, '<' => ['00010','00100','01000','10000','01000','00100','00010']
	, '>' => ['01000','00100','00010','00001','00010','00100','01000']
	, '=' => ['00000','11111','00000','11111','00000','00000','00000']
	, '\'' => ['00100','00100','00000','00000','00000','00000','00000']
	, '"' => ['01010','01010','00000','00000','00000','00000','00000']
	, '#' => ['01010','11111','01010','01010','11111','01010','00000']
	, '%' => ['11001','11010','00100','01000','10110','00110','00000']
	, '&' => ['01100','10010','10100','01000','10101','10010','01101']
	, '_' => ['00000','00000','00000','00000','00000','00000','11111']
	, '@' => ['01110','10001','10111','10101','10111','10000','01110']
	, '|' => ['00100','00100','00100','00100','00100','00100','00100']
	, '^' => ['00100','01010','10001','00000','00000','00000','00000']
	, '~' => ['00000','00000','01001','10110','00000','00000','00000']
	, '×' => ['00000','10001','01010','00100','01010','10001','00000']
	, '−' => ['00000','00000','00000','11111','00000','00000','00000']
	, '·' => ['00000','00000','00000','00100','00000','00000','00000']
	, '…' => ['00000','00000','00000','00000','00000','10101','10101']
	, '←' => ['00000','00100','01000','11111','01000','00100','00000']
	, '→' => ['00000','00100','00010','11111','00010','00100','00000']
	, '↑' => ['00100','01110','10101','00100','00100','00100','00000']
	, '↓' => ['00100','00100','00100','00100','10101','01110','00100']
	, '♥' => ['00000','01010','11111','11111','01110','00100','00000']
	, '»' => ['00000','10010','01001','00100','01001','10010','00000']
];

$characters = array_merge(array_map('strval', array_keys($glyphs)), [' ']);
$columns = 16;
$tileWidth = 12;
$tileHeight = 16;
$rows = (int)ceil(count($characters) / $columns);
$image = imagecreatetruecolor($columns * $tileWidth, $rows * $tileHeight);
imagealphablending($image, false);
imagesavealpha($image, true);
$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
$ink = imagecolorallocatealpha($image, 255, 255, 255, 0);
imagefill($image, 0, 0, $transparent);

foreach($characters as $index => $character)
{
	if($character === ' ') continue;

	$originX = ($index % $columns) * $tileWidth + 1;
	$originY = intdiv($index, $columns) * $tileHeight + 1;

	foreach($glyphs[$character] as $y => $row)
	foreach(str_split($row) as $x => $pixel)
	if($pixel === '1')
	imagefilledrectangle(
		$image
		, $originX + $x * 2
		, $originY + $y * 2
		, $originX + $x * 2 + 1
		, $originY + $y * 2 + 1
		, $ink
	);
}

$directory = __DIR__ . '/assets';
imagepng($image, $directory . '/font.png', 9);
imagedestroy($image);

// SDL uses a native one-pixel source so each HUD scale can remain integral.
$sdlTileWidth = 6;
$sdlTileHeight = 8;
$sdl = imagecreatetruecolor($columns * $sdlTileWidth, $rows * $sdlTileHeight);
imagealphablending($sdl, false);
imagesavealpha($sdl, true);
$sdlTransparent = imagecolorallocatealpha($sdl, 0, 0, 0, 127);
$sdlInk = imagecolorallocatealpha($sdl, 255, 255, 255, 0);
imagefill($sdl, 0, 0, $sdlTransparent);

foreach($characters as $index => $character)
{
	if($character === ' ') continue;

	$originX = ($index % $columns) * $sdlTileWidth;
	$originY = intdiv($index, $columns) * $sdlTileHeight;

	foreach($glyphs[$character] as $y => $row)
	foreach(str_split($row) as $x => $pixel)
	if($pixel === '1') imagesetpixel($sdl, $originX + $x, $originY + $y, $sdlInk);
}

imagepng($sdl, $directory . '/font-sdl.png', 9);
imagedestroy($sdl);

file_put_contents($directory . '/font.json', json_encode([
	'image' => 'font.png'
	, 'tileWidth' => $tileWidth
	, 'tileHeight' => $tileHeight
	, 'columns' => $columns
	, 'rows' => $rows
	, 'characters' => $characters
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
) . "\n"
);

$tiles = [];

foreach($characters as $index => $character)
{
	$tiles[] = [
		'id' => $index
		, 'properties' => [
			['name' => 'glyph.char', 'type' => 'string', 'value' => $character]
			, ['name' => 'glyph.width', 'type' => 'int', 'value' => $sdlTileWidth]
			, ['name' => 'glyph.height', 'type' => 'int', 'value' => $sdlTileHeight]
			, ['name' => 'glyph.advance', 'type' => 'int', 'value' => 6]
			, ['name' => 'glyph.pixelRatio', 'type' => 'int', 'value' => 1]
			, ['name' => 'glyph.smooth', 'type' => 'bool', 'value' => false]
			, ['name' => 'glyph.tint', 'type' => 'bool', 'value' => true]
		]
	];
}

$tiled = static fn(array $data): string => json_encode(
	$data
	, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . "\n";

file_put_contents($directory . '/font.tsj', $tiled([
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'tiledversion' => '1.12.2'
	, 'name' => 'php-noid-font'
	, 'image' => 'font-sdl.png'
	, 'imagewidth' => $columns * $sdlTileWidth
	, 'imageheight' => $rows * $sdlTileHeight
	, 'tilewidth' => $sdlTileWidth
	, 'tileheight' => $sdlTileHeight
	, 'columns' => $columns
	, 'tilecount' => count($characters)
	, 'margin' => 0
	, 'spacing' => 0
	, 'properties' => [
		['name' => 'font.lineHeight', 'type' => 'int', 'value' => 8]
		, ['name' => 'font.spaceAdvance', 'type' => 'int', 'value' => 4]
		, ['name' => 'font.fallback', 'type' => 'string', 'value' => '?']
		, ['name' => 'font.caseFold', 'type' => 'bool', 'value' => true]
	]
	, 'tiles' => $tiles
]));

file_put_contents($directory . '/hud.tsj', $tiled([
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'tiledversion' => '1.12.2'
	, 'name' => 'php-noid-hud'
	, 'image' => 'hud-rail.png'
	, 'imagewidth' => 230
	, 'imageheight' => 480
	, 'tilewidth' => 230
	, 'tileheight' => 480
	, 'columns' => 1
	, 'tilecount' => 1
	, 'margin' => 0
	, 'spacing' => 0
]));

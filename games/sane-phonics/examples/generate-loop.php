<?php
declare(strict_types=1);
// Invoked by generate-assets.php. Original art and polygon geometry for an 8x8 loop.
// Clip each quarter's solid exterior to the tile grid, retaining the curved inner edge.

function clipLoop(array $points, int $axis, float $bound, bool $greater): array
{
	$result = [];
	$previous = $points[count($points) - 1];

	$inside = fn($p) => $greater?$p[$axis] >= $bound:$p[$axis] <= $bound;

	foreach($points as $point)
	{
		if($inside($point) !== $inside($previous))
		{
			$t = ($bound - $previous[$axis]) / ($point[$axis] - $previous[$axis]);
			$intersection = [
				$previous[0] + $t * ($point[0] - $previous[0])
				, $previous[1] + $t * (
					$point[1] - $previous[1]
				)
			];

			$intersection[$axis] = $bound;
			$result[] = $intersection;
		}

		if($inside($point))
		{
			$result[] = $point;
		}

		$previous = $point;
	}

	return $result;
}

png(__DIR__ . '/assets/loop.png', 256, 256, static function($x, $y) {
	$distance = hypot($x + .5 - 128, $y + .5 - 128);

	if($distance < 128)
	{
		return [0,0,0,0];
	}

	if($distance < 134)
	{
		return [105,212,157,255];
	}

	return ($x + $y) % 7 === 0?[71,100,105,255]:[42,63,77,255];
});

$loopTiles = [];

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		// The four quarter polygons run from an outer corner along the circular hole.
		$quarter = $y < 4?($x < 4?2:3):($x < 4?1:0);
		$polygon = [[$x < 4?0:256,$y < 4?0:256]];

		for($i = 0; $i <= 64; $i++)
		{
			$angle = ($quarter + $i / 64) * M_PI / 2;
			$polygon[] = [round(128 + cos($angle) * 128, 6),round(128 + sin($angle) * 128, 6)];
		}

		foreach([
			[0,$x * 32,true],[0,($x + 1) * 32,false],[1,$y * 32,true]
			, [
				1
				, ($y + 1) * 32
				, false
			]
		] as [
			$axis,$bound,$greater
		]
		){
			if($polygon)
			{
				$polygon = clipLoop($polygon, $axis, $bound, $greater);
			}
		}

		$unique = [];

		foreach($polygon as [$px,$py])
		{
			$point = ['x' => round($px - $x * 32, 6),'y' => round($py - $y * 32, 6)];

			if(!$unique || $point !== $unique[count($unique) - 1])
			{
				$unique[] = $point;
			}
		}

		if(count($unique) > 1 && $unique[0] === $unique[count($unique) - 1])
		{
			array_pop($unique);
		}

		if(count($unique) >= 3)
		{
			$loopTiles[] = [
				'id' => $y * 8 + $x
				, 'objectgroup' => [
					'objects' => [
						[
							'id' => 1
							, 'x' => 0
							, 'y' => 0
							, 'polygon' => $unique
						]
					]
				]
			];
		}
	}
}

jsonAsset(
	'loop.tsj'
	, [
		'type' => 'tileset'
		, 'name' => 'loop'
		, 'tilewidth' => 32
		, 'tileheight' => 32
		, 'tilecount' => 64
		, 'columns' => 8
		, 'image' => 'loop.png'
		, 'imagewidth' => 256
		, 'imageheight' => 256
		, 'tiles' => $loopTiles
	]
);

$width = 64;
$height = 20;
$loopFirstGid = $tiles['tilecount'] + 1;
$planes = array_fill(0, 3, array_fill(0, $width * $height, 0));

// A 256px loop at (768,192), with a shared floor at y=448.
for($y = 14; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$planes[0][$y * $width + $x] = 1;
	}
}

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		// Common crown, left route on 1, right route on 2, as in moving-boxes-2.
		$plane = $y < 2?0:($x < 4?1:2);
		$planes[$plane][($y + 6) * $width + $x + 24] = $loopFirstGid + $y * 8 + $x;
	}
}

$loopLayers = $background;

foreach($planes as $plane => $cells)
{
	$loopLayers[] = [
		'id' => $plane + 1
		, 'name' => 'Collision ' . $plane
		, 'type' => 'tilelayer'
		, 'width' => $width
		, 'height' => $height
		, 'data' => $cells
		, 'properties' => [
			[
				'name' => 'collisionLayer'
				, 'type' => 'int'
				, 'value' => $plane
			]
		]
	] + $layerDefaults;
}

$gates = [];

foreach([
	['Left entrance',704,176,32,288,false],['Crown',880,168,32,64,true]
	, [
		'Right entrance'
		, 1056
		, 176,32,288,false
	]
] as $i => [$name,$x,$y,$w,$h,$back])
{
	$gates[] = [
		'id' => $i + 1
		, 'name' => $name
		, 'class' => 'layer-switch'
		, 'x' => $x
		, 'y' => $y
		, 'width' => $w
		, 'height' => $h
		, 'properties' => [['name' => 'back','type' => 'bool','value' => $back]]
	];
}

$loopLayers[] = [
	'id' => 4
	, 'name' => 'Layer switches'
	, 'type' => 'objectgroup'
	, 'objects' => $gates
] + $layerDefaults;

jsonAsset(
	'loop.tmj'
	, [
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
			['firstgid' => 1,'source' => 'tiles.tsj']
			, [
				'firstgid' => $loopFirstGid
				, 'source' => 'loop.tsj'
			]
		]
		, 'layers' => $loopLayers
	]
);

// A half-submerged loop resting on the seabed, switch-controlled pool, floating platform, and
// the reference map's object 3958 vertical door block. The pool bottom remains fixed while its
// surface moves.
$waterLoopPlanes = $planes;

// Remove the dry level's loop and lower its bottom from y=448 to the seabed at y=576.
for($plane = 0; $plane < 3; $plane++)
{
	for($y = 6; $y < 14; $y++)
	{
		for($x = 24; $x < 32; $x++)
		{
			$waterLoopPlanes[$plane][$y * $width + $x] = 0;
		}
	}
}

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		$plane = $y < 2?0:($x < 4?1:2);
		$waterLoopPlanes[$plane][($y + 10) * $width + $x + 24] = $loopFirstGid + $y * 8 + $x;
	}
}

for($y = 14; $y < 18; $y++)
{
	for($x = 16; $x < 48; $x++)
	{
		$gid = $waterLoopPlanes[0][$y * $width + $x];

		if($gid < $loopFirstGid)
		{
			$waterLoopPlanes[0][$y * $width + $x] = 0;
		}
	}
}

$waterLoopLayers = $background;

foreach($waterLoopPlanes as $plane => $cells)
{
	$waterLoopLayers[] = [
		'id' => $plane + 1
		, 'name' => 'Collision ' . $plane
		, 'type' => 'tilelayer'
		, 'width' => $width
		, 'height' => $height
		, 'data' => $cells
		, 'properties' => [
			[
				'name' => 'collisionLayer'
				, 'type' => 'int'
				, 'value' => $plane
			]
		]
	] + $layerDefaults;
}

$waterLoopLayers[] = [
	'id' => 4
	, 'name' => 'Layer switches'
	, 'type' => 'objectgroup'
	, 'objects' => array_map(static fn($gate) => array_replace($gate, ['y' => $gate['y'] + 128]), $gates)
] + $layerDefaults;

$waterLoopLayers[] = [
	'id' => 5
	, 'name' => 'Water controls'
	, 'type' => 'objectgroup'
	, 'objects' => [
		[
			'id' => 10
			, 'name' => 'Loop pool'
			, 'class' => 'WaterRegion'
			, 'x' => 512
			, 'y' => 448
			, 'width' => 1024
			, 'height' => 192
			, 'properties' => $waterProperties
		]
		, ['id' => 111,'name' => 'Low water','point' => true,'x' => 0,'y' => 512]
		, ['id' => 112,'name' => 'Half loop','point' => true,'x' => 0,'y' => 448]
		, ['id' => 113,'name' => 'High water','point' => true,'x' => 0,'y' => 384]
		, [
			'id' => 101
			, 'name' => 'Drain'
			, 'class' => 'Switch'
			, 'gid' => 4
			, 'x' => 160
			, 'y' => 448
			, 'width' => 32
			, 'height' => 8
			, 'properties' => [
				['name' => 'water','type' => 'object','value' => 10]
				, ['name' => 'setPoint','type' => 'object','value' => 111]
				, ['name' => 'fillSpeed','type' => 'float','value' => 96]
				, ['name' => 'drainSpeed','type' => 'float','value' => 128]
			]
		]
		, [
			'id' => 102
			, 'name' => 'Half'
			, 'class' => 'Switch'
			, 'gid' => 4
			, 'x' => 256
			, 'y' => 448
			, 'width' => 32
			, 'height' => 8
			, 'properties' => [
				['name' => 'water','type' => 'object','value' => 10]
				, ['name' => 'setPoint','type' => 'object','value' => 112]
				, ['name' => 'fillSpeed','type' => 'float','value' => 96]
				, ['name' => 'drainSpeed','type' => 'float','value' => 128]
			]
		]
		, [
			'id' => 103
			, 'name' => 'Fill'
			, 'class' => 'Switch'
			, 'gid' => 4
			, 'x' => 352
			, 'y' => 448
			, 'width' => 32
			, 'height' => 8
			, 'properties' => [
				['name' => 'water','type' => 'object','value' => 10]
				, ['name' => 'setPoint','type' => 'object','value' => 113]
				, ['name' => 'fillSpeed','type' => 'float','value' => 96]
				, ['name' => 'drainSpeed','type' => 'float','value' => 128]
			]
		]
		, [
			'id' => 120
			, 'name' => 'Floating platform'
			, 'class' => 'Block'
			, 'gid' => 1
			, 'x' => 608
			, 'y' => 464
			, 'width' => 128
			, 'height' => 32
			, 'properties' => [
				['name' => 'water','type' => 'object','value' => 10]
				, ['name' => 'density','type' => 'float','value' => .5]
				, ['name' => 'hLock','type' => 'bool','value' => true]
			]
		]
		, [
			'id' => 3958
			, 'name' => 'Buoyant door block'
			, 'class' => 'Block'
			, 'gid' => 1
			, 'x' => 1312
			, 'y' => 570
			, 'width' => 32
			, 'height' => 160
			, 'properties' => [
				['name' => 'water','type' => 'object','value' => 10]
				, ['name' => 'density','type' => 'float','value' => .8]
				, ['name' => 'hLock','type' => 'bool','value' => true]
			]
		]
	]
] + $layerDefaults;

jsonAsset(
	'water-loop.tmj'
	, [
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
			['firstgid' => 1,'source' => 'tiles.tsj']
			, ['firstgid' => $loopFirstGid,'source' => 'loop.tsj']
		]
		, 'layers' => $waterLoopLayers
	]
);

// The grinding course uses the same collision curve with rail-only artwork. Each quarter-circle
// is a 90-degree bend; the three collision planes and gates make the circle traversable both ways.
png(__DIR__ . '/assets/grinding.png', 256, 256, static function($x, $y) {
	$distance = hypot($x + .5 - 128, $y + .5 - 128);

	if($distance >= 126 && $distance < 132)
	{
		return abs($distance - 128) < 1.25?[255,245,188,255]:[244,195,107,255];
	}

	return [0,0,0,0];
});

jsonAsset(
	'grinding.tsj'
	, [
		'type' => 'tileset'
		, 'name' => 'grinding-loop'
		, 'tilewidth' => 32
		, 'tileheight' => 32
		, 'tilecount' => 64
		, 'columns' => 8
		, 'image' => 'grinding.png'
		, 'imagewidth' => 256
		, 'imageheight' => 256
		, 'tiles' => $loopTiles
	]
);

$grindingFirstGid = $tiles['tilecount'] + 1;
$grindingWidth = 80;
$grindingHeight = 20;
$grindingPlanes = array_fill(0, 3, array_fill(0, $grindingWidth * $grindingHeight, 0));
$grindingGround = array_fill(0, $grindingWidth * $grindingHeight, 0);
$grindingArt = array_fill(0, $grindingWidth * $grindingHeight, 0);

// The stop wall clears tangent motion, letting opposite input reverse the direction-retaining rail.
for($y = 0; $y < $grindingHeight; $y++)
{
	$grindingGround[$y * $grindingWidth + 73] = 1;
}

// Four independent quarter bends raise the rail, cross an upper straight, and lower it again.
foreach([[16,10,4,4],[20,6,0,0],[32,6,4,0],[36,10,0,4]] as [
	$mapX,$mapY,$sourceX,$sourceY
]){
	for($y = 0; $y < 4; $y++)
	{
		for($x = 0; $x < 4; $x++)
		{
			$grindingArt[($mapY + $y) * $grindingWidth + $mapX + $x]
				= $grindingFirstGid + ($sourceY + $y) * 8 + $sourceX + $x;
		}
	}
}

foreach([...range(0, 5),...range(10, 15)] as $x) $grindingArt[14 * $grindingWidth + $x] = 4;

for($y = 14; $y < $grindingHeight; $y++)
{
	foreach(range(6, 9) as $x) $grindingArt[$y * $grindingWidth + $x] = 1;
}

foreach(range(24, 31) as $x) $grindingArt[6 * $grindingWidth + $x] = 4;

foreach(range(40, 72) as $x) $grindingArt[14 * $grindingWidth + $x] = 4;

$quarterPath = [['x' => 320,'y' => 448],['x' => 512,'y' => 448]];

foreach([
	[512,320,M_PI / 2,0],[768,320,M_PI,3 * M_PI / 2]
	, [1024,320,3 * M_PI / 2,2 * M_PI],[1280,320,M_PI,M_PI / 2]
] as [$cx,$cy,$from,$to])
{
	for($i = 1; $i <= 16; $i++)
	{
		$angle = $from + ($to - $from) * $i / 16;
		$quarterPath[] = [
			'x' => round($cx + cos($angle) * 128, 6)
			, 'y' => round($cy + sin($angle) * 128, 6)
		];
	}

	if($cx === 768) $quarterPath[] = ['x' => 1024,'y' => 192];
}

$quarterPath[] = ['x' => 2336,'y' => 448];
$quarterPath[] = ['x' => 2336,'y' => 640];
$quarterPath[] = ['x' => 320,'y' => 640];

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		$plane = $y < 2?0:($x < 4?1:2);
		$grindingPlanes[$plane][($y + 6) * $grindingWidth + $x + 48]
			= $grindingFirstGid + $y * 8 + $x;
	}
}

$grindingGates = [];

foreach([
	['Left entrance',1472,176,32,288,false],['Crown',1648,168,32,64,true]
	, ['Right entrance',1824,176,32,288,false]
] as $i => [$name,$x,$y,$w,$h,$back])
{
	$grindingGates[] = [
		'id' => $i + 1
		, 'name' => $name
		, 'class' => 'layer-switch'
		, 'x' => $x
		, 'y' => $y
		, 'width' => $w
		, 'height' => $h
		, 'properties' => [['name' => 'back','type' => 'bool','value' => $back]]
	];
}

$grindingLayers = $background;
$grindingLayers[] = [
	'id' => 1
	, 'name' => 'Stop wall'
	, 'type' => 'tilelayer'
	, 'width' => $grindingWidth
	, 'height' => $grindingHeight
	, 'data' => $grindingGround
] + $layerDefaults;

$grindingLayers[] = [
	'id' => 2
	, 'name' => 'Quarter bend art'
	, 'type' => 'tilelayer'
	, 'width' => $grindingWidth
	, 'height' => $grindingHeight
	, 'data' => $grindingArt
	, 'properties' => [['name' => 'collision','type' => 'bool','value' => false]]
] + $layerDefaults;

$grindingLayers[] = [
	'id' => 3
	, 'name' => 'Quarter bend collision'
	, 'type' => 'objectgroup'
	, 'objects' => [
		[
			'id' => 1
			, 'name' => 'Entry rail'
			, 'class' => 'Grinding'
			, 'x' => 0
			, 'y' => 0
			, 'polygon' => [
				['x' => 0,'y' => 448],['x' => 192,'y' => 448]
				, ['x' => 192,'y' => 640],['x' => 0,'y' => 640]
			]
			, 'properties' => [['name' => 'oneWay','type' => 'bool','value' => false]]
		]
		, [
			'id' => 2
			, 'name' => 'Level ground transition'
			, 'x' => 0
			, 'y' => 0
			, 'polygon' => [
				['x' => 192,'y' => 448],['x' => 320,'y' => 448]
				, ['x' => 320,'y' => 640],['x' => 192,'y' => 640]
			]
			, 'properties' => [
				['name' => 'solid','type' => 'bool','value' => true]
				, ['name' => 'oneWay','type' => 'bool','value' => false]
			]
		]
		, [
			'id' => 3
			, 'name' => 'Four quarter bends'
			, 'class' => 'Grinding'
			, 'x' => 0
			, 'y' => 0
			, 'polygon' => $quarterPath
			, 'properties' => [['name' => 'oneWay','type' => 'bool','value' => false]]
		]
	]
] + $layerDefaults;

foreach($grindingPlanes as $plane => $cells)
{
	$grindingLayers[] = [
		'id' => $plane + 4
		, 'name' => 'Grinding ' . $plane
		, 'type' => 'tilelayer'
		, 'width' => $grindingWidth
		, 'height' => $grindingHeight
		, 'data' => $cells
		, 'properties' => [
			['name' => 'collisionLayer','type' => 'int','value' => $plane]
			, ['name' => 'grinding','type' => 'bool','value' => true]
		]
	] + $layerDefaults;
}

$grindingLayers[] = [
	'id' => 7
	, 'name' => 'Layer switches'
	, 'type' => 'objectgroup'
	, 'objects' => $grindingGates
] + $layerDefaults;

jsonAsset(
	'grinding.tmj'
	, [
		'type' => 'map'
		, 'version' => '1.10'
		, 'orientation' => 'orthogonal'
		, 'renderorder' => 'right-down'
		, 'width' => $grindingWidth
		, 'height' => $grindingHeight
		, 'tilewidth' => 32
		, 'tileheight' => 32
		, 'infinite' => false
		, 'tilesets' => [
			['firstgid' => 1,'source' => 'tiles.tsj']
			, ['firstgid' => $grindingFirstGid,'source' => 'grinding.tsj']
		]
		, 'layers' => $grindingLayers
	]
);

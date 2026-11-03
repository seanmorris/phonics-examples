<?php
declare(strict_types=1);

/**
 * Generate the freely distributable Hexen-format portal demonstration maps.
 *
 * The PWAD contains original map records and a procedurally generated water flat.
 * Other textures, sprites and sounds come from the demo world's Doom shareware IWAD.
 */

$destination = $argv[1] ?? dirname(__DIR__) . '/demo/PORTALS.WAD';

$rooms = [
	['A',0,0,0,128,176,0]
	, ['B',512,0,0,128,144,0]
	, ['C',1024,0,0,128,112,60]
	, ['D',0,512,128,256,208,0]
	, ['E',512,512,128,256,160,0]
	, ['F',1024,512,0,128,128,0]
	, ['G',512,1024,0,128,128,0]
	, ['H',1024,1024,0,128,96,50]
];

$portalLines = [
	'0:2' => [12,11,1,0]
	, '1:0' => [11,12,1,0]
	, '1:2' => [22,21,1,0]
	, '2:0' => [21,22,1,0]
	, '2:2' => [32,31,1,1]
	, '3:0' => [31,32,1,1]
	, '3:2' => [42,41,1,1]
	, '4:0' => [41,42,1,1]
	, '4:2' => [52,51,1,1]
	, '5:0' => [51,52,1,1]
];

$sectorPortalLines = [
	'2:1' => [60,0,1,0,0] // C ceiling anchor; C carries sector tag 60.
	, '2:3' => [50,0,0,1,0] // H floor reference.
	, '7:1' => [60,0,1,1,0] // C ceiling reference.
	, '7:3' => [50,0,0,0,0] // H floor anchor; H carries sector tag 50.
];

$vertices = $sides = $lines = $segs = $sectors = '';

foreach($rooms as $roomIndex => [$label,$x,$y,$floor,$ceiling,$light,$tag])
{
	$firstVertex = $roomIndex * 4;

	// Clockwise boundaries keep sidedef zero on the playable right/front side.
	foreach([[$x,$y],[$x,$y + 256],[$x + 256,$y + 256],[$x + 256,$y]] as [$vx,$vy])
	$vertices .= pack('v2', $vx & 0xffff, $vy & 0xffff);

	foreach([[0,1],[1,2],[2,3],[3,0]] as $sideIndex => [$from,$to])
	{
		$key = "$roomIndex:$sideIndex";
		$right = intdiv(strlen($sides), 30);
		$isLinePortal = isset($portalLines[$key]);
		$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', $isLinePortal ? '-' : 'STARTAN3', $roomIndex);
		$left = 0xffff;

		if($isLinePortal)
		{
			$left = intdiv(strlen($sides), 30);
			$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', '-', $roomIndex);
			[$destinationId,$currentId,$type,$alignment] = $portalLines[$key];
			$action = 156;
			$args = [$destinationId,$currentId,$type,$alignment,0];
			$flags = 4;
		}
		elseif(isset($sectorPortalLines[$key]))
		{
			$action = 57;
			$args = $sectorPortalLines[$key];
			$flags = 1;
		}
		else
		{
			$action = 0;
			$args = [0,0,0,0,0];
			$flags = 1;
		}

		$lineIndex = intdiv(strlen($lines), 16);

		$lines .= pack('v3C6v2', ...[
			$firstVertex + $from, $firstVertex + $to, $flags
			, $action, ...$args, $right, $left
		]);

		$segs .= pack('v6', $firstVertex + $from, $firstVertex + $to, 0, $lineIndex, 0, 0);
	}

	$sectors .= pack('v2a8a8v3', $floor & 0xffff, $ceiling & 0xffff, 'FLOOR0_1', 'CEIL3_5', $light, 0, $tag);
}

$things = '';

$thing = static function(
	int $id
	, int $x
	, int $y
	, int $z
	, int $angle
	, int $type
	, array $args = [0,0,0,0,0]
) use (&$things): void {
	$things .= pack('v7C6', $id, $x & 0xffff, $y & 0xffff, $z & 0xffff, $angle, $type, 1799, 0, ...$args);
};

$thing(0, 64, 128, 0, 0, 1); // Player one starts facing the first line portal.
$thing(0, 640, 128, 0, 180, 2035);
$thing(0, 1088, 64, 0, 0, 2028); // Keep the line-portal route's center clear.
$thing(77, 640, 640, 0, 0, 9077); // Upper stack viewpoint: its sector floor looks down.
$thing(77, 640, 1152, 0, 0, 9078); // Separate lower room prevents two views into F.
$thing(0, 1088, 608, 0, 0, 2035);
$thing(0, 1216, 704, 0, 0, 35); // Doom 1 candelabra; avoid Doom 2-only TechLamp's invisible solid body.

$subsectors = '';

foreach(array_keys($rooms) as $roomIndex) $subsectors .= pack('v2', 4, $roomIndex * 4);

$bounds = [1280,0,0,1280]; // top, bottom, left, right; conservative for this tiny BSP.

$node = static function(int $x, int $y, int $dx, int $dy, int $right, int $left) use (&$bounds): string {
	return pack('v14', ...[
		$x & 0xffff, $y & 0xffff, $dx & 0xffff, $dy & 0xffff
		, ...array_map(static fn(int $value): int => $value & 0xffff, [...$bounds,...$bounds])
		, $right, $left
	]);
};

$nodes = $node(0, 384, 1280, 0, 0x8000, 0x8003) // A / D
		. $node(512, 896, 768, 0, 0x8004, 0x8006) // E / G
		. $node(512, 384, 768, 0, 0x8001, 1) // B / (E and G)
		. $node(384, 0, 0, 1280, 2, 0) // first two columns
		. $node(1024, 896, 256, 0, 0x8005, 0x8007) // F / H
		. $node(1024, 384, 256, 0, 0x8002, 4) // C / (F and H)
		. $node(896, 0, 0, 1280, 5, 3); // root: final column / first two columns

$lumps = [
	'PORTALS' => ''
	, 'THINGS' => $things
	, 'LINEDEFS' => $lines
	, 'SIDEDEFS' => $sides
	, 'VERTEXES' => $vertices
	, 'SEGS' => $segs
	, 'SSECTORS' => $subsectors
	, 'NODES' => $nodes
	, 'SECTORS' => $sectors
	, 'REJECT' => ''
	, 'BLOCKMAP' => ''
	, 'BEHAVIOR' => "ACS\0" . pack('V2', 8, 0)
];

$firstMapLumps = $lumps;

/**
 * Build a second map from rectangular convex cells. The two rooms deliberately
 * occupy disconnected map space: the floor/ceiling and hallway portals are the
 * only paths between them.
 *
 * @return array<string,string>
 */
$buildPortalRooms = static function(): array {
	$cells = [];

	$addCell = static function(
		int $x0
		, int $y0
		, int $x1
		, int $y1
		, int $floor
		, int $ceiling
		, int $light
	) use (&$cells): void {
		$cells[] = compact('x0', 'y0', 'x1', 'y1', 'floor', 'ceiling', 'light');
	};

	// Each 768-square room is a 3x3 grid. Its center cell is therefore exactly
	// one ninth of the room. The upper center floor is recessed by 16 map units.
	foreach([0,256,512] as $y)
	foreach([0,256,512] as $x)
	$addCell($x, $y, $x + 256, $y + 256, $x === 256 && $y === 256 ? 112 : 128, 384, 176);
	$addCell(768, 256, 1280, 512, 128, 384, 144); // Upper east hallway.

	foreach([1536,1792,2048] as $y)
	foreach([0,256,512] as $x)
	$addCell($x, $y, $x + 256, $y + 256, -144, 112, 112);
	// Put the return hallway on the opposite side of its room. A line portal
	// transforms the source front side to the destination back side; placing both
	// halls west of equally wound seams sends the remote camera into the void.
	$addCell(-512, 1792, 0, 2048, -144, 112, 96); // Lower west hallway.

	$vertexIds = $vertexRows = [];

	$vertex = static function(int $x, int $y) use (&$vertexIds, &$vertexRows): int {
		$key = "$x:$y";

		if(isset($vertexIds[$key])) return $vertexIds[$key];

		$vertexIds[$key] = count($vertexRows);
		$vertexRows[] = [$x,$y];

		return $vertexIds[$key];
	};

	$edgeKey = static function(int $a, int $b): string { return $a < $b ? "$a:$b" : "$b:$a"; };

	$edges = $cellSegments = [];

	foreach($cells as $sector => $cell)
	{
		$corners = [
			[$cell['x0'],$cell['y0']], [$cell['x0'],$cell['y1']]
			, [$cell['x1'],$cell['y1']], [$cell['x1'],$cell['y0']]
		];

		$cellSegments[$sector] = [];

		foreach([[0,1],[1,2],[2,3],[3,0]] as [$from,$to])
		{
			$a = $vertex(...$corners[$from]);
			$b = $vertex(...$corners[$to]);
			$key = $edgeKey($a, $b);

			if(!isset($edges[$key]))
			{
				$edges[$key] = [
					'start' => $a
					, 'end' => $b
					, 'right' => $sector
					, 'left' => null
					, 'action' => 0
					, 'args' => [0,0,0,0,0]
				];

				$direction = 0;
			}
			else
			{
				if($edges[$key]['start'] !== $b || $edges[$key]['end'] !== $a || $edges[$key]['left'] !== null)
				throw new RuntimeException("Invalid shared edge $key in PORTAL2");

				$edges[$key]['left'] = $sector;
				$direction = 1;
			}

			$cellSegments[$sector][] = ['edge' => $key, 'direction' => $direction];
		}
	}

	// The boundary portal lines are made two-sided with their own sector on both
	// sides, matching Hexen's passable Line_SetPortal requirements.
	$hallPortals = [
		[[1280,256],[1280,512],[202,201,1,1,0]]
		, [[-512,1792],[-512,2048],[201,202,1,1,0]]
	];

	foreach($hallPortals as [$aPoint,$bPoint,$args])
	{
		$a = $vertex(...$aPoint);
		$b = $vertex(...$bPoint);
		$key = $edgeKey($a, $b);

		if(!isset($edges[$key]) || $edges[$key]['left'] !== null)
		throw new RuntimeException("Missing hallway boundary $key in PORTAL2");

		$edges[$key]['left'] = $edges[$key]['right'];
		$edges[$key]['action'] = 156;
		$edges[$key]['args'] = $args;
	}

	// Linked Sector_SetPortal controls make the center planes traversable. These
	// two-sided control lines live inside their sectors and are not BSP segments;
	// they only provide the anchor/reference positions used by the Hexen special.
	$controlLines = [
		['sector' => 4, 'from' => [320,320], 'to' => [448,320], 'args' => [60,6,0,0,0]]
		, ['sector' => 14, 'from' => [320,1856], 'to' => [448,1856], 'args' => [60,6,0,1,0]]
		, ['sector' => 14, 'from' => [320,1888], 'to' => [448,1888], 'args' => [50,6,1,0,0]]
		, ['sector' => 4, 'from' => [320,352], 'to' => [448,352], 'args' => [50,6,1,1,0]]
	];

	foreach($controlLines as &$control)
	{
		$control['from'] = $vertex(...$control['from']);
		$control['to'] = $vertex(...$control['to']);
	}

	unset($control);

	$vertices = '';

	foreach($vertexRows as [$x,$y]) $vertices .= pack('v2', $x & 0xffff, $y & 0xffff);

	$sides = $lines = '';
	$lineIds = [];

	foreach($edges as $key => $edge)
	{
		$lineIds[$key] = intdiv(strlen($lines), 16);
		$twoSided = $edge['left'] !== null;
		$right = intdiv(strlen($sides), 30);
		$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', $twoSided ? '-' : 'STARTAN3', $edge['right']);
		$left = 0xffff;

		if($twoSided)
		{
			$left = intdiv(strlen($sides), 30);
			$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', '-', $edge['left']);
		}

		$lines .= pack('v3C6v2', ...[
			$edge['start'],$edge['end'],$twoSided ? 4 : 1
			, $edge['action'], ...$edge['args'], $right,$left
		]);
	}

	foreach($controlLines as $control)
	{
		$right = intdiv(strlen($sides), 30);
		$sides .= pack('v2a8a8a8v', 0, 0, '-', '-', '-', $control['sector']);
		$left = intdiv(strlen($sides), 30);
		$sides .= pack('v2a8a8a8v', 0, 0, '-', '-', '-', $control['sector']);

		$lines .= pack('v3C6v2', ...[
			$control['from'],$control['to'],4,57,...$control['args'],$right,$left
		]);
	}

	$segs = $subsectors = '';

	foreach($cellSegments as $segments)
	{
		$first = intdiv(strlen($segs), 12);

		foreach($segments as $segment)
		{
			$edge = $edges[$segment['edge']];
			$start = $segment['direction'] ? $edge['end'] : $edge['start'];
			$end = $segment['direction'] ? $edge['start'] : $edge['end'];
			$segs .= pack('v6', $start, $end, 0, $lineIds[$segment['edge']], $segment['direction'], 0);
		}

		$subsectors .= pack('v2', count($segments), $first);
	}

	$sectors = '';

	foreach($cells as $sector => $cell)
	$sectors .= pack(
		'v2a8a8v3'
		, $cell['floor'] & 0xffff
		, $cell['ceiling'] & 0xffff
		, 'FLOOR0_1'
		, 'CEIL3_5'
		, $cell['light']
		, 0
		, $sector === 4 ? 60 : ($sector === 14 ? 50 : 0)
	);

	$things = '';

	$thing = static function(
		int $id
		, int $x
		, int $y
		, int $z
		, int $angle
		, int $type
		, array $args = [0,0,0,0,0]
	) use (&$things): void {
		$things .= pack('v7C6', $id, $x & 0xffff, $y & 0xffff, $z & 0xffff, $angle, $type, 1799, 0, ...$args);
	};

	$thing(0, 128, 384, 0, 0, 1); // Face the recessed portal and upper hallway.
	$thing(0, 128, 128, 0, 0, 2035);
	$thing(0, 640, 640, 0, 0, 35);
	$thing(0, 128, 1664, 0, 0, 35);
	$thing(0, 640, 2176, 0, 0, 2035);

	// Recursively partition whole rectangular cells. Every leaf points at the
	// corresponding four-segment subsector; nodes are emitted post-order so the
	// final record is the BSP root expected by Doom map readers.
	$nodeRows = [];
	$buildNode = null;

	$buildNode = static function(array $ids) use (&$buildNode, &$nodeRows, $cells): int {
		if(count($ids) === 1) return 0x8000 | $ids[0];

		$candidates = [];
		$xValues = $yValues = [];

		foreach($ids as $id)
		{
			$xValues[] = $cells[$id]['x0'];
			$xValues[] = $cells[$id]['x1'];
			$yValues[] = $cells[$id]['y0'];
			$yValues[] = $cells[$id]['y1'];
		}

		foreach(array_values(array_unique($xValues)) as $split)
		{
			$west = $east = [];
			$valid = true;

			foreach($ids as $id)
			{
				if($cells[$id]['x1'] <= $split) $west[] = $id;
				elseif($cells[$id]['x0'] >= $split) $east[] = $id;
				else
				{
					$valid = false;
					break;
				}
			}

			if($valid && $west && $east)
			$candidates[] = [
				'score' => abs(count($west) - count($east))
				, 'axis' => 'x'
				, 'split' => $split
				, 'low' => $west
				, 'high' => $east
			];
		}

		foreach(array_values(array_unique($yValues)) as $split)
		{
			$south = $north = [];
			$valid = true;

			foreach($ids as $id)
			{
				if($cells[$id]['y1'] <= $split) $south[] = $id;
				elseif($cells[$id]['y0'] >= $split) $north[] = $id;
				else
				{
					$valid = false;
					break;
				}
			}

			if($valid && $south && $north)
			$candidates[] = [
				'score' => abs(count($south) - count($north))
				, 'axis' => 'y'
				, 'split' => $split
				, 'low' => $south
				, 'high' => $north
			];
		}

		if(!$candidates) throw new RuntimeException('Cannot partition PORTAL2 cells');

		usort($candidates, static fn(array $a, array $b): int => $a['score'] <=> $b['score']);
		$choice = $candidates[0];
		$low = $buildNode($choice['low']);
		$high = $buildNode($choice['high']);

		if($choice['axis'] === 'x')
		$nodeRows[] = [$choice['split'],0,0,1,$high,$low]; // east/right, west/left
		else
		$nodeRows[] = [0,$choice['split'],1,0,$low,$high]; // south/right, north/left

		return count($nodeRows) - 1;
	};

	$buildNode(array_keys($cells));
	$bounds = [2304,0,-512,1280];
	$nodes = '';

	foreach($nodeRows as [$x,$y,$dx,$dy,$right,$left])
	$nodes .= pack('v14', ...[
		$x & 0xffff,$y & 0xffff,$dx & 0xffff,$dy & 0xffff
		, ...array_map(static fn(int $value): int => $value & 0xffff, [...$bounds,...$bounds])
		, $right,$left
	]);

	return [
		'PORTAL2' => ''
		, 'THINGS' => $things
		, 'LINEDEFS' => $lines
		, 'SIDEDEFS' => $sides
		, 'VERTEXES' => $vertices
		, 'SEGS' => $segs
		, 'SSECTORS' => $subsectors
		, 'NODES' => $nodes
		, 'SECTORS' => $sectors
		, 'REJECT' => ''
		, 'BLOCKMAP' => ''
		, 'BEHAVIOR' => "ACS\0" . pack('V2', 8, 0)
	];
};

/** @return array<string,string> One room exercising camera textures and Line_Mirror. */
$buildCameraRoom = static function(): array {
	// Keep the UDB-authored sector indices stable. Four short sectors bridge the
	// recessed camera trench to the room; the middle pair carries the south
	// PHPINFO controls and the north pair carries the matching controls.
	$cells = [
		['x0' => 0,   'y0' => 0,   'x1' => 128, 'y1' => 192, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 128,'y0' => 192, 'x1' => 192, 'y1' => 480, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 192,'y0' => 224, 'x1' => 320, 'y1' => 480, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 320,'y0' => 192, 'x1' => 384, 'y1' => 480, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 384,'y0' => 192, 'x1' => 512, 'y1' => 480, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 0,   'y0' => 480, 'x1' => 128, 'y1' => 512, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 128,'y0' => 480, 'x1' => 192, 'y1' => 512, 'floor' => 48,  'ceiling' => 256]
		, ['x0' => 192,'y0' => 480, 'x1' => 320, 'y1' => 512, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 320,'y0' => 480, 'x1' => 384, 'y1' => 512, 'floor' => 48,  'ceiling' => 256]
		, ['x0' => 384,'y0' => 480, 'x1' => 512, 'y1' => 512, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 192,'y0' => 192, 'x1' => 224, 'y1' => 224, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 288,'y0' => 192, 'x1' => 320, 'y1' => 224, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 192,'y0' => 0,   'x1' => 320, 'y1' => 192, 'floor' => -32, 'ceiling' => 256]
		, ['x0' => 128,'y0' => 0,   'x1' => 192, 'y1' => 192, 'floor' => -16, 'ceiling' => 256]
		, ['x0' => 0,   'y0' => 192, 'x1' => 128, 'y1' => 480, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 384,'y0' => 0,   'x1' => 512, 'y1' => 192, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 320,'y0' => 0,   'x1' => 384, 'y1' => 192, 'floor' => -16, 'ceiling' => 256]
		, ['x0' => 224,'y0' => 192, 'x1' => 256, 'y1' => 224, 'floor' => 0,   'ceiling' => 256]
		, ['x0' => 256,'y0' => 192, 'x1' => 288, 'y1' => 224, 'floor' => 0,   'ceiling' => 256]
	];

	$vertexRows = [];
	$vertexIds = [];

	$vertex = static function(int $x, int $y) use (&$vertexRows, &$vertexIds): int {
		$key = "$x:$y";

		if(!isset($vertexIds[$key]))
		{
			$vertexIds[$key] = count($vertexRows);
			$vertexRows[] = [$x,$y];
		}

		return $vertexIds[$key];
	};

	$edgeKey = static fn(int $a, int $b): string => min($a, $b) . ':' . max($a, $b);
	$edges = [];
	$cellSegments = array_fill(0, count($cells), []);

	foreach($cells as $cell)
	foreach([
		[$cell['x0'],$cell['y0']]
		, [$cell['x0'],$cell['y1']]
		, [$cell['x1'],$cell['y1']]
		, [$cell['x1'],$cell['y0']]
	] as [$x,$y])
	$vertex($x, $y);

	// Build the recessed sector first so every switch line's front/right side is
	// the lower, usable face. Subsector output remains in numeric sector order.
	$edgeBuildOrder = [12,...array_values(array_diff(array_keys($cells), [12]))];

	foreach($edgeBuildOrder as $sector)
	{
		$cell = $cells[$sector];
		$corners = [
			[$cell['x0'],$cell['y0']], [$cell['x0'],$cell['y1']]
			, [$cell['x1'],$cell['y1']], [$cell['x1'],$cell['y0']]
		];

		foreach([[0,1],[1,2],[2,3],[3,0]] as [$from,$to])
		{
			[$fromX,$fromY] = $corners[$from];
			[$toX,$toY] = $corners[$to];

			$points = array_values(
				array_filter(
					$vertexRows
					, static fn(array $point): bool => ($fromX === $toX
						? $point[0] === $fromX && $point[1] >= min($fromY, $toY) && $point[1] <= max($fromY, $toY)
						: $point[1] === $fromY && $point[0] >= min($fromX, $toX) && $point[0] <= max($fromX, $toX)
					)
				)
			);

			usort($points, static function(array $a, array $b) use ($fromX, $fromY, $toX, $toY): int {
				$axis = $fromX === $toX ? 1 : 0;
				$direction = ($axis ? $toY - $fromY : $toX - $fromX) <=> 0;

				return $direction * ($a[$axis] <=> $b[$axis]);
			});

			for($point = 0; $point + 1 < count($points); $point++)
			{
				$a = $vertex(...$points[$point]);
				$b = $vertex(...$points[$point + 1]);
				$key = $edgeKey($a, $b);

				if(!isset($edges[$key]))
				{
					$edges[$key] = [
						'start' => $a
						, 'end' => $b
						, 'right' => $sector
						, 'left' => null
						, 'action' => 0
						, 'args' => [0,0,0,0,0]
						, 'flags' => 1
						, 'rightXOffset' => 0
						, 'rightYOffset' => 0
						, 'leftXOffset' => 0
						, 'rightTextures' => ['upper' => 'STARTAN3','lower' => 'STARTAN3','middle' => 'STARTAN3']
						, 'leftTextures' => ['upper' => 'STARTAN3','lower' => 'STARTAN3','middle' => '-']
					];

					$direction = 0;
				}
				else
				{
					if($edges[$key]['start'] !== $b || $edges[$key]['end'] !== $a || $edges[$key]['left'] !== null)
					throw new RuntimeException("Invalid shared edge $key in CAMERAS");

					$edges[$key]['left'] = $sector;
					$edges[$key]['flags'] = 4;
					$edges[$key]['rightTextures']['middle'] = '-';
					$direction = 1;
				}

				$cellSegments[$sector][] = ['edge' => $key, 'direction' => $direction];
			}
		}
	}

	foreach($edges as &$edge)
	{
		[$ax,$ay] = $vertexRows[$edge['start']];
		[$bx,$by] = $vertexRows[$edge['end']];
		$minX = min($ax, $bx);
		$maxX = max($ax, $bx);
		$minY = min($ay, $by);
		$maxY = max($ay, $by);

		if($edge['left'] === null && $minY === 512)
		{
			$edge['rightTextures']['middle'] = 'PHPINFO';
			// The north wall is split around the raised switches. Continue the
			// 512-unit DOM texture instead of restarting it on every linedef.
			$edge['rightXOffset'] = $minX;
		}
		elseif($edge['left'] === null && $minX === 512)
		$edge['rightTextures']['middle'] = 'CAMTEX';
		elseif($edge['left'] === null && $ay === $by && $minY === 0
			&& $minX === 192 && $maxX === 320
		){
			$edge['rightTextures']['middle'] = '-';
			$edge['action'] = 182;
		}

		if($edge['left'] !== null && $ay === $by)
		{
			$script = $minY === 480 && $minX === 128 && $maxX === 192
				? 2
				: ($minY === 480 && $minX === 320 && $maxX === 384
					? 3
					: ($minY === 192 && $minX === 224 && $maxX === 256
						? 2
						: ($minY === 192 && $minX === 256 && $maxX === 288 ? 3 : 0)
					)
				);

			if($script)
			{
				$edge['action'] = 80; // ACS_Execute: safe source parser binds ScrollDomTexture.
				$edge['args'] = [$script,0,0,0,0];
				$edge['flags'] = 4 | 512 | (1 << 10); // two-sided, repeatable, player-use.
				$edge['rightTextures']['lower'] = 'SW1COMP';
				$edge['rightXOffset'] = $minY === 192 ? 16 : 0;
				$edge['rightYOffset'] = $minY === 192 ? 72 : 64;
			}
		}
	}

	unset($edge);

	$vertices = '';

	foreach($vertexRows as [$x,$y]) $vertices .= pack('v2', $x & 0xffff, $y & 0xffff);

	$sides = $lines = '';
	$lineIds = [];

	foreach($edges as $key => $edge)
	{
		$lineIds[$key] = intdiv(strlen($lines), 16);
		$right = intdiv(strlen($sides), 30);
		$texture = $edge['rightTextures'];

		$sides .= pack(
			'v2a8a8a8v'
			, $edge['rightXOffset'] & 0xffff
			, $edge['rightYOffset'] & 0xffff
			, $texture['upper']
			, $texture['lower']
			, $texture['middle']
			, $edge['right']
		);

		$left = 0xffff;

		if($edge['left'] !== null)
		{
			$left = intdiv(strlen($sides), 30);
			$texture = $edge['leftTextures'];

			$sides .= pack(
				'v2a8a8a8v'
				, $edge['leftXOffset'] & 0xffff
				, 0
				, $texture['upper']
				, $texture['lower']
				, $texture['middle']
				, $edge['left']
			);
		}

		$lines .= pack('v3C6v2', ...[
			$edge['start']
			, $edge['end']
			, $edge['flags']
			, $edge['action']
			, ...$edge['args']
			, $right
			, $left
		]);
	}

	$segs = $subsectors = '';

	foreach($cellSegments as $segments)
	{
		$first = intdiv(strlen($segs), 12);

		foreach($segments as $segment)
		{
			$edge = $edges[$segment['edge']];
			$start = $segment['direction'] ? $edge['end'] : $edge['start'];
			$end = $segment['direction'] ? $edge['start'] : $edge['end'];
			$segs .= pack('v6', $start, $end, 0, $lineIds[$segment['edge']], $segment['direction'], 0);
		}

		$subsectors .= pack('v2', count($segments), $first);
	}

	$sectors = '';

	foreach($cells as $cell)
	$sectors .= pack('v2a8a8v3', $cell['floor'], $cell['ceiling'], 'FLOOR0_1', 'CEIL3_5', 176, 0, 0);

	$things = '';

	$thing = static function(
		int $id
		, int $x
		, int $y
		, int $z
		, int $angle
		, int $type
		, array $args = [0,0,0,0,0]
	) use (&$things): void {
		$things .= pack('v7C6', $id, $x, $y, $z, $angle, $type, 1799, 0, ...$args);
	};

	$thing(0, 256, 96, 0, 0, 1); // Face the camera monitor on the east wall.
	$thing(100, 384, 128, 56, 135, 9025, [0,45,16,0,0]);
	$thing(0, 128, 384, 0, 0, 2035);
	$thing(0, 384, 384, 0, 0, 35);

	$nodeRows = [];
	$buildNode = null;

	$buildNode = static function(array $ids) use (&$buildNode, &$nodeRows, $cells): int {
		if(count($ids) === 1) return 0x8000 | $ids[0];

		$candidates = [];
		$xValues = $yValues = [];

		foreach($ids as $id)
		{
			$xValues[] = $cells[$id]['x0'];
			$xValues[] = $cells[$id]['x1'];
			$yValues[] = $cells[$id]['y0'];
			$yValues[] = $cells[$id]['y1'];
		}

		foreach(array_values(array_unique($xValues)) as $split)
		{
			$west = $east = [];
			$valid = true;

			foreach($ids as $id)
			{
				if($cells[$id]['x1'] <= $split) $west[] = $id;
				elseif($cells[$id]['x0'] >= $split) $east[] = $id;
				else
				{
					$valid = false;
					break;
				}
			}

			if($valid && $west && $east)
			$candidates[] = [
				'score' => abs(count($west) - count($east))
				, 'axis' => 'x'
				, 'split' => $split
				, 'low' => $west
				, 'high' => $east
			];
		}

		foreach(array_values(array_unique($yValues)) as $split)
		{
			$south = $north = [];
			$valid = true;

			foreach($ids as $id)
			{
				if($cells[$id]['y1'] <= $split) $south[] = $id;
				elseif($cells[$id]['y0'] >= $split) $north[] = $id;
				else
				{
					$valid = false;
					break;
				}
			}

			if($valid && $south && $north)
			$candidates[] = [
				'score' => abs(count($south) - count($north))
				, 'axis' => 'y'
				, 'split' => $split
				, 'low' => $south
				, 'high' => $north
			];
		}

		if(!$candidates) throw new RuntimeException('Cannot partition CAMERAS cells');

		usort($candidates, static fn(array $a, array $b): int => $a['score'] <=> $b['score']);
		$choice = $candidates[0];
		$low = $buildNode($choice['low']);
		$high = $buildNode($choice['high']);

		if($choice['axis'] === 'x') $nodeRows[] = [$choice['split'],0,0,1,$high,$low];
		else $nodeRows[] = [0,$choice['split'],1,0,$low,$high];

		return count($nodeRows) - 1;
	};

	$buildNode(array_keys($cells));
	$bounds = [512,0,0,512];
	$nodes = '';

	foreach($nodeRows as [$x,$y,$dx,$dy,$right,$left])
	$nodes .= pack('v14', ...[
		$x & 0xffff,$y & 0xffff,$dx & 0xffff,$dy & 0xffff
		, ...array_map(static fn(int $value): int => $value & 0xffff, [...$bounds,...$bounds])
		, $right,$left
	]);

	return [
		'CAMERAS' => ''
		, 'THINGS' => $things
		, 'LINEDEFS' => $lines
		, 'SIDEDEFS' => $sides
		, 'VERTEXES' => $vertices
		, 'SEGS' => $segs
		, 'SSECTORS' => $subsectors
		, 'NODES' => $nodes
		, 'SECTORS' => $sectors
		, 'REJECT' => ''
		, 'BLOCKMAP' => ''
		, 'BEHAVIOR' => "ACS\0" . pack('V2', 8, 0)
		, 'SCRIPTS' => "script 1 OPEN\n{\n\tSetCameraToTexture(100, \"CAMTEX\", 90);\n}\n"
			. "script 2 (void)\n{\n\tScrollDomTexture(\"PHPINFO\", -96);\n}\n"
			. "script 3 (void)\n{\n\tScrollDomTexture(\"PHPINFO\", 96);\n}\n"
	];
};

$lumps = [
	[
		'ANIMDEFS'
		, <<<ANIMDEFS
cameratexture CAMTEX 128 128 FIT 128 128 WorldPanning
domtexture PHPINFO 512 256 FIT 512 256 WorldPanning
ANIMDEFS
	]
];

$buildPool = require __DIR__ . '/generate-pool.php';
require_once __DIR__ . '/LayeredPrototype.php';

foreach([$firstMapLumps,$buildPortalRooms(),$buildCameraRoom(),$buildPool(),\DoomPhonics\Tools\LayeredPrototype::build()['lumps']] as $mapLumps)
foreach($mapLumps as $name => $bytes) $lumps[] = [$name,$bytes];
$data = $directory = '';

foreach($lumps as [$name,$bytes])
{
	$directory .= pack('V2a8', 12 + strlen($data), strlen($bytes), $name);
	$data .= $bytes;
}

$wad = 'PWAD' . pack('V2', count($lumps), 12 + strlen($data)) . $data . $directory;
$folder = dirname($destination);

if(!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder))
throw new RuntimeException("Cannot create $folder");

if(file_put_contents($destination, $wad) !== strlen($wad))
throw new RuntimeException("Cannot write $destination");

// Keep direct-launch worlds usable after regenerating the checked-in WAD.
// An alternate output path leaves both the demo and its world definitions alone.
if(realpath($destination) === realpath(dirname(__DIR__) . '/demo/PORTALS.WAD'))
{
	$integrity = 'sha256-' . base64_encode(hash('sha256', $wad, true));
	foreach(glob(dirname(__DIR__) . '/demo/*.phonics-world.json') as $file)
	{
		$json = file_get_contents($file);
		$world = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		foreach($world['sources'] ?? [] as $source)
		foreach($source['resources'] ?? [] as $resource)
		if(($resource['url'] ?? null) === './PORTALS.WAD' && isset($resource['integrity']))
		$json = str_replace($resource['integrity'], $integrity, $json);
		if($json !== file_get_contents($file)) file_put_contents($file, $json);
	}
}

fwrite(STDOUT, "Generated $destination (" . strlen($wad) . " bytes).\n");

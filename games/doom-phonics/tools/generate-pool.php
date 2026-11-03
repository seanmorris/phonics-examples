<?php
declare(strict_types=1);

require_once __DIR__ . '/Staircase.php';
require_once __DIR__ . '/RectangularCells.php';
require_once __DIR__ . '/WaterFlat.php';

use DoomPhonics\Tools\Staircase;
use DoomPhonics\Tools\RectangularCells;

/**
 * Build the POOL3D Hexen demo with liquid, drain/refill controls, and stacked rooms.
 *
 * Return a reusable closure producing map-name/lump-byte pairs for the PWAD writer
 * in generate-portals.php. The cell layout and model sectors below are the source
 * of truth; generation needs no IWAD, browser runtime, or external node builder.
 * See the package README's portal demonstration section for tags and safe output.
 */
return static function(): array {
	$cells = [];
	// A continuous pool occupies the center of the room. The north stepping
	// stones and floating platform are volumes above the same water column.
	for($row = 0; $row < 9; $row++)
	for($column = 0; $column < 9; $column++)
	{
		$pool = $row > 0 && $row < 8 && $column > 0 && $column < 8;
		$floor = $pool ? -192 : 16;
		$tag = $pool ? 100 : 0;
		if($pool && $row >= 4 && $row <= 5 && $column >= 4 && $column <= 5) $tag = 101;
		if($row === 7 && $column === 4) $tag = 102;
		if($row === 6 && $column === 4) $tag = 103;
		$cells[] = ['x0' => $column * 128,'y0' => $row * 128,
			'x1' => ($column + 1) * 128,'y1' => ($row + 1) * 128,
			'floor' => $floor,'ceiling' => 320,'light' => 192,'tag' => $tag,
			'floorTexture' => $pool ? 'FLOOR0_1' : 'FLAT5_4','ceilingTexture' => 'CEIL3_5'];
	}
	// Twelve rectangular treads join the bottom to the west rim in 16-unit rises.
	$cells = Staircase::carve($cells, Staircase::treads(
		x: 896, y: 512, fromHeight: -192, toHeight: 16,
		rise: 16, run: 64, width: 128, direction: 'west'
	));
	$cells = RectangularCells::merge($cells);
	// Each gallery uses five rectangles around an opening matching the pool.
	// Separate map-space rooms become three physical storeys through portals.
	$upperOffset = 1536;
	$galleryStarts = [];
	foreach([1 => 150,2 => 160] as $storey => $portalTag)
	{
		$galleryStarts[$storey] = count($cells);
		$offset = $upperOffset * $storey;
		$floor = 320 + ($storey - 1) * 256;
		foreach([[0,0,1152,128,0],[0,128,128,1024,0],[128,128,1024,1024,$portalTag],
			[1024,128,1152,1024,0],[0,1024,1152,1152,0]] as [$x0,$y0,$x1,$y1,$tag])
		$cells[] = ['x0' => $x0,'y0' => $y0 + $offset,'x1' => $x1,'y1' => $y1 + $offset,
			'floor' => $floor,'ceiling' => $floor + 256,'light' => 224,'tag' => $tag,
			'floorTexture' => 'FLAT5_4','ceilingTexture' => 'CEIL3_5'];
	}
	$modelStart = count($cells);
	// Separate model sectors: water, main platform, and two approach stones.
	foreach([[-192,0,200,'P3WATER'],[56,80,0,'FLAT5_4'],[8,32,0,'FLAT5_4'],[32,56,0,'FLAT5_4']] as $i => [$floor,$ceiling,$tag,$texture])
	$cells[] = ['x0' => 1536 + $i * 128,'y0' => 0,'x1' => 1600 + $i * 128,'y1' => 64,
		'floor' => $floor,'ceiling' => $ceiling,'light' => $i ? 192 : 144,'tag' => $tag,
		'floorTexture' => $texture,'ceilingTexture' => $texture];

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
	$horizontal = $vertical = [];
	// Preserve the two 128-unit switch faces even when the north rim is one sector.
	foreach([256,384,768,896] as $x)
	{
		$id = $vertex($x,1152);
		$horizontal[1152][$x] = $id;
		$vertical[$x][1152] = $id;
	}
	foreach($cells as $cell)
	foreach([$cell['x0'],$cell['x1']] as $x)
	foreach([$cell['y0'],$cell['y1']] as $y)
	{
		$id = $vertex($x,$y);
		$horizontal[$y][$x] = $id;
		$vertical[$x][$y] = $id;
	}
	foreach($horizontal as &$points) ksort($points, SORT_NUMERIC);
	foreach($vertical as &$points) ksort($points, SORT_NUMERIC);
	unset($points);

	foreach($cells as $sector => $cell)
	{
		$corners = [
			[$cell['x0'],$cell['y0']], [$cell['x0'],$cell['y1']]
			, [$cell['x1'],$cell['y1']], [$cell['x1'],$cell['y0']]
		];

		$cellSegments[$sector] = [];

		foreach([[0,1],[1,2],[2,3],[3,0]] as [$from,$to])
		{
			[$ax,$ay] = $corners[$from];
			[$bx,$by] = $corners[$to];
			$points = $ax === $bx ? $vertical[$ax] : $horizontal[$ay];
			$start = $ax === $bx ? $ay : $ax;
			$end = $ax === $bx ? $by : $bx;
			// Split long edges at neighboring tread corners so T-junctions share
			// the same two-sided linedefs instead of leaving invisible barriers.
			$points = array_values(array_filter($points,
				static fn(int $coordinate): bool => $coordinate >= min($start,$end) && $coordinate <= max($start,$end),
				ARRAY_FILTER_USE_KEY));
			if($start > $end) $points = array_reverse($points);
			for($part = 1; $part < count($points); $part++)
			{
				$a = $points[$part - 1];
				$b = $points[$part];
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
					throw new RuntimeException("Invalid shared edge $key in POOL3D");

					$edges[$key]['left'] = $sector;
					$direction = 1;
				}

				$cellSegments[$sector][] = ['edge' => $key, 'direction' => $direction];
			}
		}
	}

	$waterEdge = 0;
	$solidModels = [];
	foreach($edges as &$edge)
	{
		$sector = $edge['right'];
		if($sector >= $modelStart)
		{
			$edge['action'] = 160;
			if($sector === $modelStart)
			{
				// Four edges target the four pool tags, all sharing this water model.
				$tag = 100 + $waterEdge++;
				$edge['args'] = [$tag,6,2,166,0];
			}
			else
			{
				$edge['args'] = [100 + $sector - $modelStart,1,0,255,0];
				// Only one control line per solid model, avoiding duplicate volumes.
				if(isset($solidModels[$sector])) $edge['action'] = 0;
				$solidModels[$sector] = true;
			}
		}
		[$ax,$ay] = $vertexRows[$edge['start']];
		[$bx,$by] = $vertexRows[$edge['end']];
		if($ay === 1152 && $by === 1152 && in_array(min($ax,$bx), [256,768], true))
		{
			$drain = min($ax,$bx) === 256;
			$edge['action'] = 47;
			$edge['args'] = [200,16,$drain ? 192 : 0,$drain ? 1 : 0,0];
			$edge['switch'] = $drain ? 'SW1BRCOM' : 'SW1COMP';
		}
	}
	unset($edge);

	$lowerAnchor = null;
	foreach($cells as $id => $cell)
	if($cell['x0'] < 320 && $cell['x1'] > 320 && $cell['y0'] < 320 && $cell['y1'] > 352)
	{
		$lowerAnchor = $id;
		break;
	}
	if($lowerAnchor === null) throw new RuntimeException('POOL3D portal anchor is outside the pool');
	$upperAnchor = $galleryStarts[1] + 2;
	$topAnchor = $galleryStarts[2] + 2;
	// Internal two-sided control lines supply Sector_SetPortal anchors, not BSP
	// edges. Copies share one ceiling link across every water/platform target.
	$controls = [
		[$lowerAnchor,320,[100,6,1,0,0]],
		[$upperAnchor,320 + $upperOffset,[100,6,1,1,0]],
		[$upperAnchor,352 + $upperOffset,[150,6,0,0,0]],
		[$lowerAnchor,352,[150,6,0,1,0]],
		[$upperAnchor,384 + $upperOffset,[150,6,1,0,0]],
		[$topAnchor,384 + 2 * $upperOffset,[150,6,1,1,0]],
		[$topAnchor,416 + 2 * $upperOffset,[160,6,0,0,0]],
		[$upperAnchor,416 + $upperOffset,[160,6,0,1,0]],
	];
	foreach([101,102,103] as $i => $tag) $controls[] = [$lowerAnchor,368 + $i * 16,[$tag,1,1,100,0]];
	$controlLines = [];
	foreach($controls as [$sector,$y,$args])
	$controlLines[] = ['sector' => $sector,'from' => $vertex(256,$y),'to' => $vertex(384,$y),'args' => $args];

	$vertices = '';

	foreach($vertexRows as [$x,$y]) $vertices .= pack('v2', $x & 0xffff, $y & 0xffff);

	$sides = $lines = '';
	$lineIds = [];

	foreach($edges as $key => $edge)
	{
		$lineIds[$key] = intdiv(strlen($lines), 16);
		$twoSided = $edge['left'] !== null;
		$right = intdiv(strlen($sides), 30);
		$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', $edge['switch'] ?? ($twoSided ? '-' : 'STARTAN3'), $edge['right']);
		$left = 0xffff;

		if($twoSided)
		{
			$left = intdiv(strlen($sides), 30);
			$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', '-', $edge['left']);
		}

		$lines .= pack('v3C6v2', ...[
			$edge['start'],$edge['end'],isset($edge['switch']) ? 1537 : ($twoSided ? 4 : 1)
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
		, $cell['floorTexture']
		, $cell['ceilingTexture']
		, $cell['light']
		, 0
		, $cell['tag']
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

	$thing(0, 576, 1088, 0, 270, 1);
	$thing(0, 576, 960, 224, 0, 2014); // Bonus on the first stepping stone.
	$thing(0, 704, 704, 272, 0, 2035); // Barrel on the floating platform.
	$thing(0, 192, 1088, 0, 0, 2003); // Rockets for testing slab collisions.
	$thing(0, 192, 960, 0, 0, 2046);
	foreach(array_keys($galleryStarts) as $storey)
	{
		$thing(0, 256, 64 + $storey * $upperOffset, 0, 0, 2028); // Lamps on each gallery rim.
		$thing(0, 896, 64 + $storey * $upperOffset, 0, 0, 2028);
	}

	// Recursively partition whole rectangular cells. Every leaf points at the
	// corresponding subsector; nodes are emitted post-order so the
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

		if(!$candidates) throw new RuntimeException('Cannot partition POOL3D cells');

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
	$bounds = [max(array_column($cells,'y1')),min(array_column($cells,'y0')),
		min(array_column($cells,'x0')),max(array_column($cells,'x1'))];
	$nodes = '';

	foreach($nodeRows as [$x,$y,$dx,$dy,$right,$left])
	$nodes .= pack('v14', ...[
		$x & 0xffff,$y & 0xffff,$dx & 0xffff,$dy & 0xffff
		, ...array_map(static fn(int $value): int => $value & 0xffff, [...$bounds,...$bounds])
		, $right,$left
	]);

	// An original, tileable 64x64 flat in Doom's blue palette ramp. Shareware
	// DOOM1 has no FWATER1, so the demo supplies its own surface without copying
	// any IWAD artwork. Integer waves keep regeneration byte-for-byte stable.
	$water = DoomPhonics\Tools\WaterFlat::bytes();

	return [
		'F_START' => ''
		, 'P3WATER' => $water
		, 'F_END' => ''
		, 'POOL3D' => ''
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

<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Encode compact rectangular cells as an original Hexen-format map.
 *
 * Shared edges are split at neighboring corners and authored action endpoints.
 * BSP construction requires a slicing layout; unsupported partitions fail instead
 * of emitting incorrect nodes. Arbitrary polygon subdivision is a separate task.
 */
final class RectangularMap
{
	/** Wrap ordered named lumps in a PWAD for the normal archive/map reader. */
	public static function archive(array $lumps): string
	{
		$data = $directory = '';
		foreach($lumps as $name => $bytes)
		{
			$directory .= pack('V2a8',12 + strlen($data),strlen($bytes),$name);
			$data .= $bytes;
		}
		return 'PWAD' . pack('V2',count($lumps),12 + strlen($data)) . $data . $directory;
	}

	/** Encode cells, boundary specials, internal controls, and [x,y,z,angle,type] things. */
	public static function build(string $name, array $cells, array $boundaries = [], array $controls = [], array $thingRows = []): array
	{
		if(!preg_match('/^[A-Z][A-Z0-9_]{0,7}$/D', $name) || !$cells)
		throw new \InvalidArgumentException('Invalid map name or empty map');
		foreach($cells as $i => $cell)
		{
			if($cell['x0'] >= $cell['x1'] || $cell['y0'] >= $cell['y1'] || $cell['floor'] > $cell['ceiling'])
			throw new \InvalidArgumentException("Invalid cell $i");
			foreach(['x0','x1','y0','y1','floor','ceiling'] as $field)
			if(!is_int($cell[$field]) || $cell[$field] < -32768 || $cell[$field] > 32767)
			throw new \InvalidArgumentException("Cell $i exceeds classic WAD coordinate range");
			foreach(array_slice($cells, 0, $i) as $other)
			if(max($cell['x0'],$other['x0']) < min($cell['x1'],$other['x1'])
				&& max($cell['y0'],$other['y0']) < min($cell['y1'],$other['y1']))
			throw new \InvalidArgumentException('Packed cells overlap');
		}
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
		foreach($boundaries as $boundary)
		foreach([$boundary['from'],$boundary['to']] as [$x,$y])
		{
			$id = $vertex($x,$y);
			$horizontal[$y][$x] = $id;
			$vertical[$x][$y] = $id;
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
						throw new \RuntimeException("Invalid shared edge $key in rectangular map");

						$edges[$key]['left'] = $sector;
						$direction = 1;
					}

					$cellSegments[$sector][] = ['edge' => $key, 'direction' => $direction];
				}
			}
		}

		foreach($boundaries as $boundary)
		{
			$key = $edgeKey($vertex(...$boundary['from']), $vertex(...$boundary['to']));
			if(!isset($edges[$key])) throw new \RuntimeException('Action boundary must match a complete split edge');
			$edges[$key]['action'] = $boundary['action'];
			$edges[$key]['args'] = $boundary['args'];
			if(isset($boundary['flags'])) $edges[$key]['flags'] = $boundary['flags'];
			if(isset($boundary['texture'])) $edges[$key]['switch'] = $boundary['texture'];
			if(isset($boundary['lowerTexture'])) $edges[$key]['lowerTexture'] = $boundary['lowerTexture'];
			if($boundary['portal'] ?? false)
			{
				if($edges[$key]['left'] !== null) throw new \RuntimeException('Wall portal requires an exterior boundary');
				$edges[$key]['left'] = $edges[$key]['right'];
			}
		}
		$controlLines = [];
		foreach($controls as $control)
		{
			$control['from'] = $vertex(...$control['from']);
			$control['to'] = $vertex(...$control['to']);
			$controlLines[] = $control;
		}
		$vertices = '';

		foreach($vertexRows as [$x,$y]) $vertices .= pack('v2', $x & 0xffff, $y & 0xffff);

		$sides = $lines = '';
		$lineIds = [];

		foreach($edges as $key => $edge)
		{
			$lineIds[$key] = intdiv(strlen($lines), 16);
			$twoSided = $edge['left'] !== null;
			$right = intdiv(strlen($sides), 30);
			$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', $edge['lowerTexture'] ?? 'STARTAN3', $edge['switch'] ?? ($twoSided ? '-' : 'STARTAN3'), $edge['right']);
			$left = 0xffff;

			if($twoSided)
			{
				$left = intdiv(strlen($sides), 30);
				$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', $edge['lowerTexture'] ?? 'STARTAN3', '-', $edge['left']);
			}

			$lines .= pack('v3C6v2', ...[
				$edge['start'],$edge['end'],$edge['flags'] ?? ($twoSided ? 4 : 1)
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
				$control['from'],$control['to'],4,$control['action'] ?? 57,...$control['args'],$right,$left
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
		foreach($thingRows as [$x,$y,$z,$angle,$type])
		$things .= pack('v7C6', 0,$x & 0xffff,$y & 0xffff,$z & 0xffff,$angle,$type,1799,0,0,0,0,0,0);

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

			if(!$candidates) throw new \RuntimeException('Rectangular BSP needs a full separating cut; split the crossing cell');

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

		return [
			$name => ''
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
	}
}

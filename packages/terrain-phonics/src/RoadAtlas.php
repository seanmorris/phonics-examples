<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Read-only float texture of road planes, indexed by world tile for clipmap generation. */
final class RoadAtlas
{
	public const MATERIALS = ['rock', 'grass', 'sand', 'snow', 'road'];

	/**
	 * Pack normalized road operations in application order. All pointers address RGBA texels. A sample takes three:
	 * the high parts of X and Z, height and tangent X; tangent Z, grade, bank and opacity; the low parts of X and Z.
	 * @param array $roads Committed splines with their derived sample planes.
	 * @param float $extent Width of the editable square in meters.
	 * @return array Float texture bytes, dimensions and spatial directory coordinates.
	 */
	public static function build(array $roads, float $extent): array
	{
		// A sample reaches at most nine tiles. Only occupied 8-by-8 tile blocks need leaves.
		$cell = 64.;
		foreach($roads as $op)
		{
			while($cell < $op['_road']['radius']) $cell *= 2;
		}
		$half = $extent / 2;
		$minTile = (int)floor(-$half / $cell);
		$maxTile = (int)floor($half / $cell);
		$blocksAcross = intdiv($maxTile - $minTile, 8) + 1;
		$data = str_repeat("\0", 16); // Zero is the absent pointer at both levels.
		$blocks = [];

		foreach($roads as $op)
		{
			$road = $op['_road'];
			$radius = $road['radius'];
			$inner2 = $road['half'] ** 2;
			$material = array_search($op['material'], self::MATERIALS, true);
			$params = intdiv(strlen($data), 16);
			$data .= pack('g4', $inner2, $radius ** 2 - $inner2, $op['crown'], $material === false ? 0 : $material + 1);
			foreach($road['samples'] as $i => [$x, $y, $z, $tx, $tz, $grade, $bank, $opacity])
			{
				// A 32-bit float rounds an absolute coordinate by up to 1 mm at 30 km, and the kernel weights turn that
				// into centimetres of height. Store each of X and Z as the float nearest to it plus the remainder, so
				// the shader can subtract both and stay within a micron of PHP anywhere in a 65.5 km world.
				[, $xHigh] = unpack('g', pack('g', $x));
				[, $zHigh] = unpack('g', pack('g', $z));
				$address = intdiv(strlen($data), 16);
				$next = $road['samples'][$i+1] ?? $road['samples'][$i];
				$data .= pack('g12', $xHigh, $zHigh, $y, $tx, $tz, $grade, $bank, $opacity,
					$x - $xHigh, $z - $zHigh, $next[0]-$x, $next[2]-$z);
				$left = max($minTile, (int)floor(($x - $radius) / $cell)) - $minTile;
				$right = min($maxTile, (int)floor(($x + $radius) / $cell)) - $minTile;
				$front = max($minTile, (int)floor(($z - $radius) / $cell)) - $minTile;
				$back = min($maxTile, (int)floor(($z + $radius) / $cell)) - $minTile;
				for($j = $front; $j <= $back; $j++)
				for($i = $left; $i <= $right; $i++)
				{
					$block = intdiv($j, 8) * $blocksAcross + intdiv($i, 8);
					$tile = ($j % 8) * 8 + $i % 8;
					$blocks[$block][$tile][$params][] = $address;
				}
			}
		}

		$directory = array_fill(0, $blocksAcross ** 2, 0);
		foreach($blocks as $block => $tiles)
		{
			$leaves = array_fill(0, 64, 0);
			foreach($tiles as $tile => $groups)
			{
				$leaves[$tile] = intdiv(strlen($data), 16);
				$lastRoad = array_key_last($groups);
				foreach($groups as $params => $addresses)
				{
					// Each group starts with its parameters, kernel count and last-group flag.
					$count = count($addresses);
					$data .= pack('g4', $params, $count, (int)($params === $lastRoad), 0);
					$data .= pack('g*', ...$addresses) . str_repeat("\0", (4 - $count % 4) % 4 * 4);
				}
			}
			$directory[$block] = intdiv(strlen($data), 16);
			$data .= pack('g*', ...$leaves);
		}
		$directoryStart = intdiv(strlen($data), 16);
		$data .= pack('g*', ...$directory);
		// Even at the terrain format's 16,384 kernel limit this fits the WebGL 2 minimum texture size.
		$width = min(1024, (int)ceil(strlen($data) / 16));
		$height = (int)ceil(strlen($data) / ($width * 16));
		return [
			'bytes' => str_pad($data, $width * $height * 16, "\0"), 'width' => $width, 'height' => $height,
			'directory' => $directoryStart, 'blocksAcross' => $blocksAcross, 'minTile' => $minTile, 'cell' => $cell,
		];
	}

	/** Material ID plus two signed 12-bit offsets to the centerline, at 1/32 m precision. */
	public static function paintTexel(?string $material, ?array $offset): string
	{
		$id = array_search($material,self::MATERIALS,true);
		$id = $id === false ? 0 : $id+1;
		if($offset === null) return pack('C4',$id,0,0,0);
		[$x,$z] = array_map(static fn($v) => max(1,min(4095,(int)floor($v*32+.5)+2048)), $offset);
		return pack('C4',$id,$x>>4,$z>>4,(($x&15)<<4)|($z&15));
	}
}

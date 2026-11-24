<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Read-only float texture of road planes, indexed by world tile for clipmap generation. */
final class RoadAtlas
{
	public const MATERIALS = ['rock', 'grass', 'sand', 'snow', 'road'];

	/**
	 * Pack normalized road operations in application order. All pointers address RGBA texels. Each sample stores
	 * the high parts of X and Z, height and tangent X; tangent Z, grade, bank and opacity; the low parts of X and Z and the segment vector; then the dash phase, phase increment, end flags and optional continuation table address.
	 * @param array $roads Committed splines with their derived sample planes.
	 * @param float $extent Width of the editable square in meters.
	 * @return array Float texture bytes, dimensions and spatial directory coordinates.
	 */
	public static function build(array $roads, float $extent, array &$cache = []): array
	{
		// A sample reaches at most nine tiles. Only occupied 8-by-8 tile blocks need leaves.
		$cell = 64.;
		foreach($roads as $op)
		{
			while($cell < $op['_road']['gridSize']) $cell *= 2;
		}
		$half = $extent / 2;
		$minTile = (int)floor(-$half / $cell);
		$maxTile = (int)floor($half / $cell);
		$blocksAcross = intdiv($maxTile - $minTile, 8) + 1;
		$data = str_repeat("\0", 16); // Zero is the absent pointer at both levels.
		$blocks = []; $paths=[];

		$used = []; $rebuilt = 0;
		foreach($roads as $op) {
			$key = hash('sha256',serialize([Edits::serialize($op),$op['_road']['ink'] ?? null]));
			if(!isset($cache[$key]) && !isset($used[$key])) {
				$cache[$key] = ['bytes'=>self::overlay($op)];
				$rebuilt++;
			}
			$overlay = $used[$key] ?? $cache[$key];
			if(($overlay['grid'] ?? null) !== [$cell,$minTile,$maxTile]) {
				$overlay['tiles'] = self::coverageTiles($op['_road'],$cell,$minTile,$maxTile,$blocksAcross);
				$overlay['grid'] = [$cell,$minTile,$maxTile];
			}
			$used[$key] = $overlay;
			$base = intdiv(strlen($data),16);
			$data .= $overlay['bytes'];
			$paths[$base]=[$op['_road']['ink']['network']??0,$op['_road']['ink']['stripeRole']??0];
			foreach($overlay['tiles'] as $block=>$tiles)
				foreach($tiles as $tile=>$addresses) $blocks[$block][$tile][$base] = $addresses;
		}
		$cache = $used;

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
					[$network,$role]=$paths[$params];
					$data .= pack('g4', $params, $count, 2*$role+(int)($params === $lastRoad), $network);
					$data .= pack('g*', ...$addresses) . str_repeat("\0", (4 - $count % 4) % 4 * 4);
				}
			}
			$directory[$block] = intdiv(strlen($data), 16);
			$data .= pack('g*', ...$leaves);
		}
		$directoryStart = intdiv(strlen($data), 16);
		$data .= pack('g*', ...$directory);
		// Even at the active road sample limit this fits the WebGL 2 minimum texture size.
		$width = min(1024, (int)ceil(strlen($data) / 16));
		$height = (int)ceil(strlen($data) / ($width * 16));
		return [
			'bytes' => str_pad($data, $width * $height * 16, "\0"), 'width' => $width, 'height' => $height,
			'rebuilt' => $rebuilt, 'directory' => $directoryStart, 'blocksAcross' => $blocksAcross, 'minTile' => $minTile, 'cell' => $cell,
		];
	}

	/** Cache each road's grade, paint and dash data with tile references relative to its own first texel. */
	private static function overlay(array $op): string
	{
		$data = '';
		$road = $op['_road'];
		$radius = $road['radius'];
		$inner2 = $road['half'] ** 2;
		$material = array_search($op['material'], self::MATERIALS, true);
		$data .= pack('g4', $inner2, $radius ** 2 - $inner2, $op['crown'], $material === false ? 0 : $material + 1);
		// Only end samples address this table. Interior texels use the normal projection without a second search.
		$extra = ''; $pointers = [];
		$ends = 1 + 4*count($road['samples']);
		foreach($road['ink']['ends'] ?? [] as $end=>$segments) {
			$pointers[$end] = $ends + 1 + intdiv(strlen($extra),16);
			$extra .= pack('g4',count($segments),0,0,0);
			foreach($segments as [$x,$z,$vx,$vz,$phase,$length,$cap]) {
				[, $hx] = unpack('g',pack('g',$x));
				[, $hz] = unpack('g',pack('g',$z));
				$extra .= pack('g8',$hx,$hz,$vx,$vz,$x-$hx,$z-$hz,fmod($phase,self::MARK_CYCLE),$length);
				$extra .= pack('g4',$cap,0,0,0);
			}
		}
		foreach($road['samples'] as $i => [$x, $y, $z, $tx, $tz, $grade, $bank, $opacity])
		{
			// A 32-bit float rounds an absolute coordinate by up to 1 mm at 30 km, and the kernel weights turn that
			// into centimetres of height. Store each of X and Z as the float nearest to it plus the remainder, so
			// the shader can subtract both and stay within a micron of PHP anywhere in a 65.5 km world.
			[, $xHigh] = unpack('g', pack('g', $x));
			[, $zHigh] = unpack('g', pack('g', $z));
			$next = $road['samples'][$i+1] ?? $road['samples'][$i];
			$station = RoadMarkings::station($road,$i);
			$length = isset($road['stations'][$i+1]) ? RoadMarkings::station($road,$i+1)-$station : 0.;
			$last = count($road['samples'])-1;
			$caps = ($i === 0 ? 1 : 0) | ($i+1 === $last ? 2 : 0) | ($i === $last ? 4 : 0);
			$continuation=$caps && $pointers ? $ends : 0.;
			$caps |= (($road['ink']['forkCaps']??0)<<3);
			$data .= pack('g16', $xHigh, $zHigh, $y, $tx, $tz, $grade, $bank, $opacity,
				$x - $xHigh, $z - $zHigh, $next[0]-$x, $next[2]-$z, fmod($station,self::MARK_CYCLE), $length, $caps, $continuation);
		}
		return $pointers ? $data.pack('g4',$pointers[0] ?? 0,$pointers[1] ?? 0,0,0).$extra : $data;
	}

	private static function coverageTiles(array $road, float $cell, int $minTile, int $maxTile, int $blocksAcross): array
	{
		$tiles = []; $radius = $road['gridSize'];
		foreach($road['samples'] as $i=>[$x,,$z]) {
			$address = 1+4*$i;
			$left = max($minTile, (int)floor(($x - $radius) / $cell)) - $minTile;
			$right = min($maxTile, (int)floor(($x + $radius) / $cell)) - $minTile;
			$front = max($minTile, (int)floor(($z - $radius) / $cell)) - $minTile;
			$back = min($maxTile, (int)floor(($z + $radius) / $cell)) - $minTile;
			for($j = $front; $j <= $back; $j++)
			for($col = $left; $col <= $right; $col++)
			{
				$block = intdiv($j, 8) * $blocksAcross + intdiv($col, 8);
				$tile = ($j % 8) * 8 + $col % 8;
				$tiles[$block][$tile][] = $address;
			}
		}
		return $tiles;
	}

	// Sixteen pairs of 10-foot dashes and gaps, interpolated as an angle across wraparound.
	public const MARK_CYCLE = 16*2*RoadMarkings::DASH_METERS;

	/** Pack lateral position in half-widths and cyclic distance along the road into 24 exact bits. */
	public static function markCode(array $mark): int
	{
		[$cross,$station] = $mark;
		$half = $mark[2] ?? 4.;
		// Twelve lateral bits, ten cyclic station bits and two bits for the arm channel.
		$role=$mark[4]??0;
		if($role&1) $cross=-$cross;
		$lateral = max(1,min(4095,(int)floor(($cross/$half)*1024+.5)+2048));
		$phase = (int)floor(fmod($mark[3] ?? $station,self::MARK_CYCLE)*(1024/self::MARK_CYCLE)+.5) & 1023;
		return ($lateral << 12) | (($role >> 1) << 10) | $phase;
	}

	public static function paintTexel(?string $material, ?array $mark): string
	{
		$id = array_search($material,self::MATERIALS,true);
		$id = $id === false ? 0 : $id+1;
		$code = $mark === null ? 0 : self::markCode($mark);
		return pack('C4',$id,$code>>16,($code>>8)&255,$code&255);
	}
}

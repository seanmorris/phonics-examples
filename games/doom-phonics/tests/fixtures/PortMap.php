<?php
declare(strict_types=1);
namespace DoomPhonics\Tests;

use DoomPhonics\Archive\Archive;
use DoomPhonics\Archive\Stack;
use DoomPhonics\Map\MapData;

/** Small authored binary fixtures; no commercial WAD is needed for port coverage. */
final class PortMap
{

	public static function archive(array $lumps): Archive
	{
		$data = $directory = '';

		foreach($lumps as $name => $bytes)
		{
			$directory .= pack('V2a8', 12 + strlen($data), strlen($bytes), $name);
			$data .= $bytes;
		}

		return new Archive(
			'IWAD' . pack('V2', count($lumps), 12 + strlen($data)) . $data . $directory
			, 'port-fixture.wad'
		);
	}

	/** Two adjacent rooms with a ramp rising from x=0,z=0 to x=128,z=64. */
	public static function map(): MapData
	{
		$vertices = '';

		foreach([[0,0],[0,128],[128,128],[128,0],[256,128],[256,0]] as $point)
		$vertices .= pack('v2', ...$point);
		$sides = '';

		foreach([0,1,0,0,0,1,1,1] as $i => $sector)
		$sides .= pack('v2a8a8a8v', 0, 0, 'STARTAN3', 'STARTAN3', $i < 2 ? '-' : 'STARTAN3', $sector);
		$lines = '';

		foreach([[2,3,0,1],[0,1,2,65535],[1,2,3,65535],[3,0,4,65535],[2,4,5,65535],[4,5,6,65535],[5,3,7,65535]] as $i =>
			[$a,$b,$right,$left]
		)
		$lines .= pack('v3C6v2', $a, $b, $i === 0 ? 4 : 1, $i === 0 ? 181 : 0, 1, 1, 0, 0, 0, $right, $left);
		$things = '';

		foreach([
			[1,16,1799]
			, [5080,32,1799]
			, [5080,48,1799]
			, [5081,64,1799]
			, [5082,80,1799]
			, [2010,40,258]
			, [2010,56,260]
			, [2010,72,514]
			, [2010,88,1026]
		] as [$type,$x,$flags])
		$things .= pack('v7C6', 0, $x, 64, 0, 90, $type, $flags, 0, 0, 0, 0, 0, 0);

		$archive = self::archive([
			'MAP01' => ''
			, 'THINGS' => $things
			, 'LINEDEFS' => $lines
			, 'SIDEDEFS' => $sides
			, 'VERTEXES' => $vertices
			, 'SEGS' => pack('v6', 2, 3, 0, 0, 0, 0) . pack('v6', 3, 2, 0, 0, 1, 0)
			, 'SSECTORS' => pack('v4', 1, 0, 1, 1)
			, 'NODES' => pack('v14', 128, 0, 0, 128, 128, 0, 128, 256, 128, 0, 0, 128, 0x8001, 0x8000)
			, 'SECTORS' => pack('v2a8a8v3', 0, 128, 'FLOOR0_1', 'CEIL1_1', 160, 0, 1)
				. pack('v2a8a8v3', 64, 192, 'FLOOR0_1', 'CEIL1_1', 240, 0, 2)
			, 'BEHAVIOR' => "ACS\0" . pack('V2', 8, 0)
		]);

		return (new Stack($archive))->map('MAP01');
	}
}

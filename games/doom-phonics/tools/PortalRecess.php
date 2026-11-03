<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . "/PolygonGeometry.php";

/**
 * Cut a roofed portal passage into the inside edge of a rectangular room.
 *
 * The portal stays on its pinned boundary. A lower passage ceiling and real jamb
 * sectors reveal wall thickness without changing the crossing's floor height.
 */
final class PortalRecess
{

	/** Find the cell immediately inside a packed, axis-aligned entrance frame. */
	public static function sector(array $cells, array $point, array $normal): int
	{
		$x = $point[0] - $normal[0];
		$y = $point[1] - $normal[1];
		foreach($cells as $id => $cell)
		if($x > $cell['x0'] && $x < $cell['x1'] && $y > $cell['y0'] && $y < $cell['y1']
			&& (!isset($cell['polygon']) || PolygonGeometry::contains($cell['polygon'],$x,$y))) return $id;
		throw new \InvalidArgumentException('Entrance does not face a map region');
	}

	/** Split a room into its retained core, optional jambs, and a recessed opening. */
	public static function wall(array &$cells, array $point, array $normal, int $width, string $name,
		int $floor, int $ceiling, int $depth = 64): int
	{
		if($normal[2] !== 0 || abs($normal[0]) + abs($normal[1]) !== 1 || $width <= 0 || $depth <= 0 || $ceiling <= $floor)
		throw new \InvalidArgumentException('Invalid wall recess dimensions');
		$id = self::sector($cells,$point,$normal);
		$room = $cells[$id];
		$axis = $normal[0] ? 'x' : 'y';
		$cross = $normal[0] ? 'y' : 'x';
		$component = $normal[0] ? 0 : 1;
		$edge = $normal[$component] > 0 ? '1' : '0';
		$inner = $room[$axis . $edge] - $normal[$component] * $depth;
		$lo = $point[1 - $component] - intdiv($width,2);
		$hi = $lo + $width;
		if($point[$component] !== $room[$axis . $edge]
			|| $inner <= $room[$axis . '0'] || $inner >= $room[$axis . '1']
			|| $lo < $room[$cross . '0'] || $hi > $room[$cross . '1']
			|| $floor < $room['floor'] || $ceiling > $room['ceiling'])
		throw new \InvalidArgumentException('Portal recess does not fit its room');
		$cells[$id][$axis . $edge] = $inner;
		$strip = $room;
		$strip[$axis . ($edge === '1' ? '0' : '1')] = $inner;
		foreach([[$room[$cross . '0'],$lo],[$hi,$room[$cross . '1']]] as $index => [$a,$b])
		if($a < $b)
		$cells[] = array_replace($strip,['id' => "$name.jamb-$index",$cross . '0' => $a,$cross . '1' => $b]);
		$opening = count($cells);
		$cells[] = array_replace($strip,['id' => $name,$cross . '0' => $lo,$cross . '1' => $hi,
			'floor' => $floor,'ceiling' => $ceiling,'ceilingTexture' => 'CEIL3_5']);
		return $opening;
	}
}

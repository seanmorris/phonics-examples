<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** One Line_Mirror surface and its reflected camera transform. */
final readonly class MirrorView
{

	public function __construct(public int $line, public int $sector) {}

	/** @return array{x:float,y:float,z:float,angle:float,sector:int} */
	public function camera(Level $level, float $x, float $y, float $z, float $angle): array
	{
		$line = $level->map->linedefs[$this->line];
		$a = $level->map->vertices[$line['from']];
		$b = $level->map->vertices[$line['to']];
		$dx = (float)$b['x'] - $a['x'];
		$dy = (float)$b['y'] - $a['y'];
		$length2 = max(.0001, $dx * $dx + $dy * $dy);
		$t = (($x - $a['x']) * $dx + ($y - $a['y']) * $dy) / $length2;
		$px = $a['x'] + $t * $dx;
		$py = $a['y'] + $t * $dy;
		$lineAngle = atan2($dy, $dx);

		return [
			'x' => 2 * $px - $x
			, 'y' => 2 * $py - $y
			, 'z' => $z
			, 'angle' => 2 * $lineAngle - $angle
			, 'sector' => $this->sector
		];
	}
}

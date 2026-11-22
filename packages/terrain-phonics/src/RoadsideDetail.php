<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** A world-anchored road proximity mask, evaluated in 128 m tiles as the driver approaches. */
final class RoadsideDetail
{
	public const CELL = 16;
	private const TILE = 8;
	private array $segments = [], $buckets = [], $tiles = [];

	public function __construct(array $roads)
	{
		foreach($roads as $road) {
			$samples = $road['_road']['samples'];
			[$ax,,$az] = $samples[0];
			// Short chords follow bends without indexing every grading kernel.
			$last = array_key_last($samples);
			foreach($samples as $i => [$bx,,$bz]) {
				$dx = $bx-$ax; $dz = $bz-$az;
				$squared = $dx*$dx+$dz*$dz;
				if($squared == 0. || ($squared < 12**2 && $i !== $last)) continue;
				$half = $road['width']/2;
				$outer = $half+48.;
				$segment = count($this->segments);
				$this->segments[] = [$ax,$az,$dx,$dz,1./$squared,$half+24.,$outer];
				$scale = self::CELL*self::TILE;
				$x0 = (int)floor((min($ax,$bx)-$outer)/$scale);
				$x1 = (int)floor((max($ax,$bx)+$outer)/$scale);
				$z0 = (int)floor((min($az,$bz)-$outer)/$scale);
				$z1 = (int)floor((max($az,$bz)+$outer)/$scale);
				for($z=$z0; $z<=$z1; $z++) for($x=$x0; $x<=$x1; $x++)
					$this->buckets[$z][$x][] = $segment;
				$ax = $bx; $az = $bz;
			}
		}
	}

	private function tile(int $x, int $z): string
	{
		$bytes = str_repeat("\0",self::TILE**2);
		foreach($this->buckets[$z][$x] as $index) {
			[$ax,$az,$dx,$dz,$inv,$inner,$outer] = $this->segments[$index];
			$inner2 = $inner*$inner; $outer2 = $outer*$outer;
			for($j=0; $j<self::TILE; $j++) {
				$vz = ($z*self::TILE+$j)*self::CELL-$az;
				for($i=0; $i<self::TILE; $i++) {
					$k = $j*self::TILE+$i;
					if($bytes[$k] === "\xff") continue;
					$vx = ($x*self::TILE+$i)*self::CELL-$ax;
					$t = max(0.,min(1.,($vx*$dx+$vz*$dz)*$inv));
					$d2 = ($vx-$t*$dx)**2+($vz-$t*$dz)**2;
					if($d2 >= $outer2) continue;
					$w = $d2 <= $inner2 ? 255 : (int)round(255.*($outer-sqrt($d2))/($outer-$inner));
					if($w > ord($bytes[$k])) $bytes[$k] = chr($w);
				}
			}
		}
		return $bytes;
	}

	/** The window moves in 64 m increments and includes an interpolation border on every side. */
	public static function window(array $focus, float $radius): array
	{
		$pad = (int)ceil($radius/self::CELL)+2;
		$x = 4*(int)floor($focus[0]/(4*self::CELL))-$pad;
		$z = 4*(int)floor($focus[1]/(4*self::CELL))-$pad;
		return [$x,$z,4*(int)ceil((2*$pad+5)/4)];
	}

	/** Single-channel texture and conservative world bounds of its bilinear support. */
	public function sampleWindow(array $window): array
	{
		[$x,$z,$size] = $window;
		$bytes = str_repeat("\0",$size*$size);
		$left = $top = $size; $right = $bottom = -1;
		for($j=0; $j<$size; $j++) {
			$row = $z+$j;
			$tz = (int)floor($row/self::TILE);
			if(!isset($this->buckets[$tz])) continue;
			for($i=0; $i<$size; $i++) {
				$col = $x+$i;
				$tx = (int)floor($col/self::TILE);
				if(!isset($this->buckets[$tz][$tx])) continue;
				$tile = $this->tiles[$tz][$tx] ??= $this->tile($tx,$tz);
				$v = $tile[($row-$tz*self::TILE)*self::TILE+($col-$tx*self::TILE)];
				if($v === "\0") continue;
				$bytes[$j*$size+$i] = $v;
				$left = min($left,$i); $right = max($right,$i);
				$top = min($top,$j); $bottom = max($bottom,$j);
			}
		}
		return [$bytes,$right<0 ? null : [($x+$left-1)*self::CELL,($z+$top-1)*self::CELL,
			($x+$right+1)*self::CELL,($z+$bottom+1)*self::CELL]];
	}
}

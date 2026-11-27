<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** One direct sound at impact, followed by muffled returns from large slopes around the source. */
final class BurstAcoustics
{
	private ?Landscape $world = null;
	private ?FlightTrails $source = null;
	private array $surveys = [];
	private int $serial = 0;

	/** Delay in seconds, gain, low-pass cutoff in Hz, stereo pan and whether this is the direct arrival. */
	private static function tap(array $point, Camera $ear, float $outbound = 0., float $reflectivity = 1.): array
	{
		[$x,$y,$z] = array_map(static fn($a,$b) => $a-$b, $point, $ear->position);
		$distance = sqrt($x*$x+$y*$y+$z*$z);
		$path = $distance+$outbound;
		[$rx,$ry,$rz] = $ear->basis()[1];
		return [$path/343., $reflectivity/(1.+$path/155.),
			$outbound > 0. ? 280.+1100./(1.+$path/350.) : 1200.+11000./(1.+$path/500.),
			($rx*$x+$ry*$y+$rz*$z)/max(1.,$distance), $outbound===0.];
	}

	/** Sample lattice nodes on eight spokes. Yield after each probe so cold terrain can be surveyed across frames. */
	private static function survey(array $origin, Camera $ear, Landscape $land, float $water): \Generator
	{
		[$x,$y,$z] = $origin;
		$recipe = $land->recipe();
		$half = $recipe['worldSize']/2;
		$spacing = $recipe['spacing'];
		$walls = [];
		for($i=0; $i<8; $i++) {
			$dx=cos($i*M_PI/4); $dz=sin($i*M_PI/4);
			$lastHeight = $y; $lastRange = 0.;
			foreach([96,224,480,960,1664,2560] as $range) {
				$px = round(($x+$dx*$range)/$spacing)*$spacing;
				$pz = round(($z+$dz*$range)/$spacing)*$spacing;
				if(max(abs($px),abs($pz)) > $half) break;
				$height = $land->height($px,$pz);
				yield;
				$rise = $height-$y;
				$grade = ($height-$lastHeight)/($range-$lastRange);
				if($height>$water && $rise>12. && $rise/$range>.075 && $grade>.13) {
					// The reflecting face is between the foot and the crest of the sampled slope.
					$point = [($px+$x+$dx*$lastRange)/2, $y+.45*$rise, ($pz+$z+$dz*$lastRange)/2];
					$leg = hypot(hypot($point[0]-$x,$point[2]-$z),$point[1]-$y);
					$walls[] = self::tap($point,$ear,$leg,min(.68,.18+.48*$grade));
					break;
				}
				$lastHeight=$height; $lastRange=$range;
			}
		}
		usort($walls,static fn($a,$b) => $b[1]<=>$a[1]);
		$returns=[];
		foreach($walls as $tap) {
			// Neighboring spokes can hit one face. Avoid doubling indistinguishable arrivals.
			foreach($returns as $other) if(abs($other[0]-$tap[0])<.075 && abs($other[3]-$tap[3])<.5) continue 2;
			$returns[]=$tap;
			if(count($returns)===3) break;
		}
		return $returns;
	}

	public function frame(?FlightTrails $source, Landscape $world, Camera $ear, float $water, mixed $mode, mixed $play): void
	{
		if($source!==$this->source || ($source!==null && $world!==$this->world)) {
			$this->surveys=[];
			$this->world=$source===null ? null : $world;
			$this->source=$source;
			$mode($source!==null);
		}
		if(!$source) return;
		foreach($source->takeBursts() as $point) {
			$id=++$this->serial;
			$direct=self::tap($point,$ear);
			if($direct[1]<.015) continue;
			$play($id,...$direct);
			if(count($this->surveys)>=4) unset($this->surveys[array_key_first($this->surveys)]);
			$this->surveys[$id]=self::survey($point,clone $ear,$world,$water);
		}
		if(!$this->surveys) return;
		$deadline=hrtime(true)+450000;
		foreach($this->surveys as $id=>$survey) {
			while($survey->valid()) {
				if(hrtime(true)>=$deadline) return;
				$survey->next();
			}
			foreach($survey->getReturn() as $tap) $play($id,...$tap);
			unset($this->surveys[$id]);
		}
	}
}

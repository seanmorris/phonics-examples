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
	/** World-space emission or reflection point, outbound path and reflectivity for each sounding arrival. */
	private array $voices = [];
	private ?array $lastPosition = null;
	private int $serial = 0;

	/** Spatial mix for a direct or reflected arrival. Positive bearing points toward the listener's right ear. */
	private static function tap(array $point, array $ear, array $right, array $velocity,
		float $outbound, float $reflectivity): array
	{
		$x=$point[0]-$ear[0]; $y=$point[1]-$ear[1]; $z=$point[2]-$ear[2];
		$distance = sqrt($x*$x+$y*$y+$z*$z);
		$path = $distance+$outbound;
		$inverse = $distance > .001 ? 1./$distance : 0.;
		$bearing = max(-1.,min(1.,($right[0]*$x+$right[1]*$y+$right[2]*$z)*$inverse));
		$closing = ($velocity[0]*$x+$velocity[1]*$y+$velocity[2]*$z)*$inverse;
		// Limit the shift to roughly four semitones at jet speeds.
		$doppler = max(.8,min(1.25,1.+$closing/343.));
		return [$path/343., $reflectivity/(1.+$path/155.),
			$outbound > 0. ? 280.+1100./(1.+$path/350.) : 1200.+11000./(1.+$path/500.),
			$bearing, $outbound===0., $doppler];
	}

	/** Sample lattice nodes on eight spokes. Yield after each probe so cold terrain can be surveyed across frames. */
	private static function survey(array $origin, Camera $ear, Landscape $land, float $water): \Generator
	{
		[$x,$y,$z] = $origin;
		$recipe = $land->recipe();
		$half = $recipe['worldSize']/2;
		$spacing = $recipe['spacing'];
		$walls = [];
		$right = $ear->basis()[1];
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
					$path = [$point,$leg,min(.68,.18+.48*$grade)];
					$walls[] = ['path'=>$path, 'mix'=>self::tap($point,$ear->position,$right,[0.,0.,0.],$leg,$path[2])];
					break;
				}
				$lastHeight=$height; $lastRange=$range;
			}
		}
		usort($walls,static fn($a,$b) => $b['mix'][1]<=>$a['mix'][1]);
		$returns=[];
		foreach($walls as $wall) {
			// Neighboring spokes can hit one face. Avoid doubling indistinguishable arrivals.
			foreach($returns as $other) if(abs($other['mix'][0]-$wall['mix'][0])<.075 && abs($other['mix'][3]-$wall['mix'][3])<.5) continue 2;
			$returns[]=$wall;
			if(count($returns)===3) break;
		}
		return array_column($returns,'path');
	}

	public function frame(?FlightTrails $source, Landscape $world, Camera $ear, float $water, mixed $mode, mixed $play,
		float $seconds): void
	{
		if($source!==$this->source || ($source!==null && $world!==$this->world)) {
			$this->surveys=$this->voices=[];
			$this->lastPosition=null;
			$this->world=$source===null ? null : $world;
			$this->source=$source;
			$mode($source!==null);
		}
		if(!$source) return;
		$previous=$this->lastPosition;
		$this->lastPosition=$ear->position;
		$impacts=$source->takeBursts();
		if(!$impacts && !$this->voices && !$this->surveys) return;
		$velocity=[0.,0.,0.];
		if($previous!==null && $seconds>.0001 && $seconds<.25) {
			foreach($previous as $i=>$v) $velocity[$i]=($ear->position[$i]-$v)/$seconds;
			// A camera cut or teleport is not a flyby.
			if($velocity[0]**2+$velocity[1]**2+$velocity[2]**2>400.**2) $velocity=[0.,0.,0.];
		}
		$right=$ear->basis()[1];
		$mix=static fn(array $path)=>self::tap($path[0],$ear->position,$right,$velocity,$path[1],$path[2]);
		foreach($this->voices as $id=>$paths) {
			foreach($paths as $slot=>$path) {
				if(!$play($id,$slot,false,...$mix($path))) unset($this->voices[$id][$slot]);
			}
			if(!$this->voices[$id]) unset($this->voices[$id]);
		}
		foreach($impacts as $point) {
			$id=++$this->serial;
			$path=[$point,0.,1.];
			$direct=$mix($path);
			if($direct[1]<.015) continue;
			if($play($id,0,true,...$direct)) $this->voices[$id][0]=$path;
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
			foreach($survey->getReturn() as $index=>$path) {
				$slot=$index+1;
				if($play($id,$slot,true,...$mix($path))) $this->voices[$id][$slot]=$path;
			}
			unset($this->surveys[$id]);
		}
	}
}

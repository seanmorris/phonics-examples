<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Pavement rubber retained within half a mile of the current player. */
final class SkidMarks
{
	public const RADIUS = 804.672;
	/** @var array<string,SkidTile> */
	public array $tiles = [];
	private array $previous = [];
	private ?array $lastTrim = null;
	private float $margin = 0.;

	/** Translation from the marks' original landscape frame to the current journey square. */
	public array $offset = [0.,0.,0.];

	public function translate(array $shift): void
	{
		foreach($shift as $i=>$distance) $this->offset[$i]+=$distance;
	}

	public function lift(): void { $this->previous=[]; }

	/** Each contact is [wheel index, world x, height, world z, slip intensity]. */
	public function step(array $contacts,float $x,float $z): void
	{
		$next=[];
		foreach($contacts as [$wheel,$bx,$height,$bz,$strength]) {
			$bx-=$this->offset[0]; $height-=$this->offset[1]; $bz-=$this->offset[2];
			[$ax,$az]=$this->previous[$wheel] ?? [$bx,$bz];
			// A recovery, a cut in a recording, or an interrupted contact starts a fresh stroke.
			if(hypot($bx-$ax,$bz-$az)>8.) { $ax=$bx; $az=$bz; }
			$next[$wheel]=[$bx,$bz,$strength];
			if(isset($this->previous[$wheel]) && $ax===$bx && $az===$bz && $this->previous[$wheel][2]>=$strength) continue;
			$size=SkidTile::METERS;
			$l=(int)floor((min($ax,$bx)-.22)/$size); $r=(int)floor((max($ax,$bx)+.22)/$size);
			$t=(int)floor((min($az,$bz)-.22)/$size); $b=(int)floor((max($az,$bz)+.22)/$size);
			for($j=$t;$j<=$b;$j++) for($i=$l;$i<=$r;$i++) {
				$key="$i,$j"; $ox=$i*$size; $oz=$j*$size;
				$tile=$this->tiles[$key] ??= new SkidTile($ox,$oz);
				$tile->paint(($ax-$ox)*SkidTile::SCALE,($az-$oz)*SkidTile::SCALE,
					($bx-$ox)*SkidTile::SCALE,($bz-$oz)*SkidTile::SCALE,$strength,$height);
				if($tile->empty()) unset($this->tiles[$key]);
			}
		}
		$this->previous=$next;
		$this->prune($x,$z);
	}

	public function prune(float $x,float $z): void
	{
		$x-=$this->offset[0]; $z-=$this->offset[2];
		if(!$this->tiles) { $this->lastTrim=null; return; }
		if($this->lastTrim && hypot($x-$this->lastTrim[0],$z-$this->lastTrim[1])<=$this->margin) return;
		$this->lastTrim=[$x,$z];
		// New tiles are always at the car. Even a newly painted tile is safe over this distance.
		$this->margin=self::RADIUS/3;
		$half=SkidTile::METERS/2;
		foreach($this->tiles as $key=>$tile) {
			$dx=abs($x-$tile->x-$half); $dz=abs($z-$tile->z-$half);
			$inside=self::RADIUS-hypot($dx+$half,$dz+$half);
			if($inside<0.) {
				if(hypot(max(0.,$dx-$half),max(0.,$dz-$half))>self::RADIUS) { unset($this->tiles[$key]); continue; }
				$tile->trim(($x-$tile->x)*SkidTile::SCALE,($z-$tile->z)*SkidTile::SCALE,self::RADIUS*SkidTile::SCALE);
				if($tile->empty()) { unset($this->tiles[$key]); continue; }
			}
			$this->margin=min($this->margin,max(0.,$inside));
		}
	}
}

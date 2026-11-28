<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** One channel of rubber coverage. Only rows touched by a tire occupy CPU memory. */
final class SkidTile
{
	public const SIZE = 384, METERS = 32, BORDER = 4;
	public const SCALE = (self::SIZE - 2*self::BORDER) / self::METERS;
	// A linear shoulder spanning 1.7 texels keeps the half-coverage contour at the tread edge.
	private const HALF_TREAD = .145, HALF_FEATHER = .85/self::SCALE;
	// Neighboring tiles contain matching samples for linear and mip filtering at a boundary.
	public const PAINT_MARGIN = self::HALF_TREAD + self::HALF_FEATHER + self::BORDER/self::SCALE;
	private array $rows = [];
	private ?array $dirty = null;
	public int $revision = 0;
	public float $low = INF, $high = -INF;

	public function __construct(public readonly int $x, public readonly int $z) {}

	private function changed(int $left,int $top,int $right,int $bottom): void
	{
		$d=$this->dirty;
		$this->dirty=$d ? [min($d[0],$left),min($d[1],$top),max($d[2],$right),max($d[3],$bottom)] : [$left,$top,$right,$bottom];
		$this->revision++;
	}

	/** Endpoints in local texels, relative to the tile's world origin. */
	public function paint(float $ax,float $az,float $bx,float $bz,float $strength,float $height): void
	{
		$ax+=self::BORDER; $az+=self::BORDER; $bx+=self::BORDER; $bz+=self::BORDER;
		$dx=$bx-$ax; $dz=$bz-$az; $length2=$dx*$dx+$dz*$dz;
		$invLength2=$length2>0. ? 1./$length2 : 0.;
		$inner=(self::HALF_TREAD-self::HALF_FEATHER)*self::SCALE;
		$outer=(self::HALF_TREAD+self::HALF_FEATHER)*self::SCALE;
		$inner2=$inner*$inner; $outer2=$outer*$outer;
		$peak=(int)round(255*$strength); $falloff=255*$strength/($outer-$inner);
		$clipRows=abs($dz)>2*$outer;
		if($clipRows) $reach=$outer/abs($dz);
		$l=max(0,(int)floor(min($ax,$bx)-$outer)); $r=min(self::SIZE,(int)ceil(max($ax,$bx)+$outer));
		$t=max(0,(int)floor(min($az,$bz)-$outer)); $b=min(self::SIZE,(int)ceil(max($az,$bz)+$outer));
		$changed=false;
		for($j=$t;$j<$b;$j++) {
			$z=$j+.5-$az;
			// Only scan the slice of the segment within one brush radius of this row.
			$left=$l; $right=$r;
			if($clipRows) {
				$at=$z/$dz; $near=max(0.,$at-$reach); $far=min(1.,$at+$reach);
				$x1=$ax+$near*$dx; $x2=$ax+$far*$dx;
				$left=max($l,(int)floor(min($x1,$x2)-$outer)); $right=min($r,(int)ceil(max($x1,$x2)+$outer));
			}
			$row=$this->rows[$j] ?? str_repeat("\0",self::SIZE);
			$rowChanged=false;
			for($i=$left;$i<$right;$i++) {
				$previous=ord($row[$i]);
				if($previous>=$peak) continue;
				$x=$i+.5-$ax;
				$at=max(0.,min(1.,($x*$dx+$z*$dz)*$invLength2));
				$px=$x-$at*$dx; $pz=$z-$at*$dz;
				$d2=$px*$px+$pz*$pz;
				if($d2>=$outer2) continue;
				$ink=$d2<=$inner2 ? $peak : (int)round(($outer-sqrt($d2))*$falloff);
				if($ink<=$previous) continue;
				$row[$i]=chr($ink); $rowChanged=true;
			}
			if($rowChanged) { $this->rows[$j]=$row; $changed=true; }
		}
		if($changed) {
			$this->changed($l,$t,$r,$b);
			$this->low=min($this->low,$height); $this->high=max($this->high,$height);
		}
	}

	public function empty(): bool { return !$this->rows; }

	/** Permanently trim a tile straddling the radius, including when the player doubles back. */
	public function trim(float $cx,float $cz,float $radius): void
	{
		$cx+=self::BORDER; $cz+=self::BORDER;
		$blank=str_repeat("\0",self::SIZE);
		foreach($this->rows as $j=>$row) {
			$dz=$j+.5-$cz;
			$span=$radius*$radius-$dz*$dz;
			$l=0; $r=0;
			if($span>=0.) {
				$reach=sqrt($span);
				$l=max(0,min(self::SIZE,(int)ceil($cx-$reach-.5)));
				$r=max(0,min(self::SIZE,(int)floor($cx+$reach-.5)+1));
			}
			if($l===0 && $r===self::SIZE) continue;
			$kept=substr($blank,0,$l).substr($row,$l,$r-$l).substr($blank,$r);
			if($kept===$row) continue;
			if($kept===$blank) unset($this->rows[$j]); else $this->rows[$j]=$kept;
			$this->changed(0,$j,self::SIZE,$j+1);
		}
	}

	public function pixels(array $rect): string
	{
		[$l,$t,$r,$b]=$rect;
		$blank=str_repeat("\0",$r-$l); $bytes='';
		for($j=$t;$j<$b;$j++) $bytes.=isset($this->rows[$j]) ? substr($this->rows[$j],$l,$r-$l) : $blank;
		return $bytes;
	}

	public function dirtyRect(): ?array { return $this->dirty; }
	public function uploaded(): void { $this->dirty=null; }
}

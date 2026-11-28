<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** One channel of rubber coverage. Only rows touched by a tire occupy CPU memory. */
final class SkidTile
{
	public const SIZE = 256, METERS = 32, SCALE = self::SIZE / self::METERS;
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

	/** Endpoints in local texels. A soft one-texel edge gives subpixel coverage on diagonals. */
	public function paint(float $ax,float $az,float $bx,float $bz,float $strength,float $height): void
	{
		$dx=$bx-$ax; $dz=$bz-$az; $length=$dx*$dx+$dz*$dz;
		$radius=.145*self::SCALE+.5;
		$l=max(0,(int)floor(min($ax,$bx)-$radius)); $r=min(self::SIZE,(int)ceil(max($ax,$bx)+$radius));
		$t=max(0,(int)floor(min($az,$bz)-$radius)); $b=min(self::SIZE,(int)ceil(max($az,$bz)+$radius));
		$changed=false;
		for($j=$t;$j<$b;$j++) for($i=$l;$i<$r;$i++) {
			$x=$i+.5-$ax; $z=$j+.5-$az;
			$at=$length>0. ? max(0.,min(1.,($x*$dx+$z*$dz)/$length)) : 0.;
			$coverage=$radius-hypot($x-$at*$dx,$z-$at*$dz);
			if($coverage<=0.) continue;
			$ink=(int)round(255*$strength*min(1.,$coverage));
			if($ink<=ord($this->rows[$j][$i] ?? "\0")) continue;
			$this->rows[$j] ??= str_repeat("\0",self::SIZE);
			$this->rows[$j][$i]=chr($ink); $changed=true;
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

<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** An offline or time-sliced survey of the unedited landform. Samples are cell centers in world meters. */
final class LandformPreview
{
	public readonly float $span;
	private Recipe|JoinedTerrain $field;
	private array $heights = [];
	private string $pixels = '';

	public function __construct(array $recipe, public readonly float $water, public readonly int $size = 64)
	{
		if($size < 16 || $size > 256 || !is_finite($water)) throw new \InvalidArgumentException('Use a 16–256 cell survey and finite water level.');
		$this->field = isset($recipe['join']) ? new JoinedTerrain($recipe) : new Recipe($recipe);
		$this->span = $this->field->values()['worldSize'];
	}

	/** Yield between short groups of samples. The caller owns scheduling and cancellation. */
	public function survey(): \Generator
	{
		$n = $this->size; $step = $this->span/$n; $land = $steep = $crests = 0;
		// Short local baselines reveal steep faces that would average away over an overview cell.
		$stride = max(1,intdiv($n,16)); $phase = intdiv($stride,2); $probes = 0;
		$run = min(2.,$step/2);
		for($j=0;$j<$n;$j++) for($i=0;$i<$n;$i++) {
			$x=($i+.5-$n/2)*$step; $z=($j+.5-$n/2)*$step;
			$h = $this->field->height($x,$z);
			if($i%$stride===$phase && $j%$stride===$phase) {
				$dx = ($this->field->height($x+$run,$z)-$h)/$run;
				$dz = ($this->field->height($x,$z+$run)-$h)/$run;
				if($dx*$dx+$dz*$dz>1/3) $steep++; // tan(30 degrees) squared
				$probes++;
			}
			$this->heights[$j*$n+$i] = $h;
			if($h>$this->water) $land++;
			if(($i & 3)===3) yield;
		}
		$min = min($this->heights); $max = max($this->heights);
		$bins = array_fill(0,24,0);
		$relief = max(1e-12,$max-$min);
		$this->pixels = '';
		for($j=0;$j<$n;$j++) {
			for($i=0;$i<$n;$i++) {
				$h = $this->heights[$j*$n+$i];
				$l = $this->heights[$j*$n+max(0,$i-1)]; $r = $this->heights[$j*$n+min($n-1,$i+1)];
				$t = $this->heights[max(0,$j-1)*$n+$i]; $b = $this->heights[min($n-1,$j+1)*$n+$i];
				$dx = ($r-$l)/($step*($i===0 || $i===$n-1 ? 1 : 2));
				$dz = ($b-$t)/($step*($j===0 || $j===$n-1 ? 1 : 2));
				if($h>$l && $h>=$r && $i>0 && $i<$n-1) $crests++;
				if($h>$t && $h>=$b && $j>0 && $j<$n-1) $crests++;
				$bins[min(23,(int)(24*($h-$min)/$relief))]++;
				$light = max(.18,min(1.,.3+.7*(.62+.6*$dx+.5*$dz)/sqrt(1+$dx*$dx+$dz*$dz)));
				$tint = .63+.37*($h-$min)/$relief;
				$rgb = $h>$this->water ? [218*$tint,215*$tint,175*$tint] : [81,148,189];
				$this->pixels .= pack('C3',(int)($rgb[0]*$light),(int)($rgb[1]*$light),(int)($rgb[2]*$light));
			}
			yield;
		}
		// Sort short runs and merge in slices so a percentile query cannot monopolize a game frame.
		$ranked = $this->heights; $count = count($ranked);
		for($width=1;$width<$count;$width*=2) {
			$merged = [];
			for($left=0;$left<$count;$left+=2*$width) {
				$mid = min($count,$left+$width); $right = min($count,$mid+$width);
				$i = $left; $j = $mid;
				for($k=$left;$k<$right;$k++) {
					$merged[] = $j===$right || ($i<$mid && $ranked[$i]<=$ranked[$j]) ? $ranked[$i++] : $ranked[$j++];
					if(($k & 127)===127) yield;
				}
			}
			$ranked = $merged;
		}
		$percentiles = [];
		for($q=0;$q<=100;$q++) $percentiles[] = $ranked[(int)round(($n*$n-1)*$q/100)];
		return ['size'=>$n,'span'=>$this->span,'step'=>$step,'water'=>$this->water,
			'land'=>$land/($n*$n),'steep'=>$steep/$probes,'gradeProbes'=>$probes,'gradeRun'=>$run,'percentiles'=>$percentiles,'bins'=>$bins,
			'crestSpacing'=>$crests ? 2*$n*($n-2)*$step/$crests : null,
			'rgb'=>base64_encode($this->pixels)];
	}

	/** Render a perspective thumbnail from the same grid and the game's real camera rays. */
	public function oblique(Camera $camera,int $width=256,int $height=144): string
	{
		$n=$this->size; $step=$this->span/$n; $rgb='';
		[$cx,$cy,$cz]=$camera->position;
		// Refine around the eye. A world overview alone cannot resolve a bluff beside the camera.
		$levels = [[$this->span,0.,0.,$this->heights]];
		for($side=$this->span/4;$side>max(128.,$this->span/128);$side/=4) {
			$grid=[];
			for($j=0;$j<$n;$j++) for($i=0;$i<$n;$i++)
				$grid[]=$this->field->height($cx+($i+.5-$n/2)*$side/$n,$cz+($j+.5-$n/2)*$side/$n);
			$levels[]=[$side,$cx,$cz,$grid];
		}
		$levels=array_reverse($levels);
		$min=min($this->heights); $relief=max(1.,max($this->heights)-$min);
		for($y=0;$y<$height;$y++) for($x=0;$x<$width;$x++) {
			[$dx,$dy,$dz]=$camera->ray(($x+.5)/$width,($y+.5)/$height,$width/$height);
			$pixel="\x9e\xb9\xca";
			$t0=.25; $t1=3*$this->span;
			$edge=($this->span-$step)/2;
			foreach([[$cx,$dx],[$cz,$dz]] as [$origin,$direction]) {
				if(abs($direction)<1e-9) {
					if(abs($origin)>$edge) $t1=0.;
					continue;
				}
				$lo=(-$edge-$origin)/$direction; $hi=($edge-$origin)/$direction;
				$t0=max($t0,min($lo,$hi)); $t1=min($t1,max($lo,$hi));
			}
			$level=0;
			for($t=$t0;$t<$t1;) {
				[$side,$ox,$oz,$grid]=$levels[$level];
				$cell=$side/$n;
				$u=($cx+$dx*$t-$ox)/$cell+$n/2-.5; $v=($cz+$dz*$t-$oz)/$cell+$n/2-.5;
				if($u<0 || $v<0 || $u>=$n-1 || $v>=$n-1) {
					if(++$level===count($levels)) break;
					continue;
				}
				$i=(int)$v*$n+(int)$u; $u-=(int)$u; $v-=(int)$v;
				$a=$grid[$i]; $b=$grid[$i+1]; $c=$grid[$i+$n]; $d=$grid[$i+$n+1];
				$h=(1-$v)*((1-$u)*$a+$u*$b)+$v*((1-$u)*$c+$u*$d);
				if($cy+$dy*$t<=max($h,$this->water)) {
					$sx=((1-$v)*($b-$a)+$v*($d-$c))/$cell;
					$sz=((1-$u)*($c-$a)+$u*($d-$b))/$cell;
					$light=max(.18,min(1.,.25+.75*(.62+.6*$sx+.5*$sz)/sqrt(1+$sx*$sx+$sz*$sz)));
					$tint=max(.5,min(1.,.63+.37*($h-$min)/$relief));
					$pixel=$h>$this->water ? pack('C3',(int)(218*$light*$tint),(int)(215*$light*$tint),(int)(175*$light*$tint)) : "\x43\x80\xa8";
					break;
				}
				$t+=max(.5,$cell/2,$t/64);
			}
			$rgb.=$pixel;
		}
		return $rgb;
	}

	/** Score views of dry relief in a fan, sampling only the completed survey grid. Angles are degrees. */
	public function findView(): \Generator
	{
		$n=$this->size; $step=$this->span/$n; $best=-INF; $view=null;
		$stride=max(2,intdiv($n,8));
		for($j=$stride;$j<$n-$stride;$j+=$stride) for($i=$stride;$i<$n-$stride;$i+=$stride) {
			$eye=max($this->heights[$j*$n+$i],$this->water)+100;
			for($angle=0;$angle<360;$angle+=30) {
				$score=0.; $samples=0;
				foreach([-35,-17,0,17,35] as $offset) {
					$rad=deg2rad($angle+$offset); $sky=-M_PI/2;
					for($k=1;$k<=2*$stride;$k++) {
						$x=(int)round($i+sin($rad)*$k); $z=(int)round($j-cos($rad)*$k);
						if($x<0 || $z<0 || $x>=$n || $z>=$n) break;
						$h=$this->heights[$z*$n+$x]; $samples++;
						if($h>$this->water) $sky=max($sky,atan2($h-$eye,$k*$step));
					}
					$score+=max(0.,$sky+.18);
				}
				$score*=$samples/(10*$stride);
				if($score>$best) {
					$best=$score; $view=[($i+.5-$n/2)*$step,($j+.5-$n/2)*$step,100.,$angle,-8.];
				}
				yield;
			}
		}
		return $view;
	}
}

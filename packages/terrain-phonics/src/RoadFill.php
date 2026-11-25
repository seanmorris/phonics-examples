<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Sparse fill strips where an embankment meets a nearby natural hillside. */
final class RoadFill
{
	public const CROSS_FALL = .08;

	public static function prepare(array $road, callable $height,float $water): \Generator
	{
		$half=$road['half']; $shoulder=$road['radius']-$half;
		$spacing=max(8.,min(24.,$half));
		$reach=min(128.,max(32.,4.*$half));
		$step=max(4.,min(12.,.5*$shoulder));
		$fills=$grid=[]; $next=0.; $probes=0; $bounds=$road['bounds']; $range=$road['range'];
		foreach($road['samples'] as $i=>[$x,$y,$z,$tx,$tz,$grade,$bank,$opacity]) {
			$s=$road['stations'][$i];
			if($s<$next && $i!==array_key_last($road['samples'])) continue;
			$next=$s+$spacing;
			if((++$probes&15)===0) yield;
			if($opacity<.99) continue;
			foreach([-1.,1.] as $side) {
				$nx=-$tz*$side; $nz=$tx*$side; $edge=$y+$bank*$half*$side;
				$start=$road['radius']; $h=$height($x+$nx*$start,$z+$nz*$start);
				$gap=$edge-self::CROSS_FALL*$shoulder-$h;
				if($gap<1.5 || $h<=$water || $gap>$reach) continue;
				$initialGap=$gap;
				for($offset=$step;$offset<=$reach;$offset+=$step) {
					$d=$start+$offset;
					$h=$height($x+$nx*$d,$z+$nz*$d);
					if($h<=$water) break;
					$remaining=$edge-self::CROSS_FALL*($d-$half)-$h;
					if($remaining>0.) { $gap=$remaining; continue; }
					// Interpolate the first intersection of the sloped shoulder and the bank.
					$end=$d-$step+$step*$gap/($gap-$remaining);
					// The bank must close at least half the gap. A sloping shoulder also
					// intersects flat ground eventually, even when there is no hillside.
					if(self::CROSS_FALL*($end-$start) > .5*$initialGap) break;
					$apron=$spacing+8.; $pad=$apron*1.5;
					$ax=$x+$nx*$half; $az=$z+$nz*$half;
					$bx=$x+$nx*($end+$apron); $bz=$z+$nz*($end+$apron);
					$box=[min($ax,$bx)-$pad,min($az,$bz)-$pad,max($ax,$bx)+$pad,max($az,$bz)+$pad];
					$id=count($fills);
					$fills[]=[$x,$z,$edge,$half,$nx,$nz,$end,$apron,-$side*$grade,$box];
					foreach([0,1] as $k) {
						$bounds[$k]=min($bounds[$k],$box[$k]);
						$bounds[$k+2]=max($bounds[$k+2],$box[$k+2]);
					}
					$range[1]=max($range[1],$edge+abs($grade)*$pad);
					foreach(self::cells($box,$road['gridSize']) as [$gx,$gz]) $grid["$gx,$gz"][]=$id;
					break;
				}
			}
		}
		if($fills) {
			$road['fills']=$fills; $road['fillGrid']=$grid; $road['fillKey']=hash('sha256',serialize($fills));
			$road['bounds']=$bounds; $road['range']=$range;
		}
		return $road;
	}

	public static function cells(array $box,float $size): \Generator
	{
		for($x=(int)floor($box[0]/$size);$x<=(int)floor($box[2]/$size);$x++)
			for($z=(int)floor($box[1]/$size);$z<=(int)floor($box[3]/$size);$z++) yield [$x,$z];
	}

	public static function height(float $x,float $z,float $ground,array $road): float
	{
		$key=(int)floor($x/$road['gridSize']).','.(int)floor($z/$road['gridSize']);
		$result=$ground;
		foreach($road['fillGrid'][$key] ?? [] as $id) {
			[$sx,$sz,$edge,$half,$nx,$nz,$end,$apron,$grade]=$road['fills'][$id];
			$dx=$x-$sx; $dz=$z-$sz; $across=$dx*$nx+$dz*$nz; $along=$dz*$nx-$dx*$nz;
			$t=max(0.,min(1.,($across-$half)/$apron,($end+$apron-$across)/$apron,1.5-abs($along)/$apron));
			$surface=$edge+$grade*$along-self::CROSS_FALL*($across-$half);
			$result=max($result,$ground+($surface-$ground)*$t*$t*(3.-2.*$t));
		}
		return $result;
	}
}

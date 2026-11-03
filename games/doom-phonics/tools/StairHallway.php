<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/EmbeddedPlanarGraph.php';

/** A wide enclosed stair connection with level landings at both ends. */
final class StairHallway
{
	public const WIDTH=256;
	public const WALL=32;
	public const LANDING=256;

	/** The entrance is the high end; inward points toward the lower room. */
	public static function build(string $id,array $entrance,array $inward,int $from,int $to,int $ceiling): array
	{
		[$nx,$ny]=$inward;
		if(abs($nx)+abs($ny)!==1 || $from>=$to || ($to-$from)%16 || $ceiling-$to<192)
		throw new \InvalidArgumentException('Stair hall needs cardinal direction, quantized rise and standing headroom');
		$steps=intdiv($to-$from,16); $run=self::LANDING+$steps*64+128;
		$rect=static function(int $lo,int $hi,int $half) use ($entrance,$nx,$ny): array {
			$p=[]; foreach([[$lo,-$half],[$lo,$half],[$hi,$half],[$hi,-$half]] as [$n,$t])
			$p[]=[$entrance[0]+$nx*$n-$ny*$t,$entrance[1]+$ny*$n+$nx*$t];
			return $p;
		};
		$cells=[];
		for($s=0;$s<=$steps+1;$s++) {
			$lo=$s ? self::LANDING+($s-1)*64 : 0;
			$hi=$s===$steps+1 ? $run : ($s ? self::LANDING+$s*64 : self::LANDING);
			$floor=$to-16*min($steps,$s);
			$cells[]=PolygonGeometry::cell(['id'=>"$id.stair-$s",'floor'=>$floor,'ceiling'=>$ceiling,'tag'=>0,'light'=>192,
				'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5','role'=>'stair-hall'],$rect($lo,$hi,intdiv(self::WIDTH,2)));
		}
		return ['cells'=>$cells,'polygon'=>$rect(0,$run,intdiv(self::WIDTH,2)+self::WALL),
			'clearance'=>$rect(0,$run+128,intdiv(self::WIDTH,2)+self::WALL+128),
			'kind'=>'stair-hall','width'=>self::WIDTH,'wallThickness'=>self::WALL,'landing'=>self::LANDING,
			'bottomLanding'=>128,'from'=>$from,'to'=>$to,'entrance'=>$entrance,'inward'=>$inward];
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/SeedSequence.php';

/**
 * Split a reserved passage into fixed lintels and a repeatable moving door.
 *
 * The passage's first and last cross-sections meet the neighboring rooms.
 * Cardinal and diagonal reservations retain integer vertices; the panel height
 * stays at BIGDOOR2's authored height, independently of the surrounding ceiling.
 */
final class DoorRecess
{

	/** Roll independently for a named connection, without perturbing room layout randomness. */
	public static function selected(string $seed,string $connection,int $chance): bool
	{
		if($chance<0 || $chance>100) throw new \InvalidArgumentException('Door chance must be 0–100');
		return (new SeedSequence('room-door:'.json_encode([$seed,$connection],JSON_THROW_ON_ERROR)))->integer(0,99)<$chance;
	}

	/** Build from a clockwise rectangle whose first edge is the inner entrance. */
	public static function build(array $passage,array $neighbors): array
	{
		$p=PolygonGeometry::points($passage); $id=$passage['id'];
		if(count($p)!==4 || count($neighbors)!==2) throw new \InvalidArgumentException('Door needs a rectangular reservation and two neighbors');
		[$ax,$ay]=$p[0]; [$bx,$by]=$p[3];
		$dx=$bx-$ax; $dy=$by-$ay; $depth=hypot($dx,$dy);
		$width=hypot($p[1][0]-$ax,$p[1][1]-$ay);
		if($depth<112 || $width<64) throw new \InvalidArgumentException('Door reservation is too small');
		// Snap the inset along the major coordinate, preserving diagonal grid lines.
		$inset=round(40*max(abs($dx),abs($dy))/$depth/4)*4;
		$fraction=$inset/max(abs($dx),abs($dy));
		$recess=$depth*$fraction;
		if($recess<36 || $depth-2*$recess<40
			|| $passage['ceiling']-$passage['floor']<128)
		throw new \InvalidArgumentException('Door reservation needs clear lintels, panel and texture-height headroom');
		$at=static fn(int $i,float $t)=>[$p[$i][0]+$dx*$t,$p[$i][1]+$dy*$t];
		$rect=static fn(float $a,float $b)=>[$at(0,$a),$at(1,$a),$at(1,$b),$at(0,$b)];
		$cells=[];
		foreach([['inner',0,$fraction,$neighbors[0]],['outer',1-$fraction,1,$neighbors[1]]] as [$side,$lo,$hi,$neighbor])
		{
			$c=$passage; $c['id']="$id.$side"; $c['ceiling']=$c['floor']+128;
			$c['joins']=[$neighbor,$id]; $c['clearWidth']=$width;
			$c['wallYOffset']=$passage['ceiling']-$c['ceiling'];
			$cells[]=PolygonGeometry::cell($c,$rect($lo,$hi));
		}
		$c=$passage; $c['ceiling']=$c['floor']; $c['joins']=["$id.inner","$id.outer"];
		$c['clearWidth']=$width; $c['wallTexture']='DOORTRAK'; $c['lowerUnpegged']=true;
		$panel=$rect($fraction,1-$fraction); $cells[]=PolygonGeometry::cell($c,$panel);
		$faces=[];
		$edgeOffset=max(0,(int)floor(128-$width));
		$right=(new SeedSequence('door-crop:'.$id))->integer(0,1)===1;
		$crop=$right ? $edgeOffset : 0;
		foreach([[0,1,-1,'inner'],[2,3,1,'outer']] as [$a,$b,$side,$neighbor])
		$faces[]=['from'=>$panel[$a],'to'=>$panel[$b],'neighbor'=>"$id.$neighbor",
			'point'=>[($panel[$a][0]+$panel[$b][0])/2+$side*$dx/$depth*32,($panel[$a][1]+$panel[$b][1])/2+$side*$dy/$depth*32],
			'angle'=>atan2(-$side*$dy,-$side*$dx),
			'xOffset'=>$side<0 ? $crop : $edgeOffset-$crop];
		return ['cells'=>$cells,'door'=>['region'=>$id,'texture'=>'BIGDOOR2','width'=>$width,'height'=>128,
			'xOffset'=>$crop,'crop'=>$right ? 'right' : 'left',
			'recess'=>$recess,'depth'=>$depth-2*$recess,'faces'=>$faces]];
	}
}

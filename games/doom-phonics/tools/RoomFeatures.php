<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/OrganicPacking.php';

/** Solid obstacles and room-sized depressions, carved before navigation certification. */
final class RoomFeatures
{
	/** Select one treatment only where entrances and their landings remain clear. */
	public static function apply(array &$layer,string $name,string $seed): bool
	{
		if(($layer['role']??'room')!=='room' || isset($layer['gallery']) || isset($layer['lift'])) return false;
		$body=$layer['cells'][0]; $shell=PolygonGeometry::points($body);
		foreach($layer['cells'] as $cell) if(isset($cell['verticalPortal'])) return false;
		$w=$body['x1']-$body['x0']; $h=$body['y1']-$body['y0'];
		if(min($w,$h)<704) return false;
		$random=new SeedSequence("room-feature:$seed:$name"); $kind=['pillar','pillars','lowered-center'][$random->integer(0,2)];
		if(min($w,$h)<896) $kind='pillar';
		$x=(int)(round(($body['x0']+$body['x1'])/32)*16); $y=(int)(round(($body['y0']+$body['y1'])/32)*16);
		$openings=[];
		if($kind==='pillar') $openings[]=OrganicPacking::rectangle($x-128,$y-128,256,256);
		elseif($kind==='pillars') {
			$missing=$random->integer(0,3);
			foreach([[-192,-192],[-192,192],[192,192],[192,-192]] as $i=>[$dx,$dy])
			if($i!==$missing) $openings[]=OrganicPacking::rectangle($x+$dx-64,$y+$dy-64,128,128);
		} else {
			$width=(int)(floor(($w-512)/64)*64); $height=(int)(floor(($h-512)/64)*64);
			$openings[]=OrganicPacking::rectangle($x-intdiv($width,2),$y-intdiv($height,2),$width,$height);
		}
		foreach($openings as $opening) foreach($layer['reservations']??[] as $reserved) if(PolygonGeometry::overlaps($opening,$reserved['polygon'])) return false;
		foreach($openings as $opening) foreach($opening as $v)
		if(!PolygonGeometry::contains($shell,...[...$v,128])) return false;
		$parts=[$shell];
		foreach($openings as $opening) {
			$next=[]; foreach($parts as $part) array_push($next,...PolygonGeometry::surround($part,$opening)); $parts=$next;
		}
		$cells=[];
		foreach($parts as $i=>$p) $cells[]=PolygonGeometry::cell(array_replace($body,['id'=>$i ? "$name.feature-rim-$i" : $body['id']]),$p);
		if($kind==='lowered-center') $cells[]=PolygonGeometry::cell(array_replace($body,['id'=>"$name.lowered-center",'floor'=>$body['floor']-16]),$openings[0]);
		$layer['cells']=array_merge($cells,array_slice($layer['cells'],1));
		$layer['feature']=['kind'=>$kind,'footprints'=>$openings,'minimumPassage'=>128,'depth'=>$kind==='lowered-center' ? 16 : null,'exception'=>$kind==='lowered-center' ? 'Intentional depression; every edge is a traversable return step' : null];
		return true;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/DoorRecess.php';
require_once __DIR__ . '/ConformingRooms.php';
require_once __DIR__ . '/OutlinePacking.php';
require_once __DIR__ . '/OutdoorSpace.php';

/**
 * Pack rooms against shared planar constraints before opening their doorways.
 *
 * Moving a shared partition changes both neighboring outlines together. Each
 * room offsets the same dividing line inward, producing parallel wall faces and
 * a continuous solid wall network rather than overlapping independent outlines.
 */
final class RoomCluster
{
	/** Reserve two forty-unit lintels and a moving panel inside dividing walls. */
	public const WALL = 128;
	/** Minimum clear doorway width; the player diameter is 32 map units. */
	public const OPENING = 128;
	/** Every packed room contains a clear circle at least this wide. */
	public const MIN_ROOM_SIZE = 384;
	/** Room footprint before asymmetric exterior cutbacks. */
	private const FOOTPRINT = 2400;
	/** Keep stair approaches outside the packed room footprint. */
	public const APPROACH = 256;
	/** Radial cluster footprint plus the approach margin on both sides. */
	public const ENVELOPE_SIZE = self::FOOTPRINT + 2 * self::APPROACH;

	/** Carve an invading garden through an octagonal courtyard and its surrounding rooms. */
	public static function packed(string $name,int $floor,int $ceiling,int $variant,string $seed='',int $doorChance=0): array
	{
		$center=intdiv(self::ENVELOPE_SIZE,2);
		$lo=self::APPROACH; $hi=self::ENVELOPE_SIZE-$lo;
		$envelope=OutlinePacking::cluster($variant,$center);
		$outline=$envelope['outline'];
		$origin=[$center,$center]; $radius=576;
		$intrusion=RoomIntrusion::garden($seed.':'.$variant,$origin);
		$geometry=ConformingRooms::radial([[$lo,$lo],[$lo,$hi],[$hi,$hi],[$hi,$lo]],$origin,$radius,self::WALL,$envelope['pieces'],[],$intrusion['polygon']);
		// A clipped wedge may be too small to remain a separate room. Join it to
		// its largest interior neighbor rather than retaining a cramped sliver.
		for($attempt=0;$attempt<8;$attempt++) {
			$groups=$geometry['owners']; $garden=max($groups); $small=null;
			foreach(array_unique($groups) as $group) {
				if($group===0 || $group===$garden) continue;
				$spacious=false;
				foreach($geometry['rooms'] as $i=>$p) if($groups[$i]===$group && $p) {
					$c=PolygonGeometry::cell([], $p)['center'];
					if(PolygonGeometry::contains($p,...[...$c,self::MIN_ROOM_SIZE/2])) $spacious=true;
				}
				if(!$spacious) { $small=$group; break; }
			}
			if($small===null) break;
			$neighbors=[];
			foreach($geometry['partitions'] as $i=>$p) if($groups[$i]===$small)
			foreach($geometry['partitions'] as $j=>$q) {
				if(in_array($groups[$j],[$small,0,$garden],true) || !($edge=PolygonGeometry::shared($p,$q))) continue;
				$neighbors[$groups[$j]]=($neighbors[$groups[$j]]??0)+hypot($edge[1][0]-$edge[0][0],$edge[1][1]-$edge[0][1]);
			}
			if(!$neighbors) break;
			arsort($neighbors); $replacement=array_key_first($neighbors);
			$groups=array_map(static fn($g)=>$g===$small ? $replacement : $g,$groups);
			$geometry=ConformingRooms::offset($geometry['partitions'],$groups,self::WALL,true);
		}
		$partitions=$geometry['partitions']; $planes=$geometry['planes']; $groups=$geometry['owners'];
		$garden=max($groups); $rooms=[]; $gardenRegions=[];
		foreach($geometry['rooms'] as $i=>$polygon) {
			$id=$i ? "$name.room-$i" : "$name.core";
			if($groups[$i]===$garden) { $id="$name.garden".(count($gardenRegions) ? '-'.count($gardenRegions) : ''); $gardenRegions[]=$id; }
			$rooms[$i]=self::cell($id,$polygon,$floor,$ceiling)+['room'=>"$name.owner-".$groups[$i]];
			if($groups[$i]===0 || $groups[$i]===$garden) {
				$rooms[$i]['outdoor']=$groups[$i]===0 ? 'courtyard' : 'garden';
				$rooms[$i]['ceiling']=$ceiling+OutdoorSpace::ROOF_THICKNESS;
				$rooms[$i]['alignTextures']=true;
				$rooms[$i]['ceilingTexture']='F_SKY1'; $rooms[$i]['floorTexture']='FLOOR7_1';
				$rooms[$i]['light']=224;
			}
		}

		$doors=$walls=$adjacency=$operable=$doorways=$candidates=[];
		foreach($rooms as $i=>$a) foreach($rooms as $j=>$b)
		{
			if($j<=$i || !PolygonGeometry::shared($partitions[$i],$partitions[$j])) continue;
			if(!isset($planes[$i][$j])) { $adjacency[$i][]=$j; $adjacency[$j][]=$i; continue; }
			[$nx,$ny,$d,$inset]=$planes[$i][$j]; $length=hypot($nx,$ny); $normal2=$nx*$nx+$ny*$ny;
			$intervals=[];
			foreach([[$a,$d-$inset],[$b,$d+$inset]] as [$room,$face]) {
				$points=array_values(array_filter($room['polygon'],static fn($p)=>abs($nx*$p[0]+$ny*$p[1]-$face)<1e-6));
				if(count($points)<2) continue 2;
				$t=array_map(static fn($p)=>-$ny*$p[0]+$nx*$p[1],$points); $intervals[]=[min($t),max($t)];
			}
			$lo=max($intervals[0][0],$intervals[1][0]); $hi=min($intervals[0][1],$intervals[1][1]);
			$walls[]=['rooms'=>[$a['id'],$b['id']],'normal'=>[$nx,$ny],'faces'=>[$d-$inset,$d+$inset],
				'thickness'=>2*$inset/$length,'span'=>[$lo,$hi]];
			$id="$name.door-$i-$j";
			$selected=DoorRecess::selected($seed,$id,$doorChance);
			// Keep passages wide enough for free movement, including diagonal walls.
			$half=ceil(self::OPENING/2/$length/8)*8*$normal2;
			$tOrigin=-$ny*$origin[0]+$nx*$origin[1];
			$middle=$tOrigin+round((($lo+$hi)/2-$tOrigin)/(8*$normal2))*8*$normal2;
			if($middle-$half<$lo+8*$length || $middle+$half>$hi-8*$length) continue;
			$p=[];
			foreach([[$d-$inset,$middle-$half],[$d-$inset,$middle+$half],[$d+$inset,$middle+$half],[$d+$inset,$middle-$half]] as [$n,$t])
			$p[]=[($nx*$n-$ny*$t)/$normal2,($ny*$n+$nx*$t)/$normal2];
			$passage=self::cell($id,$p,$floor,$ceiling)+['joins'=>[$a['id'],$b['id']],'clearWidth'=>2*$half/$length];
			$selected=$selected && $passage['clearWidth']<=128;
			$candidates[]=compact('i','j','id','passage','selected');
		}
		$parents=array_keys($rooms);
		$root=static function(int $i) use (&$parents): int { while($parents[$i]!==$i) $i=$parents[$i]; return $i; };
		foreach($adjacency as $i=>$neighbors) foreach($neighbors as $j) $parents[$root($j)]=$root($i);
		$entrances=[];
		foreach($candidates as $candidate) {
			['i'=>$i,'j'=>$j,'id'=>$id,'passage'=>$passage,'selected'=>$selected]=$candidate;
			$a=$rooms[$i]; $b=$rooms[$j];
			$cut=$groups[$i]===$garden || $groups[$j]===$garden;
			$required=$root($i)!==$root($j);
			if($cut && !$required && count($entrances)>=2 && (new SeedSequence('intrusion-door:'.$seed.':'.$id))->integer(0,99)>=45) continue;
			$parents[$root($j)]=$root($i);
			if($cut) $entrances[]=['rooms'=>[$a['id'],$b['id']],'required'=>$required,'passage'=>$id];
			$doorways[]=$id;
			if($selected) {
				$recess=DoorRecess::build($passage,[$a['id'],$b['id']]);
				array_push($doors,...$recess['cells']);
				$operable[]=$recess['door']+['kind'=>'cluster','connection'=>[$a['id'],$b['id']]];
			} else $doors[]=$passage;
			$adjacency[$i][]=$j; $adjacency[$j][]=$i;
		}
		$seen=[]; $queue=[0];
		while($queue) { $i=array_pop($queue); if(isset($seen[$i])) continue; $seen[$i]=true; array_push($queue,...($adjacency[$i]??[])); }
		if(count($seen)!==count($rooms)) throw new \LogicException('Cluster has an inaccessible room');
		$cells=array_merge($rooms,$doors);
		$gardenDetails=OutdoorSpace::garden($cells,$gardenRegions,$origin);
		return ['kind'=>'conforming','cells'=>$cells,'walls'=>$walls,'rooms'=>array_column($rooms,'id'),
			'doorways'=>$doorways,'doors'=>$operable,'minimumWall'=>self::WALL,'minimumOpening'=>self::OPENING,
			'minimumRoomSize'=>self::MIN_ROOM_SIZE,'envelopeSize'=>self::ENVELOPE_SIZE,'outline'=>$outline,
			'pattern'=>'outline-first','intrusion'=>$intrusion+['rooms'=>$gardenRegions,'entrances'=>$entrances],
			'outdoors'=>['courtyard'=>[$name.'.core'],'garden'=>$gardenRegions]+$gardenDetails,
			'outlineComponents'=>$envelope['components'],
			'parameters'=>['center'=>$origin,'radius'=>$radius,'cornerCut'=>$radius/2,'wall'=>self::WALL]];
	}

	/** Build a rounded hall with four open entrances or repeatable doors around its central room. */
	public static function circular(string $name,int $floor,int $ceiling,bool $withDoors=false,int $sweep=180,int $rotation=0): array
	{
		if(!in_array($sweep,[90,180,270],true)) throw new \InvalidArgumentException('Concentric sweep must be 90, 180 or 270 degrees');
		$inner=[[-112,-224],[-224,-112],[-224,112],[-112,224],[112,224],[224,112],[224,-112],[112,-224]];
		// BIGDOOR2 is 128 × 128. Forty-unit lintels flank a 48-unit moving panel.
		$wallRadius=$withDoors ? 352 : 272; $corner=intdiv($wallRadius,2);
		$outerWall=[[-$corner,-$wallRadius],[-$wallRadius,-$corner],[-$wallRadius,$corner],[-$corner,$wallRadius],
			[$corner,$wallRadius],[$wallRadius,$corner],[$wallRadius,-$corner],[$corner,-$wallRadius]];
		$arcs=[
			[[-176,-352],[-272,-272],[-352,-176]], [[-352,-176],[-384,-64],[-384,64],[-352,176]],
			[[-352,176],[-272,272],[-176,352]], [[-176,352],[-64,384],[64,384],[176,352]],
			[[176,352],[272,272],[352,176]], [[352,176],[384,64],[384,-64],[352,-176]],
			[[352,-176],[272,-272],[176,-352]], [[176,-352],[64,-384],[-64,-384],[-176,-352]]
		];
		$arcs=array_map(static fn($arc)=>array_map(static fn($p)=>[$p[0]*1.5,$p[1]*1.5],$arc),$arcs);
		$shift=static fn($p)=>[$p[0]+832,$p[1]+832];
		$cells=[self::cell("$name.core",array_map($shift,$inner),$floor,$ceiling)+['room'=>"$name.core"]];
		$curved=[]; $gap=[];
		foreach($arcs as $i=>$arc) {
			$round=(($i-$rotation+8)%8)<$sweep/45;
			if($round) { $polygon=[...$arc,$outerWall[($i+1)%8],$outerWall[$i]]; $curved[]=$i; }
			else {
				// Fill the interrupted arc with straight gallery bays. Their deeper
				// corners and unequal run break the symmetry without random slopes.
				$polygon=[[-576,-576],[-576,576],[576,576],[576,-576]];
				$a=$outerWall[$i]; $b=$outerWall[($i+1)%8];
				$nx=$a[1]-$b[1]; $ny=$b[0]-$a[0]; $d=$nx*$a[0]+$ny*$a[1];
				$polygon=PolygonGeometry::clip($polygon,-$nx,-$ny,-$d);
				foreach([[-$a[1],$a[0]],[$b[1],-$b[0]]] as [$nx,$ny])
				$polygon=PolygonGeometry::clip($polygon,$nx,$ny,0);
				$gap[]=$i;
			}
			$cells[]=self::cell("$name.ring-$i",array_map($shift,$polygon),$floor,$ceiling)
				+['room'=>$round ? "$name.ring" : "$name.gallery",'curved'=>$round];
		}
		$doors=$walls=$operable=[];
		foreach($inner as $i=>$a) {
			$b=$inner[($i+1)%8]; $dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $length=hypot($dx,$dy);
			$walls[]=['rooms'=>["$name.core","$name.ring"],'normal'=>[-$dy/$length,$dx/$length],
				'faces'=>[($dx*$a[1]-$dy*$a[0])/$length,($dx*$outerWall[$i][1]-$dy*$outerWall[$i][0])/$length],
				'thickness'=>$i%2 ? $wallRadius-224 : ($wallRadius*1.5-336)/sqrt(2)];
			if($i%2===0) continue;
			$cx=($a[0]+$b[0])/2; $cy=($a[1]+$b[1])/2; $nx=-$dy/$length; $ny=$dx/$length;
			$rect=static function(int $lo,int $hi,int $half) use ($shift,$cx,$cy,$nx,$ny,$dx,$dy,$length): array {
				$p=[];
				foreach([[$lo,-$half],[$hi,-$half],[$hi,$half],[$lo,$half]] as [$n,$t])
				$p[]=$shift([$cx+$nx*$n+$dx/$length*$t,$cy+$ny*$n+$dy/$length*$t]);
				return $p;
			};
			$id="$name.door-$i"; $doors[]=$id;
			if(!$withDoors)
			{
				$cells[]=self::cell($id,$rect(0,48,64),$floor,$ceiling)+['joins'=>["$name.core","$name.ring-$i"],'clearWidth'=>128];
				continue;
			}
			$p=$rect(0,128,64);
			$reservation=self::cell($id,[$p[3],$p[0],$p[1],$p[2]],$floor,$ceiling);
			$recess=DoorRecess::build($reservation,["$name.core","$name.ring-$i"]);
			array_push($cells,...$recess['cells']);
			$operable[]=$recess['door']+['kind'=>'portal'];
		}
		return ['kind'=>'circular','cells'=>$cells,'walls'=>$walls,'rooms'=>["$name.core","$name.ring"],
			'doorways'=>$doors,'doors'=>$operable,'minimumWall'=>48,'minimumOpening'=>128,
			'minimumRoomSize'=>448,'outerDiameter'=>1152,'envelopeSize'=>1664,
			'sweep'=>$sweep,'rotation'=>$rotation*45,'curvedBays'=>$curved,'straightBays'=>$gap];
	}

	/** Supply material defaults and exact bounds for a convex room or passage. */
	private static function cell(string $id,array $polygon,int $floor,int $ceiling): array
	{
		return PolygonGeometry::cell(compact('id','floor','ceiling')+['tag'=>0,'light'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'],$polygon);
	}

}

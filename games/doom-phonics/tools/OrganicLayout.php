<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/OrganicPacking.php';
require_once __DIR__.'/StairHallway.php';

/** Fit walkable room subdivisions and reserved entrances to each planar embedding. */
final class OrganicLayout
{
	/** Material defaults shared by rooms, terraces and passages. */
	private static function cell(string $id,array $p,int $floor,int $ceiling): array
	{
		return PolygonGeometry::cell(compact('id','floor','ceiling')+['tag'=>0,'light'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5'],$p);
	}

	/** Build all three networks before portals, flood controls and final stacking. */
	public static function build(string $seed,int $budget,int $depth,string $upperPort,bool $withDoors,int $doorChance,callable $room,callable $cavern,int $recovery=0,bool $mirror=true): array
	{
		$layers=$pins=$topology=$repairs=$galleryRooms=[];
		foreach(['basement'=>1920,'ground'=>RoomCluster::ENVELOPE_SIZE,'upper'=>1664] as $plane=>$size) {
			$g=OrganicPacking::build($seed,$plane,$budget,$size,$recovery);
			$random=new SeedSequence("elevation-v13:$seed:$plane");
			$direction=[$random->integer(0,1) ? 1 : -1,$random->integer(0,1) ? 1 : -1];
			$rise=128+16*$random->integer(0,8);
			$values=[];
			foreach($g['candidates'] as $id=>$candidate) $values[$id]=$candidate['center'][0]*$direction[0]+$candidate['center'][1]*$direction[1];
			$lo=min($values); $hi=max($values); $floors=[0=>$plane==='basement' ? 0 : 64];
			foreach($values as $id=>$v) if($id) $floors[$id]=(int)round(($v-$lo)/max(1,$hi-$lo)*$rise/16)*16-($plane==='basement' ? $rise : 0);
			// The full basin surface is architectural Z=0. Ground rooms keep room
			// for a 32-unit recessed aperture and the 32-unit slab below it.
			if($plane==='ground') foreach($floors as $id=>$floor) {
				$floors[$id]=max(64,$floor);
				if($floor<64) $repairs[]=['kind'=>'flood-slab','room'=>"$plane.$id",'from'=>$floor,'to'=>64,'reason'=>'Reserve a recessed portal and solid slab above the normalized basement ceiling'];
			}
			if($recovery>=2) foreach($floors as $id=>$_) $floors[$id]=$floors[0];
			$floors[1]=$floors[0];
			$ports=['east'=>128];
			if($plane==='basement') $ports['north']=128;
			if($plane==='ground') { $ports['south']=128; $ports['north']=160; }
			if($plane==='upper') $ports[$upperPort]=160;
			$passages=[];
			foreach($ports as $port=>$width) $passages[$port]=['height'=>$port==='east' ? $floors[0] : 0,'ceiling'=>256];
			if($plane==='basement') $special=$cavern($ports,$depth,$passages,1920,[1920,1920]);
			else $special=$room("$plane.0",$ports,64,256,256,$passages,0,false,0,$plane==='ground' ? 'conforming' : 'circular',$withDoors,$size,$seed,$doorChance,[$size,$size]);
			$layers["$plane.0"]=$special+['plane'=>$plane,'grid'=>[0,0],'localFloor'=>$floors[0],'role'=>$plane==='basement' ? 'pool' : ($plane==='ground' ? 'courtyard' : 'hub')];
			$rooms=[]; $pieces=[];
			foreach($g['rooms'] as $i=>$p) {
				$owner=$g['owners'][$i]; if(!$owner) continue;
				$name="$plane.$owner";
				$pieces[$name][]=$p; $rooms[$i]=$name;
			}
			if($g['galleryOwner']!==null && !isset($pieces["$plane.{$g['galleryOwner']}"])) $g['galleryOwner']=null;
			// Reserve one large gallery before stairs and other room treatments.
			if($budget>=6) foreach($pieces as $name=>$parts) {
				$id=(int)substr($name,strrpos($name,'.')+1);
				if($id===1 || ($g['galleryOwner']!==null && $id!==$g['galleryOwner'])) continue;
				usort($parts,static fn($a,$b)=>PolygonGeometry::area($b)<=>PolygonGeometry::area($a)); $pieces[$name]=$parts;
				$c=PolygonGeometry::cell([], $parts[0]);
				if(min($c['x1']-$c['x0'],$c['y1']-$c['y0'])>=1024) { $galleryRooms[$plane]=$name; break; }
			}
			if($g['galleryOwner']!==null) $galleryRooms[$plane]="$plane.{$g['galleryOwner']}";
			// Connection IDs name a boundary, never a compass direction: several openings may share a wall.
			$edges=[];
			foreach($g['partitions'] as $i=>$a) foreach($g['partitions'] as $j=>$b) {
				if($j<=$i || $g['owners'][$i]===$g['owners'][$j] || !isset($g['planes'][$i][$j])) continue;
				[$nx,$ny,$d,$inset]=$g['planes'][$i][$j];
				$pa=$g['rooms'][$i]; $pb=$g['rooms'][$j]; $n2=$nx*$nx+$ny*$ny; $len=sqrt($n2);
				$intervals=[];
				foreach([[$pa,$d-$inset],[$pb,$d+$inset]] as [$p,$face]) {
					$ts=[]; foreach($p as $v) if(abs($nx*$v[0]+$ny*$v[1]-$face)<.01) $ts[]=-$ny*$v[0]+$nx*$v[1];
					if(count($ts)<2) continue 2; $intervals[]=[min($ts),max($ts)];
				}
				$low=max($intervals[0][0],$intervals[1][0]); $high=min($intervals[0][1],$intervals[1][1]);
				$half=(int)(ceil(64/$len/8)*8*$n2); $t=(int)(round(($low+$high)/2/(8*$n2))*8*$n2);
				$oa=$g['owners'][$i]; $ob=$g['owners'][$j];
				if($oa===0) $t=$nx*$size/2; // The fixed special entrance is centered on its east wall.
				if($t-$half<$low || $t+$half>$high) continue;
				$edges[]=['id'=>"edge-$i-$j",'a'=>$oa,'b'=>$ob,'nx'=>$nx,'ny'=>$ny,'d'=>$d,'inset'=>$inset,'t'=>$t,'half'=>$half,'i'=>$i,'j'=>$j];
			}
			// Cuts can leave gaps rather than shared walls. Reconnect their planar
			// components through visible straight corridors before dropping a remnant.
			for($repair=0;$repair<count($pieces);$repair++) {
				$component=[]; foreach($g['owners'] as $owner) $component[$owner]=$owner;
				foreach($edges as $e) { $old=$component[$e['b']]; $new=$component[$e['a']]; foreach($component as &$v) if($v===$old) $v=$new; unset($v); }
				$best=null;
				foreach($g['rooms'] as $i=>$p) foreach($g['rooms'] as $j=>$q) {
					if($j<=$i || !$g['owners'][$i] || $component[$g['owners'][$i]]===$component[$g['owners'][$j]]) continue;
					foreach($p as $k=>$a) {
						$b=$p[($k+1)%count($p)]; $dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; if($dx && $dy) continue;
						$nx=$dy ? -($dy<=>0) : 0; $ny=$dx ? ($dx<=>0) : 0; $fa=$nx*$a[0]+$ny*$a[1];
						foreach($q as $l=>$c) {
							$d=$q[($l+1)%count($q)]; if(abs($nx*($d[0]-$c[0])+$ny*($d[1]-$c[1]))>1e-6) continue;
							$fb=$nx*$c[0]+$ny*$c[1]; $gap=$fb-$fa;
							if($gap<128 || $gap>1024 || ($best && $gap>=$best['gap'])) continue;
							$ta=[-$ny*$a[0]+$nx*$a[1],-$ny*$b[0]+$nx*$b[1]]; $tb=[-$ny*$c[0]+$nx*$c[1],-$ny*$d[0]+$nx*$d[1]];
							$lo=max(min($ta),min($tb)); $hi=min(max($ta),max($tb)); $t=(int)(round(($lo+$hi)/16)*8);
							if($t-64<$lo+16 || $t+64>$hi-16) continue;
							$poly=[]; foreach([[$fa,$t-64],[$fa,$t+64],[$fb,$t+64],[$fb,$t-64]] as [$n,$v]) $poly[]=[$nx*$n-$ny*$v,$ny*$n+$nx*$v];
							$clear=true; foreach($g['rooms'] as $other) if(PolygonGeometry::overlaps($poly,$other)) { $clear=false; break; }
							if(!$clear) continue;
							$best=['id'=>"gap-$i-$j",'a'=>$g['owners'][$i],'b'=>$g['owners'][$j],'nx'=>$nx,'ny'=>$ny,'d'=>($fa+$fb)/2,'inset'=>$gap/2,'t'=>$t,'half'=>64,'i'=>$i,'j'=>$j,'gap'=>$gap];
						}
					}
				}
				if(!$best) break; $edges[]=$best;
				$repairs[]=['kind'=>'hallway','rooms'=>["$plane.{$best['a']}","$plane.{$best['b']}"],'length'=>$best['gap'],'reason'=>'Reconnect surviving graph components across the cut gap'];
			}
			$seen=[0=>true]; $changed=true;
			while($changed) { $changed=false; foreach($edges as $e) if(isset($seen[$e['a']]) xor isset($seen[$e['b']])) { $seen[$e['a']]=$seen[$e['b']]=true; $changed=true; } }
			foreach(array_keys($pieces) as $name) {
				$id=(int)substr($name,strrpos($name,'.')+1);
				if(isset($seen[$id])) continue;
				unset($pieces[$name]); $repairs[]=['kind'=>'remove-remnant','room'=>$name,'reason'=>'No full-width entrance survives the structural cut'];
				if(($galleryRooms[$plane]??null)===$name) { unset($galleryRooms[$plane]); $g['galleryOwner']=null; }
			}
			$edges=array_values(array_filter($edges,static fn($e)=>isset($seen[$e['a']],$seen[$e['b']])));
			$floors=array_intersect_key($floors,$seen);
			// Coalesce incompatible elevation regions. Each repair removes one degree of
			// freedom, so this converges in at most the number of surviving room nodes.
			$groups=[]; foreach($floors as $id=>$floor) $groups[$id]=$id<=1 ? 0 : $id;
			for($repair=0;$repair<count($floors);$repair++) {
				$reserved=[]; $conflict=null;
				foreach($edges as $e) {
					$a=$e['a']; $b=$e['b']; if($floors[$a]===$floors[$b]) continue;
					$low=$floors[$a]<$floors[$b] ? $a : $b; $high=$low===$a ? $b : $a;
					// Keep one major ground chamber's approaches level so its interior
					// can hold a broad ledge rather than several separate stair halls.
					if($plane==='ground' && $budget>=6 && $low===1) { $conflict=[$a,$b]; break; }
					if(!$low || ($e['nx'] && $e['ny'])) { $conflict=[$a,$b]; break; }
					$sign=$low===$a ? -1 : 1; $face=$e['d']+$sign*$e['inset'];
					$nx=$e['nx']; $ny=$e['ny']; $t=$e['t'];
					$hall=StairHallway::build($e['id'],[$nx*$face-$ny*$t,$ny*$face+$nx*$t],[$nx*$sign,$ny*$sign],$floors[$low],$floors[$high],max($floors)+192);
					$p=$hall['clearance']; $covered=0;
					foreach($pieces["$plane.$low"] as $part) $covered+=PolygonGeometry::area(PolygonGeometry::intersect($part,$p));
					$fits=abs($covered-PolygonGeometry::area($p))<.01;
					if(($galleryRooms[$plane]??null)==="$plane.$low") $fits=false;
					foreach($reserved[$low]??[] as $other) if(PolygonGeometry::overlaps($p,$other)) $fits=false;
					if(!$fits) { $conflict=[$a,$b]; break; }
					$reserved[$low][]=$p;
				}
				if(!$conflict) break;
				[$a,$b]=$conflict; $ga=$groups[$a]; $gb=$groups[$b]; $ids=[];
				foreach($groups as $id=>$group) if($group===$ga || $group===$gb) $ids[]=$id;
				$height=in_array(0,$ids,true) ? $floors[0] : (int)round(array_sum(array_intersect_key($floors,array_flip($ids)))/count($ids)/16)*16;
				foreach($ids as $id) { $groups[$id]=min($ga,$gb); $floors[$id]=$height; }
				$repairs[]=['kind'=>'clearance','rooms'=>array_map(static fn($id)=>"$plane.$id",$ids),'to'=>$height,'reason'=>'Coalesce elevations where a 256-unit-wide enclosed stair hall, top landing and room circulation cannot fit'];
			}
			if($plane==='basement' && max($floors)+192<$depth)
			$repairs[]=['kind'=>'flood-ceiling','layer'=>$plane,'from'=>max($floors)+192-$depth,'to'=>0,'reason'=>'Keep full water at or below every ordinary room ceiling and its portal openings'];
			foreach($pieces as $name=>$parts) {
				$id=(int)substr($name,strrpos($name,'.')+1); $floor=$floors[$id];
				// Normalize ordinary basement ceilings to at least the full water
				// height, before doors, galleries and portal apertures are detailed.
				$ceiling=max(max($floors)+192,$plane==='basement' ? $depth : 0);
				$cells=[]; foreach($parts as $k=>$p) $cells[]=self::cell($name.($k ? ".piece-$k" : '.core'),$p,$floor,$ceiling)+['room'=>$name];
				$layers[$name]=['plane'=>$plane,'grid'=>$g['candidates'][$id]['center']??[0,0],'localFloor'=>$floor,'cells'=>$cells,'anchors'=>[],'corridors'=>[],'ceiling'=>$ceiling,'merged'=>null,'role'=>'room','footprint'=>['polygon'=>$parts[0],'dimensions'=>[$cells[0]['x1']-$cells[0]['x0'],$cells[0]['y1']-$cells[0]['y0']]],'tier'=>$g['candidates'][$id]['tier']??'remnant'];
				if(isset($g['candidates'][$id]['sourceRectangles'])) $layers[$name]['footprint']['sourceRectangles']=$g['candidates'][$id]['sourceRectangles'];
				if($plane==='ground' && $budget>=6 && $id===1) $layers[$name]['ledgeCandidate']=true;
			}
			if($g['galleryOwner']!==null) $layers[$galleryRooms[$plane]]['galleryCenter']=$g['candidates'][$g['galleryOwner']]['center'];
			// Stable start/goal roles survive cuts and merges; choose ordinary usable interiors.
			$names=array_keys($pieces); $spawn=$names[0]; $goal=end($names);
			if($goal===($galleryRooms[$plane]??null)) $goal=$names[max(0,count($names)-2)];
			$layers[$spawn]['role']=$plane==='basement' ? 'spawn' : $layers[$spawn]['role'];
			$layers[$goal]['role']=$plane==='upper' ? 'goal' : $layers[$goal]['role'];
			foreach($edges as $e) {
				['nx'=>$nx,'ny'=>$ny,'d'=>$d,'inset'=>$inset,'t'=>$t,'half'=>$half]=$e;
				$a="$plane.{$e['a']}"; $b="$plane.{$e['b']}"; $n2=$nx*$nx+$ny*$ny;
				$point=static fn($n,$v)=>[(int)(($nx*$n-$ny*$v)/$n2),(int)(($ny*$n+$nx*$v)/$n2)];
				$height=max($floors[$e['a']],$floors[$e['b']]); $roof=max($layers[$a]['ceiling'],$layers[$b]['ceiling'],$height+192);
				$id=$e['id'];
				// Enclose the widened connection so ordinary room floors remain level.
				foreach([[$a,$e['a'],-1,$d-$inset],[$b,$e['b'],1,$d+$inset]] as [$name,$owner,$sign,$face]) {
					if(!$owner || $floors[$owner]===$height) continue;
					self::stairHall($layers[$name],StairHallway::build("$name.$id",$point($face,$t),[$nx*$sign,$ny*$sign],$floors[$owner],$height,$layers[$name]['ceiling']));
				}
				$middle=$d; $width=(int)(2*$half/sqrt($n2));
				foreach([[$a,-1,$d-$inset],[$b,1,$d+$inset]] as [$name,$sign,$face]) {
					if($e['a']===0 && $name===$a) continue;
					$near=$e['a']===0 && $name===$b ? $d : $middle;
					$p=[$point($near,$t-$half),$point($near,$t+$half),$point($face,$t+$half),$point($face,$t-$half)];
					if($sign<0) $p=array_reverse($p);
					$cellId="$name.$id"; $layers[$name]['cells'][]=self::cell($cellId,$p,$height,$roof);
					$layers[$name]['anchors'][$id]=['point'=>[...$point($middle,$t),$height],'normal'=>[-$nx*$sign,-$ny*$sign,0],'size'=>[$width,$roof-$height]];
					$layers[$name]['corridors'][$id]=['from'=>$height,'to'=>$height,'bend'=>0,'length'=>$inset,'width'=>$width,'cells'=>[$cellId]];
				}
				if($e['a']===0) {
					$layers[$b]['anchors'][$id]['size']=$layers[$a]['anchors']['east']['size'];
					$layers[$b]['anchors'][$id]['point'][2]=$layers[$a]['anchors']['east']['point'][2];
				}
				if($e['a']!==0 && $width===128 && $inset<=128 && DoorRecess::selected($seed,"$plane:$id",$doorChance)) {
					$p=[$point($d-$inset,$t-$half),$point($d-$inset,$t+$half),$point($d+$inset,$t+$half),$point($d+$inset,$t-$half)];
					$neighbors=[];
					foreach([[$a,[$p[0],$p[1]]],[$b,[$p[2],$p[3]]]] as [$name,$edge]) {
						$layers[$name]['cells']=array_values(array_filter($layers[$name]['cells'],static fn($c)=>$c['id']!=="$name.$id"));
						foreach($layers[$name]['cells'] as $c) if(PolygonGeometry::shared(PolygonGeometry::points($c),$edge)) { $neighbors[]=$c['id']; break; }
					}
					$recess=DoorRecess::build(self::cell("$a.$id.door",$p,$height,$roof),$neighbors);
					array_push($layers[$a]['cells'],...$recess['cells']);
					$layers[$a]['doors'][]=$recess['door']+['kind'=>'connection','connection'=>[$a,$b],'connectionId'=>"$plane:$id"];
					$layers[$a]['anchors'][$id]['point']=$layers[$b]['anchors'][$id]['point']=[...$point($d+$inset,$t),$height];
					$layers[$a]['corridors'][$id]['cells']=array_column($recess['cells'],'id');
					$layers[$b]['corridors'][$id]['cells']=[];
				}
				$pins[]=[$a,$e['a']===0 ? 'east' : $id,$b,$id];
			}
			$topology[$plane]=['graph'=>$g['graph'],'rooms'=>array_map(static fn($c)=>$c['center'],$g['candidates']),'edges'=>array_map(static fn($e)=>['from'=>$e['a'],'to'=>$e['b'],'id'=>$e['id']],$edges),'loops'=>count($edges)-count($pieces),'anchors'=>$g['anchors'],'candidates'=>$g['candidates'],'requested'=>$budget,'surviving'=>count($pieces)+1,'history'=>$g['history'],'elevation'=>['direction'=>$direction,'rise'=>$rise,'requested'=>$values,'floors'=>$floors]];
		}
		// Grow the upper graph away from the curved connector's occupied columns.
		if(!$mirror) {
			foreach($layers as $name=>&$layer) {
				if($layer['plane']!=='upper') continue;
				foreach($layer['cells'] as &$c) $c=PolygonGeometry::cell($c,array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],PolygonGeometry::points($c)))); unset($c);
				foreach($layer['anchors'] as &$a) { $a['point'][0]=1664-$a['point'][0]; $a['normal'][0]*=-1; } unset($a);
				foreach(['doors','cluster'] as $key) {
					if(!isset($layer[$key])) continue;
					$doors=$key==='cluster' ? ($layer[$key]['doors']??[]) : $layer[$key];
					foreach($doors as &$door) foreach($door['faces'] as &$face) {
						foreach(['from','to','point'] as $point) $face[$point][0]=1664-$face[$point][0];
						[$face['from'],$face['to']]=[$face['to'],$face['from']]; $face['angle']=M_PI-$face['angle'];
					} unset($face,$door);
					if($key==='cluster') $layer[$key]['doors']=$doors; else $layer[$key]=$doors;
				}
				if(isset($layer['galleryCenter'])) $layer['galleryCenter'][0]=1664-$layer['galleryCenter'][0];
				foreach($layer['reservations']??[] as $i=>$reservation) {
					foreach(['polygon','clearance'] as $key) if(isset($reservation[$key])) $layer['reservations'][$i][$key]=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$reservation[$key]));
					if(isset($reservation['entrance'])) $layer['reservations'][$i]['entrance'][0]=1664-$reservation['entrance'][0];
					if(isset($reservation['inward'])) $layer['reservations'][$i]['inward'][0]*=-1;
				}
				if(isset($layer['footprint']['polygon'])) $layer['footprint']['polygon']=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$layer['footprint']['polygon']));
				foreach($layer['footprint']['sourceRectangles']??[] as $i=>$polygon) $layer['footprint']['sourceRectangles'][$i]=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$polygon));
			} unset($layer);
			$layers['upper.0']['anchors']['west']=$layers['upper.0']['anchors']['east']; unset($layers['upper.0']['anchors']['east']);
			$layers['upper.0']['corridors']['west']=$layers['upper.0']['corridors']['east']; unset($layers['upper.0']['corridors']['east']);
			foreach($pins as &$pin) if($pin[0]==='upper.0' && $pin[1]==='east') $pin[1]='west'; unset($pin);
			$topology['upper']['elevation']['direction'][0]*=-1;
			foreach($topology['upper']['anchors'] as &$polygon) $polygon=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$polygon)); unset($polygon);
			foreach($topology['upper']['candidates'] as &$candidate) {
				$candidate['center'][0]=1664-$candidate['center'][0];
				foreach($candidate['sourceRectangles']??[] as $i=>$polygon) $candidate['sourceRectangles'][$i]=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$polygon));
			} unset($candidate);
			foreach($topology['upper']['history'] as &$event) {
				if(isset($event['footprint'])) $event['footprint']=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$event['footprint']));
				foreach($event['rectangles']??[] as $i=>$polygon) $event['rectangles'][$i]=array_reverse(array_map(static fn($p)=>[1664-$p[0],$p[1]],$polygon));
			} unset($event);
			foreach($topology['upper']['rooms'] as &$center) $center[0]=1664-$center[0]; unset($center);
			$topology['upper']['reflection']=['axis'=>'x','origin'=>832];
		}
		if(count($galleryRooms)!==3) $galleryRooms=[];
		$pins[]=['basement.0','north','ground.0','south'];
		$pins[]=['ground.0','north','spiral','in']; $pins[]=['spiral','out','upper.0',$upperPort];
		return compact('layers','pins','topology','repairs','galleryRooms');
	}

	/** Carve a hallway envelope; its side walls are solid voids, never exposed risers. */
	private static function stairHall(array &$layer,array $hall): void
	{
		$kept=[]; $id=count($layer['reservations']??[]);
		foreach($layer['cells'] as $cell) {
			$p=PolygonGeometry::points($cell);
			if(!PolygonGeometry::overlaps($p,$hall['polygon'])) { $kept[]=$cell; continue; }
			foreach(PolygonGeometry::surround($p,$hall['polygon']) as $k=>$q) $kept[]=PolygonGeometry::cell(array_replace($cell,['id'=>$cell['id'].($k ? ".hall-rim-$id-$k" : '')]),$q);
		}
		$layer['cells']=array_merge($kept,$hall['cells']);
		$layer['elevation']=['kind'=>'hallway-stairs','minimumLanding'=>StairHallway::LANDING];
		unset($hall['cells']); $layer['reservations'][]=$hall;
	}
}

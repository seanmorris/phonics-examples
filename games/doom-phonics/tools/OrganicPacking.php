<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__.'/ConformingRooms.php';
require_once __DIR__.'/EmbeddedPlanarGraph.php';
require_once __DIR__.'/CompoundRooms.php';
require_once __DIR__.'/RoomJoin.php';

/** Fit logical rooms to an embedded planar graph, preserving its shared boundaries. */
final class OrganicPacking
{
	/** Shared graph geometry helpers, also used for structural cuts. */
	public static function rectangle(int $x,int $y,int $w,int $h): array { return EmbeddedPlanarGraph::rectangle($x,$y,$w,$h); }
	public static function distance(array $p,array $anchors): float { return EmbeddedPlanarGraph::distance($p,$anchors); }
	public static function size(float $distance): int { return EmbeddedPlanarGraph::size($distance); }

	/** Split disconnected survivors into logical rooms, retaining open convex seams. */
	public static function components(array $partitions,array $owners): array
	{
		$next=max($owners)+1; $seen=[]; $result=$owners; $history=[]; $first=[];
		foreach($partitions as $i=>$p) {
			if(isset($seen[$i])) continue;
			$owner=$owners[$i]; $group=isset($first[$owner]) ? $next++ : $owner; $first[$owner]=true;
			$queue=[$i]; $seen[$i]=true;
			for($k=0;$k<count($queue);$k++) {
				$a=$queue[$k]; $result[$a]=$group;
				foreach($partitions as $j=>$q) {
					if(isset($seen[$j]) || $owners[$j]!==$owner) continue;
					$edge=PolygonGeometry::shared($partitions[$a],$q);
					if(!$edge || hypot($edge[1][0]-$edge[0][0],$edge[1][1]-$edge[0][1])<128) continue;
					$seen[$j]=true; $queue[]=$j;
				}
			}
			if($group!==$owner) $history[]=['kind'=>'split','from'=>$owner,'to'=>$group];
		}
		return ['partitions'=>$partitions,'owners'=>$result,'history'=>$history];
	}

	/** Apply an overlapping shape, including full deletion and disconnected survivors. */
	public static function insert(array $partitions,array $owners,array $polygon,int $owner): array
	{
		$pieces=$groups=$history=[];
		foreach($partitions as $i=>$p) {
			$kept=PolygonGeometry::surround($p,$polygon);
			if(PolygonGeometry::overlaps($p,$polygon)) $history[]=['kind'=>$kept ? 'cut' : 'delete','owner'=>$owners[$i],'by'=>$owner];
			foreach($kept as $q) { $pieces[]=$q; $groups[]=$owners[$i]; }
		}
		$pieces[]=$polygon; $groups[]=$owner;
		$result=self::components($pieces,$groups);
		$result['history']=array_merge($history,$result['history']);
		return $result;
	}

	/** Build bounded largest-first candidates around an existing special reservation. */
	public static function build(string $seed,string $plane,int $budget,int $specialSize,int $recovery=0): array
	{
		$graph=EmbeddedPlanarGraph::build($seed,$plane,$budget,$specialSize);
		$partitions=$graph['reservations']; $owners=$graph['owners']; $anchors=$graph['anchors'];
		$candidates=$graph['nodes']; $history=$graph['history'];
		$r=new SeedSequence("organic-shapes:$seed:$plane");
		// Facet exposed anchor corners only when the graph's complete entrance spans survive.
		foreach($partitions as $i=>&$polygon) {
			if(!$i || $candidates[$owners[$i]]['tier']!=='anchor' || $i%2!==1) continue;
			$c=PolygonGeometry::cell([], $polygon); $cut=(int)(floor(min($c['x1']-$c['x0'],$c['y1']-$c['y0'])/256)*64);
			foreach([[1,1,$c['x1']+$c['y1']-$cut],[-1,1,-$c['x0']+$c['y1']-$cut],[-1,-1,-$c['x0']-$c['y0']-$cut],[1,-1,$c['x1']-$c['y0']-$cut]] as [$nx,$ny,$d]) {
				$next=PolygonGeometry::clip($polygon,$nx,$ny,$d); $valid=true;
				foreach($graph['edges'] as $edge) {
					if(!in_array($owners[$i],[$edge['from'],$edge['to']],true)) continue;
					$contact=PolygonGeometry::shared($next,$edge['boundary']);
					if(!$contact || hypot($contact[1][0]-$contact[0][0],$contact[1][1]-$contact[0][1])<384) $valid=false;
				}
				if($valid) $polygon=$next;
			}
		}
		unset($polygon);
		// Fit each footprint to the embedded graph. A later neighbor must preserve
		// the same contact as the already fitted room, not a different part of its wall.
		$fitted=[];
		foreach($partitions as $i=>$polygon) $fitted[$owners[$i]]=[$polygon];
		foreach($partitions as $i=>$polygon) {
			$owner=$owners[$i]; if(!$owner) continue;
			if(count($polygon)>4) { $candidates[$owner]['shape']='faceted'; continue; }
			$contacts=[];
			foreach($graph['edges'] as $edge) {
				if(!in_array($owner,[$edge['from'],$edge['to']],true)) continue;
				$other=$edge['from']===$owner ? $edge['to'] : $edge['from'];
				$contacts[]=['pieces'=>$fitted[$other],'boundary'=>$edge['boundary']];
			}
			$random=new SeedSequence("compound:$seed:$plane:$owner");
			$count=$recovery || $owner===1 ? 1 : $random->integer(1,3);
			$fit=CompoundRooms::fit($polygon,$contacts,$random,$count);
			$fitted[$owner]=$fit['pieces'];
			$candidates[$owner]['shape']='rectangles';
			$candidates[$owner]['sourceRectangles']=$fit['rectangles'];
			if(count($fit['rectangles'])>1) $history[]=['kind'=>'compound','owner'=>$owner,'rectangles'=>$fit['rectangles']];
		}
		$partitions=$owners=[];
		foreach($fitted as $owner=>$pieces) foreach($pieces as $piece) { $partitions[]=$piece; $owners[]=$owner; }
		// A selected gallery is a graph-node insertion. Its footprint can replace several
		// neighboring nodes; all affected contacts are rebuilt before wall reservations.
		$galleryOwner=null;
		if($budget>=6 && !$recovery) {
			foreach($partitions as $i=>$p) {
				if($owners[$i]<2) continue;
				$c=PolygonGeometry::cell([], $p); $x=(int)(round($c['center'][0]/64)*64); $y=(int)(round($c['center'][1]/64)*64);
				$footprint=self::rectangle($x-640,$y-640,1280,1280);
				$valid=true;
				foreach($partitions as $j=>$q) if($owners[$j]<=1 && PolygonGeometry::overlaps($q,$footprint)) $valid=false;
				if(!$valid) continue;
				$galleryOwner=max($owners)+1;
				$inserted=self::insert($partitions,$owners,$footprint,$galleryOwner);
				$partitions=$inserted['partitions']; $owners=$inserted['owners'];
				array_push($history,...$inserted['history']);
				$history[]=['kind'=>'gallery-insertion','owner'=>$galleryOwner,'footprint'=>$footprint];
				$candidates[$galleryOwner]=['role'=>'gallery','tier'=>'reservation','distance'=>self::distance([$x,$y],$anchors),'size'=>1280,'center'=>[$x,$y]];
				// Narrow remnants become part of an adjacent logical room. The gallery's
				// square stays intact while its optional peripheral pieces remain open.
				for($repair=0;$repair<32;$repair++) {
					$changed=false;
					foreach($partitions as $j=>$q) {
						if($owners[$j]<=1 || $owners[$j]===$galleryOwner) continue;
						$vertices=[];
						foreach($partitions as $k=>$piece) if($owners[$k]===$owners[$j]) array_push($vertices,...$piece);
						$xs=array_column($vertices,0); $ys=array_column($vertices,1);
						if(min(max($xs)-min($xs),max($ys)-min($ys))>=512) continue;
						$best=null; $length=0;
						foreach($partitions as $k=>$neighbor) {
							if($owners[$k]===$owners[$j] || $owners[$k]<=1) continue;
							$area=0; foreach($partitions as $index=>$piece) if(in_array($owners[$index],[$owners[$j],$owners[$k]],true)) $area+=PolygonGeometry::area($piece);
							if($area>RoomJoin::MAX_AREA) continue;
							$edge=PolygonGeometry::shared($q,$neighbor); if(!$edge) continue;
							$l=hypot($edge[1][0]-$edge[0][0],$edge[1][1]-$edge[0][1]);
							if($l>$length) { $best=$owners[$k]; $length=$l; }
						}
						if($best!==null) { $history[]=['kind'=>'remnant-merge','from'=>$owners[$j],'to'=>$best]; $old=$owners[$j]; $owners=array_map(static fn($o)=>$o===$old ? $best : $o,$owners); $changed=true; }
					}
					if(!$changed) break;
				}
				foreach($partitions as $j=>$q) if(!isset($candidates[$owners[$j]]))
				$candidates[$owners[$j]]=['role'=>'remnant','tier'=>'remnant','distance'=>0,'size'=>512,'center'=>PolygonGeometry::cell([],$q)['center']];
				break;
			}
		}
		for($repair=0;$repair<32;$repair++) {
			$probe=ConformingRooms::offset($partitions,$owners,128,true); $remove=[];
			foreach($probe['rooms'] as $i=>$p) if(!$p) $remove[]=$i;
			if(!$remove) break;
			$partitions=$probe['partitions']; $owners=$probe['owners'];
			foreach(array_reverse($remove) as $i) {
				$history[]=['kind'=>'remove-remnant','owner'=>$owners[$i],'reason'=>'Wall offsets consume this fragment'];
				array_splice($partitions,$i,1); array_splice($owners,$i,1);
			}
		}
		// Merge selected neighboring candidates before walls, retaining exact concavities.
		$unmerged=$owners; $claimed=[];
		foreach($partitions as $i=>$p) foreach($partitions as $j=>$q) {
			if($recovery || $j<=$i || $owners[$i]===$owners[$j] || $owners[$i]<=1 || $owners[$j]<=1 || isset($claimed[$owners[$i]]) || isset($claimed[$owners[$j]]) || $owners[$i]===$galleryOwner || $owners[$j]===$galleryOwner) continue;
			$edge=PolygonGeometry::shared($p,$q);
			if(!$edge || hypot($edge[1][0]-$edge[0][0],$edge[1][1]-$edge[0][1])<384 || PolygonGeometry::area($p)+PolygonGeometry::area($q)>RoomJoin::MAX_AREA) continue;
			$area=0; foreach($partitions as $k=>$piece) if(in_array($owners[$k],[$owners[$i],$owners[$j]],true)) $area+=PolygonGeometry::area($piece);
			if($area>RoomJoin::MAX_AREA) continue;
			if($r->integer(0,99)>=25) continue;
			$from=$owners[$j]; $to=$owners[$i];
			$history[]=['kind'=>'merge','from'=>$from,'to'=>$to,'area'=>$area];
			$owners=array_map(static fn($owner)=>$owner===$from ? $to : $owner,$owners);
			$claimed[$from]=$claimed[$to]=true;
		}
		$geometry=ConformingRooms::offset($partitions,$owners,128);
		$connected=self::components($geometry['rooms'],$geometry['owners']);
		if($connected['owners']!==$geometry['owners']) {
			$history[]=['kind'=>'clearance','reason'=>'Revert optional merges whose wall offsets disconnect their interior'];
			$owners=$unmerged; $geometry=ConformingRooms::offset($partitions,$owners,128);
			$history=array_values(array_filter($history,static fn($h)=>$h['kind']!=='merge'));
		}
		// A wall on one segment can trim a longer convex piece away from its
		// decomposition neighbor. Recheck the finished interiors, including cuts
		// that existed before optional merges, and split or remove those remnants.
		for($repair=0;$repair<32;$repair++) {
			$connected=self::components($geometry['rooms'],$geometry['owners']);
			$groups=[];
			foreach($connected['owners'] as $i=>$owner) $groups[$owner][]=$i;
			$remove=[];
			foreach($groups as $owner=>$indices) {
				$vertices=[]; foreach($indices as $i) array_push($vertices,...$geometry['rooms'][$i]);
				$xs=array_column($vertices,0); $ys=array_column($vertices,1);
				if(min(max($xs)-min($xs),max($ys)-min($ys))<128) {
					array_push($remove,...$indices);
					$history[]=['kind'=>'remove-remnant','owner'=>$owner,'reason'=>'Finished disconnected interior is narrower than 128 units'];
				}
			}
			if(!$remove && $connected['owners']===$geometry['owners']) break;
			array_push($history,...$connected['history']);
			$partitions=$geometry['partitions']; $owners=$connected['owners'];
			rsort($remove);
			foreach($remove as $i) { array_splice($partitions,$i,1); array_splice($owners,$i,1); }
			$geometry=ConformingRooms::offset($partitions,$owners,128);
		}
		$connected=self::components($geometry['rooms'],$geometry['owners']);
		if($connected['owners']!==$geometry['owners']) throw new \LogicException('Finished logical room interiors remain disconnected');
		$owners=$geometry['owners'];
		foreach($geometry['rooms'] as $i=>$p) if(!isset($candidates[$owners[$i]]))
		$candidates[$owners[$i]]=['role'=>'remnant','tier'=>'remnant','distance'=>self::distance(PolygonGeometry::cell([],$p)['center'],$anchors),'size'=>512,'center'=>PolygonGeometry::cell([],$p)['center']];
		$geometry['galleryOwner']=$galleryOwner; $geometry['graph']=$graph; $geometry['candidates']=$candidates; $geometry['anchors']=$anchors; $geometry['history']=$history;
		$geometry['requested']=$budget; $geometry['surviving']=count(array_unique($owners));
		return $geometry;
	}
}

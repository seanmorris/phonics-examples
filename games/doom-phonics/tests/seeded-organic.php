<?php
declare(strict_types=1);

use DoomPhonics\Tools\{OrganicPacking,EmbeddedPlanarGraph,CompoundRooms,ConformingRooms,PolygonGeometry,RoomFeatures,PortalGallery,SpiralStaircase,RoomJoin,SeedSequence};

$rect=OrganicPacking::rectangle(...);
$g=EmbeddedPlanarGraph::build('graph-proof','ground',14,2912);
verifyWad($g===EmbeddedPlanarGraph::build('graph-proof','ground',14,2912),'the embedded graph repeats independently of room detailing');
verifyWad(count($g['anchors'])===3 && count($g['nodes'])<=14,'placement budget counts graph candidates and scales the anchor count');
foreach(['basement'=>1920,'ground'=>2912,'upper'=>1664] as $plane=>$size) {
	$packed=OrganicPacking::build('first-light',$plane,14,$size);
	$connected=OrganicPacking::components($packed['rooms'],$packed['owners']);
	verifyWad($connected['owners']===$packed['owners'],'finished wall offsets leave every logical room connected');
	$owned=[]; foreach($packed['rooms'] as $i=>$polygon) {
		$owner=$packed['owners'][$i]; $owned[$owner]??=[];
		array_push($owned[$owner],...$polygon);
	}
	foreach($owned as $vertices) {
		$xs=array_column($vertices,0); $ys=array_column($vertices,1);
		verifyWad(min(max($xs)-min($xs),max($ys)-min($ys))>=128,'disconnected narrow remnants cannot survive final wall offsets');
	}
}
foreach($g['anchors'] as $i=>$a) foreach($g['anchors'] as $j=>$b) if($j>$i) {
	$ac=PolygonGeometry::cell([],$a)['center']; $bc=PolygonGeometry::cell([],$b)['center'];
	verifyWad($ac[0]!==$bc[0] && $ac[1]!==$bc[1],'anchor centers do not share grid rows or columns');
}
$previous=1024;
foreach(range(0,4096,64) as $distance) {
	$size=OrganicPacking::size($distance);
	verifyWad($size<=$previous && $size>=384 && $size<=896,'surrounding room targets decrease with distance from an anchor boundary'); $previous=$size;
}
$whole=$rect(0,0,1024,1024);
foreach([1,2,3] as $count) foreach(range(0,8) as $seed) {
	$fit=CompoundRooms::fit($whole,[],new SeedSequence("shape-$seed"),$count);
	verifyWad(count($fit['rectangles'])===$count,'each compound template uses the requested one, two or three rectangles');
	verifyWad($fit===CompoundRooms::fit($whole,[],new SeedSequence("shape-$seed"),$count),'compound footprint selection repeats exactly');
	$axes=[];
	foreach($fit['rectangles'] as $polygon) {
		$c=PolygonGeometry::cell([],$polygon); $axes[]=($c['x1']-$c['x0'])<=>($c['y1']-$c['y0']);
		foreach($polygon as $vertex) {
			verifyWad($vertex[0]>=0 && $vertex[0]<=1024 && $vertex[1]>=0 && $vertex[1]<=1024,'source rectangles fit inside the graph node reservation');
			foreach($vertex as $coordinate) verifyWad($coordinate===(int)$coordinate,'compound vertices use integer WAD coordinates');
		}
	}
	if($count>1) {
		verifyWad(in_array(-1,$axes,true) && in_array(1,$axes,true),'multi-rectangle rooms contain opposing horizontal and vertical aspect ratios');
		verifyWad(array_sum(array_map([PolygonGeometry::class,'area'],$fit['pieces']))<1024*1024,'compound outlines retain their concave missing corners');
	}
	$owners=array_fill(0,count($fit['pieces']),0);
	verifyWad(OrganicPacking::components($fit['pieces'],$owners)['owners']===$owners,'the complete compound interior connects through seams at least 128 units wide');
	foreach($fit['pieces'] as $i=>$p) foreach($fit['pieces'] as $j=>$q) if($j>$i)
	verifyWad(!PolygonGeometry::overlaps($p,$q),'compound rectangles decompose into disjoint walkable pieces');
}
$boundary=[[1024,128],[1024,896]]; $neighbor=$rect(1024,128,1024,768);
$fit=CompoundRooms::fit($whole,[['boundary'=>$boundary,'pieces'=>[$neighbor]]],new SeedSequence('contact-first'),3);
$next=CompoundRooms::fit($neighbor,[['boundary'=>$boundary,'pieces'=>$fit['pieces']]],new SeedSequence('contact-second'),2);
verifyWad(CompoundRooms::contact($fit['pieces'],$next['pieces'],$boundary)>=384,'both fitted neighbors retain the same continuous entrance span');
verifyWad(CompoundRooms::contact([$rect(0,0,64,192),$rect(0,320,64,192)],[$rect(64,0,64,512)],[[64,0],[64,512]])===192.0,
	'disjoint short contacts cannot masquerade as one wide entrance');
$deleted=OrganicPacking::insert([$whole],[0],$rect(-64,-64,1152,1152),1);
verifyWad(!in_array(0,$deleted['owners'],true),'an overlap can completely replace an existing logical room');
$split=OrganicPacking::insert([$whole],[0],$rect(448,-128,128,1280),1);
verifyWad(count(array_unique($split['owners']))===3,'a through-cut gives disconnected survivors separate logical ownership');
foreach($split['partitions'] as $i=>$p) foreach($split['partitions'] as $j=>$q) if($j>$i)
verifyWad(!PolygonGeometry::overlaps($p,$q),'cut survivors and their replacement have disjoint interiors');
$union=PolygonGeometry::union([$rect(0,0,768,512),$rect(0,512,384,512)]);
verifyWad(count($union)>1 && array_sum(array_map([PolygonGeometry::class,'area'],$union))<=RoomJoin::MAX_AREA,'a concave merge keeps its exterior recess within the combined area budget');
verifyWad(!array_filter($union,static fn($p)=>PolygonGeometry::contains($p,640,768)),'the missing corner of an L-shaped union stays empty');
$paired=ConformingRooms::offset([[[0,0],[0,1024],[1024,0]],[[0,1024],[1024,1024],[1024,0]]],[0,1],128);
[$nx,$ny,$d,$inset]=$paired['planes'][0][1];
verifyWad(2*$inset/hypot($nx,$ny)>=128,'both diagonal wall faces derive their thickness from the same boundary');
foreach($paired['rooms'] as $p) foreach($p as $v) foreach($v as $coordinate)
verifyWad(abs($coordinate-round($coordinate))<1e-6,'paired diagonal offsets preserve integer WAD vertices');

$featureKinds=[];
foreach(range(0,20) as $seed) {
	$body=PolygonGeometry::cell(['id'=>'feature.core','floor'=>0,'ceiling'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5','light'=>192,'tag'=>0],$rect(0,0,1536,1536));
	$layer=['role'=>'room','cells'=>[$body],'anchors'=>[]];
	if(!RoomFeatures::apply($layer,'feature',(string)$seed)) continue;
	$feature=$layer['feature']; $featureKinds[$feature['kind']]=true;
	foreach($feature['footprints'] as $opening) foreach($opening as $v)
	verifyWad(PolygonGeometry::contains($body['polygon'],...array_merge($v,[128])),'pillars and depressions keep a full passage around their boundaries');
	foreach($layer['cells'] as $i=>$c) foreach($layer['cells'] as $j=>$other) if($j>$i)
	verifyWad(!PolygonGeometry::overlaps($c['polygon'],$other['polygon']),'features leave disjoint convex walkable regions');
	if($feature['kind']!=='lowered-center') foreach($layer['cells'] as $c) foreach($feature['footprints'] as $opening)
	verifyWad(!PolygonGeometry::overlaps($c['polygon'],$opening),'pillars are solid voids with no walkable geometry inside');
	else verifyWad($feature['depth']===16 && PolygonGeometry::area($feature['footprints'][0])>=512*512,'a substantial lowered center has a directly traversable return step');
}
verifyWad(count($featureKinds)===3,'seeded treatments include one pillar, an incomplete group and a lowered center');

$body=['floor'=>0,'ceiling'=>192,'floorTexture'=>'FLOOR0_1','ceilingTexture'=>'CEIL3_5','light'=>192,'tag'=>0];
$gallery=['galleryCenter'=>[1024,768],'cells'=>[PolygonGeometry::cell($body+['id'=>'gallery.core'],$rect(0,0,1024,1536)),PolygonGeometry::cell($body+['id'=>'gallery.piece'],$rect(1024,0,1024,1536))]];
$door=PolygonGeometry::cell(array_replace($body,['id'=>'gallery.door','ceiling'=>0]),$rect(2048,1100,48,128));
$gallery['cells'][]=$door;
$id=PortalGallery::carve($gallery,'gallery',true,true);
verifyWad($id==='gallery.gallery-aperture' && $gallery['gallery']['inserted'],'a gallery footprint spans several original convex room pieces');
verifyWad($gallery['cells'][array_search('gallery.door',array_column($gallery['cells'],'id'),true)]===$door,'gallery cuts preserve complete moving doors outside the insertion footprint');
foreach($gallery['cells'] as $i=>$a) foreach($gallery['cells'] as $j=>$b) if($j>$i)
verifyWad(!PolygonGeometry::overlaps($a['polygon'],$b['polygon']),'inserted gallery rims, hallway walls and recessed aperture do not overlap');
foreach([90,180,270] as $sweep) {
	$stairs=SpiralStaircase::build(steps:8,sweep:$sweep);
	verifyWad($stairs['landing']===256,'every curved stair sweep reserves 256 units after the final riser');
}

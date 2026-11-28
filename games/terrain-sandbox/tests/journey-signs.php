<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';

use TerrainPhonics\{Edits,JoinedTerrain,Landscape};
use TerrainSandbox\{JourneyClock,JourneyNetwork,JourneySignModel,JourneySigns};

function expectSign(bool $test, string $why): void { if(!$test) throw new RuntimeException($why); }
function layout(Landscape $land,?array $endpoints=null): array
{
	$work=JourneySigns::layout($land,$endpoints);
	foreach($work as $_) {}
	return $work->getReturn();
}
function distance(array $a,array $b): float { return hypot(hypot($a[0]-$b[0],$a[2]-$b[2]),$a[1]-$b[1]); }
// Signed planar distance to a straight arm, positive on its right in world XZ.
function lateral(array $p,array $a,array $b): float
{
	$dx=$b[0]-$a[0]; $dz=$b[2]-$a[2];
	return ($dx*($p[2]-$a[2])-$dz*($p[0]-$a[0]))/hypot($dx,$dz);
}
function road(Landscape $land,array $a,array $b,float $width=28.): void
{
	$land->queue(['type'=>'road','width'=>$width,'stationSpacing'=>8.,'shoulder'=>4.,'points'=>[$a,$b]]);
}
function kinds(array $signs,string $kind): array { return array_values(array_filter($signs,fn($s)=>$s['kind']===$kind)); }

$leaf=['start'=>false,'finishes'=>[0]];
$form=['preset'=>'coast','waterLevel'=>-200.,'heightScale'=>0.,'detailAmplitude'=>0.];
$land=new Landscape($form+['join'=>['span'=>16384,'destinations'=>[$form,$form]]]);
$edge=$land->recipe()['worldSize']/2;
$start=[-$edge,100.,0.]; $a=[-1880.,30.,0.]; $bend=[-1550.,70.,850.]; $split=[-950.,110.,350.];
[$west,$north,$south]=JoinedTerrain::origins(16384,2);
$n=[5500.,110.,$north[1]]; $s=[5500.,110.,$south[1]];
// Deliberately submit chunks out of traversal order, including a resized section and a bend inside the warning half-mile.
// The northern route briefly crosses the southern square before finding its own valley.
$detour=[3800.,110.,5000.];
road($land,$split,$detour);
road($land,$detour,$n);
road($land,$bend,$split);
road($land,$n,[$edge,110.,$north[1]]);
road($land,$start,$a);
road($land,$split,$s);
road($land,$a,$bend,34.);
road($land,$s,[$edge,110.,$south[1]]);
$land->commit();
$signs=layout($land);
$standalone=kinds($signs,'wrongWay'); $total=count($signs);
expectSign($standalone && $total===14+count($standalone),'The standalone fork has three endpoint banners alongside nine mile markers, a fork post, an exit gantry and reverse warnings.');
$starts=kinds($signs,'start'); $finishes=kinds($signs,'finish');
expectSign(count($starts)===1 && abs(distance($start,$starts[0]['position'])-96.)<.01 && $starts[0]['heading'][0]>.99,
	'The single start faces the driver ahead of the west-edge spawn.');
expectSign(count($finishes)===2 && array_column(array_column($finishes,'position'),2)===[$north[1],$south[1]],
	'Each terminal exit has its own finish.');
foreach($finishes as $gate) expectSign(abs($gate['position'][0]-($edge-96.))<.01 && $gate['width']===28.
	&& $gate['heading'][0]>.99,'The finish spans its own road before the CPU stopping point.');
$routes=new JourneyNetwork("A coast -> B,C\nB coast -> D\nC coast\nD coast");
$opening=layout($land,$routes->endpoints['A']);
expectSign(kinds($opening,'start')===$starts && kinds($opening,'finish')===[$finishes[1]],
	'The terminal branch finishes while the onward branch continues without a banner.');
expectSign($routes->endpoints['B']===['start'=>false,'finishes'=>[1]] && $routes->endpoints['C']===$leaf,
	'Previewing a later stage starts no new journey, and a terminal preview keeps its finish.');
foreach([$west,$north,$south] as $i=>$square) {
	$miles=array_values(array_filter(kinds($signs,'mile'),fn($site)=>$site['square']===$i));
	expectSign(array_column($miles,'fraction')===[.25,.5,.75],'Every traversed landscape has three quarter marks.');
	foreach($miles as $j=>$site) {
		expectSign($site['remaining']>0 && ($j===0 || $site['remaining']<$miles[$j-1]['remaining']),'Distance counts down on each approach.');
		if($i===0) {
			[$x,$y,$z]=$site['position'];
			expectSign(abs($x-($start[0]+$site['fraction']*16384))<.02,'Quarter distances exclude the transition strip.');
			expectSign($site['caption']==='junction' && abs($site['remaining']-(distance($site['position'],$a)+distance($a,$bend)+distance($bend,$split)))<.05,
				'Mileage to the junction includes the slope and the bend across road records.');
		} else expectSign($site['caption']==='stage','A final landscape counts down to its own end.');
	}
}
$exits=kinds($signs,'exits')[0]; $fork=kinds($signs,'fork')[0];
$p=$fork['position'];
$fromLeft=lateral($p,$split,$detour)-14.; $fromRight=-lateral($p,$split,$s)-14.;
expectSign(min($fromLeft,$fromRight)>1.3 && max($fromLeft,$fromRight)<3.2 && abs($fromLeft-$fromRight)<.1,
	'The fork sign fits near the tip of the unpaved median, centered between both inside edges.');
expectSign(!Edits::onRoad($p[0],$p[2],$land->roads()) && $p[1]===$land->height($p[0],$p[2]) && $fork['heading'][1]<0,
	'The single post meets the ground between the branches and faces approaching traffic.');
$warning=JourneySigns::MILE/2;
expectSign(abs(distance($exits['position'],$bend)+distance($bend,$split)-$warning)<.05
	&& $exits['heading'][1]>0 && $exits['width']===34.,'The warning stands half a driven mile before the fork, with the width and heading of that section.');
expectSign(distance($exits['position'],$split)<$warning-10.,'The approach follows the bend when measuring the half mile.');
$restored=Landscape::load($land->save());
expectSign(layout($restored)===$signs,'The same saved pavement restores the same signs.');

$partial=$land->previewWithoutRoads([4,7]); // Hide the western entry and the end of the southern highway.
$partialSigns=layout($partial);
expectSign(!kinds($partialSigns,'start') && kinds($partialSigns,'finish')===[$finishes[0]],
	'Road visibility removes the affected banners without promoting exposed interior endpoints.');
$partial->close();
$visible=$land->previewWithoutRoads([0]);
expectSign(!kinds(layout($visible),'fork') && !kinds(layout($visible),'exits'),'Hiding a diverging arm removes both fork instructions.');
$cache=new JourneySignModel;
for($i=0;$i<1000 && !$cache->count;$i++) $cache->update($land);
expectSign($cache->count===$total && !$cache->update($land),'An idle frame reuses the completed layout.');
$dots=array_map(static fn($sign)=>[$sign['position'][0],$sign['position'][2]],kinds($signs,'mile'));
expectSign(count($dots)===9 && $cache->mileMarkers()===$dots,'Every mileage gantry has exactly one map dot at its real road position.');
for($i=0;$i<1000;$i++) if($cache->update($restored) && $cache->count===$total) break;
expectSign($cache->count===$total,'Opening the same saved journey again rebuilds its markers at the same revision.');
for($i=0;$i<1000;$i++) if($cache->update($restored,$routes->endpoints['A']) && $cache->count===count($opening)) break;
expectSign($cache->count===count($opening),'Changing just the route topology refreshes the cached decorations.');
$restored->close();
$land->undo(); $cache->update($land);
expectSign($cache->count===0,'Undo clears obsolete signs from the renderer before the next draw.');
expectSign($cache->mileMarkers()===[],'Changing roads clears old map dots with the old gantries.');
$cache->close(); $land->close(); $visible->close();

// The narrow arm is on the left. Both first records end while the asphalt still overlaps.
$staggered=new Landscape($form+['worldSize'=>1024]);
$joint=[0.,0.,0.]; $left=[320.,0.,-160.]; $right=[320.,0.,160.];
road($staggered,[-300.,0.,0.],$joint);
road($staggered,$joint,[8.,0.,4.],34.);
road($staggered,[6.,0.,-3.],$left,12.);
road($staggered,$joint,[6.,0.,-3.],12.);
road($staggered,[8.,0.,4.],$right,34.);
// An unrelated approach ends at this short section. The original two arms still open into one median.
road($staggered,[-150.,0.,70.],[8.,0.,4.],8.);
$staggered->commit();
$post=kinds(layout($staggered,$leaf),'fork')[0];
$p=$post['position'];
$leftGap=lateral($p,$joint,$left)-6.; $rightGap=-lateral($p,$joint,$right)-17.;
expectSign($p[2]<-5. && min($leftGap,$rightGap)>1.3 && abs($leftGap-$rightGap)<.12,
	'Unequal widths move the post toward the narrower arm, across editable road chunks.');
foreach([-1.325,0.,1.325] as $width)
	expectSign(!Edits::onRoad($p[0],$p[2]+$width,$staggered->roads()),'The whole fork sign clears the pavement.');
$staggered->close();

$short=new Landscape($form+['worldSize'=>1024]);
road($short,[-300.,0.,0.],$joint);
foreach([-2.,2.] as $side) {
	road($short,$joint,[40.,0.,$side]); road($short,[40.,0.,$side],[80.,0.,0.]);
}
road($short,[80.,0.,0.],[200.,0.,0.]); $short->commit();
$signs=layout($short,$leaf);
expectSign(!kinds($signs,'fork') && array_column(kinds($signs,'exits'),'position')===[[-300.,0.,0.]],
	'Overlapping arms that rejoin without opening a median keep their overhead warning and get no post on the asphalt.');
$short->close();

$terminal=new Landscape($form+['worldSize'=>16384]);
$gore=[3000.,100.,0.]; $finish=[8192.,100.,0.];
road($terminal,[-8192.,100.,-1900.],$gore);
road($terminal,[-8192.,100.,1900.],$gore);
road($terminal,$gore,$finish); $terminal->commit();
$plain=layout($terminal);
expectSign($plain && count(kinds($plain,'wrongWay'))===count($plain),'Long standalone roads carry periodic reverse warnings.');
$merged=layout($terminal,$leaf);
expectSign(!kinds($merged,'fork') && !kinds($merged,'exits'),'Two origins and one destination is a rejoin, so it has no exit warning.');
expectSign(count(kinds($merged,'mile'))===5 && count(array_filter($merged,fn($s)=>($s['caption'] ?? '')==='junction'))===4,
	'Both incoming routes count down to the rejoin and share the single mile marker on their common tail.');
expectSign(!kinds($merged,'start') && count(kinds($merged,'finish'))===1,'Both arrivals share the finish on the common tail.');
$terminal->close();
// Three-mile stations follow the pavement through a bend and across a reversed, out-of-order edit.
$mile=JourneySigns::MILE;
$highway=new Landscape($form+['worldSize'=>16384]);
$a=[-7000.,0.,-3000.]; $b=[-4500.,0.,-3000.]; $c=[-4500.,0.,2600.]; $d=[3000.,0.,2600.];
road($highway,$c,$b); road($highway,$c,$d); road($highway,$a,$b); $highway->commit();
$posts=kinds(layout($highway),'wrongWay');
expectSign(count($posts)===3,'A 9.69-mile highway has three reverse warnings.');
foreach($posts as $i=>$post) {
	$at=3.*($i+1)*$mile;
	[$x,,$z]=$post['position']; [$tx,$tz]=$post['heading'];
	if(!$i) expectSign(abs($x-(-4500.+16.5))<.01 && abs($z-(-3000.+$at-2500.))<.01 && $tz<-.999,
		'The three-mile sign faces reverse traffic on its right shoulder after the first bend.');
	else expectSign(abs($x-(-4500.+$at-8100.))<.01 && abs($z-(2600.-16.5))<.01 && $tx<-.999,
		'The six- and nine-mile faces follow the tangent after the second bend.');
	expectSign(!Edits::onRoad($x,$z,$highway->roads()),'The reverse sign footing clears the pavement.');
}
$hidden=$highway->previewWithoutRoads([0]);
expectSign(count(kinds(layout($hidden),'wrongWay'))===1,'Hiding a road breaks the continuous distance at the missing link.');
$hidden->close(); $highway->close();

// Both shorter arms inherit the two miles before the fork. Their common tail needs one copy.
$network=new Landscape($form+['worldSize'=>16384]);
$f=[-6500.+2*$mile,0.,0.]; $j=[$f[0]+2*$mile,0.,0.];
road($network,[-6500.,0.,0.],$f);
foreach([-1.,1.] as $side) {
	$mid=[$f[0]+$mile,0.,$side*.75*$mile];
	road($network,$f,$mid); road($network,$mid,$j);
}
road($network,$j,[$j[0]+3*$mile,0.,0.]); $network->commit();
$posts=kinds(layout($network),'wrongWay');
$arms=array_filter($posts,fn($s)=>abs($s['position'][2])>.5*$mile);
$tail=array_values(array_filter($posts,fn($s)=>$s['position'][0]>$j[0]));
expectSign(count($posts)===3 && count($arms)===2 && count($tail)===1 && abs($tail[0]['position'][0]-($j[0]+1.5*$mile))<.01,
	'Each arm gets the three-mile sign, then one six-mile sign serves both routes after the rejoin.');
$network->close();

// The wraparound gap is the same three miles as the gap through the middle of the lap.
$lap=new Landscape($form+['worldSize'=>16384]);
$a=[0.,0.,0.]; $b=[2*$mile,0.,0.]; $c=[2*$mile,0.,$mile]; $d=[0.,0.,$mile];
foreach([[$a,$b],[$b,$c],[$c,$d],[$d,$a]] as [$from,$to]) road($lap,$from,$to);
road($lap,[-5000.,0.,0.],[-4900.,0.,0.]); $lap->commit();
$posts=kinds(layout($lap),'wrongWay');
expectSign(count($posts)===2 && abs($posts[0]['position'][0]-1.5*$mile)<.01 && abs($posts[1]['position'][0]-.5*$mile)<.01
	&& $posts[0]['heading'][0]<-.999 && $posts[1]['heading'][0]>.999,'A six-mile circuit has two signs facing against the lap.');
$lap->close();

$gantries=new Landscape($form+['worldSize'=>16384]);
road($gantries,[-8192.,0.,0.],[0.,0.,0.]); road($gantries,[0.,0.,0.],[8192.,0.,0.]); $gantries->commit();
$signs=layout($gantries,$leaf);
expectSign(count(kinds($signs,'mile'))===3 && !kinds($signs,'wrongWay'),'Existing gantry backs every 2.55 miles already cover this stage.');
$gantries->close();

expectSign(JourneySigns::legend(JourneySigns::MILE)===['1','MILE']
	&& JourneySigns::legend(0.46*JourneySigns::MILE)===['0.5','MILES']
	&& JourneySigns::legend(19.6*JourneySigns::MILE)===['20','MILES'],'Mileage lettering handles sub-mile values, rounding and singular units.');
echo "Journey highway sign placement and lifecycle passed.\n";


$clock = new JourneyClock(10.);
$cross = static function(array $gate,float $time,bool $forward=true,float $side=0.,array $stages=['A','B','C']) use($clock): void {
	[$x,,$z]=$gate['position']; [$hx,$hz]=$gate['heading'];
	$x+=$side*$hz; $z-=$side*$hx;
	$d=$forward ? 2. : -2.;
	$clock->advance([$gate],$stages,['x'=>$x-$d*$hx,'z'=>$z-$d*$hz],['x'=>$x+$d*$hx,'z'=>$z+$d*$hz],$time,1.);
};
$cross(kinds($opening,'start')[0],12.);
$mile=kinds($opening,'mile')[0];
$cross($mile,15.,false); $cross($mile,16.,true,$mile['width']);
expectSign($clock->split === null,'A backwards or roadside pass earns no journey split');
$cross($mile,20.);
expectSign($clock->split['label']==='Journey split' && abs($clock->split['seconds']-8.)<1e-8,'The mileage sign times the forward crossing from the start banner');
$saved=$clock->split; $cross($mile,25.);
expectSign($clock->split===$saved,'Recrossing the same mileage marker keeps the original time');
$cross(kinds($opening,'finish')[0],40.);
expectSign($clock->split['label']==='Journey finish' && abs($clock->split['seconds']-28.)<1e-8,'The journey finish shows elapsed driving time');

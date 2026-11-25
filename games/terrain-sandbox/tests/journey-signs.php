<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';

use TerrainPhonics\{Edits,JoinedTerrain,Landscape};
use TerrainSandbox\{JourneySignModel,JourneySigns};

function expectSign(bool $test, string $why): void { if(!$test) throw new RuntimeException($why); }
function layout(Landscape $land,bool $stage=false): array
{
	$work=JourneySigns::layout($land,$stage);
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

$form=['preset'=>'coast','waterLevel'=>-200.,'heightScale'=>0.,'detailAmplitude'=>0.];
$land=new Landscape($form+['join'=>['span'=>16384,'destinations'=>[$form,$form]]]);
$edge=$land->recipe()['worldSize']/2;
$start=[-$edge,100.,0.]; $a=[-1880.,30.,0.]; $bend=[-1550.,70.,850.]; $split=[-950.,110.,350.];
[$west,$north,$south]=JoinedTerrain::origins(16384,2);
$n=[5500.,110.,$north[1]]; $s=[5500.,110.,$south[1]];
// Deliberately submit chunks out of traversal order, including a resized section and a bend inside the warning mile.
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
expectSign(count($signs)===11,'Three traversed landscapes have nine mile markers, a paired exit gantry and one fork post.');
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
expectSign(abs(distance($exits['position'],$bend)+distance($bend,$split)-JourneySigns::MILE)<.05
	&& $exits['heading'][1]>0 && $exits['width']===34.,'The warning is a driven mile upstream, with the width and heading of that section.');
expectSign(distance($exits['position'],$split)<JourneySigns::MILE-500.,'The warning does not use a one-mile radius.');
$restored=Landscape::load($land->save());
expectSign(layout($restored)===$signs,'The same saved pavement restores the same signs.');

$visible=$land->previewWithoutRoads([0]);
expectSign(!kinds(layout($visible),'fork') && !kinds(layout($visible),'exits'),'Hiding a diverging arm removes both fork instructions.');
$cache=new JourneySignModel;
for($i=0;$i<1000 && !$cache->count;$i++) $cache->update($land);
expectSign($cache->count===11 && !$cache->update($land),'An idle frame reuses the completed layout.');
for($i=0;$i<1000;$i++) if($cache->update($restored) && $cache->count===11) break;
expectSign($cache->count===11,'Opening the same saved journey again rebuilds its markers at the same revision.');
$restored->close();
$land->undo(); $cache->update($land);
expectSign($cache->count===0,'Undo clears obsolete signs from the renderer before the next draw.');
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
$post=kinds(layout($staggered,true),'fork')[0];
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
$signs=layout($short,true);
expectSign(!kinds($signs,'fork') && count(kinds($signs,'exits'))===1,
	'Overlapping arms that rejoin without opening a median keep their overhead warning and get no post on the asphalt.');
$short->close();

$terminal=new Landscape($form+['worldSize'=>16384]);
$gore=[3000.,100.,0.]; $finish=[8192.,100.,0.];
road($terminal,[-8192.,100.,-1900.],$gore);
road($terminal,[-8192.,100.,1900.],$gore);
road($terminal,$gore,$finish); $terminal->commit();
expectSign(!layout($terminal),'A standalone sandbox road does not acquire highway signage.');
$merged=layout($terminal,true);
expectSign(!kinds($merged,'fork') && !kinds($merged,'exits'),'Two origins and one destination is a rejoin, so it has no exit warning.');
expectSign(count($merged)===5 && count(array_filter($merged,fn($s)=>$s['caption']==='junction'))===4,
	'Both incoming routes count down to the rejoin and share the single mile marker on their common tail.');
$terminal->close();
expectSign(JourneySigns::legend(JourneySigns::MILE)===['1','MILE']
	&& JourneySigns::legend(0.46*JourneySigns::MILE)===['0.5','MILES']
	&& JourneySigns::legend(19.6*JourneySigns::MILE)===['20','MILES'],'Mileage lettering handles sub-mile values, rounding and singular units.');
echo "Journey highway sign placement and lifecycle passed.\n";

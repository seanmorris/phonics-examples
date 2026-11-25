<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';

use TerrainPhonics\{JoinedTerrain,Landscape};
use TerrainSandbox\{JourneySignModel,JourneySigns};

function expectSign(bool $test, string $why): void { if(!$test) throw new RuntimeException($why); }
function layout(Landscape $land,bool $stage=false): array
{
	$work=JourneySigns::layout($land,$stage);
	foreach($work as $_) {}
	return $work->getReturn();
}
function distance(array $a,array $b): float { return hypot(hypot($a[0]-$b[0],$a[2]-$b[2]),$a[1]-$b[1]); }
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
expectSign(distance($fork['position'],$split)<.001 && $fork['heading'][1]<0,'The single post faces traffic at the surveyed split.');
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

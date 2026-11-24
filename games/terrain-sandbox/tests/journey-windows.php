<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
use TerrainPhonics\{Landscape,JoinedTerrain};
use TerrainSandbox\{JourneyHighways,JourneyNetwork,JourneyPavement};
function checkWindow(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function surveyWindow(JourneyHighways $planner,string $id,?string $from=null,?Landscape $active=null,?callable $report=null): Landscape {
	$land=new Landscape($planner->recipe($id));
	foreach($planner->build($land,$id,$report,$from,$active) as $_) {}
	return $land;
}
function pavement(Landscape $land,array $origin,int $span): array {
	$pieces=JourneyPavement::square($land->roadRecords(),$origin,$span/2);
	foreach($pieces as $_) {}
	$keys=array_map(JourneyPavement::key(...),$pieces->getReturn());sort($keys);
	return $keys;
}
// A clipped banked road keeps the endpoint slopes as well as its spline.
$line=['type'=>'road','width'=>8.,'shoulder'=>2.,'points'=>[[-100.,0.,0.],[100.,0.,0.]],
	'handles'=>[['out'=>[200./3.,0.,0.]],['in'=>[-200./3.,0.,0.]]],'banks'=>[-.2,.2]];
$clip=JourneyPavement::square([$line],[0.,0.,0.],50.);
foreach($clip as $_) {}
[$piece]=$clip->getReturn();
checkWindow(abs($piece['points'][0][0]+50.)<.001 && abs($piece['banks'][0]+.1)<1.e-6 && abs(end($piece['banks'])-.1)<1.e-6,
	'Clipped roads interpolate bank angles at the same curve parameters.');

$span=16384;
$net=new JourneyNetwork("A rolling-prairie -> B,C\nB canyon -> E,F\nC mountain -> F,G\nE limestone\nF river-valley\nG coast");
$routes=new JourneyHighways($net,$span);
$a=surveyWindow($routes,'A');
// Start with a fresh survey cache and inspect the downstream junction first.
$downstream=new JourneyHighways($net,$span);
$approaches=[];
$c=surveyWindow($downstream,'C',report:static function(string $message) use (&$approaches): void {
	if(str_starts_with($message,'Surveying the approach')) $approaches[]=$message;
});
checkWindow(in_array('Surveying the approach from A into C…',$approaches,true),'Survey progress names the approach shown in the map.');
$centers=JoinedTerrain::origins($span,2);
[$x,$z]=$centers[2]; $ar=$a->recipe();
$inA=[$x,$ar['waterLevel']-$ar['join']['destinations'][1]['waterLevel'],$z];
$inC=[$centers[0][0],0.,0.];
$cRoads=pavement($a,$inA,$span);$onward=pavement($c,$inC,$span);
checkWindow(count($cRoads)>1 && $cRoads===$onward,'C must contain identical cubic road segments and handles in both windows, even when surveyed first.');
$hits=0;
foreach($a->roads() as $road) foreach($road['_road']['samples'] as $i=>[$px,,$pz]) {
	if($i%16 || abs($px-$x)>$span/2-64 || abs($pz-$z)>$span/2-64) continue;
	$cx=$px-$x+$inC[0];$cz=$pz-$z;
	checkWindow($c->material($cx,$cz)==='road' && abs($a->height($px,$pz)-$inA[1]-$c->height($cx,$cz))<.01,'Pavement and tire contact agree across C.');
	$hits++;
}
checkWindow($hits>100,'Sample the full length of C.');
$b=surveyWindow($downstream,'B'); $f=surveyWindow($downstream,'F');
$fRoads=pavement($f,[0.,0.,0.],$span);
foreach([[$b,1],[$c,0]] as [$land,$slot]) {
	$r=$land->recipe(); [$px,$pz]=$centers[$slot+1];
	$visible=pavement($land,[$px,$r['waterLevel']-$r['join']['destinations'][$slot]['waterLevel'],$pz],$span);
	checkWindow(count($fRoads)>2 && $fRoads===$visible,'Both origins and the terminal map must show the same roads in F.');
}
foreach($a->roadRecords() as $index=>$road) {
	$p=$road['points'][intdiv(count($road['points']),2)];
	if(abs($p[0]-$x)>$span*.4 || abs($p[2]-$z)>$span*.4) continue;
	$road['points'][intdiv(count($road['points']),2)][2]+=7.;
	$a->queue(['replaceAt'=>$index]+$road);$a->commit();break;
}
$driven=surveyWindow($routes,'C','A',$a);
$edited=pavement($a,$inA,$span);
checkWindow($edited!==$cRoads && $edited===pavement($driven,$inC,$span),'The handoff must preserve edits to the incoming road.');
foreach([$a,$b,$c,$f,$driven] as $land) $land->close();
echo "C matched across $hits road samples. Both incoming windows agreed on F and an edited approach carried through.\n";

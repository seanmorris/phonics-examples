<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
use TerrainPhonics\{Landscape,JoinedTerrain};
use TerrainSandbox\{JourneyAtlas,JourneyHighways,JourneyMerge,JourneyNetwork,JourneyPavement,JourneySigns,Session};
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

// Both arms cross between knots, pull apart and cross again before the scheduled merge.
function testRoad(array $points): array {
	$handles=array_fill(0,count($points),[]);
	for($i=1;$i<count($points);$i++) {
		$out=array_map(static fn($a,$b)=>($b-$a)/3.,$points[$i-1],$points[$i]);
		$handles[$i-1]['out']=$out; $handles[$i]['in']=array_map(static fn($v)=>-$v,$out);
	}
	return ['type'=>'road','points'=>$points,'width'=>28.,'handles'=>$handles];
}
function reconcile(array $roads): ?array {
	$work=JourneyMerge::apply($roads); foreach($work as $_) {} return $work->getReturn();
}
function junctions(array $roads): array {
	$starts=$ends=$points=[];
	foreach($roads as $road) {
		$p=$road['points']; $starts[JourneySigns::node($p[0])][]=$road;
		$b=end($p); $key=JourneySigns::node($b); $ends[$key][]=$road; $points[$key]=$b;
	}
	return array_filter($points,static fn($k)=>count($ends[$k])>1 && count($starts[$k] ?? [])===1,ARRAY_FILTER_USE_KEY);
}
$points=[[0.,0.,220.],[500.,0.,-60.],[880.,0.,120.],[1250.,0.,145.],[1600.,0.,0.]];
$trunk=testRoad([[0.,0.,0.],end($points)])+['banks'=>[0.,.16]];
$branch=testRoad($points)+['banks'=>array_fill(0,count($points),-.04)];
$exit=testRoad([end($points),[2000.,0.,0.]]);
$aligned=reconcile([$trunk,$branch,$exit]);
checkWindow($aligned!==null && count(junctions($aligned))===1,'A repeated crossing becomes one real merge.');
[$merge]=array_values(junctions($aligned));
checkWindow($merge[0]>340. && $merge[0]<490. && abs($merge[2])<1.e-8,'Use the first meeting between knots.');
$at=JourneySigns::node($merge);
$approaching=array_values(array_filter($aligned,static fn($r)=>JourneySigns::node(end($r['points']))===$at));
foreach($approaching as $road) {
	$last=array_key_last($road['points']); $v=$road['handles'][$last]['in'];
	checkWindow($v[0]<0. && abs($v[1])+abs($v[2])<1.e-8 && abs(end($road['banks'])-.16*$merge[0]/1600.)<1.e-7,
		'Both incoming roads arrive at the same tangent and bank.');
}
checkWindow(reconcile($aligned)===null,'Reapplying the planner preserves the new junction.');
foreach($points as $i=>&$p) if($i<count($points)-1) $p[1]=24.;
unset($p);
checkWindow(reconcile([$trunk,testRoad($points),$exit])===null,'Overlapping centerlines at different elevations keep their planned junction.');
checkWindow(reconcile([$trunk,$branch])===null,'Crossings without a shared onward road are outside merge planning.');

$span=16384;
$net=new JourneyNetwork("A rolling-prairie -> B,C\nB canyon -> E,F\nC mountain -> F,G\nE limestone\nF river-valley\nG coast");
$session=new Session;
$routes=$session->highways($net,$span);
$a=surveyWindow($routes,'A');
$replies=[];
$atlas=new JourneyAtlas;
$atlas->request(['id'=>7,'span'=>$span,'plan'=>$net->plan,'stage'=>'A','survey'=>true],$session,
	static function(int $id,string $json) use (&$replies): void { $replies[]=json_decode($json,true,flags:JSON_THROW_ON_ERROR); });
checkWindow(count($replies)===1 && count($replies[0]['roads'] ?? [])===count($a->roads()) && !$atlas->needsChart,
	'An already built junction must return all its road curves without a survey tick.');
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
$merges=array_values(junctions($f->roadRecords()));
checkWindow(count($merges)===1 && $merges[0][0]<-3500. && $merges[0][0]>-4100.,'River Valley shares one road from its first at-grade encounter.');
checkWindow($f->material(2543.3,-4921.58)!=='road','The superseded approach must also disappear from the graded ground.');
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
checkWindow($routes->completedRoads('C','A',$a)===$driven->roadRecords() && $routes->completedRoads('C')===null,
	'An edited approach previews instantly for the drive that reached it.');
$a->undo();
checkWindow($routes->completedRoads('C','A',$a)===null,'Changing the incoming road invalidates its prepared preview.');
foreach([$a,$b,$c,$f,$driven] as $land) $land->close();
$session->close();
echo "C matched across $hits road samples. Both incoming windows agreed on F and an edited approach carried through.\n";

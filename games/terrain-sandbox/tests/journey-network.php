<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
use TerrainSandbox\{JourneyAtlas,JourneyNetwork,JourneyRoad,Session};

function networkCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function settleNetwork(Session $s): void { while($s->preparingRoad()) $s->input([],0.,1.,9.); }

$plan="A rolling-prairie -> B,C\nB limestone -> E,F\nC coast -> F,G\nE mountain\nF river-valley -> H\nG canyon\nH rolling-prairie";
$chain='';
for($i=0;$i<200;$i++) $chain.="Map$i coast".($i<199?' -> Map'.($i+1):'')."\n";
networkCheck(count((new JourneyNetwork($chain))->nodes)===200,'Long networks retain shared references without expanding possible itineraries.');
foreach(["A coast -> F","A coast -> B\nB mountain -> A","A coast -> B\nB coast\nC canyon"] as $bad) {
	try { new JourneyNetwork($bad); throw new RuntimeException('Invalid topology accepted'); } catch(InvalidArgumentException) {}
}
$s=new Session;
$original=$s->landscape;
$atlas=new JourneyAtlas;
$roads=null;
$report=static function(int $id,string $json) use (&$roads): void {
	$packet=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
	if(isset($packet['error'])) throw new RuntimeException($packet['error']);
	if(isset($packet['roads'])) $roads=$packet['roads'];
};
$atlas->request(['id'=>1,'span'=>16384,'plan'=>$plan,'stage'=>'A','survey'=>true],$s,$report);
$atlas->request(['id'=>2],$s,$report);
$atlas->step($s,$report,true);
networkCheck($roads===null && !$atlas->needsChart,'Closing an unbuilt preview cancels its road survey.');
$atlas->request(['id'=>3,'span'=>16384,'plan'=>$plan,'stage'=>'A','survey'=>true],$s,$report);
while($roads===null) $atlas->step($s,$report,true);
networkCheck(count($roads)>=3 && count($roads[0][1])===6 && $s->landscape===$original && $s->mode==='fly',
	'The preview produces road curves and leaves the playing landscape undisturbed.');
$s->input(['actions'=>[['type'=>'journey-build','network'=>$plan]]],0.,1.);
networkCheck(!$s->preparingRoad() && $s->mode==='car','Build reuses the completed survey in one input tick.');
networkCheck($s->landscape->recipe()['join']['span']===16384,'A new network defaults to 16,384 meters per square.');
$initial=$s->save();

// Read the route sign on each actual branch as it leaves the connector, even if
// the off-center fork has not yet carried the car across the map's central axis.
[$fx,$fz]=JourneyRoad::entrances($s->landscape)['junction'];
$roads=$s->landscape->roads();
$branches=array_values(array_filter($roads,static fn($r)=>$r['points'][0][0]===$fx && $r['points'][0][2]===$fz));
usort($branches,static fn($a,$b)=>$a['points'][1][2]<=>$b['points'][1][2]);
networkCheck(count($branches)===2,'Two paved exits at the network fork.');
foreach($branches as $i=>$road) {
	while(end($road['points'])[0]<-JourneyRoad::FORK+50.) {
		$end=end($road['points']);
		$next=array_values(array_filter($roads,static fn($r)=>$r['points'][0]===$end));
		networkCheck(count($next)===1,'The branch continues to its destination.');
		$road=$next[0];
	}
	foreach($road['_road']['samples'] as [$x,,$z,$tx,$tz]) if($x>=-JourneyRoad::FORK+50.) break;
	$site=$s->car->checkedSite($s->landscape,$s->water->heightRange()[1],$x,$z,atan2($tx,$tz),[]);
	networkCheck($site!==null,'Dry road at the branch sign.');
	$s->car->spawn($site,$s->landscape,$s->water->level());
	networkCheck($s->status()['journeyBranch']===($i===0 ? 'B' : 'C'),'The network sign identifies the selected arm.');
}


/** Start on the actual incoming pavement with the car moving and cross the streaming handoff. */
function onward(Session $s,int $branch,string $target): void {
	$before=$s->landscape->recipe();
	$approachX=$before['worldSize']/2-1000.;
	$network=new JourneyNetwork($s->journeyPlan());
	$shift=$network->translation($s->status()['journeySquare'],$target,$before);
	$site=null;
	foreach($s->landscape->roads() as $road) foreach($road['_road']['samples'] as [$x,,$z,$tx,$tz]) {
		if(abs($x-$approachX)>7. || count($before['join']['destinations'])>1 && ($z>0)!==(bool)$branch) continue;
		$site=$s->car->checkedSite($s->landscape,$s->water->heightRange()[1],$x,$z,atan2($tx,$tz),[]);
		if($site!==null) break 2;
	}
	networkCheck($site!==null,"Dry approach to $target.");
	$s->car->spawn($site,$s->landscape,$s->water->level());
	$s->input(['keys'=>['KeyW'=>true]],.01,1.);
	for($i=0;$i<12;$i++) $s->step(1/60);
	settleNetwork($s);
	$s->step(1/60);
	$old=$s->car->state();
	$h=$s->landscape->height($old['x'],$old['z']); $material=$s->landscape->material($old['x'],$old['z']);
	$s->input([],0.,1.,8.);
	$new=$s->car->state();
	networkCheck($s->status()['journeySquare']===$target && $s->mode==='car',"Entered shared node $target: ".$s->message);
	networkCheck(abs($new['speed']-$old['speed'])<1.e-8 && $new['speed']>.1,'Handoff preserves a moving car.');
	networkCheck(abs($new['x']-$old['x']-$shift[0])<1.e-6 && abs($new['z']-$old['z']-$shift[2])<1.e-6,'Continuous local position.');
	networkCheck(abs($s->landscape->height($new['x'],$new['z'])-$h-$shift[1])<.01 && $material==='road'
		&& $s->landscape->material($new['x'],$new['z'])==='road','Same pavement and tire-contact height after the rebase.');
}
$shared=null;
foreach([[0,'B',1],[1,'C',0]] as [$branch,$parent,$toF]) {
	$s->load($initial); $s->input(['actions'=>['journey-fork']],0.,1.);
	onward($s,$branch,$parent);
	onward($s,$toF,'F');
	$r=$s->landscape->recipe();
	networkCheck($r['join']['span']===16384,'Streamed squares keep the selected map width.');
	if($shared!==null) networkCheck($shared===$r,'Both entries reach the same F landscape and onward destinations.');
	$shared=$r; $file=$s->save(); $s->load($file);
	networkCheck($s->status()['journeySquare']==='F' && $s->status()['journeyExits']===['H'],'Save and load keep the shared onward route.');
}
$s->close();
echo "Shared F reached through B and C with continuous car motion and pavement.\n";

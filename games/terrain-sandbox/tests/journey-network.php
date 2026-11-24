<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
use TerrainSandbox\{JourneyNetwork,Session};

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
$s->input(['actions'=>[['type'=>'journey-build','network'=>$plan,'span'=>32768]]],0.,1.);
settleNetwork($s);
$initial=$s->save();

/** Start on the actual incoming pavement with the car moving and cross the streaming handoff. */
function onward(Session $s,int $branch,string $target): void {
	$before=$s->landscape->recipe();
	$half=$before['worldSize']/2;
	$network=new JourneyNetwork($s->journeyPlan());
	$shift=$network->translation($s->status()['journeySquare'],$target,$before);
	$site=null;
	foreach($s->landscape->roads() as $road) foreach($road['_road']['samples'] as [$x,,$z,$tx,$tz]) {
		if(abs($x-($half-2850.))>7. || count($before['join']['destinations'])>1 && ($z>0)!==(bool)$branch) continue;
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
	if($shared!==null) networkCheck($shared===$r,'Both entries reach the same F landscape and onward destinations.');
	$shared=$r; $file=$s->save(); $s->load($file);
	networkCheck($s->status()['journeySquare']==='F' && $s->status()['journeyExits']===['H'],'Save and load keep the shared onward route.');
}
$s->close();
echo "Shared F reached through B and C with continuous car motion and pavement.\n";

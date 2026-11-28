<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
use TerrainPhonics\{JoinedTerrain,Landscape};
use TerrainSandbox\{Car,JourneyAtlas,JourneyDriver,JourneyNetwork,JourneyRoad,JourneySigns,Session};

function networkCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
function settleNetwork(Session $s): void { while($s->preparingRoad()) $s->input([],0.,1.,9.); }

// A short section of genuine journey pavement exercises both fork choices with the normal tires and gearbox.
$roadRecipe=JourneyRoad::recipe('rolling-prairie',['rolling-prairie','rolling-prairie'],16384);
$testLand=new Landscape($roadRecipe); $edge=$testLand->recipe()['worldSize']/2;
foreach([[[$edge-650.,80.,0.],[$edge-450.,80.,0.]],
	[[$edge-450.,80.,0.],[$edge-320.,80.,-25.],[$edge-170.,80.,-100.],[$edge,80.,-100.]],
	[[$edge-450.,80.,0.],[$edge-320.,80.,25.],[$edge-170.,80.,100.],[$edge,80.,100.]]] as $points)
	$testLand->queue(['type'=>'road','width'=>28.,'shoulder'=>18.,'points'=>$points]);
$testLand->commit();
foreach([0,1] as $exit) {
	$car=new Car;
	$site=$car->checkedSite($testLand,-10.,$edge-600.,0.,M_PI/2,[]);
	$car->spawn($site,$testLand,-10.);
	$driver=JourneyDriver::board($testLand,$car->state(),$exit);
	networkCheck($driver?->exit===$exit,'Both exits are reachable before the fork.');
	for($i=0;$i<3600 && !$driver->arrived;$i++) {
		[$pedal,$steer,$handbrake,$boost]=$driver->controls($car->state());
		networkCheck($car->step(1/60,$pedal,$steer,$testLand,-10.,$handbrake,[],$boost),'CPU stays on supported ground.');
		if(($i%30)===0) {
			$p=$car->state();
			networkCheck(Car::onPavement($testLand,$p['x'],$p['z']),'CPU follows the pavement through the fork.');
		}
	}
	$p=$car->state();
	networkCheck($driver->arrived && $p['x']>$edge-35. && abs($p['z']-($exit ? 100. : -100.))<4.,
		'The car physically reaches the selected exit.');
}
$testLand->close();

// Two long straights joined by a 90-degree bend. Exercise boost, braking, and the distance-based refill.
$highway=new Landscape($roadRecipe);
$x=$edge-3400.; $radius=100.; $handle=4./3.*tan(M_PI/8.)*$radius;
$pavement=['type'=>'road','width'=>28.,'shoulder'=>18.];
$highway->queue($pavement+['points'=>[[$x,80.,-3500.],[$x,80.,0.]]]);
$highway->queue($pavement+['points'=>[[$x,80.,0.],[$x+$radius,80.,$radius]],
	'handles'=>[['out'=>[0.,0.,$handle]],['in'=>[-$handle,0.,0.]]]]);
$highway->queue($pavement+['points'=>[[$x+$radius,80.,$radius],[$edge,80.,$radius]]]);
$highway->commit();
$car=new Car;
$car->spawn($car->checkedSite($highway,-10.,$x,-3480.,0.,[]),$highway,-10.);
$driver=JourneyDriver::board($highway,$car->state(),0);
$peak=$entrySpeed=$insideCut=0.; $handAt=-100;
$launchBoost=$braked=$exitBoost=$caughtSlide=false;
for($i=0;$i<12000 && !$driver->arrived;$i++) {
	$p=$car->state();
	[$pedal,$steer,$handbrake,$boost]=$driver->controls($p);
	networkCheck($car->step(1/60,$pedal,$steer,$highway,-10.,$handbrake,[],$boost),'Highway remains drivable.');
	$q=$car->state();
	$burned=$q['nitrous']<$p['nitrous'];
	networkCheck(!($handbrake && $boost),'Release the rear tires before catching the slide.');
	if($handbrake && $p['speed']>24.) $handAt=$i;
	if($i-$handAt<20 && $burned && abs($p['drift'])>.025) $caughtSlide=true;
	if($q['x']>$x && $q['x']<$x+$radius && $q['z']>0. && $q['z']<$radius)
		$insideCut=max($insideCut,$radius-hypot($q['x']-($x+$radius),$q['z']));
	if($p['z']<0.) {
		$peak=max($peak,$q['speed']);
		$launchBoost=$launchBoost || $burned;
		$braked=$braked || ($p['z']>-1200. && $pedal<0. && !$boost);
		if($q['z']>=0.) $entrySpeed=$q['speed'];
	}
	if($p['x']>$x+$radius) $exitBoost=$exitBoost || $burned;
	if($i%30===0) networkCheck(Car::onPavement($highway,$q['x'],$q['z']),'The racing line stays on the pavement.');
}
networkCheck($launchBoost && $peak>235.*Car::MPS_PER_MPH,'The CPU spends nitrous and approaches top speed on a clear straight.');
networkCheck($braked && $entrySpeed>0. && $entrySpeed<45.,'The CPU releases boost and brakes before a tight bend.');
networkCheck($caughtSlide && $insideCut>3. && $insideCut<$pavement['width']/2.,
	'Use the inside pavement and catch a high-speed handbrake slide with real nitrous.');
networkCheck($exitBoost && $driver->arrived && abs($q['x']-($edge-25.))<5.,
	'The CPU spends recharged nitrous after the bend and parks at the destination.');
$highway->close();

$plan="A rolling-prairie -> B,C\nB limestone -> E,F\nC coast -> F,G\nE mountain\nF river-valley -> H\nG canyon\nH rolling-prairie";
$chain='';
for($i=0;$i<200;$i++) $chain.="Map$i coast".($i<199?' -> Map'.($i+1):'')."\n";
networkCheck(count((new JourneyNetwork($chain))->nodes)===200,'Long networks retain shared references without expanding possible itineraries.');
foreach(["A coast -> F","A coast -> B\nB mountain -> A","A coast -> B\nB coast\nC canyon"] as $bad) {
	try { new JourneyNetwork($bad); throw new RuntimeException('Invalid topology accepted'); } catch(InvalidArgumentException) {}
}
$duplicate=new JourneyNetwork("A coast -> B,B\nB mountain");
networkCheck($duplicate->nodes['A']['exits']===['B'] && count($duplicate->recipe('A',16384)['join']['destinations'])===1,
	'Repeating one edge in an older plan is one road from one origin.');
$twins=new JourneyNetwork("A coast -> B,C\nB mountain\nC mountain");
[$b,$c]=$twins->recipe('A',16384)['join']['destinations'];
networkCheck($b['seed']!==$c['seed'],'Distinct stage IDs with the same preset still produce separate landscapes.');
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
$s->input(['actions'=>[['type'=>'journey-build','network'=>$plan]]],0.,1.,0.);
networkCheck($s->preparingRoad() && $s->mode==='fly','Previewing A does not skip building the rest of the network.');
$stages=[];
while($s->preparingRoad()) {
	if($progress=$s->status()['journeyBuildProgress']) {
		networkCheck($progress['total']===7 && $s->mode==='fly','Driving waits for all seven stages.');
		$stages[$progress['stage']]=$progress['done'];
	}
	$s->input([],0.,1.,2.);
}
networkCheck($s->mode==='car' && $stages['A']===0 && max($stages)>=5,'The progress count advances before boarding.');
$planner=$s->highways(new JourneyNetwork($plan),16384);
foreach($planner->network->nodes as $id=>$_) networkCheck(!empty($planner->completedRoads($id)),"Roads for $id are ready at departure.");
$window=new \TerrainPhonics\Landscape($planner->recipe('B'));
foreach($planner->build($window,'B',static function() { throw new RuntimeException('A prepared stage started a new survey.'); },
	'A',$s->landscape) as $_) {}
networkCheck($window->roadRecords()===$planner->completedRoads('B'),'Driving restores the exact prepared splines.');
$window->close();
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
	if(!$network->nodes[$target]['exits']) {
		networkCheck($s->status()['journeyArrival']===$target && $s->status()['journeyBranch']===$target,'A direct road reports arrival at its final destination.');
		return;
	}
	networkCheck($s->status()['journeySquare']===$target && $s->mode==='car',"Entered shared node $target: ".$s->message);
	networkCheck(abs($new['speed']-$old['speed'])<1.e-8 && $new['speed']>.1,'Handoff preserves a moving car.');
	networkCheck(abs($new['x']-$old['x']-$shift[0])<1.e-6 && abs($new['z']-$old['z']-$shift[2])<1.e-6,'Continuous local position.');
	networkCheck(abs($s->landscape->height($new['x'],$new['z'])-$h-$shift[1])<.01 && $material==='road'
		&& $s->landscape->material($new['x'],$new['z'])==='road','Same pavement and tire-contact height after the rebase.');
}
$shared=$sharedPavement=null;
foreach([[0,'B',1],[1,'C',0]] as [$branch,$parent,$toF]) {
	$s->load($initial); $s->input(['actions'=>['journey-fork']],0.,1.);
	onward($s,$branch,$parent);
	onward($s,$toF,'F');
	$r=$s->landscape->recipe();
	networkCheck($r['join']['span']===16384,'Streamed squares keep the selected map width.');
	if($shared!==null) networkCheck($shared===$r,'Both entries reach the same F landscape and onward destinations.');
	// Each origin streams its own approach. Compare the actual road where the two
	// approaches meet, in the coordinate system of their common destination.
	$approach=null;
	foreach($s->landscape->roadRecords() as $road) {
		$last=end($road['points']);
		if(abs($last[0]+JoinedTerrain::GAP/2)<1.e-6) $approach=$road['points'];
	}
	networkCheck(count($approach ?? [])>=8,'Carried the shared arrival pavement into F.');
	if($sharedPavement!==null) {
		networkCheck(count($sharedPavement)===count($approach),'Both origins reach the same road in F.');
		foreach($approach as $i=>$point) foreach($point as $axis=>$v)
			networkCheck(abs($v-$sharedPavement[$i][$axis])<.01,'The roads from B and C meet on the same pavement within one centimeter.');
	}
	$sharedPavement=$approach; $shared=$r; $file=$s->save(); $s->load($file);
	networkCheck($s->status()['journeySquare']==='F' && $s->status()['journeyExits']===['H'] && !$s->status()['journeyHasFork'],
		'Save and load keep one onward road from the shared destination.');
	$s->input(['actions'=>['journey-drive']],0.,1.);
	onward($s,0,'H');
}
$s->close();

// Exercise the reported cold right fork. Drive to the edge faster than the survey can
// advance, then let the regular input clock finish it without a further driving tick.
$plan="A karst-bay -> B,C\nB autumn-folds -> E,F\nC yardang-desert -> F,G\nE tepui-highlands\nF chalk-headlands\nG salt-pans";
$s=new Session;
$s->input(['actions'=>[['type'=>'journey-build','network'=>$plan]]],0.,1.);
settleNetwork($s);
$land=$s->landscape;
$half=$land->recipe()['worldSize']/2;
[$forkX,$forkZ]=JourneyRoad::entrances($land)['junction'];
$arm=$edge=null;
foreach($land->roads() as $road) {
	if($road['points'][0][0]===$forkX && $road['points'][0][2]===$forkZ && $road['points'][1][2]>$forkZ) {
		foreach($road['_road']['samples'] as [$x,,$z,$tx,$tz]) {
			if($x<$forkX+JourneyRoad::SPLIT_RUN+20.) continue;
			$arm=$s->car->checkedSite($land,$s->water->heightRange()[1],$x,$z,atan2($tx,$tz),[]);
			if($arm) break;
		}
	}
	foreach($road['_road']['samples'] as [$x,,$z,$tx,$tz]) {
		if(abs($x-($half-450.))>7. || $z<=0.) continue;
		$edge=$s->car->checkedSite($land,$s->water->heightRange()[1],$x,$z,atan2($tx,$tz),[]);
		if($edge) break;
	}
}
networkCheck($arm!==null && $edge!==null,'Two dry stations on the road into C.');
$s->car->spawn($arm,$land,$s->water->level());
$s->input(['keys'=>['KeyW'=>true]],.01,1.);
$s->step(1/60);
networkCheck($s->status()['journeySurveying']==='C','The right fork starts the C survey at the split.');
$s->car->spawn($edge,$land,$s->water->level());
$s->step(1/60);
$held=$s->car->state();
networkCheck($s->status()['journeyWaiting'] && $held['speed']>0.,'A late window holds a moving car on the highway.');
for($i=0;$i<90;$i++) $s->step(1/60);
networkCheck($s->car->state()===$held,'The car cannot roll into the void while the next window loads.');
$shift=(new JourneyNetwork($plan))->translation('A','C',$land->recipe());
networkCheck(JourneyDriver::board($land,$held,0)?->exit===1,'Beyond the fork the CPU follows the arm the car is already on.');
$s->input(['actions'=>['journey-cpu']],0.,1.,0.);
settleNetwork($s);
$after=$s->car->state();
networkCheck($s->status()['journeySquare']==='C' && $s->status()['journeyExits']===['F','G']
	&& !$s->status()['journeyWaiting'] && $after['speed']===$held['speed']
	&& abs($after['x']-$held['x']-$shift[0])<1.e-6 && $s->landscape->material($after['x'],$after['z'])==='road',
	'The input clock resumes C on the same pavement with both onward destinations.');
networkCheck($s->watchingJourney() && in_array($s->status()['journeyDriver']['destination'],['F','G'],true),
	'The CPU takes the next fork decision on the new stage without resetting the moving car.');
$s->input(['actions'=>['journey-take-wheel']],0.,1.);
networkCheck(!$s->watchingJourney() && $s->mode==='car' && $s->car->state()===$after,'Taking control preserves the live car and speed.');
$mid=$s->save();
foreach([['B','journey-fork'],['G','journey-cpu'],['C','journey-drive']] as [$stage,$action]) {
	$s->input(['actions'=>[['type'=>$action,'stage'=>$stage,'generation'=>$s->generation]]],0.,1.);
	settleNetwork($s);
	$car=$s->car->state();
	[$x,$z]=JourneyRoad::entrances($s->landscape,true)[$action==='journey-fork'?'fork':'west'];
	networkCheck($s->mode==='car' && $s->journeySquare()===$stage && hypot($car['x']-$x,$car['z']-$z)<3.,
		"The selected stage $stage supplies the requested entrance.");
	$s->step(1/60);
	if($stage==='G') {
		networkCheck($s->watchingJourney() && $s->status()['journeyDriver']['destination']==='G','Watch boards the inspected terminal stage.');
		// Board a short distance before the actual eastern edge, and let the regular physics stop the car.
		$s->input(['actions'=>['journey-take-wheel']],0.,1.);
		$east=$s->landscape->recipe()['worldSize']/2; $site=null;
		foreach($s->landscape->roads() as $road) {
			$end=end($road['points']);
			if(abs($end[0]-$east)>1.) continue;
			$length=end($road['_road']['stations']);
			$pose=JourneySigns::pose($road,$length-120.);
			[$x,,$z]=$pose['position']; [$tx,$tz]=$pose['heading'];
			$site=$s->car->checkedSite($s->landscape,$s->water->level(),$x,$z,atan2($tx,$tz),[]);
			if($site) break;
		}
		networkCheck($site!==null,'The terminal highway has a dry final approach.');
		$s->car->spawn($site,$s->landscape,$s->water->level());
		$s->input(['actions'=>['journey-cpu'],'keys'=>['KeyA'=>true,'KeyS'=>true]],0.,1.);
		for($i=0;$i<2400 && !$s->status()['journeyDriver']['arrived'];$i+=30)
			for($j=0;$j<30;$j++) $s->step(1/60);
		$parked=$s->car->state();
		networkCheck($s->status()['journeyDriver']['arrived'] && $parked['x']>$east-42. && $parked['x']<$east-10. && abs($parked['speed'])<.31,
			'The CPU drives to the destination and brakes before the map edge.');
		$s->load($s->save());
		networkCheck($s->journeySquare()==='G','A terminal map can be saved and reopened.');
	}
}
foreach(['F','G'] as $branch=>$leaf) {
	$s->load($mid); $s->input(['actions'=>['journey-fork']],0.,1.); onward($s,$branch,$leaf);
}
$s->close();
echo "Shared F reached through B and C with continuous car motion and pavement.\n";

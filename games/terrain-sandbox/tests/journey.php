<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';

use TerrainPhonics\{JoinedTerrain,Landscape,Recipe};
use TerrainSandbox\{Car,JourneyNetwork,JourneyRoad,JourneySurvey,Session};

function expectJourney(bool $ok,string $reason): void {
	if(!$ok) throw new RuntimeException($reason);
}

// A smooth hillside rises roughly 20% straight across. The road can gain the
// very same elevation on longer traverses between two fixed endpoints.
$form=['preset'=>'coast','heightScale'=>900.,'baseBias'=>0.,'rolling'=>0.,'secondary'=>0.,
	'ridgeHeight'=>0.,'wallHeight'=>0.,'detailAmplitude'=>0.,'directionalRise'=>1.,'waterLevel'=>-2000.];
$slope=new Landscape($form+['join'=>['span'=>16384,'destinations'=>[$form]]]);
$ties=[[-10940.,0.],[-9540.,0.]];
$survey=new JourneySurvey($slope->recipe());
$search=$survey->route($ties);
foreach($search as $_) {}
$alignment=$search->getReturn(); $run=0.;
foreach($alignment as $i=>[$x,$z]) if($i) $run+=hypot($x-$alignment[$i-1][0],$z-$alignment[$i-1][1]);
$rise=$slope->height(...$ties[1])-$slope->height(...$ties[0]);
expectJourney($alignment[0]===$ties[0] && end($alignment)===$ties[1]
	&& $rise/1400.>.18 && $rise/$run<.105,'Traverse a steep hillside at road grade between the fixed landings.');
$slope->close();

// A nearer flooded road and two separate dry branches exercise the stick click between junctions.
$flat = ['preset'=>'coast','spacing'=>2.,'heightScale'=>0.,'detailAmplitude'=>0.,'waterLevel'=>-5.];
$recovery = new Session($flat + ['join'=>['version'=>2,'span'=>32768,'destinations'=>[$flat]]]);
foreach([[[100,0,-36],[-100,0,-36]], [[-100,-12,0],[100,-12,0]], [[-100,0,80],[100,0,80]]] as $points) {
	$recovery->landscape->queue(['type'=>'road','points'=>$points,'width'=>10,'shoulder'=>2,'material'=>'road']);
}
$recovery->landscape->commit();
$recovery->mode = 'car';
foreach([[12.,-36.,-M_PI/2],[60.,80.,M_PI/2]] as [$startZ,$roadZ,$yaw]) {
	$x = 24.35;
	$site = $recovery->car->checkedSite($recovery->landscape,-5.,$x,$startZ,0.,[]);
	expectJourney($site !== null && !Car::onPavement($recovery->landscape,$x,$startZ),'Start off the journey pavement.');
	$recovery->car->spawn($site,$recovery->landscape,-5.);
	$before = $recovery->car->state();
	$recovery->input(['actions'=>['pause','pad-rs']],0,1.);
	expectJourney($recovery->car->state() === $before,'Recovery waits until driving resumes.');
	$recovery->input(['actions'=>['pause','pad-rs']],0,1.);
	$after = $recovery->car->state();
	expectJourney(abs($after['x']-$x)<.001 && abs($after['z']-$roadZ)<.001 && abs($after['yaw']-$yaw)<.001,
		'Choose the closest safe branch and face its forward tangent.');
	expectJourney($after['recoveries']===1 && $after['roadHold']===1.5 && $after['speed']===0. && $after['nitrous']===$before['nitrous'],
		'Journey recovery uses the circuit delay and keeps the bottle.');
	$recovery->input(['actions'=>['pad-rs']],0,1.);
	expectJourney($recovery->car->state() === $after,'A click on the pavement leaves the car alone.');
}
$edge = $recovery->landscape->recipe()['worldSize']/2;
$recovery->landscape->queue(['type'=>'road','points'=>[[$edge,0.,0.],[$edge-110.,0.,0.]],
	'width'=>12.,'shoulder'=>2.,'material'=>'road']);
$recovery->landscape->commit();
$site = $recovery->car->checkedSite($recovery->landscape,-5.,$edge-4.,0.,-M_PI/2,[]);
$recovery->car->spawn($site,$recovery->landscape,-5.);
$nose = $recovery->car->state();
$nose['pitch'] = -M_PI/2;
$nose['y'] = 2.04;
$recovery->car->resume($nose,[...$recovery->car->motion(),0.,0.,0.,0.,0.,0.],$recovery->landscape,-5.);
expectJourney($recovery->car->state()['motion']==='tumbling','The car is balanced on its nose above the end pavement.');
$recovery->input(['actions'=>['pad-rs']],0.,1.);
$after = $recovery->car->state();
expectJourney($after['motion']==='driving' && $after['recoveries']===1 && $after['wheelContacts']===4
	&& $after['roadHold']===1.5 && abs($after['pitch'])<.01 && $recovery->camera->position[0]>$edge,
	'Right-stick recovery plants a nose-down wreck on its tires even when its chase camera is beyond the journey edge.');
$recovery->close();

/** Follow connected asphalt from the actual west edge, choosing a branch by its surveyed centerline. */
function throughRoute(array $roads,float $edge,bool $south): array {
	$at = null;
	foreach($roads as $road) if($road['points'][0][0] === -$edge) $at=$road['points'][0];
	$path=[];
	while($at !== null && $at[0] < $edge) {
		$next=array_values(array_filter($roads,static fn($road)=>$road['points'][0]==$at));
		expectJourney(count($next)===1 || count($next)===2,'Continuous road at each joint.');
		if(count($next)===2) usort($next,static fn($a,$b)=>$a['points'][1][2]<=>$b['points'][1][2]);
		$road=$next[$south ? count($next)-1 : 0];
		foreach($road['_road']['samples'] as $sample) $path[]=[$sample[0],$sample[2],atan2($sample[3],$sample[4]),$sample[1]];
		$at=end($road['points']);
	}
	expectJourney($at !== null && $at[0]===$edge,'Drive a full map to its outer edge.');
	return $path;
}

function driveStrip(Session $s,array $path): void {
	$index=0;
	while($path[$index][0]<-JoinedTerrain::GAP/2-100.) $index++;
	[$x,$z,$yaw]=$path[$index];
	$water=$s->water->level();
	$site=$s->car->checkedSite($s->landscape,$s->water->heightRange()[1],$x,$z,$yaw,[]);
	expectJourney($site!==null,'Dry road at the western seam.');
	$s->car->spawn($site,$s->landscape,$water);
	// A fork can now begin in the departure square and take a long traverse
	// along the shore. Allow driving time in proportion to the surveyed distance.
	$length=0.;
	for($j=$index;$path[$j][0]<=JoinedTerrain::GAP/2+100.;$j++)
		$length+=hypot($path[$j+1][0]-$path[$j][0],$path[$j+1][1]-$path[$j][1]);
	$frames=(int)ceil(30.*(20.+$length/15.));
	for($i=0;$i<$frames;$i++) {
		$c=$s->car->state();
		if($c['x']>JoinedTerrain::GAP/2+100.) return;
		// Track distance along the surveyed road, including bends that turn back west.
		while($index+1<count($path) && hypot($path[$index+1][0]-$c['x'],$path[$index+1][1]-$c['z'])
			<=hypot($path[$index][0]-$c['x'],$path[$index][1]-$c['z'])) $index++;
		$target=$index;
		while($target+1<count($path) && hypot($path[$target][0]-$c['x'],$path[$target][1]-$c['z'])<14.) $target++;
		[$tx,$tz]=$path[$target];
		$error=atan2($tx-$c['x'],$tz-$c['z'])-$c['yaw'];
		$steer=-atan2(2*Car::WHEELBASE*sin($error),hypot($tx-$c['x'],$tz-$c['z']))/Car::steeringLimit($c['speed']);
		$bend=0.;
		for($j=$index+1;$j<min(count($path),$index+20);$j++) {
			$delta=$path[$j][2]-$path[$j-1][2];
			$bend=max($bend,abs(atan2(sin($delta),cos($delta)))/max(.1,hypot($path[$j][0]-$path[$j-1][0],$path[$j][1]-$path[$j-1][1])));
		}
		$speed=min(26.,sqrt(5./max(.001,$bend)));
		$pedal=max(-1.,min(1.,($speed-$c['speed'])*.4));
		expectJourney($s->car->step(1/30,$pedal,max(-1.,min(1.,$steer)),$s->landscape,$water,false,[],false),'Car keeps driving across the connector.');
		expectJourney($c['motion']==='driving' && $s->landscape->material($c['x'],$c['z'])==='road',"Stay on the paved branch at ({$c['x']}, {$c['z']}), {$c['motion']}, aiming for ($tx,$tz).");
	}
	throw new RuntimeException("The car did not reach the next landscape at ($c[x],$c[z]), speed $c[speed], station $index, target ($tx,$tz), desired $speed.");
}

foreach([[16384,['mountain']],[32768,['mountain']],[65536,['canyon','coast']]] as [$span,$destinations]) {
	$s = new Session;
	$s->input(['actions'=>[['type'=>'journey-build','west'=>'rolling-prairie','destinations'=>$destinations,'span'=>$span]]],0,1.);
	while($s->preparingRoad()) $s->input([],0,1.,6.);
	$land=$s->landscape; $r=$land->recipe(); $half=$r['worldSize']/2;
	expectJourney($s->mode==='car' && $s->car->state()['x']<-$half+55.,'West-edge boarding.');
	$views=$s->status()['journeyViews'];
	expectJourney(count($views)===1+count($destinations),'One overlook on each full landscape square.');
	foreach($views as [$vx,$vz,$drop]) expectJourney($land->material($vx,$vz)==='road' && $drop>0.,'A roadside overlook above its surroundings.');
	$roads=$land->roads();
	$split=count($destinations)===2;
	$entrances=JourneyRoad::entrances($land);
	expectJourney(isset($entrances['fork'])===$split && $s->status()['journeyHasFork']===$split,'Only two distinct exits create a fork.');
	$ends=[];
	foreach($split ? [false,true] : [false] as $south) {
		$path=throughRoute($roads,$half,$south);
		$ends[]=end($path);
		foreach($path as $i=>[$x,$z]) if($i%48===0) {
			expectJourney($land->height($x,$z)>$s->water->heightRange()[1],'Dry road across both full maps.');
			expectJourney($land->material($x,$z)==='road','No missing pavement between road records.');
		}
		driveStrip($s,$path);
	}
	if($split) expectJourney($ends[0]!==$ends[1],'Both arms reach their respective landscapes.');
	$origins=JoinedTerrain::origins($span,count($destinations));
	foreach($r['join']['destinations'] as $i=>$form) {
		[$x,$z]=$origins[$i+1];
		$height=(new Recipe($form))->height(105.,601.)+$r['waterLevel']-$form['waterLevel'];
		expectJourney(abs($land->height($x+105.,$z+601.)-$height)<1.e-6,'Destination keeps its local noise coordinates above the common sea.');
	}
	$records=$land->roadRecords();
	$first=reset($records);
	$joint=end($first['points']);
	[$knotX,$knotZ]=$split ? $entrances['junction'] : [$joint[0],$joint[2]];
	foreach($records as $id=>$road) if($road['points'][0][0]===$knotX && $road['points'][0][2]===$knotZ) break;
	$road['replaceAt']=$id; $road['points'][0][0]+=120.; $road['points'][0][2]+=12.;
	$updates=JourneyRoad::connectedEdits($land,$road);
	expectJourney(count($updates)===($split ? 3 : 2),'Moving a joint edits each incident road.');
	foreach($updates as $update) foreach($land->queueSteps($update) as $_) {}
	$land->commit();
	$moved=$land->roadRecords();
	foreach($updates as $update)
		expectJourney($moved[$update['replaceAt']]['points']===$update['points'],'Persist every moved junction endpoint.');
	expectJourney($land->undo() && $land->roadRecords()===$records,'One undo restores every incident road.');
	expectJourney($land->redo() && $land->roadRecords()===$moved,'One redo reapplies the whole junction.');
	$file=$s->save();
	$s->load($file);
	expectJourney($s->landscape->roadRecords()===$moved && $s->landscape->recipe()===$r && $s->status()['journeyViews']==$views,'Recipes, junctions and edited splines survive reload.');
	$s->input(['actions'=>[$split ? 'journey-fork' : 'journey-drive']],0,1.);
	expectJourney($s->mode==='car' && $s->status()['journeyForkAhead']===$split,'The saved highway offers its actual entrances.');
	if($split) expectJourney(abs($s->car->state()['x']-$road['points'][0][0]+140.)<6.,'Drive from the saved fork.');
	$s->input(['actions'=>[['type'=>'generate','preset'=>'coast']]],0,1.);
	expectJourney(!isset($s->landscape->recipe()['join']) && $s->landscape->recipe()['worldSize']==$span,'Ordinary generation exits the journey.');
	$s->close();
	echo count($destinations)." destination(s) passed on the $span m journey.\n";
}

// Compare finished pavement with untouched Canyon ground at fixed distance intervals.
$land=new Landscape(JourneyRoad::recipe('canyon',['mountain'],32768));
$ground=new JoinedTerrain($land->recipe());
foreach(JourneyRoad::build($land) as $_) {}
$errors=[];
foreach($land->roads() as $road) {
	$distance=0.;
	foreach($road['_road']['samples'] as $i=>[$x,$y,$z]) {
		if(!$i || $x>=-JoinedTerrain::GAP/2) continue;
		$prev=$road['_road']['samples'][$i-1];
		$distance+=hypot($x-$prev[0],$z-$prev[2]);
		if($distance<32.) continue;
		$distance-=32.;
		$errors[]=abs($ground->height($x,$z)-$y);
	}
}
sort($errors,SORT_NUMERIC);
expectJourney(count($errors)>900 && array_sum($errors)/count($errors)<50. && $errors[intdiv(count($errors),2)]<8.,
	'The Canyon highway must detour around escarpments and stay near natural ground for most of the crossing.');
checkForkEarthworks($land,10.,45.);
$land->close();

// Compare the fitted spline to the ungraded land through the entire seam, including
// the bank on the approach and both destination exits. Stock seeds are reproducible.
function checkForkEarthworks(Landscape $land,float $meanLimit,float $peakLimit): void {
	$ground=new JoinedTerrain($land->recipe());
	$errors=[];
	foreach($land->roads() as $road) foreach($road['_road']['samples'] as $i=>[$x,$y,$z]) {
		if($i%8===0 && abs($x)<=JoinedTerrain::GAP/2) $errors[]=abs($ground->height($x,$z)-$y);
	}
	expectJourney(count($errors)>100 && array_sum($errors)/count($errors)<$meanLimit && max($errors)<$peakLimit,
		'The fork and its approaches should follow the natural terrain on both branches.');
}

$network=new JourneyNetwork("A rolling-prairie -> B,C\nB canyon -> F\nC mountain -> F\nF coast");
$land=new Landscape($network->recipe('A',32768));
foreach(JourneyRoad::build($land) as $_) {}
checkForkEarthworks($land,10.,50.);
$land->close();

// The eastern Canyon arrival used to run along a disconnected plateau with a
// 300 m cut. Measure the finished spline and both edges, including the fork.
$land=new Landscape(JourneyRoad::recipe('rolling-prairie',['canyon'],16384));
$bare=new JoinedTerrain($land->recipe());
foreach(JourneyRoad::build($land) as $_) {}
$length=$highEdges=0.;
foreach($land->roads() as $road) {
	$last=null;
	foreach($road['_road']['samples'] as $i=>[$x,$y,$z,$tx,$tz]) {
		if($i%4) continue;
		$run=$last ? hypot($x-$last[0],$z-$last[1]) : 0.;
		$length+=$run; $last=[$x,$z];
		$error=0.;
		foreach([-14.,0.,14.] as $offset)
			$error=max($error,abs($y-$bare->height($x-$tz*$offset,$z+$tx*$offset)));
		if($error>30.48) $highEdges+=$run;
	}
}
expectJourney($length>32768. && $highEdges<$length*.02,'Keep 100-foot cuts and fills below two percent of the Rolling Prairie to Canyon pavement.');
$land->close();

// The former fixed split sent the Canyon arm into a shoreline dogleg of 137 degrees
// in 100 m. Check the drivable spline from the actual junction to the far bank.
$land=new Landscape(JourneyRoad::recipe('rolling-prairie',['canyon','basalt-fjords'],16384));
$bare=new JoinedTerrain($land->recipe());
$build=JourneyRoad::build($land);
foreach($build as $_) {}
expectJourney(count($build->getReturn())===3,'Keep an overlook in each square of the Prairie/Canyon/Fjords journey.');
[$forkX,$forkZ]=JourneyRoad::entrances($land)['junction'];
foreach([false,true] as $south) {
	$path=throughRoute($land->roads(),$land->recipe()['worldSize']/2,$south);
	$active=false; $last=null; $history=[]; $run=$rise=$fall=$bend=$cut=0.;
	foreach($path as [$x,$z,$yaw,$y]) {
		if($x===$forkX && $z===$forkZ) $active=true;
		if(!$active) continue;
		if($last) {
			$run+=hypot($x-$last[0],$z-$last[1]);
			$rise+=max(0.,$y-$last[2]); $fall+=max(0.,$last[2]-$y);
		}
		$last=[$x,$z,$y];
		while($history && $history[0][0]<$run-100.) array_shift($history);
		foreach($history as [, $heading]) $bend=max($bend,abs(atan2(sin($yaw-$heading),cos($yaw-$heading))));
		$history[]=[$run,$yaw];
		$cut=max($cut,abs($y-$bare->height($x,$z)));
		if($x>=JoinedTerrain::GAP/2) break;
	}
	expectJourney($active && $x>=JoinedTerrain::GAP/2 && $bend<deg2rad(60.),
		'Both destination roads round the headland without a shoreline hairpin.');
	expectJourney(min($rise,$fall)<80. && $cut<20.,
		'Both fork arms use accessible passes without climbing and descending the intervening mountain.');
}
$land->close();

<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';

use TerrainPhonics\{JoinedTerrain,Landscape,Recipe};
use TerrainSandbox\{Car,JourneyRoad,Session};

function expectJourney(bool $ok,string $reason): void {
	if(!$ok) throw new RuntimeException($reason);
}

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
		foreach($road['_road']['samples'] as $sample) $path[]=[$sample[0],$sample[2],atan2($sample[3],$sample[4])];
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
	for($i=0;$i<13000;$i++) {
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

foreach([[32768,['mountain']],[65536,['canyon','coast']]] as [$span,$destinations]) {
	$s = new Session;
	$s->input(['actions'=>[['type'=>'journey-build','west'=>'rolling-prairie','destinations'=>$destinations,'span'=>$span]]],0,1.);
	while($s->preparingRoad()) $s->input([],0,1.,6.);
	$land=$s->landscape; $r=$land->recipe(); $half=$r['worldSize']/2;
	expectJourney($s->mode==='car' && $s->car->state()['x']<-$half+55.,'West-edge boarding.');
	$views=$s->status()['journeyViews'];
	expectJourney(count($views)===1+count($destinations),'One overlook on each full landscape square.');
	foreach($views as [$vx,$vz,$drop]) expectJourney($land->material($vx,$vz)==='road' && $drop>0.,'A roadside overlook above its surroundings.');
	$roads=$land->roads();
	$ends=[];
	foreach([false,true] as $south) {
		$path=throughRoute($roads,$half,$south);
		$ends[]=end($path);
		foreach($path as $i=>[$x,$z]) if($i%48===0) {
			expectJourney($land->height($x,$z)>$s->water->heightRange()[1],'Dry road across both full maps.');
			expectJourney($land->material($x,$z)==='road','No missing pavement between road records.');
		}
		driveStrip($s,$path);
	}
	expectJourney(($ends[0]===$ends[1]) === (count($destinations)===1),'Branches rejoin or reach distinct destinations.');
	$origins=JoinedTerrain::origins($span,count($destinations));
	foreach($r['join']['destinations'] as $i=>$form) {
		[$x,$z]=$origins[$i+1];
		$height=(new Recipe($form))->height(105.,601.)+$r['waterLevel']-$form['waterLevel'];
		expectJourney(abs($land->height($x+105.,$z+601.)-$height)<1.e-6,'Destination keeps its local noise coordinates above the common sea.');
	}
	$records=$land->roadRecords();
	foreach($records as $id=>$road) if($road['points'][0][0]===JourneyRoad::FORK) break;
	$road['replaceAt']=$id; $road['points'][0][0]+=120.; $road['points'][0][2]+=12.;
	$updates=JourneyRoad::connectedEdits($land,$road);
	expectJourney(count($updates)===3,'Moving a fork knot edits all three incident roads.');
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
	$s->input(['actions'=>['journey-fork']],0,1.);
	expectJourney($s->mode==='car' && $s->status()['journeyForkAhead'] && abs($s->car->state()['x']-$road['points'][0][0]+140.)<6.,'Drive from the saved fork.');
	$s->input(['actions'=>[['type'=>'generate','preset'=>'coast']]],0,1.);
	expectJourney(!isset($s->landscape->recipe()['join']) && $s->landscape->recipe()['worldSize']==$span,'Ordinary generation exits the journey.');
	$s->close();
	echo "Both branches passed on the $span m journey.\n";
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
$land->close();

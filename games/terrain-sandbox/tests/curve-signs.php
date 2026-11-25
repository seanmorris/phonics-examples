<?php
declare(strict_types=1);

require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
require dirname(__DIR__).'/autoload.php';
use TerrainPhonics\Landscape;
use TerrainSandbox\Car;
use TerrainSandbox\CurveSignModel;
use TerrainSandbox\CurveSigns;
use TerrainSandbox\RoadWarnings;
use TerrainSandbox\Session;

function check(bool $ok, string $why): void { if(!$ok) throw new RuntimeException($why); }

// A circular arc, mirrored about the incoming lane for the opposite turn.
function bend(float $radius, int $turn, float $width = 12., string $material = 'road', float $angle = M_PI/2): array
{
	$handle = 4/3*tan($angle/4)*$radius;
	return ['type'=>'road','width'=>$width,'shoulder'=>6.,'material'=>$material,
		'points'=>[[0.,0.,0.],[-$turn*$radius*(1.-cos($angle)),0.,$radius*sin($angle)]],
		'handles'=>[['out'=>[0.,0.,$handle]],['in'=>[$turn*$handle*sin($angle),0.,-$handle*cos($angle)]]]];
}
function sites(Landscape $land): array
{
	return array_values(array_filter(iterator_to_array(CurveSigns::sites($land),false)));
}
foreach([-1,1] as $turn) foreach([12.,36.] as $width) {
	$land = new Landscape(['worldSize'=>1024,'heightScale'=>0,'detailAmplitude'=>0]);
	$land->queue(bend(60.,$turn,$width)); $land->commit();
	$row = sites($land);
	$outsideArc = M_PI/2*(60.+$width/2+2.);
	check(count($row)===(int)round($outsideArc/(6*.9144)),'A quarter turn has one sign per six yards of outside shoulder');
	$previous = null;
	foreach($row as [$x,$z,$tx,$tz,$sign]) {
		check(abs(hypot($x+$turn*60.,$z)-(60.+$width/2+2.)) < .10,'The post stands on the outside of the resized pavement');
		check($sign === $turn && abs(hypot($tx,$tz)-1.)<1e-8, 'The panel faces along the lane and the chevron points into the turn');
		if($previous !== null) {
			$gap = hypot($x-$previous[0],$z-$previous[1])/.9144;
			check(abs($gap-6.)<.3,'Successive posts are about six yards apart on the outside edge');
		}
		$previous = [$x,$z];
	}
	$model = new CurveSignModel;
	for($i=0; $i<1000 && !$model->count; $i++) $model->update($land);
	check($model->count===count($row),'Spatial batches retain all signs');
	$land->undo(); $model->update($land);
	check(!$model->count && !sites($land),'Undo retires the old bends and their signs');
	$land->redo();
	check(sites($land)===$row,'Redo restores the identical row');
	$model->close(); $land->close();
}
$land = new Landscape(['worldSize'=>2048]);
$land->queue(bend(120.,1)); // A sustained sweeper that used to qualify.
$land->queue(bend(60.,-1,angle:deg2rad(22))); // A tight but brief kink.
$land->queue(bend(60.,-1,12.,'grass'));
$land->queue(['type'=>'road','width'=>12.,'shoulder'=>4.,'points'=>[[-300.,0.,0.],[-300.,0.,220.]]]);
$land->commit();
check(sites($land)===[],'Straights, broad sweepers, brief kinks and unpaved bends get no signs');
$land->close();

function warnings(Landscape $land, int $kind): array
{
	$faces=[];
	foreach(RoadWarnings::sites($land) as $pole) if($pole!==null) {
		foreach(array_slice($pole,4) as $symbol) if(abs($symbol)===$kind)
			$faces[]=[...array_slice($pole,0,4),$symbol];
	}
	return $faces;
}
function pavement(array $a,array $b): array
{
	return ['type'=>'road','width'=>12.,'shoulder'=>4.,'stationSpacing'=>2.,'points'=>[$a,$b]];
}
/** Exact straight and circular sections. Signed degrees turn to the driver's right. */
function warningCourse(array $sections,bool $closed=false): Landscape
{
	$r=45.; $heading=0.; $p=[0.,0.,0.]; $edits=[];
	if(!$closed) $edits[]=pavement([0.,0.,-450.],$p);
	foreach($sections as [$turn,$straight]) {
		$next=$heading+deg2rad($turn); $side=$turn<=>0;
		$q=[$p[0]+$side*$r*(cos($next)-cos($heading)),0.,$p[2]+$side*$r*(sin($next)-sin($heading))];
		$h=4./3.*tan(abs($next-$heading)/4.)*$r;
		$edits[]=pavement($p,$q)+['handles'=>[['out'=>[-$h*sin($heading),0.,$h*cos($heading)]],
			['in'=>[$h*sin($next),0.,-$h*cos($next)]]]];
		$p=[$q[0]-$straight*sin($next),0.,$q[2]+$straight*cos($next)];
		$edits[]=pavement($q,$p);
		$heading=$next;
	}
	$land=new Landscape(['worldSize'=>2048]);
	if(count($edits)>4) $edits[$closed ? 1 : 2]['points']=array_reverse($edits[$closed ? 1 : 2]['points']);
	foreach(array_reverse($edits) as $edit) $land->queue($edit);
	$land->commit(); return $land;
}
foreach([[RoadWarnings::CURVE,55,0],[RoadWarnings::HAIRPIN,160,0],[RoadWarnings::SWERVE,45,-45],[RoadWarnings::KINK,85,-85]] as [$kind,$first,$second]) {
	foreach([-1,1] as $mirror) {
		$track=warningCourse($second ? [[$mirror*$first,18.],[$mirror*$second,150.]] : [[$mirror*$first,150.]]);
		$signs=warnings($track,$kind);
		check(count($signs)===1,'One forward-facing diamond warns of the approach to a bend or pair of bends.');
		$forward=array_values(array_filter($signs,static fn($s)=>abs($s[0]+8.5)<.1 && abs($s[1]+RoadWarnings::LEAD)<6.1));
		check(count($forward)===1 && $forward[0][4]===$mirror*$kind,'The first turn controls mirroring on the right shoulder 400 feet before the bend.');
		$track->close();
	}
}
// An 80 m straight separates the two turns. Each normal 400 ft warning would precede the first turn's exit.
foreach([90,-90] as $next) {
	$pair=warningCourse([[90,80.],[$next,220.]]);
	$signs=warnings($pair,RoadWarnings::CURVE);
	check(count($signs)===($next===90 ? 1 : 2),'Keep one repeated turn, or both conflicting turns.');
	check($signs[0][4]===RoadWarnings::CURVE && abs($signs[0][1]+400.*.3048)<6.1,'The first diamond keeps its 400 ft lead.');
	if($next<0) {
		[$x,$z,$tx,$tz,$symbol]=$signs[1];
		// The first 45 m radius quarter turn ends at (-45,45), heading west.
		check($symbol===-RoadWarnings::CURVE && $tx<-.99 && $x<-45. && $x>-63.,
			'Show the opposite turn just after clearing the first bend.');
	}
	$pair->close();
}
$clear=warningCourse([[90,200.],[90,200.]]);
check(count(warnings($clear,RoadWarnings::CURVE))===2,'Separate hazards keep separate warnings.');
$clear->close();
$tightLap=warningCourse(array_fill(0,4,[90,80.]),true);
check(count(warnings($tightLap,RoadWarnings::CURVE))===1,'A continuous run of like turns keeps one sign across the lap seam.');
$tightLap->close();
$race=warningCourse(array_fill(0,4,[90,420.]),true);
$diamonds=warnings($race,RoadWarnings::CURVE);
check(count($diamonds)===4 && count(array_filter($diamonds,static fn($s)=>abs($s[0]+8.5)<.1 && abs($s[1]+RoadWarnings::LEAD)<7.))===1,
	'A closed circuit signs every corner in the driving direction, including across the lap seam.');
$race->close();

$merge=new Landscape(['worldSize'=>1024]);
foreach([[[-95.,0.,-450.],[0.,0.,0.]],[[95.,0.,-450.],[0.,0.,0.]],[[0.,0.,0.],[0.,0.,450.]]] as [$a,$b])
	$merge->queue(pavement($a,$b));
$merge->commit(); $arms=warnings($merge,RoadWarnings::MERGE);
check(count($arms)===2 && $arms[0][4]===-$arms[1][4],'Each merging road depicts the other arm on its actual side.');
foreach($arms as [$x,$z,$tx,$tz,$symbol]) {
	check(abs(-($x*$tx+$z*$tz)-RoadWarnings::LEAD)<.05 && !\TerrainPhonics\Edits::onRoad($x,$z,$merge->roads()),
		'The merge warning stands off the pavement 400 driving feet before the shared node.');
	check(($x<0)==($symbol<0),'The merge artwork shows which shoulder the joining road comes from.');
}
// A bend shortly after the two highways meet needs its advance sign on each incoming road.
$afterMerge=$merge->fork();
$curve=bend(45.,1); foreach($curve['points'] as &$point) $point[2]+=100.;
unset($point);
$afterMerge->queue(['replaceAt'=>2]+pavement([0.,0.,0.],[0.,0.,100.]));
$afterMerge->queue($curve); $afterMerge->commit();
$forwardWarnings=warnings($afterMerge,RoadWarnings::CURVE);
check(count($forwardWarnings)===2,'A nearby bend beyond a merge warns both forward approaches.');
foreach($forwardWarnings as [$x,$z,$tx,$tz]) check($tz>0. && abs(100.-($x*$tx+$z*$tz)-400.*.3048)<7.,
	'The approach includes the distance driven beyond the merge in its 400 ft lead.');
$afterMerge->close();
// The same bend just 18 m beyond the merge needs two distinct warnings on each incoming approach.
$stacked=$merge->fork();
$curve=bend(45.,1);
foreach($curve['points'] as &$point) $point[2]+=18.;
unset($point);
$stacked->queue(['replaceAt'=>2]+pavement([0.,0.,0.],[0.,0.,18.]));
$stacked->queue($curve); $stacked->commit();
$poles=array_values(array_filter(iterator_to_array(RoadWarnings::sites($stacked),false)));
check(count($poles)===2,'Each incoming road keeps its own shared warning pole.');
foreach($poles as $pole) {
	check(count($pole)===6 && abs($pole[4])===RoadWarnings::MERGE && $pole[5]===RoadWarnings::CURVE,
		'The driver sees the merge above the bend, with both symbols visible.');
	check(($pole[0]<0.)===($pole[4]<0) && !\TerrainPhonics\Edits::onRoad($pole[0],$pole[1],$stacked->roads()),
		'The shared pole keeps the correct shoulder, facing and mirrored merge artwork.');
}
$model=new CurveSignModel;
for($i=0;$i<1000 && !$model->count;$i++) $model->update($stacked);
$cells=(fn()=>$this->cells)->call($model);
$panels=$supports=0;
foreach($cells as $cell) {
	$fit=(fn()=>$this->fit($cell['bytes']))->call($model);
	iterator_count($fit);
	[$bytes,,$high]=$fit->getReturn();
	foreach(str_split($bytes,32) as $instance) {
		[$x,$y,$z,$tx,$tz,$symbol,$lift,$support]=array_values(unpack('g*',$instance));
		if(abs($symbol)<2.) continue;
		$panels++; $supports+=(int)$support;
		check(($lift>5.30)===($support===1.) && ($lift>0.)===(abs($symbol)===floatval(RoadWarnings::MERGE)),
			'Only the higher panel supplies the shaft, with clearance between the two diamonds.');
		check($high>=$y+$lift+6.89 && $cell['lift']>=$lift-.001,'Culling bounds include the top point of each diamond.');
	}
}
check($panels===4 && $supports===2,'Four diamonds on the two approaches render on exactly two poles.');
$model->close(); $stacked->close();

// Exact driving distances also cover coincident sites, a three-sign chain and the timing-line seam.
$group=function(array $placements,bool $closed=false) {
	$path=new RoadWarnings;
	$sites=[];
	foreach($placements as [$at,$symbol]) $sites[]=['at'=>$at,'slack'=>INF,'lead'=>120.,'symbol'=>$symbol,'pose'=>[$at,0.,1.,0.]];
	$job=(function() use($sites,$closed) {
		$this->closed=$closed; $this->length=500.;
		$feet=[];
		return $this->sharedPoles($sites,1,[],$feet);
	})->call($path);
	return array_values(array_filter(iterator_to_array($job,false)));
};
$hundred=100*\TerrainSandbox\DisplayUnits::FOOT;
$poles=$group([[0.,2],[0.,2],[$hundred,RoadWarnings::HILL],[2*$hundred,RoadWarnings::DIP]]);
check(count($poles)===2 && array_slice($poles[0],4)===[2,RoadWarnings::HILL] && $poles[1][4]===RoadWarnings::DIP,
	'Include exactly 100 feet, omit duplicate artwork and keep a distant third sign at its own site.');
check(count($group([[0.,2],[$hundred+.001,7]]))===2,'Just beyond 100 feet gets two poles.');
$poles=$group([[5.,7],[475.,-2]],true);
check(count($poles)===1 && $poles[0][0]===475. && array_slice($poles[0],4)===[-2,7],
	'Across a lap seam the first hazard stays on top at the upstream pole.');
// With a compatible panel between the turn requests, the group must still remember its first turn.
$turns=[RoadWarnings::CURVE,-RoadWarnings::CURVE,RoadWarnings::HAIRPIN,-RoadWarnings::HAIRPIN,
	RoadWarnings::SWERVE,-RoadWarnings::SWERVE,RoadWarnings::KINK,-RoadWarnings::KINK];
foreach($turns as $firstTurn) foreach($turns as $nextTurn) {
	$poles=$group([[0.,$firstTurn],[4.,RoadWarnings::HILL],[12.,$nextTurn]]);
	check(array_slice($poles[0],4)===[$firstTurn,RoadWarnings::HILL],'The compatible hill stays with the first turn.');
	if($firstTurn===$nextTurn) check(count($poles)===1,'Identical arrows share one face.');
	else check(count($poles)===2 && array_slice($poles[1],4)===[$nextTurn],'A different turn arrow requires its own post.');
}
$poles=$group([[5.,RoadWarnings::KINK],[475.,-RoadWarnings::SWERVE]],true);
check(count($poles)===2 && $poles[0][0]===475. && $poles[1][0]===5.,'Conflicting turns spanning the lap line keep their own posts.');

// Equal-radius fork arms request opposing arrows at exactly the same station on the incoming road.
$symmetric=new Landscape(['worldSize'=>2048]);
$symmetric->queue(pavement([0.,0.,-450.],[0.,0.,0.]));
foreach([-1,1] as $turn) $symmetric->queue(bend(45.,$turn));
$symmetric->commit();
$poles=array_values(array_filter(iterator_to_array(RoadWarnings::sites($symmetric),false)));
check(count($poles)===2 && count($poles[0])===5 && count($poles[1])===5 && $poles[0][4]===-$poles[1][4],
	'Both opposing turns at a fork have dedicated sign faces.');
check(hypot($poles[0][0]-$poles[1][0],$poles[0][1]-$poles[1][1])>5.3,
	'Coincident turn warnings get visibly separate footings at the fork.');
foreach($poles as [$x,,,$tz,$arrow]) check($x*$arrow<0. && $tz>.99,'The two fork warnings face the driver from their respective shoulders.');
// Only the right shoulder remains available for these warnings.
$symmetric->queue(pavement([8.5,0.,-190.],[8.5,0.,-60.]));
$symmetric->commit();
$poles=array_values(array_filter(iterator_to_array(RoadWarnings::sites($symmetric),false)));
check(count($poles)===2 && $poles[0][0]<0. && $poles[1][0]<0. && abs($poles[0][1]-$poles[1][1])>5.3,
	'An occupied shoulder moves one warning along the pavement so both signs are visible.');
$symmetric->close();
// Only one incoming road has a turn before the merge. Delay its next turn warning on that arm alone.
$junction=new Landscape(['worldSize'=>2048]);
$exit=[-125.,0.,45.]; $join=[-100.,0.,45.];
$second=bend(45.,-1);
foreach($second['points'] as &$point) $point=[$exit[0]-$point[2],0.,$exit[2]+$point[0]];
unset($point);
foreach($second['handles'] as &$handle) foreach($handle as &$v) $v=[-$v[2],0.,$v[0]];
unset($handle,$v);
foreach([pavement([0.,0.,-450.],[0.,0.,0.]),bend(45.,1),pavement([-45.,0.,45.],$join),
	pavement([190.,0.,240.],$join),pavement($join,$exit),$second] as $record) $junction->queue($record);
$junction->commit();
$opposing=array_values(array_filter(warnings($junction,RoadWarnings::CURVE),static fn($s)=>$s[4]<0));
check(count($opposing)===2 && count(array_filter($opposing,static fn($s)=>$s[2]<-.99 && $s[0]<-45. && $s[0]>-63.))===1,
	'The shared turn warns each incoming route according to the bends that driver has passed.');
$junction->close();
$hidden=$merge->previewWithoutRoads([0]);
check(!warnings($hidden,RoadWarnings::MERGE),'Hiding an arm removes the merge warnings.');
$reversed=new Landscape(['worldSize'=>1024]);
foreach(array_reverse($merge->roadRecords()) as $i=>$record) {
	if($i===1) $record['points']=array_reverse($record['points']);
	$reversed->queue($record);
}
$reversed->commit(); $reverseArms=warnings($reversed,RoadWarnings::MERGE);
sort($arms); sort($reverseArms);
check(count($reverseArms)===2,'Both merge warnings survive edits to road direction.');
foreach($arms as $i=>$arm) foreach($arm as $j=>$value)
	check(abs($value-$reverseArms[$i][$j])<1.e-8,'Reversing and reordering editable roads preserves each merge sign.');
$fork=new Landscape(['worldSize'=>1024]);
foreach($merge->roadRecords() as $record) {
	$record['points']=array_reverse($record['points']); $fork->queue($record);
}
$fork->commit(); check(!warnings($fork,RoadWarnings::MERGE),'A forward fork has no merge signs for wrong-way traffic.');
$fork->close();
$reversed->close();
$hidden->close(); $merge->close();

foreach([['crest',24.,24.],['drop',0.,24.],['constant grade',24.,-24.],['gentle',.4,.4]] as [$shape,$rise,$fall]) {
	$profile=new Landscape(['worldSize'=>1024]);
	$profile->queue(pavement([0.,-$rise*2.5,-450.],[0.,0.,0.]));
	$profile->queue(pavement([0.,0.,0.],[0.,-$fall*2.5,450.])); $profile->commit();
	$hills=warnings($profile,RoadWarnings::HILL); $dips=warnings($profile,RoadWarnings::DIP);
	if($shape==='crest') check(count($hills)===1 && !$dips,'A launchable hill is signed uphill in the driving direction.');
	elseif($shape==='drop') check(!$hills && count($dips)===1 && $dips[0][3]>0. && abs($dips[0][1]+RoadWarnings::LEAD)<7.,
		'A level approach to a drop gets a dip, without a reverse hill sign.');
	else check(!$hills && !$dips,'A constant grade or gentle roller cannot unload the suspension.');
	$profile->close();
}
foreach([650.,2500.] as $radius) {
	// A long parabolic hill tests gradual unloading at high speed, without a sharp change of pitch.
	$profile=new Landscape(['worldSize'=>2048]); $grade=180./$radius; $y=-90.*$grade;
	$a=[0.,$y,-180.]; $b=[0.,$y,180.];
	$profile->queue(pavement([0.,$y-500.*$grade,-680.],$a));
	$profile->queue(pavement($a,$b)+['handles'=>[['out'=>[0.,120.*$grade,120.]],['in'=>[0.,120.*$grade,-120.]]]]);
	$profile->queue(pavement($b,[0.,$y-500.*$grade,680.])); $profile->commit();
	check(count(warnings($profile,RoadWarnings::HILL))===($radius===650. ? 1 : 0) && !warnings($profile,RoadWarnings::DIP),
		'Broad convex hills warn from each uphill approach only when the suspension can unload at a playable speed.');
	$profile->close();
}

// Drive the real session car directly through a post. A reference car gets identical ground and no obstacles.
$session = new Session(['worldSize'=>512,'heightScale'=>0.,'detailAmplitude'=>0.]);
$session->landscape->queue(bend(60.,1)); $session->landscape->commit();
[$x,$z] = sites($session->landscape)[12];
$session->camera->position = [$x,5.,$z-15.];
$dt = 1/60;
$session->input(['values'=>['waterLevel'=>-10.],'actions'=>['car']],$dt,1.);
while($session->findingCarSite()) $session->input([],$dt,1.);
check($session->mode==='car','The car boards at the bend');
$reference = new Car;
$site = $reference->checkedSite($session->landscape,-10.,$x,$z-15.,0.,[]);
$reference->spawn($site,$session->landscape,-10.);
$session->car->spawn($site,$session->landscape,-10.);
$session->input(['keys'=>['KeyW'=>true]],$dt,1.);
$closest = INF;
for($i=0; $i<360; $i++) {
	$session->step($dt); $reference->step($dt,1.,0.,$session->landscape,-10.);
	$car = $session->car->state(); $control = $reference->state();
	check([$car['x'],$car['z'],$car['speed']] === [$control['x'],$control['z'],$control['speed']],
		'A chevron changes neither the car trajectory nor speed');
	$closest = min($closest,hypot($car['x']-$x,$car['z']-$z));
	if($car['z'] > $z+5.) break;
}
check($closest<.2 && $car['z']>$z+5. && !$session->course->obstructions(),'The car and chase camera can pass straight through the sign');
$session->close();
echo "Curve warning placement, spacing, direction, edits and drive-through passed.\n";

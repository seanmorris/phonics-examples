<?php
declare(strict_types=1);

require dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
require dirname(__DIR__).'/autoload.php';
use TerrainPhonics\Landscape;
use TerrainSandbox\Car;
use TerrainSandbox\CurveSignModel;
use TerrainSandbox\CurveSigns;
use TerrainSandbox\Session;

function check(bool $ok, string $why): void { if(!$ok) throw new RuntimeException($why); }

// A cubic quarter-circle, mirrored about the incoming lane for the opposite turn.
function bend(float $radius, int $turn, float $width = 12., string $material = 'road'): array
{
	$handle = 4/3*tan(M_PI/8)*$radius;
	return ['type'=>'road','width'=>$width,'shoulder'=>6.,'material'=>$material,
		'points'=>[[0.,0.,0.],[-$turn*$radius,0.,$radius]],
		'handles'=>[['out'=>[0.,0.,$handle]],['in'=>[$turn*$handle,0.,0.]]]];
}
function sites(Landscape $land): array
{
	return array_values(array_filter(iterator_to_array(CurveSigns::sites($land),false)));
}
foreach([-1,1] as $turn) foreach([12.,36.] as $width) {
	$land = new Landscape(['worldSize'=>1024,'heightScale'=>0,'detailAmplitude'=>0]);
	$land->queue(bend(60.,$turn,$width)); $land->commit();
	$row = sites($land);
	check(count($row)>30,'A tight bend has a continuous row of warnings');
	$previous = null;
	foreach($row as [$x,$z,$tx,$tz,$sign]) {
		check(abs(hypot($x+$turn*60.,$z)-(60.+$width/2+2.)) < .10,'The post stands on the outside of the resized pavement');
		check($sign === $turn && abs(hypot($tx,$tz)-1.)<1e-8, 'The panel faces along the lane and the chevron points into the turn');
		if($previous !== null) {
			$gap = hypot($x-$previous[0],$z-$previous[1])/.9144;
			check($gap>=2 && $gap<=4,'Successive posts are two to four yards apart on the outside edge');
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
$land->queue(bend(400.,1)); $land->queue(bend(60.,-1,12.,'grass'));
$land->queue(['type'=>'road','width'=>12.,'shoulder'=>4.,'points'=>[[-300.,0.,0.],[-300.,0.,220.]]]);
$land->commit();
check(sites($land)===[],'Straight roads, broad turns and unpaved edits get no signs');
$land->close();

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

<?php
declare(strict_types=1);

use TerrainPhonics\Landscape;
use TerrainSandbox\BalloonPractice;
use TerrainSandbox\Jet;
use TerrainSandbox\Session;
use TerrainSandbox\VehicleModel;

require_once dirname(__DIR__, 3) . '/packages/terrain-phonics/autoload.php';
require_once dirname(__DIR__) . '/autoload.php';

$checks = 0;
function check(bool $ok, string $message): void
{
	global $checks;
	$checks++;
	if(!$ok) throw new RuntimeException($message);
}

$recipe = ['worldSize'=>4096, 'heightScale'=>0, 'detailAmplitude'=>0];
$land = new Landscape($recipe);
$pose = ['x'=>0, 'y'=>100, 'z'=>0, 'speed'=>140, 'yaw'=>0, 'pitch'=>0, 'roll'=>0];
foreach([[0,0,0],[1,.54,-.78],[-2,1.56,2.4],[3,-.4,M_PI]] as [$yaw,$pitch,$roll])
{
	$p = array_replace($pose, compact('yaw','pitch','roll'));
	$eye = Jet::cockpit($p);
	[$nose,$right,$up] = $eye->basis();
	$model = VehicleModel::basis($p);
	foreach(['x','y','z'] as $i=>$axis)
	{
		check(abs($nose[$i]-$model[6+$i]) < 1e-9 && abs($right[$i]+$model[$i]) < 1e-9
			&& abs($up[$i]-$model[3+$i]) < 1e-9, 'Cockpit follows all three model axes');
		check(abs($eye->position[$i]-($p[$axis]+4.6*$nose[$i]+.85*$up[$i])) < 1e-9, 'Canopy offset follows bank');
	}
}
$range = new BalloonPractice;
$tick = static function(int $steps, bool $launch = false, bool $gun = false) use ($range,$pose,$land): void {
	for($i=0; $i<$steps; $i++) $range->step(1/60,$pose,$launch && $i === 0,$gun,$land,0,true);
};
$tick(1,true);
check($range->status()['ready'] === 1 && reset($range->shots)['target'] === null, 'Can launch with no target');
$tick(30); $tick(1,true); $tick(1,true);
check($range->status()['ready'] === 0 && count($range->shots) === 2, 'Two independently expended charges');
$tick(149);
check($range->status()['ready'] === 1, 'First charge recovers ahead of the second');
$tick(32);
check($range->status()['ready'] === 2, 'Second charge recovers');
$range->reset();
$range->place(0,150,100,$land,0);
$range->place(0,200,100,$land,0);
$range->cycle();
$tick(8);
check(!$range->status()['locked'] && $range->status()['visible'], 'Visible before the dwell completes');
$tick(14);
check($range->status()['locked'], 'Sustained alignment locks');
$range->cycle();
check(!$range->status()['locked'], 'A target change clears the lock');
$tick(20); $tick(1,true);
check(reset($range->shots)['target'] === $range->status()['selected'], 'Launched projectile remembers the locked balloon');
$tick(24);
check($range->hits === 1 && count($range->targets()) === 1, 'Nearer balloon intercepts the shot aimed past it');
$tick(30,false,true);
check($range->hits === 2 && $range->status()['ready'] === 1, 'Held Vulcan pops the second balloon independently');
check(count($range->export()) === 2, 'Popping preserves authored placements');
$range->reset();
check(count($range->targets()) === 2 && !$range->shots, 'Reset restores targets and clears shots');
check($range->remove([0,100,0],[0,0,1],160) && count($range->targets()) === 1, 'Removal picks the nearest balloon');
check(!$range->remove([0,100,0],[0,0,1],10), 'Solid foreground clips an editor pick');
$range->refit($land,40);
check(array_values($range->targets())[0][1] === 140.0, 'Changing water level refits an authored placement');
check($range->start($pose,$land,0), 'Quick practice places a balloon in front');
$tick(24); $tick(1,true); $tick(84);
check($range->hits === 1, 'Quick-practice shot pops the transient balloon');
$tick(60);
check(count($range->targets()) === 2 && count($range->export()) === 1, 'Practice replaces its balloon without adding it to saves');

// A real heightfield edit blocks sight and both kinds of shot along the same segment.
$ridge = new Landscape($recipe);
$ridge->queue(['type'=>'raise','x'=>0,'z'=>190,'radius'=>70,'amount'=>260]);
$ridge->commit();
$hidden = new BalloonPractice;
$hidden->place(0,360,100,$ridge,0);
for($i=0; $i<120; $i++) $hidden->step(1/60,$pose,$i === 30,$i < 40,$ridge,0,true);
check(!$hidden->status()['locked'] && !$hidden->status()['visible'], 'A ridge conceals the targeting box and interrupts dwell');
check($hidden->hits === 0 && !$hidden->shots, 'Both kinds of shot stop at the ridge');
$ridge->close();
$range->refit($land,200);
check(min(array_column($range->targets(),1)) >= 216, 'Raising the water keeps practice and authored balloons above the surface');

$session = new Session($recipe);
$session->camera->position = [0,200,0]; $session->camera->pitch = -1.5;
$input = static fn(array $controls) => $session->input($controls,1/60,16/9);
$input(['values'=>['tool'=>'balloon','balloonAltitude'=>65], 'clicks'=>[[.5,.5]]]);
for($i=0; $i<200 && $session->picking; $i++) $input([]);
check($session->balloons->status()['placements'] === 1, 'Placement works through the ground picker');
$saved = json_decode($session->save(),true,flags:JSON_THROW_ON_ERROR);
$session->load(json_encode($saved,JSON_THROW_ON_ERROR));
check($session->balloons->export() == $saved['balloons'], 'Placements round-trip through landscape files');
$bad = array_replace($saved,['balloons'=>[[0,0,0]]]);
try { $session->load(json_encode($bad,JSON_THROW_ON_ERROR)); throw new RuntimeException('Invalid save accepted'); }
catch(InvalidArgumentException) { check($session->balloons->export() == $saved['balloons'], 'Invalid load leaves the active range intact'); }
$clamped = $saved;
$clamped['settings']['waterLevel'] = 10001;
$session->load(json_encode($clamped,JSON_THROW_ON_ERROR));
check(array_values($session->balloons->targets())[0][1] === 10065.0, 'Loaded balloons follow the applied water level');
unset($saved['balloons']);
$session->load(json_encode($saved,JSON_THROW_ON_ERROR));
check(!$session->balloons->targets(), 'Older saves open with an empty balloon list');
$input(['actions'=>['jet','jet-view','balloon-practice','jet-launch']]);
$session->step(1/60);
check($session->jetView === 'cockpit' && $session->balloons->status()['ready'] === 1, 'Jet controls work through the session');
$input(['actions'=>['pause','jet-launch']]);
$frozen = $session->balloons->status();
$session->step(1/60);
check($session->balloons->status() === $frozen, 'Pause freezes shots, dwell, respawn and recharge');
$input(['actions'=>['fly']]);
check(!$session->balloons->shots && !$session->balloons->status()['practice'], 'Leaving the F5 clears transient flight state');
$session->close(); $land->close();
echo "$checks balloon practice checks passed.\n";

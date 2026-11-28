<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/packages/terrain-phonics/autoload.php';
require_once dirname(__DIR__).'/autoload.php';

use TerrainSandbox\DemoTape;
use TerrainSandbox\Session;

function check(bool $ok,string $reason): void { if(!$ok) throw new RuntimeException($reason); }
$game = new Session(['worldSize'=>256,'heightScale'=>0.,'detailAmplitude'=>0.]);
$game->camera->position = [0.,20.,40.];
$game->camera->yaw = deg2rad(179.);
$action = static fn($a) => $game->input(['actions'=>[$a]],0.,1.);
$action('demo-record');
$tape = $game->demo;
$game->step(1/60);
$tape->capture($game);
check($tape->count() === 1,'Recording samples at most 30 times per second');
$game->step(1/60);
$game->camera->position[0] = 4.;
$game->camera->yaw = deg2rad(-179.);
$tape->capture($game);
$half = $tape->frame(1/60)['camera'];
check(abs($half->position[0]-2.)<.001 && abs(abs($half->yaw)-M_PI)<.001,'Camera and heading interpolate across the angle wrap');
$game->paused=true;
$game->step(1.);
$tape->capture($game);
check($tape->count()===2,'Menu time adds no samples');
$game->paused=false;
$action('jet');
for($i=0;$i<6;$i++) { $game->step(1/60); $tape->capture($game); }
// An authored gate during the recording starts a new world and a new guidance cue.
$gate = $game->course->place('start','air',0.,65.,30.,0.)['gate'];
for($i=0;$i<3;$i++) { $game->step(1/60); $tape->capture($game); }
$action('demo-stop');
$loaded = DemoTape::import($tape->export());
$end = $loaded->frame($loaded->duration());
check($end['mode']==='jet' && abs($end['vehicle']['x']-$game->jet->state()['x'])<.001,'An exported run restores the final vehicle pose');
$first = $loaded->frame(0.);
check($end['world']>$first['world'] && $first['events']['target']===null,'Landscape edits are time-indexed and survive export');
check($end['events']['target']['kind']==='checkpoint' && abs($end['events']['target']['z']-$gate['z'])<.001,
	'A recorded flight recovers guidance for the newly placed gate');
check($loaded->frame(0.)['events']['race']['next']===null,'Rewinding restores the original race cues');
$action('demo-play');
$before = $game->save();
$game->input(['keys'=>['KeyW'=>1],'look'=>[50,50],'actions'=>['fly','reset-camera']],.1,1.);
$game->step(1/60);
check($game->paused && $game->save()===$before && $game->mode==='jet','Playback parks the live session and ignores movement');
$action('demo-stop');
check($game->paused && $game->demo->playhead===null,'Stopping leaves the original session paused');
$file=json_decode($tape->export(),true);
$bad=$file;
$bad['cues'][]=[$tape->count()-1,json_encode(['target'=>['kind'=>'pickup','x'=>0,'y'=>0,'z'=>0,'color'=>'#ffff00','waypoint'=>42]])];
$action(['type'=>'demo-open','json'=>json_encode($bad)]);
check($game->demo===$tape && str_contains($game->message,'Invalid demo event'),'Invalid navigation cues preserve the existing take');
$file['samples']=base64_encode(substr(base64_decode($file['samples']),0,-1));
$action(['type'=>'demo-open','json'=>json_encode($file)]);
check($game->demo===$tape && str_contains($game->message,'Incomplete'),'A damaged import preserves the last playable demo');
// Editing an imported take retains the prefix and parks on either endpoint when scrubbing.
$parent=$game->demo->export();
$action(['type'=>'demo-open','json'=>$parent]);
$action(['type'=>'demo-seek','seconds'=>1000.]);
check($game->demo->held && $game->demo->playhead===$game->demo->duration(),'Seeking past the end holds the last frame');
$action('demo-step-back');
$cut=$game->demo->playhead;
check($cut<$game->demo->duration(),'Single-frame rewind moves backward');
$action('demo-step-forward'); $action('demo-step-back');
check($cut===$game->demo->playhead,'Frame stepping is reversible');
$selected=$game->demo->frame($cut);
$action('demo-take-control');
check(!$game->paused && $game->demo->recording && $game->mode==='jet' && abs($game->jet->state()['z']-$selected['vehicle']['z'])<.01,'Taking control restores a mid-flight sample');
$game->input(['keys'=>['KeyA'=>1]],.01,1.);
for($i=0;$i<8;$i++){ $game->step(1/60); $game->view(.25); $game->demo->capture($game); }
$action('demo-stop');
$newTake=$game->demo->export();
$legacy=json_decode($parent,true,64,JSON_THROW_ON_ERROR);
$legacy['version']=1;
foreach($legacy['cues'] as &$change) {
	$delta=json_decode($change[1],true,32,JSON_THROW_ON_ERROR);
	unset($delta['motion'],$delta['raceDistance'],$delta['rangeTimers']);
	$change[1]=json_encode($delta,JSON_THROW_ON_ERROR);
}
unset($change);
$old=DemoTape::import(json_encode($legacy,JSON_THROW_ON_ERROR));
$old->seek($cut);
check($old->branch()->frame($cut)['mode']==='jet','Earlier presentation-only files still seek and fork');
check($newTake!==$parent && $game->demo->duration()>$cut,'Live controls append a divergent ending');
$action('demo-swap');
check($game->demo->export()===$parent,'The original take is still available byte for byte');
$action('demo-stop'); $action('demo-swap');
check($game->demo->export()===$newTake,'Both takes can be selected again');
$action('demo-stop');
$action(['type'=>'demo-seek','seconds'=>-5]);
check($game->demo->playhead===0.,'Negative times clamp to the beginning');
$action('demo-take-control');
check($game->mode==='fly' && !$game->paused && $game->demo->recording,'Free flight can branch at frame zero');
$game->close();
// Check a fork after a real physics step in both surface vehicles, including sideways hull momentum.
foreach(['car'=>-10.,'craft'=>10.] as $mode=>$water) {
	$s=new Session(['worldSize'=>1024,'heightScale'=>0.,'detailAmplitude'=>0.,'waterLevel'=>$water]);
	$s->camera->position=[0.,20.,0.];
	$s->input(['actions'=>[$mode]],.01,1.);
	for($i=0;$i<100 && $s->mode!==$mode;$i++) $s->input([],.01,1.);
	check($s->mode===$mode,'Board the '.$mode);
	if($mode==='car') {
		$s->course->place('start','road',0.,0.,-50.,0.);
		$s->course->place('checkpoint','road',0.,0.,50.,0.);
		$s->race->advance($s->course,[0.,0.,-51.],[0.,0.,-49.],0.,1/60,'car',1);
	}
	$s->input(['actions'=>['beach-ball-spawn','demo-record'],'keys'=>['KeyW'=>1,'KeyD'=>1]],.01,1.);
	for($i=0;$i<90;$i++) { $s->step(1/60); $s->demo->capture($s); }
	$s->input(['actions'=>['demo-stop']],0.,1.);
	$file=$s->demo->export();
	$s->input(['actions'=>[['type'=>'demo-open','json'=>$file]]],0.,1.);
	$s->input(['actions'=>[['type'=>'demo-seek','seconds'=>.7]]],0.,1.);
	$selected=$s->demo->branch(); $snap=$selected->frame($selected->duration());
	$s->input(['actions'=>['demo-take-control']],0.,1.);
	$p=$s->$mode->state();
	check(count($snap['events']['beachBalls'])===1 && $s->balloons->beachBalls->states()===$snap['events']['beachBalls'],
		'A divergent recording restores the ball pose and velocity at the selected time.');
	check($s->demo->recording && $s->mode===$mode && abs($p['x']-$snap['vehicle']['x'])<.001
		&& abs($p['speed']-$snap['vehicle']['speed'])<.001 && $p['speed']>.01,'Restore moving '.$mode.' at the selected sample');
	if($mode==='car') {
		$race=$s->race->status($s->course,$s->time);
		check($race['state']==='running' && $race['remaining']===$snap['events']['race']['remaining']
			&& abs($race['time']-$snap['events']['race']['time'])<.001,'A branch keeps the race clock and next gate');
	}
	if($mode==='car') {
		$s->input(['actions'=>['demo-stop']],0.,1.);
		$immediate=DemoTape::import($s->demo->export());
		check($immediate->count()===$selected->count(),'Stopping on the cut keeps a single portable sample at that time');
	}
	if($mode==='craft') check(abs($p['velocity'][1]-$snap['vehicle']['vy'])<.00001,'Restore vertical watercraft velocity');
	$s->step(1/60); $s->view(.5); $s->demo->capture($s); $s->close();
}
$s=new Session(['worldSize'=>256]);
$send=static fn($a)=>$s->input(['actions'=>[$a]],0.,1.);
$send('demo-record');
$send(['type'=>'weather','preset'=>'snow']);
$s->input(['values'=>['starBrightness'=>175,'starTwinkle'=>40,'shootingStars'=>12]],0.,1.);
for($i=0;$i<12;$i++) { $s->step(1/60); $s->demo->capture($s); }
$snowSample=$s->demo->duration();
$send(['type'=>'weather','preset'=>'rain']);
$s->input(['values'=>['starTwinkle'=>0,'shootingStars'=>0]],0.,1.);
for($i=0;$i<12;$i++) { $s->step(1/60); $s->demo->capture($s); }
$send('demo-stop');
$file=json_decode($s->demo->export(),true);
$count=0;
foreach($file['cues'] as [, $cue]) if(isset(json_decode($cue,true)['weather'])) $count++;
check($count===3,'Only the initial weather and two edits need demo events');
$loaded=DemoTape::import($s->demo->export());
check($loaded->frame($loaded->duration())['world']===0,'Changing weather keeps the terrain chapter');
check($loaded->frame($loaded->duration())['events']['weather']['controls']['kind']==='rain','A later seek restores rain');
$send('demo-play');
$send(['type'=>'demo-seek','seconds'=>$snowSample]);
$send('demo-take-control');
check($s->atmosphere->stars->brightness===175. && $s->atmosphere->stars->twinkle===40.
	&& $s->atmosphere->stars->perMinute===12., 'Rewinding and branching restores all three star controls');
$copy=new Session;
$doc=json_decode($s->save(),true);
$copy->load(json_encode($doc));
check($copy->atmosphere->stars==$s->atmosphere->stars,'A landscape save restores star controls');
foreach(\TerrainPhonics\Starfield::DEFAULTS as $key=>$_) unset($doc['settings'][$key]);
$copy->load(json_encode($doc));
check($copy->atmosphere->stars==new \TerrainPhonics\Starfield,'Opening a legacy file resets the sky to the original static field');
$copy->close();
check($s->weather->controls()['kind']==='snow' && abs($s->weather->ground($s->time)[1]-$snowSample/60.)<.00001,'Branching restores the snow already on the ground at the seek time');
$s->close();

$s=new Session(['worldSize'=>512,'heightScale'=>0.,'detailAmplitude'=>0.,'waterLevel'=>-5.]);
$s->landscape->queue(['type'=>'road','points'=>[[0,0,-120],[0,0,120]],'width'=>12.,'shoulder'=>4.]);
$s->landscape->commit();
$s->camera->position=[0.,10.,0.]; $s->camera->yaw=M_PI;
$s->input(['actions'=>['car']],0.,1.);
for($i=0;$i<1000 && $s->findingCarSite();$i++) $s->input([],0.,1.);
$s->demo->start($s);
$s->input(['keys'=>['KeyW'=>true]],0.,1.);
for($i=0;$i<100;$i++) { $s->step(1/60); $s->demo->capture($s); }
$s->input(['keys'=>['Space'=>true]],0.,1.);
for($i=0;$i<35;$i++) { $s->step(1/60); $s->demo->capture($s); }
check((bool)$s->skidMarks->tiles,'A handbrake stop writes asphalt marks in the live session.');
$loaded=DemoTape::import($s->demo->export());
$marks=new TerrainSandbox\SkidMarks;
$end=$loaded->replaySkids($marks,$loaded->duration());
check((bool)$marks->tiles,'Seeking to the stop restores the recorded marks.');
$loaded->replaySkids($marks,0.,$end);
check(!$marks->tiles,'Rewinding to before the launch removes future tire marks.');
$s->close();

echo "Terrain demo capture, scrubbing, branching, vehicle momentum and file isolation passed.\n";

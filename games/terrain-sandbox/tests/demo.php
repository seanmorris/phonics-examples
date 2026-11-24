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
$game->close();
echo "Terrain demo capture, interpolation, file validation and isolation passed.\n";

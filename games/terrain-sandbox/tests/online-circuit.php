<?php
declare(strict_types=1);

require_once (getenv('PHONICS_TERRAIN_ROOT') ?: dirname(__DIR__,3).'/packages/terrain-phonics').'/autoload.php';
require_once dirname(__DIR__).'/autoload.php';

use TerrainSandbox\CircuitTrial;
use TerrainSandbox\Session;

function check(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function rejected(Closure $operation): void {
	try { $operation(); } catch(InvalidArgumentException|RuntimeException $e) { return; }
	throw new LogicException('Invalid competitive race was accepted.');
}
$local = new Session(['worldSize'=>512,'heightScale'=>0,'detailAmplitude'=>0,'waterLevel'=>-5]);
$local->course->place('start','road',0,0,-50,0);
$local->course->place('checkpoint','road',0,0,0,0);
$local->course->place('finish','road',0,0,60,0);
$spec = $local->raceSpec();
foreach([0,-1,1.25,'3',21] as $bad) {
	$copy = $spec; $copy['laps']=$bad; rejected(fn()=>CircuitTrial::document($copy));
	$copy = $spec; $copy['world']['course']['gates'][0]['laps']=$bad; rejected(fn()=>CircuitTrial::document($copy));
}
$wrong = $spec; $wrong['simulator'] = str_repeat('0',64); rejected(fn()=>CircuitTrial::document($wrong));
$local->course->remove($local->course->finish()['id']);
$local->course->setLaps(0);
rejected(fn()=>$local->raceSpec());
check($local->raceSpec(2)['laps']===2 && $local->course->laps()===0,'A finite copy preserves offline endless mode.');
$local->driveTrial($spec);
$local->trial->running=true;
for($i=0;$i<120;$i++) $local->step(1/60);
$local->input(['keys'=>['KeyW'=>true]],1/60,16/9);
for($i=0;$i<1800 && $local->race->state!=='finished';$i++) $local->step(1/60);
$run=$local->trial->result();
check(count($run['splits'])===2 && $run['ticks']>20,'Normal session entry scored all gates.');
$remote=new Session;
$verify=static function(array $tape) use ($remote,$spec) {
	$trial=$remote->prepareTrial($spec);
	$job=$trial->verify($tape);
	foreach($job as $_) {}
	return $job->getReturn();
};
$result=$verify($run);
check($run['ticks']===$result['ticks'] && $run['splits']===$result['splits'],'Two drivers reproduce the same race.');
check($result['poses'][0][0]===0 && end($result['poses'])[0]===$run['ticks'],'Ghost spans the grid and finish.');
$bad=$run;$bad['ticks']--; $bad['inputs']=substr($bad['inputs'],0,-4);rejected(fn()=>$verify($bad));
$bad=$run;$bad['ticks']++; $bad['inputs'].='AAAA';rejected(fn()=>$verify($bad));
$bad=$run;$bad['inputs']=str_repeat('A',$bad['ticks']*4);rejected(fn()=>$verify($bad));
if(getenv('TERRAIN_RACE_FIXTURE')) file_put_contents(getenv('TERRAIN_RACE_FIXTURE'),json_encode(compact('spec','run'),JSON_THROW_ON_ERROR));
// The live trial and ghost verifier must roll the same way from the recorded three-byte input.
$local->driveTrial($spec); $local->trial->running=true;
$replay=$remote->prepareTrial($spec);
$pose=array_replace($replay->car->state(),['y'=>40.,'roll'=>.3]);
$motion=[...$replay->car->motion(),8.,2.,.25,3.,0.,0.];
$local->car->resume($pose,$motion,$local->landscape,$local->water->level());
$replay->car->resume($pose,$motion,$remote->landscape,$remote->water->level());
$local->input(['keys'=>['KeyA'=>true],'pad'=>[.5,0.,0.,0.,0.,0.,0]],1/60,16/9);
for($tick=0;$tick<60;$tick++) $local->step(1/60);
$inputs=base64_decode($local->trial->inputsSince(0),true);
check($inputs===str_repeat(CircuitTrial::controls(0.,.5,false,false,false),60),'A trial records analog roll while tumbling.');
foreach(str_split($inputs,3) as $input) $replay->step($input);
check($local->car->state()===$replay->car->state(),'A ghost reproduces the same tumble from the recorded stick.');
$local->close();$remote->close();
echo "Circuit race creation, finite laps, fixed-step finish, ghost poses and invalid tapes passed.\n";

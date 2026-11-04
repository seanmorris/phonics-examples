<?php
declare(strict_types=1);
use PhpBilliards\Physics\{Ball,ContactKernel,PocketModel,Simulation};
require __DIR__ . '/../autoload.php';
(vrzno_env('contactGeometry'))(json_encode(PocketModel::geometry(),JSON_THROW_ON_ERROR));
$backend=vrzno_env('contactBatch');
ContactKernel::install($reference ? null : static fn(string $input):string=>(string)$backend($input));
$cases=[['default'=>true,'angle'=>0],['seed'=>0,'angle'=>.1]];
if($quick) foreach([[2,0],[4,-.5],[7,.5]]as[$seed,$angle])$cases[]=['seed'=>$seed,'angle'=>$angle];
else for($seed=0;$seed<8;$seed++)foreach([-.5,0,.5]as$angle)$cases[]=['seed'=>$seed,'angle'=>$angle];
$cases[]=['crowded'=>true];
$results=[];
foreach($cases as $case)
{
	$s=new Simulation(isset($case['seed'])?hash('sha256','billiards-break-diagnosis-'.$case['seed']):null);
	if(isset($case['crowded']))
	{
		foreach($s->balls as $b)$b->active=false;
		// Six-ball mouth congestion captured during the original diagnosis, including its existing compression.
		foreach([[6,-.055856477510466,.604425,3.0952740570054,3.7948976588758],
			[7,-.0011575308680337,.604425,-.38633643755657,7.0377743110714],
			[9,.058290443913564,.604425,-4.9040369983854,5.7525813341049],
			[2,-.05610232213738,.547175,1.9904427082911,6.9387064615708],
			[10,.0015375528836332,.547175,.8617267653076,6.2348432039698],
			[8,.058407036088015,.547175,-.30240035166822,.57292006250256]] as [$n,$x,$y,$vx,$vy])
		foreach($s->balls as $b)if($b->number===$n){$b->active=true;$b->x=$x;$b->y=$y;$b->vx=$vx;$b->vy=$vy;}
		$s->phase='moving';
	}
	else $s->shoot(deg2rad($case['angle']),1);
	$times=$hashes=[];
	for($i=0;$i<1800&&$s->moving();$i++)
	{
		$time=hrtime(true);$s->step();$times[]=(hrtime(true)-$time)/1e6;
		$events=$s->consumeEvents();
		if($exact)$hashes[]=$s->exactHash().':'.hash('sha256',serialize($events));
	}
	if($s->moving())throw new RuntimeException('Benchmark shot did not settle');
	$maxTick=array_search(max($times),$times);sort($times);
	$results[]=$case+['steps'=>count($times),'max'=>max($times),'maxTick'=>$maxTick,
		'p95'=>$times[(int)(count($times)*.95)],'total'=>array_sum($times)]+($exact?['hashes'=>$hashes]:[]);
}
$s=new Simulation;$times=[];
foreach([.15,.58,1.0]as$power)for($i=0;$i<12;$i++)
{
	$time=hrtime(true);$preview=$s->beginProjection(($i-6)*.01,$power);$preview->advanceProjection(8);$times[]=(hrtime(true)-$time)/1e6;
}
sort($times);
echo json_encode(['physics'=>$results,'preview'=>['p95'=>$times[(int)(count($times)*.95)],'max'=>max($times)]],JSON_THROW_ON_ERROR);

<?php
declare(strict_types=1);
require __DIR__ . '/../../games/php-billiards/autoload.php';
use PhpBilliards\Physics\{Ball,ContactModel,PocketModel,Simulation,Table};

// The oracle invokes the production PHP microstep and boundary scheduler, with no accelerator installed.
$rows = static function(array $balls): array {
	$data = [];
	foreach($balls as $b) array_push($data,$b->x,$b->drop,$b->y,$b->vx,$b->vDrop,$b->vy,
		$b->spinX,$b->spinY,$b->spinZ,$b->qx,$b->qy,$b->qz,$b->qw,(float)($b->pocket ?? -1),$b->active ? 1.0 : 0.0);
	return $data;
};
$advance = new ReflectionMethod(Simulation::class, 'advanceContacts');
$earliest = new ReflectionMethod(Simulation::class, 'earliest');
$resolve = new ReflectionMethod(Simulation::class, 'resolveBoundary');
$contacts = new ReflectionProperty(Simulation::class, 'impactContacts');
[$k,$c] = ContactModel::coefficients();
$parameters = [ContactModel::MAX_STEP,$k,$c,Ball::MASS,Ball::RADIUS,ContactModel::GRAVITY];
$fixtures = [];
$fixture = static function(string $name, array $balls, int $steps) use (&$fixtures,$rows,$parameters,$advance,$earliest,$resolve,$contacts): void {
	$simulation = new Simulation; $simulation->balls = $balls; $simulation->consumeEvents();
	$remaining = ContactModel::MAX_STEP * $steps;
	$input = [...$parameters,$remaining,$steps,...array_fill(0,16,0),...$rows($balls)];
	$events = [];
	for($step = 0; $step < $steps; $step++)
	{
		$dt = min($remaining, ContactModel::MAX_STEP); $boundary = null;
		if($steps > 1) $boundary = $earliest->invoke($simulation,$dt,false);
		if($boundary && $boundary['type'] !== 'sink') throw new RuntimeException("Fixture $name crosses an unbatched cloth boundary");
		if($boundary && $boundary['time'] <= 1e-12) {$resolve->invoke($simulation,$boundary);$boundary=null;}
		elseif($boundary) $dt=$boundary['time'];
		$advance->invoke($simulation,$dt); $remaining -= $dt;
		if($boundary) $resolve->invoke($simulation,$boundary);
		$new = $simulation->consumeEvents(); array_push($events,...$new);
		if($remaining <= 1e-12 || ContactModel::elasticEnergy($balls) <= 0
			|| in_array('pocket-reject',array_column($new,'type'),true)) break;
	}
	$masks=array_fill(0,16,0);$indices=[];
	foreach($balls as $i=>$b)$indices[$b->number]=$i;
	foreach($contacts->getValue($simulation) as [$a,$b])$masks[$indices[$a->number]]|=1<<$indices[$b->number];
	$encodedEvents=[];
	foreach($events as $event)
	{
		$type=match($event['type']){'impact'=>1,'pocket-rattle'=>2,'pocket-reject'=>3,'pocket'=>4};
		array_push($encodedEvents,$type,$indices[$event['ball']??0]??0,$event['pocket']??0,$event['strength']??0,$event['depth']??0);
	}
	$output=[$remaining,...$masks,count($events),...$encodedEvents,...$rows($balls)];
	$fixtures[]=['name'=>$name,'input'=>base64_encode(pack('e*',...$input)),'expected'=>base64_encode(pack('e*',...$output))];
};
foreach([.01,.5,4.0,8.75,17.5]as$speed)
foreach([1,200]as$steps)
foreach([false,true]as$spin)
{
	$a=new Ball(0,0,0);$b=new Ball(1,2*Ball::RADIUS-1e-5,.0002);
	$a->vx=$speed;$a->spinX=$spin?17.3:0;$a->spinY=$spin?-9.2:0;$b->spinZ=$spin?83.1:0;
	$fixture("cloth-$speed-$steps-".(int)$spin,[$a,$b],$steps);
}
foreach(Table::pockets()as$p=>[$x,$y])
foreach([1,100]as$steps)
foreach(['rim','liner','sink','reject','contact']as$case)
{
	[$ox,$oy]=Table::pocketOutward($p);
	$a=new Ball(0,$x-$ox*.025,$y-$oy*.025);$a->pocket=$p;$a->drop=.01;$a->vDrop=.8;
	$a->vx=-$ox*.23;$a->vy=-$oy*.23;$a->spinY=5.3;
	if($case==='liner'){$a->drop=.08;$a->vx=$ox*2;$a->vy=$oy*2;}
	if($case==='sink'){$a->x=$x;$a->y=$y;$a->drop=.115-2e-6;$a->vDrop=1;}
	if($case==='reject'){$a->x=$x-$ox*.06;$a->y=$y-$oy*.06;$a->drop=-1e-6;$a->vDrop=-.5;}
	$balls=[$a];
	if($case==='contact'){
		$a->x=$x;$a->y=$y;$a->drop=.08;$a->vDrop=0;
		$b=new Ball(1,$x,$y);$b->pocket=$p;$b->drop=.023;$b->vDrop=1;$b->spinX=3.7;$balls[]=$b;
	}
	$fixture("pocket-$p-$steps-$case",$balls,$steps);
}
echo json_encode(['geometry'=>PocketModel::geometry(),'fixtures'=>$fixtures],JSON_THROW_ON_ERROR);

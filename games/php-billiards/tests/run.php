<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

$phonicsRoot = dirname(__DIR__, 3);
$phonicsAutoloaders = [
	$phonicsRoot . '/node_modules/@seanmorris/phonics/autoload.php',
	$phonicsRoot . '/vendor/seanmorris/phonics/autoload.php',
];

foreach($phonicsAutoloaders as $phonicsAutoloader)
{
	if(!is_file($phonicsAutoloader)) continue;
	require_once $phonicsAutoloader;
	break;
}

if(!class_exists(Phonics\Ecs\Registry::class))
{
	throw new RuntimeException('Unable to locate the Phonics framework autoloader.');
}

use Phonics\Ecs\Registry;
use PhpBilliards\Ecs\BallIdentity;
use PhpBilliards\Ecs\BilliardsEntities;
use PhpBilliards\Ecs\BilliardsSession;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Simulation;
use PhpBilliards\Physics\Table;
use PhpBilliards\Geometry\MeshBuilder;
use PhpBilliards\Geometry\TableGeometry;
use PhpBilliards\Game;
use PhpBilliards\Render\Camera;
use PhpBilliards\Render\FramePacer;
use PhpBilliards\Render\Hud;
use PhpBilliards\Online\BinaryState;
use PhpBilliards\Online\EightBallMatch;

$tests = [];
$test = static function(string $name, callable $callback) use (&$tests): void { $tests[$name] = $callback; };
$expect = static function(bool $condition, string $message = 'Expectation failed'): void {
	if(!$condition) throw new RuntimeException($message);
};
$energy = static function(Simulation $game): float {
	$total = 0;

	foreach($game->balls as $ball) if($ball->active)
	{
		$total += .5 * ($ball->vx ** 2 + $ball->vy ** 2);
		$total += .2 * Ball::RADIUS ** 2 * ($ball->spinX ** 2 + $ball->spinY ** 2 + $ball->spinZ ** 2);
	}

	return $total;
};
$isolateCue = static function(Simulation $game): Ball {
	foreach($game->balls as $ball) $ball->active = $ball->number === 0;
	$game->consumeEvents();

	return $game->cue();
};

$test('standard rack is complete and separated', static function() use ($expect): void {
	$game = new Simulation;
	$expect(count($game->balls) === 16, 'Expected cue ball and fifteen object balls');
	$expect(count(array_filter($game->balls, static fn(Ball $ball): bool => $ball->active)) === 16);

	foreach($game->balls as $index => $a)
	foreach(array_slice($game->balls, $index + 1) as $b)
	$expect(hypot($a->x - $b->x, $a->y - $b->y) >= Ball::RADIUS * 2 + .000004, 'Rack balls overlap');
});

$test('ECS keeps stable ball entities around the unchanged simulation', static function() use ($expect): void {
	$registry = new Registry;
	$game = new Game;
	$ecs = new BilliardsEntities($registry, $game);
	$before = $game->simulation->exactHash();
	$entities = $registry->query(BallIdentity::class);

	$expect(count($registry->query(BilliardsSession::class)) === 1, 'The game session was not registered');
	$expect(count($entities) === 16 && count($registry->query(Ball::class)) === 16, 'The complete rack was not registered');

	$ids = [];
	foreach($game->simulation->balls as $ball)
	{
		$entity = $ids[$ball->number] = $ecs->ballEntity($ball->number);
		$expect($registry->get($entity, Ball::class) === $ball, 'The ECS copied a simulation ball');
		$expect($registry->get($entity, BallIdentity::class)->number === $ball->number, 'The ball identity changed');
	}
	$expect($game->simulation->exactHash() === $before, 'Initial ECS registration changed deterministic state');

	$oldCue = $game->simulation->cue();
	$game->simulation->rack();
	$expect($ecs->reconcile($registry, $game->simulation), 'Rack replacement was not reconciled');
	$expect($game->simulation->cue() !== $oldCue, 'The rack did not replace its physics objects');
	foreach($game->simulation->balls as $ball)
	{
		$expect($ecs->ballEntity($ball->number) === $ids[$ball->number], 'Rack replacement changed an entity ID');
		$expect($registry->get($ids[$ball->number], Ball::class) === $ball, 'Rack replacement left a stale Ball component');
	}
	$expect(!$ecs->reconcile($registry, $game->simulation), 'An unchanged ball list invalidated the ECS');

	$snapshot = $game->simulation->snapshot();
	$game->simulation->restore($snapshot);
	$expect($ecs->reconcile($registry, $game->simulation), 'Checkpoint replacement was not reconciled');
	foreach($game->simulation->balls as $ball)
	$expect($registry->get($ids[$ball->number], Ball::class) === $ball, 'Checkpoint restore left a stale Ball component');

	$ecs->close($registry);
	$expect($registry->query(BilliardsSession::class) === [] && $registry->query(Ball::class) === [],
		'Closing the adapter left billiards entities alive');
});

$test('rack variation is imperceptible and deterministic', static function() use ($expect): void {
	$left = new Simulation;
	$right = new Simulation;
	$spacing = Ball::RADIUS * 2 + .00004;
	$apex = Table::WIDTH * .23;
	$index = 1;
	$varied = false;

	for($row = 0; $row < 5; $row++)
	for($column = 0; $column <= $row; $column++)
	{
		$idealX = $apex + $row * $spacing * .866025403784;
		$idealY = ($column - $row / 2) * $spacing;
		$dx = abs($left->balls[$index]->x - $idealX);
		$dy = abs($left->balls[$index]->y - $idealY);
		$expect($dx <= .000011 && $dy <= .000011, 'Rack noise became visible');
		$varied = $varied || $dx > 1e-9 || $dy > 1e-9;
		$index++;
	}

	$expect($varied, 'Rack remained mathematically perfect');
	$expect($left->hash() === $right->hash(), 'Rack noise changed between identical games');
});

$test('racked ball textures have deterministic independent orientations', static function() use ($expect): void {
	$left = new Simulation;
	$right = new Simulation;
	$orientations = [];

	foreach($left->balls as $index => $ball)
	{
		$length = sqrt($ball->qx ** 2 + $ball->qy ** 2 + $ball->qz ** 2 + $ball->qw ** 2);
		$expect(abs($length - 1) < 1e-9, 'Initial ball orientation is not normalized');
		$expect($ball->snapshot()['rotation'] === $right->balls[$index]->snapshot()['rotation'],
			'Initial ball orientation changed between games');
		$orientations[] = implode(',', array_map(static fn(float $value): string => sprintf('%.6f', $value),
			[$ball->qx,$ball->qy,$ball->qz,$ball->qw]));
	}

	$expect(count(array_unique($orientations)) === 16, 'Racked ball textures share one orientation');
});

$test('shot power preserves touch shots and reaches break speed', static function() use ($expect): void {
	$expect(abs(Simulation::shotSpeed(1) - 8.75) < 1e-12, 'Maximum power is not calibrated for a break');
	$expect(Simulation::shotSpeed(.05) >= .5 && Simulation::shotSpeed(.05) < .51, 'Touch shot is too fast');
	$expect(Simulation::shotSpeed(.58) > 2.5 && Simulation::shotSpeed(.58) < 2.6,
		'Mid-power shot changed normal play speed');
	$expect(Simulation::shotSpeed(.8) > Simulation::shotSpeed(.58), 'Power curve is not monotonic');
});

$test('aim projection uses cushion damping and leaves its source untouched', static function() use ($expect,$isolateCue): void {
	$game = new Simulation;
	$cue = $isolateCue($game);
	$limit = Table::WIDTH / 2 - Ball::RADIUS;
	$cue->x = $cue->previousX = $limit - .12;
	$cue->y = $cue->previousY = .12;
	$before = $game->hash();
	$angle = .35;
	$path = $game->projectPath($angle, .8);
	$contact = null;

	foreach($path as $index => $point)
	if(abs($point[0] - $limit) < 1e-7) {$contact = $index; break;}

	$expect($game->hash() === $before, 'Computing the guide mutated the live game');
	$expect($contact !== null && $contact > 0 && $contact < count($path) - 1,
		'Projection did not preserve the exact cushion contact');
	$incoming = abs(($path[$contact][1] - $path[$contact - 1][1])
		/ ($path[$contact][0] - $path[$contact - 1][0]));
	$outgoing = abs(($path[$contact + 1][1] - $path[$contact][1])
		/ ($path[$contact + 1][0] - $path[$contact][0]));
	$expect(abs($outgoing - $incoming * .96 / .78) < .002,
		'Projection still uses equal-angle reflection instead of cushion damping');
});

$test('aim projection enters all six pocket openings instead of reflecting from missing rails', static function() use ($expect,$isolateCue): void {
	foreach(array_keys(Table::pockets()) as $index)
	{
		$game = new Simulation;
		$cue = $isolateCue($game);
		[$mouthX,$mouthY] = Table::pocketMouthCenter($index);
		[$outwardX,$outwardY] = Table::pocketOutward($index);
		$cue->x = $cue->previousX = $mouthX - $outwardX * .35;
		$cue->y = $cue->previousY = $mouthY - $outwardY * .35;
		$path = $game->projectPath(atan2($outwardY, $outwardX), 1);
		$expect(count($path) === 2, "Pocket $index projection reflected before its opening");
		[$lastX,$lastY] = $path[array_key_last($path)];
		[$pocketX,$pocketY] = Table::pockets()[$index];
		// A ball drops as its centre crosses the cloth edge, so the guide ends on the slate cut itself.
		$expect(abs(hypot($lastX - $pocketX, $lastY - $pocketY) - Table::THROAT_RADIUS) < 1e-8,
			"Pocket $index guide did not end where the ball drops");
	}
});

$test('aim projection stops at the first object-ball contact', static function() use ($expect,$isolateCue): void {
	$game = new Simulation;
	$cue = $isolateCue($game);
	$target = $game->balls[1];
	$cue->x = $cue->previousX = 0;
	$cue->y = $cue->previousY = 0;
	$target->active = true;
	$target->x = $target->previousX = .25;
	$target->y = $target->previousY = 0;
	$path = $game->projectPath(0, 1);
	[$lastX,$lastY] = $path[array_key_last($path)];

	$expect(abs($lastX - (.25 - Ball::RADIUS * 2)) < 1e-8 && abs($lastY) < 1e-10,
		'Guide passed through the first object ball');
});

$test('aim projection follows rounded side-pocket jaws', static function() use ($expect,$isolateCue): void {
	$game = new Simulation;
	$cue = $isolateCue($game);
	$cue->x = $cue->previousX = 0;
	$cue->y = $cue->previousY = 0;
	$path = $game->projectPath(deg2rad(86), .8, 0, 0, 1, 0);
	$rectangularLimit = Table::HEIGHT / 2 - Ball::RADIUS;

	$expect(count($path) >= 4, 'Jaw glance was flattened into one rectangular reflection');
	$expect(abs($path[1][1] - $rectangularLimit) > .005,
		'Jaw contact was projected on the rectangular cushion plane');
	$expect($path[2][0] < $path[1][0], 'Rounded jaw did not redirect the cue ball across the pocket mouth');
});

$test('projected vertices stay on the independently executed path at edge cases', static function() use ($expect,$isolateCue): void {
	$distanceToSegment = static function(array $point, array $start, array $end): float {
		$dx = $end[0] - $start[0];
		$dy = $end[1] - $start[1];
		$lengthSquared = $dx ** 2 + $dy ** 2;
		if($lengthSquared < 1e-18) return hypot($point[0] - $start[0], $point[1] - $start[1]);
		$amount = max(0, min(1, (($point[0] - $start[0]) * $dx + ($point[1] - $start[1]) * $dy) / $lengthSquared));

		return hypot($point[0] - $start[0] - $dx * $amount, $point[1] - $start[1] - $dy * $amount);
	};
	$cases = [
		'rail' => [static function(Simulation $game) use ($isolateCue): void {
			$cue = $isolateCue($game);
			$cue->x = $cue->previousX = Table::WIDTH / 2 - Ball::RADIUS - .12;
			$cue->y = $cue->previousY = .12;
		}, .35, .8, .71]
		, 'jaw' => [static function(Simulation $game) use ($isolateCue): void {
			$cue = $isolateCue($game);
			$cue->x = $cue->previousX = 0;
			$cue->y = $cue->previousY = 0;
		}, deg2rad(86), .8, 1.0]
		, 'pocket' => [static function(Simulation $game) use ($isolateCue): void {
			$cue = $isolateCue($game);
			$cue->x = $cue->previousX = 0;
			$cue->y = $cue->previousY = 0;
		}, M_PI / 2, .8, .71]
		, 'object ball' => [static function(Simulation $game) use ($isolateCue): void {
			$cue = $isolateCue($game);
			$cue->x = $cue->previousX = 0;
			$cue->y = $cue->previousY = 0;
			$target = $game->balls[1];
			$target->active = true;
			$target->x = $target->previousX = .25;
			$target->y = $target->previousY = 0;
		}, 0.0, .8, .71]
	];

	foreach($cases as $name => [$setup,$angle,$power,$length])
	{
		$projectedGame = new Simulation;
		$executedGame = new Simulation;
		$setup($projectedGame);
		$setup($executedGame);
		$projection = $projectedGame->projectPath($angle, $power, 0, 0, $length);
		$expect($executedGame->shoot($angle, $power), "$name shot was rejected");
		$cue = $executedGame->cue();
		$trace = [[$cue->x,$cue->y]];
		$travel = 0.0;

		for($step = 0; $step < 5000 && $travel < $length + .03; $step++)
		{
			$before = [$cue->x,$cue->y];
			$executedGame->step(Simulation::STEP / 16);
			$after = [$cue->x,$cue->y];
			$travel += hypot($after[0] - $before[0], $after[1] - $before[1]);
			$trace[] = $after;
			$stop = false;

			foreach($executedGame->consumeEvents() as $event)
			if($event['type'] === 'impact' || $event['type'] === 'pocket-enter') $stop = true;

			if($stop) break;
		}

		foreach($projection as $vertex)
		{
			$distance = INF;
			for($index = 1; $index < count($trace); $index++)
			$distance = min($distance, $distanceToSegment($vertex, $trace[$index - 1], $trace[$index]));
			$expect($distance < .0025, "$name projection diverged " . round($distance * 1000, 3) . ' mm from its shot');
		}
	}
});

$test('rack contacts are independent of ball storage order', static function() use ($expect): void {
	$left = new Simulation;
	$right = new Simulation;
	$right->balls = array_reverse($right->balls);
	$expect($left->shoot(0, 1) && $right->shoot(0, 1));

	for($tick = 0; $tick < 240; $tick++)
	{
		$left->step();
		$right->step();
	}

	$states = static function(Simulation $game): array {
		$result = [];
		foreach($game->balls as $ball) $result[$ball->number] = $ball->snapshot();
		ksort($result);
		return $result;
	};
	$expect($states($left) === $states($right), 'Rack result depends on array order');
});

$test('default break resolves the rack as one island without a second cue tap', static function() use ($expect): void {
	$game = new Simulation;
	$expect($game->shoot(0, .58, 0, 0), 'Default shot was rejected');
	$one = null;

	foreach($game->balls as $ball) if($ball->number === 1) $one = $ball;

	$expect($one instanceof Ball, 'Rack has no 1 ball');
	$rebounded = false;
	$lastDistance = 0.0;
	$separatingTicks = 0;

	for($tick = 0; $tick < 180; $tick++)
	{
		$game->step();
		$cue = $game->cue();
		$distance = hypot($one->x - $cue->x, $one->y - $cue->y);

		if(!$rebounded && $cue->vx < 0)
		{
			$rebounded = true;
			$expect($one->vx > 0, 'Later rack rows pushed the resolved 1 ball back into the cue ball');
			$lastDistance = $distance;
			continue;
		}

		if(!$rebounded || $separatingTicks >= 45) continue;

		$expect($distance >= $lastDistance - 1e-7, 'Cue ball reversed and contacted the 1 ball a second time');
		$lastDistance = $distance;
		$separatingTicks++;
	}

	$expect($rebounded && $separatingTicks === 45, 'Default rack contact was not observed');
});

$test('three full-power zero-spin shots clear and spread the rack', static function() use ($expect): void {
	$game = new Simulation;
	$initial = [];
	foreach($game->balls as $ball) if($ball->number !== 0) $initial[$ball->number] = [$ball->x,$ball->y];

	for($shot = 1; $shot <= 3; $shot++)
	{
		$startX = $game->cue()->x;
		$startY = $game->cue()->y;
		$expect($game->shoot(0, 1, 0, 0), "Shot $shot was rejected");
		$expect(abs(hypot($game->cue()->vx, $game->cue()->vy) - Simulation::MAX_SPEED) < 1e-12,
			"Shot $shot did not launch at break speed");
		$ticks = 0;
		while($game->moving() && $ticks++ < 2400) $game->step();
		$expect($ticks < 2400, "Shot $shot never settled");
		if($game->phase === 'placing')
		$expect($game->placeCue(-Table::WIDTH * .28, 0), "Shot $shot scratch could not be placed legally");
		$expect($game->phase === 'aim', "Shot $shot did not return to aim mode");
		$expect(hypot($game->cue()->x - $startX, $game->cue()->y - $startY) > .075,
			"Shot $shot left the cue ball at the contact point");

		if($shot === 1)
		{
			$spread = 0;
			foreach($game->balls as $ball) if($ball->number !== 0
				&& hypot($ball->x - $initial[$ball->number][0], $ball->y - $initial[$ball->number][1]) > .15) $spread++;
			$expect($spread >= 10, 'Full-power break left the rack clustered');
		}
	}

	$moved = 0;
	foreach($game->balls as $ball) if($ball->number !== 0
		&& hypot($ball->x - $initial[$ball->number][0], $ball->y - $initial[$ball->number][1]) > .15) $moved++;
	$expect($moved >= 5, 'Break left most of the rack at its starting points');
});

$test('pocket mouths use regulation American pool dimensions', static function() use ($expect): void {
	$expect(Table::CORNER_MOUTH >= 4.5 * .0254 && Table::CORNER_MOUTH <= 4.625 * .0254);
	$expect(Table::SIDE_MOUTH >= 5 * .0254 && Table::SIDE_MOUTH <= 5.25 * .0254);
	$expect(abs(Ball::RADIUS * 2 - .05715) < 1e-12, 'Ball diameter is not 2 1/4 inches');
	$expect(abs(Table::THROAT_RADIUS * 2 - Table::CORNER_MOUTH) < 1e-12,
		'Tabletop hole no longer uses the reference diameter');
	$expect(abs(Table::pocketRadius(0) - Table::pocketRadius(1)) < 1e-12,
		'Corner and side liners no longer share the rendered radius');
	$expect(abs(Table::pocketRadius(0) - (Table::THROAT_RADIUS - Table::POCKET_LIP_INSET)) < 1e-12,
		'Logical liner clearance differs from the rendered leather lip');
	[[$cornerX,$cornerY],[$sideX,$sideY]] = Table::pockets();
	$expect(abs($cornerX + Table::WIDTH / 2 + Table::CUSHION_WIDTH / 2) < 1e-12
		&& abs($cornerY + Table::HEIGHT / 2 + Table::CUSHION_WIDTH / 2) < 1e-12,
		'Logical corner throat moved away from its rendered position');
	$expect(abs($sideX) < 1e-12 && abs($sideY + Table::HEIGHT / 2 + Table::SIDE_SHELF + Table::THROAT_RADIUS) < 1e-12,
		'Logical side throat moved away from its rendered position');
	// Shelf: cloth between each mouth chord and the slate cut, where a ball still rests before it drops.
	foreach([0,1] as $index)
	{
		[$mouthX,$mouthY] = Table::pocketMouthCenter($index);
		[$outwardX,$outwardY] = Table::pocketOutward($index);
		[$pocketX,$pocketY] = Table::pockets()[$index];
		$shelf = ($pocketX - $mouthX) * $outwardX + ($pocketY - $mouthY) * $outwardY - Table::THROAT_RADIUS;
		$expect($index === 1 ? abs($shelf - Table::SIDE_SHELF) < 1e-12 : $shelf >= .0254 && $shelf <= 2.25 * .0254
			, "Pocket $index shelf is " . round($shelf * 100, 2) . ' cm');
	}
	$expect(count(Table::jaws()) === 12, 'Every pocket opening must have two physical jaws');
	$expect(count(Table::facings()) === 12, 'Every rendered tangent facing must have a physical segment');

	foreach(Table::facings() as $index => [$jawX,$jawY,$tangentX,$tangentY])
	{
		[$pocketX,$pocketY] = Table::pockets()[intdiv($index, 2)];
		$radiusX = $tangentX - $pocketX;
		$radiusY = $tangentY - $pocketY;
		$facingX = $tangentX - $jawX;
		$facingY = $tangentY - $jawY;
		$expect(abs(hypot($radiusX, $radiusY) - Table::THROAT_RADIUS) < 1e-12,
			'Physical facing missed the rendered pocket cut');
		$expect(abs($radiusX * $facingX + $radiusY * $facingY) < 1e-12,
			'Physical pocket facing is not tangent to the rendered pocket cut');
	}
});

$test('the cloth is cut exactly where a ball drops, so nothing rests over a hole', static function() use ($expect): void {
	$bed = TableGeometry::profiles()['bed'];
	foreach(Table::pockets() as $index => [$pocketX,$pocketY])
	{
		[$mouthX,$mouthY] = Table::pocketMouthCenter($index);
		for($u = -.09; $u <= .09; $u += .0045) for($v = -.04; $v <= .12; $v += .0045)
		{
			[$outwardX,$outwardY] = Table::pocketOutward($index);
			$x = $mouthX + $outwardX * $v - $outwardY * $u;
			$y = $mouthY + $outwardY * $v + $outwardX * $u;
			// Only points the cloth or a pocket cut could occupy: the tabletop and the cuts themselves.
			$inNotch = Table::pocketed($x, $y);
			$onTabletop = TableGeometry::roundedRectangleSdf($x, $y, Table::WIDTH / 2 + TableGeometry::TABLETOP_MARGIN
				, Table::HEIGHT / 2 + TableGeometry::TABLETOP_MARGIN, TableGeometry::TABLETOP_CORNER_RADIUS) < 0;
			if(!$inNotch && !$onTabletop) continue;
			$cloth = $bed($x, $y) < 0;
			$expect($cloth !== Table::pocketed($x, $y) || abs(hypot($x - $pocketX, $y - $pocketY) - Table::THROAT_RADIUS) < 1e-6
				, sprintf('Pocket %d: cloth and drop rule disagree at %.4f, %.4f', $index, $x, $y));
		}
	}
});

$test('a ball rolled over the shelf edge drops and tips in without jumping', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$ball = $game->balls[1];
	$ball->active = true;
	[$pocketX,$pocketY] = Table::pockets()[1];
	// Start on the side pocket's shelf, just short of the slate cut, rolling slowly into it.
	$ball->x = $ball->previousX = $pocketX;
	$ball->y = $ball->previousY = $pocketY + Table::THROAT_RADIUS + .004;
	$ball->vy = -.08;
	$game->phase = 'moving';
	$entered = false;
	$largestJump = 0.0;
	for($tick = 0; $tick < 600 && $ball->active; $tick++)
	{
		// A snap shows as movement the ball's own velocity does not explain.
		[$x,$y,$vx,$vy] = [$ball->x,$ball->y,$ball->vx,$ball->vy];
		$game->step();
		if($ball->pocket !== null && $ball->active)
		$largestJump = max($largestJump, hypot($ball->x - $x - $vx * Simulation::STEP, $ball->y - $y - $vy * Simulation::STEP));
		foreach($game->consumeEvents() as $event) $entered = $entered || $event['type'] === 'pocket-enter';
	}
	$expect($entered && !$ball->active, 'A slow ball crossing the shelf edge did not drop');
	$expect($largestJump < .002, 'The ball jumped ' . round($largestJump * 1000, 1) . ' mm in one step instead of tipping in');
});

$test('identical shots replay deterministically', static function() use ($expect): void {
	$left = new Simulation;
	$right = new Simulation;
	$left->shoot(.08, .82, .35, -.27);
	$right->shoot(.08, .82, .35, -.27);

	for($tick = 0; $tick < 720; $tick++)
	{
		$left->step();
		$right->step();
	}

	$expect($left->hash() === $right->hash(), 'Fixed replay diverged');
});

$test('swept sphere contacts prevent tunnelling', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$a = $game->balls[0];
	$b = $game->balls[1];
	$a->active = $b->active = true;
	$a->x = -.22; $a->y = 0; $a->vx = 24;
	$b->x = .10; $b->y = 0;
	$game->step();
	$expect($b->vx > 5, 'Fast cue ball passed through its target');
	$expect($a->x < $b->x, 'Sphere order inverted after impact');
});

$test('cushions reflect and dissipate normal speed', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$ball = $game->balls[0];
	$ball->active = true;
	$ball->x = Table::WIDTH / 2 - Ball::RADIUS - .02;
	$ball->y = .2;
	$ball->vx = 3;
	$game->step();
	$expect($ball->vx < 0, 'Cushion did not reflect the ball');
	$expect(abs($ball->vx) < 3, 'Cushion added energy');
});

$test('cloth converts sliding motion into visible rolling spin', static function() use ($expect): void {
	$game = new Simulation;
	$game->shoot(M_PI, .5, 0, 0);
	$cue = $game->cue();
	$expect(abs($cue->spinZ) < 1e-9, 'Zero-spin strike began rolling');

	for($tick = 0; $tick < 12; $tick++) $game->step();

	$expect(abs($cue->spinZ) > 1, 'Cloth did not produce rolling angular velocity');
});

$test('total kinetic energy stays bounded through contacts', static function() use ($expect, $energy): void {
	$game = new Simulation;
	$game->shoot(0, 1, 0, 0);
	$initial = $energy($game);
	$maximum = $initial;

	for($tick = 0; $tick < 900; $tick++)
	{
		$game->step();
		$maximum = max($maximum, $energy($game));
	}

	$expect($maximum <= $initial * 1.005, 'Simulation created kinetic energy');
});

$test('pockets retain object balls and scratches require legal placement', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$cue = $game->cue();
	$cue->active = true;
	$cue->x = 0;
	$cue->y = Table::HEIGHT / 2 - Ball::RADIUS - .005;
	$cue->vy = .5;
	$game->phase = 'moving';
	$ticks = 0;
	while($game->moving() && $ticks++ < 120) $game->step();
	$expect(!$cue->active && $game->scratched, 'Cue ball did not scratch');
	$expect($game->phase === 'placing', 'Scratch did not enter placement mode');
	$expect(!$game->placeCue(Table::WIDTH, 0), 'Placement accepted a point beyond the cloth');
	$expect($game->placeCue(-.65, 0), 'Legal cue placement failed');
	$expect($cue->active && $game->phase === 'aim' && !$game->scratched);
});

$test('pocket shelves and jaws reject glancing balls instead of vacuuming them in', static function() use ($expect): void {
	$edge = Table::HEIGHT / 2;
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$ball = $game->balls[1];
	$ball->active = true;
	$ball->x = $ball->previousX = .06;
	$ball->y = $ball->previousY = $edge - Ball::RADIUS - .005;
	$ball->vy = 2;
	$game->phase = 'moving';
	$game->consumeEvents();
	$game->step();
	$events = $game->consumeEvents();

	$expect($ball->active && $ball->pocket === null, 'Glancing ball was swallowed by the side pocket');
	$expect($ball->vy < 0, 'Rounded jaw did not return the glancing ball to the table');
	$expect(count(array_filter($events, static fn(array $event): bool => $event['type'] === 'cushion')) === 1,
		'Jaw collision did not emit a cushion contact');
});

$test('crowded 6 and 7 ball pocket entries cannot escape through a rail', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$states = [
		[6,-.055856477510466,.604425,3.0952740570054,3.7948976588758]
		, [7,-.0011575308680337,.604425,-.38633643755657,7.0377743110714]
		, [9,.058290443913564,.604425,-4.9040369983854,5.7525813341049]
		, [2,-.05610232213738,.547175,1.9904427082911,6.9387064615708]
		, [10,.0015375528836332,.547175,.8617267653076,6.2348432039698]
		, [8,.058407036088015,.547175,-.30240035166822,.57292006250256]
	];

	foreach($states as [$number,$x,$y,$vx,$vy])
	foreach($game->balls as $ball) if($ball->number === $number)
	{
		$ball->active = true;
		$ball->x = $ball->previousX = $x;
		$ball->y = $ball->previousY = $y;
		$ball->vx = $vx;
		$ball->vy = $vy;
	}

	$game->phase = 'moving';
	$entered = false;

	for($tick = 0; $tick < 240; $tick++)
	{
		$game->step();

		foreach($game->consumeEvents() as $event)
		if($event['type'] === 'pocket-enter') $entered = true;

		foreach($game->balls as $ball) if($ball->active && $ball->pocket === null)
		$expect(abs($ball->x) <= Table::WIDTH / 2 + .08 && abs($ball->y) <= Table::HEIGHT / 2 + .08,
			"Ball {$ball->number} escaped behind a crowded pocket");
	}

	$expect($entered, 'Crowded side pocket never registered a swept entry');
});

$test('rendered tangent pocket facings are physical collision surfaces', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$ball = $game->balls[1];
	$ball->active = true;
	[$startX,$startY,$endX,$endY] = Table::facings()[2];
	$segmentX = $endX - $startX;
	$segmentY = $endY - $startY;
	$length = hypot($segmentX, $segmentY);
	$normalX = -$segmentY / $length;
	$normalY = $segmentX / $length;
	// Near the jaw end, so the ball starts on the cloth rather than over the slate cut where it would simply drop.
	$ball->x = $ball->previousX = $startX + $segmentX * .2 + $normalX * (Ball::RADIUS + .004);
	$ball->y = $ball->previousY = $startY + $segmentY * .2 + $normalY * (Ball::RADIUS + .004);
	$expect(!Table::pocketed($ball->x, $ball->y), 'The facing test ball starts over the slate cut');
	$ball->vx = -$normalX;
	$ball->vy = -$normalY;
	$game->phase = 'moving';
	$game->consumeEvents();
	$game->step();
	$events = $game->consumeEvents();

	$expect($ball->vx * $normalX + $ball->vy * $normalY > 0, 'Ball crossed a rendered pocket facing');
	$expect(count(array_filter($events, static fn(array $event): bool => $event['type'] === 'cushion')) === 1,
		'Facing collision did not emit one cushion contact');
});

$test('pocket cone can reject an energetic ball and loses energy deterministically', static function() use ($expect): void {
	$game = new Simulation;
	$replay = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	foreach($replay->balls as $ball) $ball->active = false;
	$ball = $game->balls[1];
	$copy = $replay->balls[1];
	$ball->active = true;
	$copy->active = true;
	$ball->x = $ball->previousX = -.04;
	$copy->x = $copy->previousX = -.04;
	$ball->y = $ball->previousY = Table::HEIGHT / 2 - Ball::RADIUS - .005;
	$copy->y = $copy->previousY = $ball->y;
	$ball->vx = $copy->vx = -.5;
	$ball->vy = $copy->vy = 5;
	$game->phase = 'moving';
	$replay->phase = 'moving';
	$game->consumeEvents();
	$replay->consumeEvents();
	$rejected = false;
	$rejectedPocket = null;

	for($tick = 0; $tick < 30 && !$rejected; $tick++)
	{
		$game->step();
		$replay->step();

		foreach($game->consumeEvents() as $event)
		if($event['type'] === 'pocket-reject')
		{
			$rejected = true;
			$rejectedPocket = $event['pocket'];
		}
	}

	$expect($rejected, 'Energetic ball could not bounce out of the pocket liner');
	$expect($ball->active && $ball->pocket === null && $ball->drop === 0.0,
		'Rejected ball did not return to the cloth');
	[$mouthX,$mouthY] = Table::pocketMouthCenter($rejectedPocket);
	[$outwardX,$outwardY] = Table::pocketOutward($rejectedPocket);
	$expect(($ball->x - $mouthX) * $outwardX + ($ball->y - $mouthY) * $outwardY <= 1e-8,
		'Rejected ball left pocket physics before crossing the mouth chord');
	$expect($ball->vx * $outwardX + $ball->vy * $outwardY < 0,
		'Rejected ball was moving back into the pocket when released');
	[$pocketX,$pocketY] = Table::pockets()[$rejectedPocket];
	foreach(array_slice(Table::facings(), $rejectedPocket * 2, 2) as [$startX,$startY,$endX,$endY])
	{
		$segmentX = $endX - $startX;
		$segmentY = $endY - $startY;
		$length = hypot($segmentX, $segmentY);
		$nx = -$segmentY / $length;
		$ny = $segmentX / $length;
		if(($pocketX - $startX) * $nx + ($pocketY - $startY) * $ny < 0) [$nx,$ny] = [-$nx,-$ny];
		$expect(($ball->x - $startX) * $nx + ($ball->y - $startY) * $ny >= Ball::RADIUS - 1e-7,
			'Rejected ball overlapped a solid pocket facing');
	}
	$expect(hypot($ball->vx, $ball->vy) < 5, 'Inelastic pocket contacts failed to remove energy');
	$expect($game->hash() === $replay->hash(), 'Pocket bounce changed between identical simulations');
});

$test('reset restores the exact pre-shot state', static function() use ($expect): void {
	$game = new Simulation;
	$before = $game->hash();
	$game->shoot(.15, .9, -.5, .6);

	for($tick = 0; $tick < 45; $tick++) $game->step();

	$expect($game->hash() !== $before);
	$expect($game->resetShot(), 'Reset had no snapshot');
	$expect($game->hash() === $before, 'Reset did not restore the complete snapshot');
});

$test('render geometry derives all six pockets and constant-width rails from physics', static function() use ($expect): void {
	$pockets = TableGeometry::pockets();
	$rails = TableGeometry::cushionPolygons();
	$expect(count($pockets) === 6 && count($rails) === 6);
	$expect(count(TableGeometry::railSights()) === 18);

	foreach($pockets as $index => $pocket)
	{
		$expect(abs($pocket['x'] - Table::pockets()[$index][0]) < 1e-12);
		$expect(abs($pocket['z'] - Table::pockets()[$index][1]) < 1e-12);
		$expect(abs($pocket['radius'] - Table::THROAT_RADIUS) < 1e-12);
		$expect(abs($pocket['mouth'] - Table::pocketMouthHalf($index) * 2) < 1e-12);
		foreach($pocket['faces'] as $face)
		{
			$radius = [$face['tangent'][0] - $pocket['x'],$face['tangent'][1] - $pocket['z']];
			$facing = [$face['tangent'][0] - $face['jaw'][0],$face['tangent'][1] - $face['jaw'][1]];
			$expect(abs(hypot(...$radius) - Table::THROAT_RADIUS) < 1e-12, 'Facing missed the shared throat');
			$expect(abs($radius[0] * $facing[0] + $radius[1] * $facing[1]) < 1e-12, 'Facing is not tangent');
		}
	}

	foreach($rails as $rail)
	{
		$spanX = max(array_column($rail, 0)) - min(array_column($rail, 0));
		$spanZ = max(array_column($rail, 1)) - min(array_column($rail, 1));
		$expect(abs(min($spanX, $spanZ) - Table::CUSHION_WIDTH) < 1e-12, 'Cushion width changed at a pocket');
	}
});

$test('shared CSG profiles cut identical circular holes through every table material', static function() use ($expect): void {
	$profiles = TableGeometry::profiles();
	$outerX = Table::WIDTH / 2 + .17;
	$outerZ = Table::HEIGHT / 2 + .17;
	$expect($profiles['apron']($outerX - .01, 0) < 0);
	$expect($profiles['apron']($outerX, $outerZ) > 0, 'Outer wood corner is not rounded');
	$expect($profiles['wood'](0, 0) > 0, 'Wood covers the playfield');
	$expect(abs($profiles['cushion'](Table::WIDTH / 2, .2)) < 1e-12, 'Cushion nose left the physics boundary');

	foreach($profiles['pockets'] as $pocket)
	{
		foreach(['apron','bed','wood'] as $material)
		$expect($profiles[$material]($pocket['x'], $pocket['z']) > 0, "$material did not subtract a pocket");

		for($step = 0; $step < 24; $step++)
		{
			$angle = $step / 24 * M_PI * 2;
			$x = $pocket['x'] + cos($angle) * Table::THROAT_RADIUS;
			$z = $pocket['z'] + sin($angle) * Table::THROAT_RADIUS;
			$expect(abs(TableGeometry::circleSdf($x, $z, $pocket['x'], $pocket['z'], Table::THROAT_RADIUS)) < 1e-10);
		}
	}

	$expect(abs(TableGeometry::pocketVoidRadius() - Table::LINER_RADIUS) < 1e-12);
});

$test('adaptive render pacing preserves cadence and yields after expensive frames', static function() use ($expect): void {
	$pacer = new FramePacer(1 / 60, .5);
	$expect($pacer->ready(1.0));
	$pacer->complete(1.0, 1.1);
	$expect(!$pacer->ready(1.199), 'Expensive frame did not reserve a matching yield period');
	$expect($pacer->ready(1.2));
	$pacer->complete(2.0, 2.001);
	$expect(!$pacer->ready(2.01), 'Fast frame ignored the display cadence');
	$expect($pacer->ready(2 + 1 / 60));
	$pacer->complete(3.0, 3.001);
	$expect(!$pacer->ready(3.2), 'Deferred browser-side raster work did not schedule an adaptive yield');
	$expect(!$pacer->ready(3.399), 'Observed render cost did not retain its idle share');
	$expect($pacer->ready(3.4), 'Observed render cost blocked the frame after its idle share');
	$pacer->complete(4.0, 4.001);
	$pacer->idle();
	$expect($pacer->ready(40), 'A static scene delayed the first input-driven frame');
	$pacer->reset();
	$expect($pacer->ready(0));
});

$test('rounded PHP mesh helpers retain smooth unit normals and flush cushion bottoms', static function() use ($expect): void {
	$bottom = .012;
	$top = .105;
	$radius = TableGeometry::WOOD_EDGE_RADIUS;
	$expect(abs(MeshBuilder::roundedExtrusionSdf(0, ($bottom + $top) / 2, $bottom, $top, $radius)) < 1e-12);
	$expect(abs(MeshBuilder::roundedExtrusionSdf(-$radius, $top, $bottom, $top, $radius)) < 1e-12);
	$normal = MeshBuilder::roundedFilletNormal(M_SQRT1_2, M_SQRT1_2, M_PI / 4, .028, .018);
	$expect(abs(sqrt($normal[0] ** 2 + $normal[1] ** 2 + $normal[2] ** 2) - 1) < 1e-12);
	$expect($normal[1] > $normal[0]);
	$expect(TableGeometry::CUSHION_EDGE_RADIUS > 0
		&& TableGeometry::CUSHION_EDGE_RADIUS < TableGeometry::WOOD_EDGE_RADIUS / 2);
});

$test('rail fillets keep exact pocket jaws and never fold', static function() use ($expect): void {
	$root = dirname(__DIR__) . '/assets/generated/';
	$wood = json_decode(file_get_contents($root . 'meshes.json'), true)['meshes']['wood'];
	$bytes = gzdecode(file_get_contents($root . 'meshes.bin.gz'));
	$vertices = array_values(unpack('g*', substr($bytes, $wood['vertexOffset'], $wood['vertexLength'])));
	$indices = array_values(unpack('V*', substr($bytes, $wood['indexOffset'], $wood['indexLength'])));
	$vertex = static fn(int $index): array => array_slice($vertices, $index * 8, 6);

	// Fillet bands offset point by point used to cross at sharp jaws; every face must agree with its vertex normals.
	$folded = 0;
	for($index = 0; $index < count($indices); $index += 3)
	{
		[$a,$b,$c] = [$vertex($indices[$index]),$vertex($indices[$index + 1]),$vertex($indices[$index + 2])];
		$ab = [$b[0] - $a[0],$b[1] - $a[1],$b[2] - $a[2]];
		$ac = [$c[0] - $a[0],$c[1] - $a[1],$c[2] - $a[2]];
		$face = [$ab[1] * $ac[2] - $ab[2] * $ac[1],$ab[2] * $ac[0] - $ab[0] * $ac[2],$ab[0] * $ac[1] - $ab[1] * $ac[0]];
		$normal = [$a[3] + $b[3] + $c[3],$a[4] + $b[4] + $c[4],$a[5] + $b[5] + $c[5]];
		if($face[0] * $normal[0] + $face[1] * $normal[1] + $face[2] * $normal[2] < 0) $folded++;
	}
	$expect($folded === 0, "$folded wood faces fold against their normals");

	// The side pocket cuts the cushion line in a sharp corner; the rail wall must reach it exactly below the fillet.
	$side = TableGeometry::pockets()[1];
	$edge = Table::HEIGHT / 2 + TableGeometry::TABLETOP_MARGIN;
	$jaw = sqrt($side['radius'] ** 2 - (abs($side['z']) - $edge) ** 2);
	foreach([[-1,-1],[1,-1],[-1,1],[1,1]] as [$sx,$sz])
	{
		$nearest = INF;
		for($index = 0; $index < count($vertices); $index += 8)
		if(abs($vertices[$index + 1] - (TableGeometry::RAIL_TOP - TableGeometry::WOOD_EDGE_RADIUS)) < 1e-6)
		$nearest = min($nearest, hypot($vertices[$index] - $sx * $jaw, $vertices[$index + 2] - $sz * $edge));
		$expect($nearest < 1e-4, 'A side-pocket jaw corner is chamfered off the rail');
	}
});

$test('the turned cue faces outward, ends at its tip, and clears the rail like the posed cue', static function() use ($expect): void {
	$mesh = MeshBuilder::cue();
	$vertices = array_values(unpack('g*', $mesh->vertices));
	$indices = array_values(unpack('V*', $mesh->indices));
	$vertex = static fn(int $index): array => array_slice($vertices, $index * 8, 8);
	$inward = 0;
	$widest = 0.0;
	for($index = 0; $index < count($indices); $index += 3)
	{
		[$a,$b,$c] = [$vertex($indices[$index]),$vertex($indices[$index + 1]),$vertex($indices[$index + 2])];
		$ab = [$b[0] - $a[0],$b[1] - $a[1],$b[2] - $a[2]];
		$ac = [$c[0] - $a[0],$c[1] - $a[1],$c[2] - $a[2]];
		$face = [$ab[1] * $ac[2] - $ab[2] * $ac[1],$ab[2] * $ac[0] - $ab[0] * $ac[2],$ab[0] * $ac[1] - $ab[1] * $ac[0]];
		if($face[0] ** 2 + $face[1] ** 2 + $face[2] ** 2 < 1e-28) continue;
		$normal = [$a[3] + $b[3] + $c[3],$a[4] + $b[4] + $c[4],$a[5] + $b[5] + $c[5]];
		if($face[0] * $normal[0] + $face[1] * $normal[1] + $face[2] * $normal[2] < 0) $inward++;
	}
	for($index = 0; $index < count($vertices); $index += 8) $widest = max($widest, hypot($vertices[$index + 1], $vertices[$index + 2]));
	$xs = array_map(static fn(int $index): float => $vertices[$index], range(0, count($vertices) - 1, 8));
	$expect($inward === 0, "$inward cue faces point inward");
	$expect(abs(max($xs)) < 1e-9 && abs(min($xs) + 1.35) < 1e-6, 'The cue no longer runs from its tip at the origin back 1.35 m');
	// Camera::cuePose pitches the cue over the rails using a 28.5 mm butt.
	$expect($widest <= .0285 / 2 + 1e-6, 'The cue is wider than the butt the rail clearance assumes');
});

$test('cue cylinder side triangles all face outward', static function() use ($expect): void {
	$segments = 48;
	$mesh = MeshBuilder::cylinder(.5, .0125 / .0285 / 2, $segments);
	$vertices = unpack('g*', $mesh->vertices);
	$indices = array_values(unpack('V*', substr($mesh->indices, 0, $segments * 6 * 4)));
	$position = static fn(int $index): array => [
		$vertices[$index * 8 + 1],$vertices[$index * 8 + 2],$vertices[$index * 8 + 3]
	];

	for($index = 0; $index < count($indices); $index += 3)
	{
		$a = $position($indices[$index]);
		$b = $position($indices[$index + 1]);
		$c = $position($indices[$index + 2]);
		$ab = [$b[0] - $a[0],$b[1] - $a[1],$b[2] - $a[2]];
		$ac = [$c[0] - $a[0],$c[1] - $a[1],$c[2] - $a[2]];
		$normal = [
			$ab[1] * $ac[2] - $ab[2] * $ac[1]
			, $ab[2] * $ac[0] - $ab[0] * $ac[2]
			, $ab[0] * $ac[1] - $ab[1] * $ac[0]
		];
		$radial = [0,($a[1] + $b[1] + $c[1]) / 3,($a[2] + $b[2] + $c[2]) / 3];
		$expect($normal[1] * $radial[1] + $normal[2] * $radial[2] > 0, 'Cue cylinder contains an inward-facing side triangle');
	}
});

$test('PHP camera projects the target, reverses orbit below it, and clears the cue over rails', static function() use ($expect): void {
	$camera = new Camera;
	$expect(abs($camera->yaw - M_PI) < 1e-12 && $camera->pitch === .12 && $camera->distance === 1.5);
	$camera->beginGameplayView();
	$camera->animate(.675);
	$expect($camera->transitioning() && $camera->pitch > .12 && $camera->pitch < .72,
		'Title camera did not ease through an intermediate pose');
	$camera->animate(.675);
	$expect(!$camera->transitioning() && abs($camera->yaw + .42) < 1e-12
		&& abs($camera->pitch - .72) < 1e-12 && abs($camera->distance - 3.35) < 1e-12,
		'Title camera did not finish at the gameplay view');
	$point = $camera->tablePoint(.5, .5, 900, 600, 0);
	$expect($point !== null && abs($point[0]) < 1e-12 && abs($point[1]) < 1e-12);
	$yaw = $camera->yaw;
	$camera->orbit(20, 0, .4);
	$expect(abs($camera->yaw - ($yaw - .12)) < 1e-12);
	$camera->orbit(20, 0, .6);
	$expect(abs($camera->yaw - $yaw) < 1e-12);

	foreach([
		[-Table::WIDTH * .28,0,0]
		, [0,0,M_PI / 2]
		, [Table::WIDTH / 2 - Ball::RADIUS * 1.02,.2,M_PI]
		// A ball resting on a pocket jaw may lie just outside the nominal rail plane.
		, [1.2337996517992,.63574091226259,-M_PI / 2]
	] as [$x,$y,$angle])
	{
		$pose = Camera::cuePose($x, $y, $angle);
		$heightAtRail = $pose['ballCenter'][1] + tan($pose['pitch']) * $pose['railDistance'];
		$expect($heightAtRail >= $pose['railClearance'] - 1e-12, 'Cue intersects a solid rail');
		$offset = array_map(static fn(float $value, int $index): float => $value - $pose['ballCenter'][$index],
			$pose['tip'], array_keys($pose['tip']));
		$expect(abs(sqrt($offset[0] ** 2 + $offset[1] ** 2 + $offset[2] ** 2) - Ball::RADIUS - .008) < 1e-12);
	}
});

$test('title menu selects free or online play before native controls drive the HUD', static function() use ($expect): void {
	$linked = new Game;
	$linked->input(['actions' => ['online-play']], [], [], Hud::titleRegions(1280, 720), [0,0], 0, 1280, 720);
	$expect($linked->titleSelection === 1 && $linked->onlineRequested && $linked->statusText() === 'Online Play',
		'A linked challenge did not select the Online Play title state');

	$game = new Game;
	$title = Hud::titleRegions(1280, 720);
	$expect(!$game->started && count($title) === 2 && isset($title['free-play'], $title['online-play']));
	$game->input([], [], [[
		'type' => 'pointer','action' => 'down','button' => 1
		, 'x' => $title['free-play']['rect'][0] + 1, 'y' => $title['free-play']['rect'][1] + 1
	]], $title, [0,0], 0, 1280, 720);
	$expect($game->started && $game->simulation->shots === 0 && $game->camera->transitioning(),
		'Free Play did not enter play through the title-camera transition');
	$game->camera->animate(2);

	$regions = Hud::regions(1280, 720);
	$yaw = $game->camera->yaw;
	$pitch = $game->camera->pitch;
	$game->input([], ['right' => .5,'up' => .25,'button7' => .5,'button5' => .25], [], $regions, [.5,-.25], .1, 1280, 720);
	$expect($game->aim > 0 && $game->power > .58 && $game->top > 0 && $game->side > 0);
	$expect($game->camera->yaw > $yaw && $game->camera->pitch < $pitch);

	// Point-to-aim: pressing on the cloth points the cue from the cue ball toward that spot.
	$cue = $game->simulation->cue();
	$matrix = $game->camera->viewProjection(1280, 720);
	$target = [$cue->x,.012,$cue->y + .3,1];
	$clip = [];
	for($row = 0; $row < 4; $row++)
	{
		$clip[$row] = 0;
		for($column = 0; $column < 4; $column++) $clip[$row] += $matrix[$column * 4 + $row] * $target[$column];
	}
	$pointer = [($clip[0] / $clip[3] + 1) * 640,(1 - $clip[1] / $clip[3]) * 360];
	$game->input([], [], [['type' => 'pointer','action' => 'down','button' => 1,'x' => $pointer[0],'y' => $pointer[1]]]
		, $regions, [0,0], 0, 1280, 720);
	$game->input([], [], [['type' => 'pointer','action' => 'up','button' => 1,'x' => $pointer[0],'y' => $pointer[1]]]
		, $regions, [0,0], 0, 1280, 720);
	$expect(abs($game->aim - M_PI / 2) < .02, 'Pointing at the cloth did not aim the cue');

	// Holding aim across the back of the table wraps instead of stopping at 180 degrees.
	$game->aim = 3.13;
	$game->input([], ['right' => 1], [], $regions, [0,0], .1, 1280, 720);
	$expect($game->aim < 0 && $game->aim > -3.14, 'Keyboard aim did not wrap past 180 degrees');

	// The spin disc maps a press to the cue-tip offset and clamps it to the ball face.
	$spin = $regions['spin'];
	$game->input([], [], [['type' => 'pointer','action' => 'down','button' => 1
		, 'x' => $spin['center'][0] + $spin['radius'] * 1.4,'y' => $spin['center'][1]]], $regions, [0,0], 0, 1280, 720);
	$game->input([], [], [['type' => 'pointer','action' => 'up','button' => 1,'x' => 0,'y' => 0]], $regions, [0,0], 0, 1280, 720);
	$expect(abs($game->side - 1) < 1e-9 && abs($game->top) < 1e-9, 'Spin disc did not set clamped side spin');
	$game->input([], ['button2' => 1,'button18' => 1,'button17' => 1], [], $regions, [0,0], 0, 1280, 720);
	$expect($game->top === 0.0 && $game->side === 0.0 && $game->muted && $game->consumeFullscreenRequest());

	// A pull started anywhere on the bottom-docked power meter reaches full power before the canvas edge.
	[$meterX,$meterY,$meterWidth,$meterHeight] = $regions['power']['rect'];
	$press = ['x' => $meterX + $meterWidth / 2,'y' => $meterY + $meterHeight / 2];
	$game->input([], [], [['type' => 'pointer','action' => 'down','button' => 1] + $press], $regions, [0,0], 0, 1280, 720);
	$game->input([], [], [['type' => 'pointer','action' => 'move','button' => 1,'x' => $press['x'],'y' => 714]]
		, $regions, [0,0], 0, 1280, 720);
	$expect($game->power === 1.0, 'Dragging the power meter to the canvas edge stopped short of full power');
	$game->input([], [], [['type' => 'pointer','action' => 'up','button' => 1,'x' => $press['x'],'y' => 714]]
		, $regions, [0,0], 0, 1280, 720);
	$expect($game->stroking() && $game->stroke()['power'] === 1.0, 'A full pull did not shoot at full power');
});

$test('seeded online racks preserve regulation constraints and replay exactly', static function() use ($expect): void {
	$seed = str_repeat('ab', 32);
	$left = new Simulation($seed, 0);
	$right = new Simulation($seed, 0);
	$next = new Simulation($seed, 1);
	$expect($left->exactHash() === $right->exactHash(), 'Identical online rack seeds diverged');
	$expect($left->exactHash() !== $next->exactHash(), 'Derived rerack index did not change the rack');
	$expect($left->balls[5]->number === 8, 'Eight ball left the rack center');
	$rear = [$left->balls[11]->number,$left->balls[15]->number];
	$expect((min($rear) <= 7 && max($rear) >= 9), 'Rear rack corners do not contain opposite groups');
});

$test('online input waits for room confirmation and reserves one durable action', static function() use ($expect): void {
	$game = new Game;
	$seed = str_repeat('4d', 32);
	$player = '@alice:example.org';
	$peer = '@bob:example.org';
	$frame = static function(Game $game, array $browser): void {
		$game->input($browser, [], [], [], [0,0], 0, 1280, 720);
	};
	$frame($game, ['network' => [[
		'type' => 'start'
		, 'detail' => [
			'players' => [$player,$peer]
			, 'seed' => $seed
			, 'breaker' => $player
			, 'localPlayer' => $player
			, 'role' => 'player'
		]
	]]]);
	$status = $game->status(1);
	$ready = array_values(array_filter(
		$status['network']['outbox'], static fn(array $message): bool => $message['type'] === 'ready'
	))[0] ?? null;
	$expect(is_array($ready) && !$game->controllable(), 'Seeded setup unlocked before both room hashes agreed');
	$initialState = $game->simulation->exactHash();
	for($tick = 0; $tick < 120; $tick++) $game->step(Simulation::STEP);
	$expect($game->simulation->exactHash() === $initialState,
		'Initial online state drifted while waiting for room confirmation');

	$frame($game, ['actions' => ['primary']]);
	$expect(count($game->status(1)['network']['outbox']) === 1, 'An unconfirmed turn emitted an action');
	$frame($game, [
		'networkAcks' => [$ready['id']]
		, 'network' => [[
			'type' => 'confirmed'
			, 'detail' => ['sequence' => 0,'hash' => $ready['hash'],'nextActor' => $player,'outcome' => null]
		]]
	]);
	$expect($game->controllable(), 'Matching initial room confirmation did not unlock the breaker');

	$frame($game, ['actions' => ['primary']]);
	$frame($game, ['actions' => ['primary']]);
	$outbox = $game->status(1)['network']['outbox'];
	$requests = array_values(array_filter(
		$outbox, static fn(array $message): bool => $message['type'] === 'action-request'
	));
	$expect(count($requests) === 1 && !$game->controllable(), 'One turn queued more than one action request');

	$frame($game, [
		'networkAcks' => [$requests[0]['id']]
		, 'network' => [[
			'type' => 'write-rejected'
			, 'detail' => ['messageType' => 'action-request','message' => 'It is not your turn']
		]]
	]);
	$rejected = $game->status(1);
	$expect(!$game->controllable() && count($rejected['network']['outbox']) === 0
		&& $rejected['status'] === 'Online: It is not your turn',
		'A rejected action remained queued or incorrectly unlocked local input');
	$frame($game, ['network' => [[
		'type' => 'confirmed'
		, 'detail' => ['sequence' => 0,'hash' => $ready['hash'],'nextActor' => $player,'outcome' => null]
	]]]);
	$expect($game->controllable(), 'A fresh matching room confirmation did not recover the turn');
});

$test('online scoreboard restores turn groups and pocketed balls from confirmed state', static function() use ($expect): void {
	$seed = str_repeat('5e', 32);
	$alice = '@alice:example.org';
	$bob = '@bob:example.org';
	$simulation = new Simulation($seed);
	foreach($simulation->balls as $ball)
	if(in_array($ball->number, [2,5,11], true)) $ball->active = false;
	$match = new EightBallMatch([$alice,$bob], $alice, $seed);
	$match->groups = [$alice => 'solid',$bob => 'stripe'];
	$match->actor = $bob;
	$checkpoint = $match->checkpoint($simulation);

	$game = new Game;
	$game->input(['network' => [[
		'type' => 'start'
		, 'detail' => [
			'players' => [$alice,$bob], 'seed' => $seed, 'breaker' => $alice
			, 'localPlayer' => $alice, 'role' => 'player'
			, 'checkpoint' => $checkpoint, 'hash' => $match->hash($simulation)
		]
	]]], [], [], [], [0,0], 0, 1280, 720);

	$score = $game->onlineScoreboard();
	$expect($score !== null && $score['assigned'] && $score['actor'] === $bob && $score['openDown'] === []);
	$expect($score['players'][0]['name'] === 'alice' && $score['players'][0]['local']
		&& !$score['players'][0]['turn'] && $score['players'][0]['group'] === 'solid'
		&& $score['players'][0]['down'] === [2,5], 'Solid player scoreboard row was incorrect');
	$expect($score['players'][1]['name'] === 'bob' && !$score['players'][1]['local']
		&& $score['players'][1]['turn'] && $score['players'][1]['group'] === 'stripe'
		&& $score['players'][1]['down'] === [11], 'Stripe player scoreboard row was incorrect');
	$expect($game->status(1)['network']['scoreboard'] === $score,
		'Browser-facing status did not expose the native OSD scoreboard');

	$free = new Game;
	$expect($free->onlineScoreboard() === null, 'Free Play unexpectedly produced an online scoreboard');
});

$test('idle game frames preserve the exact render state', static function() use ($expect): void {
	$game = new Game;
	$game->input(['actions' => ['start']], [], [], [], [0,0], 0, 1280, 720);
	$before = json_encode($game->status(1), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
	for($tick = 0; $tick < 120; $tick++) $game->step(Simulation::STEP);
	$after = json_encode($game->status(1), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
	$expect($after === $before, 'Idle fixed steps changed a static frame');
});

$test('online settlement stays fixed while room confirmation is in flight', static function() use ($expect): void {
	$game = new Game;
	$seed = str_repeat('6a', 32);
	$player = '@alice:example.org';
	$peer = '@bob:example.org';
	$frame = static function(Game $game, array $browser): void {
		$game->input($browser, [], [], [], [0,0], 0, 1280, 720);
	};
	$messages = static fn(Game $game, string $type): array => array_values(array_filter(
		$game->status(1)['network']['outbox'], static fn(array $message): bool => $message['type'] === $type
	));

	$frame($game, ['network' => [[
		'type' => 'start'
		, 'detail' => [
			'players' => [$player,$peer], 'seed' => $seed, 'breaker' => $player
			, 'localPlayer' => $player, 'role' => 'player'
		]
	]]]);
	$ready = $messages($game, 'ready')[0];
	$frame($game, [
		'networkAcks' => [$ready['id']]
		, 'network' => [[
			'type' => 'confirmed'
			, 'detail' => ['sequence' => 0,'hash' => $ready['hash'],'nextActor' => $player,'outcome' => null]
		]]
	]);
	$frame($game, ['actions' => ['primary']]);
	$request = $messages($game, 'action-request')[0];
	$frame($game, [
		'networkAcks' => [$request['id']]
		, 'network' => [[
			'type' => 'action'
			, 'detail' => [
				'sequence' => 1, 'actor' => $player, 'pre_hash' => $request['preHash']
				, 'action' => $request['action']
			]
			// A late duplicate of the preceding confirmation must not abort the active shot.
			, [
				'type' => 'confirmed'
				, 'detail' => ['sequence' => 0,'hash' => $ready['hash'],'nextActor' => $player,'outcome' => null]
			]
		]]
	]);
	$expect(count($messages($game, 'fatal')) === 0, 'A stale confirmation aborted the following action');

	$settled = null;
	for($tick = 0; $tick < 2400 && $settled === null; $tick++)
	{
		$game->step(Simulation::STEP);
		$settled = $messages($game, 'settled')[0] ?? null;
	}
	$expect(is_array($settled), 'Online shot never reported a settlement');
	$before = $game->simulation->exactHash();
	for($tick = 0; $tick < 120; $tick++) $game->step(Simulation::STEP);
	$expect($game->simulation->exactHash() === $before, 'Settled state drifted before room confirmation');
	$expect(is_string($settled['checkpoint'] ?? null), 'Acting player settlement omitted its checkpoint');
	$roomState = BinaryState::import($settled['checkpoint']);
	$expect(is_array($roomState), 'Settlement checkpoint did not decode to match state');
	$roomState['simulation']['balls'][0]['x'] += .000001;
	$roomState['simulation']['balls'][0]['previousX'] = $roomState['simulation']['balls'][0]['x'];
	$roomX = $roomState['simulation']['balls'][0]['x'];
	$roomHash = BinaryState::hash($roomState);
	$roomCheckpoint = BinaryState::export($roomState);

	$frame($game, ['network' => [[
		'type' => 'confirmed'
		, 'detail' => [
			'sequence' => $settled['sequence'], 'hash' => $roomHash
			, 'nextActor' => $settled['nextActor'], 'outcome' => $settled['outcome']
			, 'checkpoint' => $settled['checkpoint']
		]
	]]]);
	$fatal = $messages($game, 'fatal')[0] ?? null;
	$expect(($fatal['message'] ?? null) === 'Room confirmation hash differs from the locally reported settlement'
		&& $game->simulation->exactHash() === $before,
		'A checkpoint with the wrong hash changed local state or bypassed confirmation validation');

	$frame($game, ['network' => [[
		'type' => 'confirmed'
		, 'detail' => [
			'sequence' => $settled['sequence'], 'hash' => $roomHash
			, 'nextActor' => $settled['nextActor'], 'outcome' => $settled['outcome']
			, 'checkpoint' => $roomCheckpoint
		]
	]], 'networkAcks' => [$fatal['id']]]);
	$status = $game->status(1);
	$expect($status['network']['confirmed'] && $status['network']['problem'] === null
		&& abs($game->simulation->balls[0]->x - $roomX) < 1e-12
		&& count(array_filter($status['network']['outbox'], static fn(array $message): bool => $message['type'] === 'fatal')) === 0,
		'Verified room checkpoint did not recover a differing local settlement');
});

$test('binary checkpoints round-trip exact floats and casual eight-ball state', static function() use ($expect): void {
	$value = ['z' => -12,'a' => [1.25,true,null,'µm']];
	$encoded = BinaryState::encode($value);
	$expect(BinaryState::decode($encoded) == $value, 'Canonical binary state did not round-trip');
	$expect(BinaryState::hash($value) === hash('sha256', $encoded));

	$seed = str_repeat('17', 32);
	$simulation = new Simulation($seed);
	$match = new EightBallMatch(['@alice:example.org','@bob:example.org'], '@alice:example.org', $seed);
	$initial = $match->hash($simulation);
	$match->begin(1, '@alice:example.org', ['type' => 'shot'], $initial);
	$match->record([['type' => 'pocket','ball' => 9,'pocket' => 0]]);
	$result = $match->settleShot($simulation);
	$expect($result['nextActor'] === '@alice:example.org', 'Break pocket should retain the table');
	$expect($match->groups['@alice:example.org'] === 'stripe'
		&& $match->groups['@bob:example.org'] === 'solid', 'Legal break pocket did not assign groups');
	$match->begin(2, '@alice:example.org', ['type' => 'shot'], $result['hash']);
	$match->record([['type' => 'pocket','ball' => 11,'pocket' => 1]]);
	$result = $match->settleShot($simulation);
	$expect($result['nextActor'] === '@alice:example.org', 'Pocketing the assigned group did not retain the turn');
	$match->begin(3, '@alice:example.org', ['type' => 'shot'], $result['hash']);
	$match->record([['type' => 'pocket','ball' => 8,'pocket' => 2]]);
	$result = $match->settleShot($simulation);
	$expect($result['outcome']['winner'] === '@bob:example.org'
		&& $result['outcome']['reason'] === 'early-eight', 'Early eight did not lose the game');

	$checkpoint = $match->checkpoint($simulation);
	$restoredSimulation = new Simulation($seed);
	$restored = new EightBallMatch(['@alice:example.org','@bob:example.org'], '@alice:example.org', $seed);
	$state = BinaryState::import($checkpoint);
	$restored->restore($state, $restoredSimulation);
	$expect($restored->hash($restoredSimulation) === $match->hash($simulation), 'Checkpoint changed exact state');
});

$test('a scratched break leaves the table open', static function() use ($expect): void {
	$seed = str_repeat('2a', 32);
	$simulation = new Simulation($seed);
	$alice = '@alice:example.org';
	$bob = '@bob:example.org';
	$match = new EightBallMatch([$alice,$bob], $alice, $seed);
	$match->begin(1, $alice, ['type' => 'shot'], $match->hash($simulation));
	$match->record([
		['type' => 'pocket','ball' => 2,'pocket' => 0]
		, ['type' => 'pocket','ball' => 0,'pocket' => 0]
	]);
	$result = $match->settleShot($simulation);
	$expect($match->groups === [$alice => null,$bob => null] && $result['nextActor'] === $bob,
		'A scratched break assigned a group or retained the turn');
});

$test('cue stroke reaches the ball before launching the unchanged deterministic shot', static function() use ($expect): void {
	$game = new Game;
	$game->input(['actions' => ['start']], [], [], [], [0,0], 0, 1280, 720);
	$game->aim = .17;
	$game->power = .74;
	$game->top = -.28;
	$game->side = .36;
	$game->input(['actions' => ['shoot']], [], [], [], [0,0], 0, 1280, 720);
	$expect($game->stroking() && $game->simulation->shots === 0 && $game->guide() === []);

	for($step = 0; $step < 7; $step++) $game->step(Simulation::STEP);
	$backswing = $game->stroke();
	$expect($backswing !== null && $backswing['offset'] > .14, 'Cue did not draw back with shot power');
	$expect($game->simulation->shots === 0 && !$game->simulation->moving(), 'Ball moved during the backswing');

	for($step = 7; $step < 11; $step++) $game->step(Simulation::STEP);
	$expect($game->simulation->shots === 0, 'Shot launched before visual contact');
	$game->step(Simulation::STEP);
	$contact = $game->stroke();
	$expect($contact !== null && $contact['contacted'] && abs($contact['offset'] + .008) < 1e-10,
		'Cue tip did not coincide with the ball at launch');
	$expect($game->simulation->shots === 1 && $game->simulation->phase === 'moving');

	$expected = new Simulation;
	$expected->shoot(.17, .74, -.28, .36);
	$expect($game->simulation->hash() === $expected->hash(), 'Animation changed deterministic shot physics');

	for($step = 0; $step < 9; $step++) $game->step(Simulation::STEP);
	$expect(!$game->stroking(), 'Cue remained visible after its follow-through');
});

$test('placement preview and immediate-contact warning use simulation rules', static function() use ($expect): void {
	$game = new Simulation;
	$game->phase = 'placing';
	$expect($game->canPlaceCue(-.65, 0));
	$expect(!$game->canPlaceCue(Table::WIDTH / 2, 0));
	$expect(!$game->canPlaceCue(...Table::pockets()[1]));
	$game->phase = 'aim';
	$cue = $game->cue();
	$target = $game->balls[1];
	foreach($game->balls as $ball) $ball->active = in_array($ball->number, [0,1], true);
	$cue->x = $cue->y = 0;
	$target->x = Ball::RADIUS * 2 + .0005;
	$target->y = 0;
	$expect($game->contactWarning(0) === 1);
	$expect($game->contactWarning(M_PI) === null);
});

$test('an illegal online placement never reaches the room', static function() use ($expect): void {
	$game = new Game;
	$player = '@alice:example.org';
	$frame = static fn(Game $game, array $browser, array $controls = []) => $game->input($browser, $controls, [], [], [0,0], .1, 1280, 720);
	$frame($game, ['network' => [['type' => 'start','detail' => [
		'players' => [$player,'@bob:example.org'],'seed' => str_repeat('6f', 32),'breaker' => $player
		, 'localPlayer' => $player,'role' => 'player'
	]]]]);
	$ready = $game->status(1)['network']['outbox'][0];
	$frame($game, ['networkAcks' => [$ready['id']],'network' => [['type' => 'confirmed'
		, 'detail' => ['sequence' => 0,'hash' => $ready['hash'],'nextActor' => $player,'outcome' => null]]]]);
	$expect($game->controllable(), 'Online setup did not unlock the breaker');

	$game->simulation->phase = 'placing';
	$game->simulation->cue()->active = false;
	$blocker = $game->simulation->balls[1];
	$game->placement = [$blocker->x,$blocker->y];
	$frame($game, ['actions' => ['primary']]);
	$requests = static fn(Game $game): array => array_values(array_filter($game->status(1)['network']['outbox']
		, static fn(array $message): bool => $message['type'] === 'action-request'));
	$expect($requests($game) === [], 'An occupied placement was sent to the room');
	$expect(str_starts_with($game->toast()['text'] ?? '', 'Too close to the ' . $blocker->number), 'Rejected placement was not explained');

	// Keyboard movement is clamped to the cloth, so holding a direction can never push the preview off the table.
	for($index = 0; $index < 80; $index++) $frame($game, [], ['right' => 1]);
	[$limitX,$limitY] = Simulation::placementLimits();
	$expect(abs($game->placement[0]) <= $limitX + 1e-12 && abs($game->placement[1]) <= $limitY + 1e-12, 'Placement left the cloth');

	$game->placement = [-.65,0.0];
	$frame($game, ['actions' => ['primary']]);
	$expect(count($requests($game)) === 1, 'A legal placement was not requested');
});

$test('the pause menu captures gameplay input until it closes', static function() use ($expect): void {
	$game = new Game;
	$game->input(['actions' => ['free-play']], [], [], [], [0,0], 0, 1280, 720);
	$game->camera->animate(2);
	$aim = $game->aim;
	$game->input([], ['button9' => 1], [], [], [0,0], .1, 1280, 720);
	$expect($game->overlay === 'pause' && $game->menuItems()[0][0] === 'resume', 'Escape did not open the pause menu');
	$game->input([], ['button9' => 0,'right' => 1,'down' => 1], [], [], [0,0], .1, 1280, 720);
	$expect($game->aim === $aim && !$game->stroking(), 'Gameplay input leaked through the pause menu');
	$expect($game->menuSelection === 1, 'Menu navigation did not move the selection');
	$game->input(['actions' => ['shoot']], [], [], [], [0,0], .1, 1280, 720);
	$expect($game->overlay === 'controls' && !$game->stroking(), 'Confirming Controls did not open the controls sheet');
	$game->input([], ['button1' => 1], [], [], [0,0], .1, 1280, 720);
	$expect($game->overlay === 'pause', 'Back did not return from the controls sheet');
	$game->input([], ['button9' => 1], [], [], [0,0], .1, 1280, 720);
	$expect($game->overlay === null, 'Escape did not close the pause menu');

	$title = new Game;
	$title->input([], ['button9' => 1], [], [], [0,0], .1, 1280, 720);
	$expect($title->overlay === null && !$title->started, 'Escape on the title opened a hidden pause state');
});

$test('HUD text keeps Matrix names readable and falls back without replacement glyphs', static function() use ($expect): void {
	$expect(Game::playerName('@sean_morris:matrix.org') === 'sean_morris');
	$text = Hud::text();
	$expect($text->characters('é@_☃;') === ['e','@','_','·',';'], 'Unexpected HUD glyph fallback');
	$expect($text->measure('AV', 20) < $text->measure('A', 20) + $text->measure('V', 20), 'Kerning was not applied');
	$fitted = $text->fit('an_extremely_long_matrix_localpart', 15, 80);
	$expect(str_ends_with($fitted, '…') && $text->measure($fitted, 15) <= 80, 'Long names were not ellipsized to width');
	$expect(count($text->wrap(str_repeat('word ', 60), 15, 200, 2)) === 2, 'Toast text was not capped at two lines');
});

$test('the aiming preview reports the same first object ball the live shot hits', static function() use ($expect): void {
	foreach([0.0,.03,-.05] as $angle)
	{
		$game = new Simulation;
		$preview = $game->projectShot($angle, .7);
		$expect($preview['contact'] !== null, 'A shot into the rack predicted no contact');
		$game->shoot($angle, .7);
		$hit = null;
		for($tick = 0; $tick < 600 && $hit === null; $tick++)
		{
			$game->step(Simulation::STEP);
			foreach($game->balls as $ball) if($ball->number !== 0 && hypot($ball->vx, $ball->vy) > 1e-9) {$hit = $ball->number; break;}
		}
		$expect($hit === $preview['contact']['ball'], "Ghost ball at $angle predicted {$preview['contact']['ball']}, shot hit $hit");
	}
});

$test('the free-play sink board lists exactly the numbered balls off the table', static function() use ($expect): void {
	$game = new Game;
	$expect($game->pocketedBalls() === [], 'A fresh rack reported pocketed balls');
	foreach($game->simulation->balls as $ball) if(in_array($ball->number, [0,3,8,12], true)) $ball->active = false;
	$expect($game->pocketedBalls() === [3,8,12], 'The sink board must list pocketed object balls in order and never the cue ball');
});

$test('newly sunk balls flash on the sink board for two seconds in half-second steps', static function() use ($expect): void {
	$game = new Game;
	$game->input(['actions' => ['free-play']], [], [], [], [0,0], 0, 1280, 720);
	foreach($game->simulation->balls as $ball) if($ball->number === 2) $ball->active = false;
	$hud = (new ReflectionClass(Hud::class))->newInstanceWithoutConstructor();
	$flashes = new ReflectionMethod(Hud::class, 'sinkFlashes');
	$expect($flashes->invoke($hud, $game, 0.0) === [], 'A ball already down when the board appeared flashed');
	foreach($game->simulation->balls as $ball) if($ball->number === 5) $ball->active = false;
	foreach([[10.0,true],[10.6,false],[11.2,true],[11.7,false]] as [$time,$shown])
	$expect(($flashes->invoke($hud, $game, $time)[5] ?? null) === $shown, "Ball 5 at {$time}s should be " . ($shown ? 'lit' : 'empty'));
	$expect($flashes->invoke($hud, $game, 12.1) === [], 'The flash did not settle after two seconds');
	foreach($game->simulation->balls as $ball) if($ball->number === 5) $ball->active = true;
	$flashes->invoke($hud, $game, 13.0);
	foreach($game->simulation->balls as $ball) if($ball->number === 5) $ball->active = false;
	$expect(($flashes->invoke($hud, $game, 20.0)[5] ?? null) === true, 'A ball sunk again after an undo did not flash');
});

$test('HUD regions stay on screen and never overlap at common canvas sizes', static function() use ($expect): void {
	foreach([[1280,720,1],[2560,1440,2],[900,540,1],[780,1688,2],[1688,780,2],[3840,2160,1.5]] as [$width,$height,$dpr])
	{
		$game = new Game;
		$game->input(['actions' => ['free-play']], [], [], [], [0,0], 0, $width, $height);
		$regions = array_values(array_filter(Hud::regions($width, $height, $dpr, $game)
			, static fn(array $region): bool => ($region['type'] ?? '') !== 'panel'));
		foreach($regions as $index => $region)
		{
			[$x,$y,$w,$h] = $region['rect'];
			$expect($x >= 0 && $y >= 0 && $x + $w <= $width && $y + $h <= $height, "{$region['name']} left a {$width}x{$height} canvas");
			foreach(array_slice($regions, $index + 1) as $other)
			{
				[$ox,$oy,$ow,$oh] = $other['rect'];
				$expect($x + $w <= $ox || $ox + $ow <= $x || $y + $h <= $oy || $oy + $oh <= $y
					, "{$region['name']} overlaps {$other['name']} at {$width}x{$height}");
			}
		}
		// The free-play sink board sits on screen and clear of every control.
		[$bx,$by,$bw,$bh] = Hud::sinkBoardLayout($width, $height, $dpr)['rect'];
		$expect($bx >= 0 && $by >= 0 && $bx + $bw <= $width && $by + $bh <= $height, "Sink board left a {$width}x{$height} canvas");
		foreach($regions as $region)
		{
			[$x,$y,$w,$h] = $region['rect'];
			$expect($bx + $bw <= $x || $x + $w <= $bx || $by + $bh <= $y || $y + $h <= $by
				, "Sink board overlaps {$region['name']} at {$width}x{$height}");
		}
		// The title buttons sit in one row above the far rail, where the title camera frames the rack.
		$title = Hud::titleRegions($width, $height, $dpr);
		[$fx,$fy,$fw,$fh] = $title['free-play']['rect'];
		[$ox,$oy,$ow,$oh] = $title['online-play']['rect'];
		$expect($fy === $oy && $fx + $fw <= $ox && $fx >= 0 && $ox + $ow <= $width, "Title buttons are not one row at {$width}x{$height}");
		$expect($fy + $fh <= $height * .32 + 1e-9, "Title buttons cover the rack at {$width}x{$height}");
	}
});

$test('an opponent\'s cue considers the shot after four idle seconds and never strikes', static function() use ($expect): void {
	$alice = '@alice:example.org';
	$bob = '@bob:example.org';
	$frame = static fn(Game $game, array $browser, float $elapsed = 0) => $game->input(
		$browser, [], [], [], [0,0], $elapsed, 1280, 720
	);
	$waiting = static function(string $next) use ($alice, $bob, $frame): array {
		$game = new Game;
		$frame($game, ['network' => [[
			'type' => 'start'
			, 'detail' => ['players' => [$alice,$bob],'seed' => str_repeat('4d', 32),'breaker' => $next
				, 'localPlayer' => $bob,'role' => 'player']
		]]]);
		$ready = array_values(array_filter(
			$game->status(1)['network']['outbox'], static fn(array $message): bool => $message['type'] === 'ready'
		))[0];
		$frame($game, [
			'networkAcks' => [$ready['id']]
			, 'network' => [['type' => 'confirmed','detail' => [
				'sequence' => 0,'hash' => $ready['hash'],'nextActor' => $next,'outcome' => null
			]]]
		]);

		return [$game,$ready['hash']];
	};

	[$own] = $waiting($bob);
	for($tick = 0; $tick < 600; $tick++) $frame($own, [], 1 / 60);
	$expect($own->controllable() && ($own->idleCue()['kind'] ?? null) !== 'considering',
		'The local player\'s own cue started considering');

	[$game,$hash] = $waiting($alice);
	$state = $game->simulation->exactHash();
	$aim = $game->aim;
	$previous = null;
	$looks = [];
	for($tick = 0; $tick < 60 * 40; $tick++)
	{
		$frame($game, [], 1 / 60);
		$pose = $game->idleCue();
		if($game->clock < 4.0)
		{
			$expect($pose === null, 'The opponent\'s cue moved before four seconds of waiting');
			continue;
		}
		$expect(($pose['kind'] ?? null) === 'considering', 'The opponent\'s cue stayed still after four seconds of waiting');
		$expect($pose['offset'] > -.008 + .002 && $pose['offset'] < .2, 'A practice stroke reached the cue ball');
		if($previous !== null) $expect(abs(Game::wrapAngle($pose['aim'] - $previous['aim'])) < deg2rad(3)
			&& abs($pose['offset'] - $previous['offset']) < .02, 'The considering cue jumped between frames');
		$looks[$pose['look']] = $pose['aim'];
		$previous = $pose;
	}
	$expect(count($looks) >= 4 && max($looks) - min($looks) > deg2rad(1), 'The opponent\'s cue never changed its angle');
	$expect($game->simulation->exactHash() === $state && $game->aim === $aim && !$game->controllable(),
		'Considering changed the shared state or the local aim');

	$frame($game, ['network' => [['type' => 'action','detail' => [
		'sequence' => 1,'actor' => $alice,'pre_hash' => $hash
		, 'action' => ['type' => 'shot','aim_udeg' => 0,'power_bp' => 9000,'top_bp' => 0,'side_bp' => 0]
	]]]], 1 / 60);
	$expect($game->idleCue() === null && $game->stroking(), 'The real shot did not replace the considering cue');
});

$test('a dawdling player\'s cue dances about its aim until any input', static function() use ($expect): void {
	$game = new Game;
	$frame = static fn(Game $game, array $browser, float $elapsed = 0) => $game->input(
		$browser, [], [], [], [0,0], $elapsed, 1280, 720
	);
	$frame($game, ['actions' => ['free-play']]);
	$frame($game, ['values' => ['aim' => 37]]);
	$state = $game->simulation->exactHash();
	$aim = $game->aim;
	$guide = $game->guide();
	$previous = null;
	$moves = [];
	for($tick = 0; $tick < 60 * 30; $tick++)
	{
		$frame($game, [], 1 / 60);
		$pose = $game->idleCue();
		if($game->clock < 8.0)
		{
			$expect($pose === null, 'The cue danced before eight idle seconds');
			continue;
		}
		$expect(($pose['kind'] ?? null) === 'dawdling', 'An idle player\'s cue never danced');
		$expect(abs(Game::wrapAngle($pose['aim'] - $aim)) <= deg2rad(3) + 1e-9
			&& $pose['offset'] > -.008 + .002 && $pose['offset'] < .2, 'The dance strayed from the aim or reached the ball');
		if($previous !== null) $expect(abs(Game::wrapAngle($pose['aim'] - $previous['aim'])) < deg2rad(1)
			&& abs($pose['offset'] - $previous['offset']) < .02, 'The dancing cue jumped between frames');
		$moves[$pose['look']] = true;
		$previous = $pose;
	}
	$expect(count($moves) >= 3, 'The dance never moved on from its first move');
	$expect($game->simulation->exactHash() === $state && $game->aim === $aim && $game->guide() === $guide,
		'Dancing changed the table, the aim, or the guide');

	$frame($game, ['values' => ['power' => .4]], 1 / 60);
	$expect($game->idleCue() === null, 'Slider input did not stop the dance');
	for($tick = 0; $tick < 60 * 7; $tick++) $frame($game, [], 1 / 60);
	$expect($game->idleCue() === null, 'The dance resumed before another eight idle seconds');
	for($tick = 0; $tick < 62; $tick++) $frame($game, [], 1 / 60);
	$expect(($game->idleCue()['kind'] ?? null) === 'dawdling', 'The dance did not resume after another eight idle seconds');

	$frame($game, ['actions' => ['pause']], 1 / 60);
	$expect($game->overlay === 'pause' && $game->idleCue() === null, 'Pause left the idle dance visible under its overlay');
	$frame($game, ['actions' => ['resume']], 1 / 60);
	for($tick = 0; $tick < 60 * 7; $tick++) $frame($game, [], 1 / 60);
	$expect($game->idleCue() === null, 'The dance resumed too soon after closing Pause');
	for($tick = 0; $tick < 62; $tick++) $frame($game, [], 1 / 60);
	$expect(($game->idleCue()['kind'] ?? null) === 'dawdling', 'The dance did not resume after closing Pause');

	$frame($game, ['actions' => ['controls']], 1 / 60);
	$expect($game->overlay === 'controls' && $game->idleCue() === null,
		'Controls left the idle dance visible under its overlay');
});

require __DIR__ . '/camera.php';

$failures = 0;

foreach($tests as $name => $callback)
try
{
	$callback();
	echo "ok - $name\n";
}
catch(Throwable $error)
{
	$failures++;
	fwrite(STDERR, "not ok - $name: {$error->getMessage()}\n");
}

if($failures) exit(1);

echo count($tests) . " PHP Billiards physics tests passed.\n";

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Simulation;
use PhpBilliards\Physics\Table;

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
		$clearance = Table::pocketRadius($index) - Ball::RADIUS;
		$expect(abs(hypot($lastX - $pocketX, $lastY - $pocketY) - $clearance) < 1e-8,
			"Pocket $index guide did not end at the physical liner entry");
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
	$expect(abs($sideX) < 1e-12 && abs($sideY + Table::HEIGHT / 2 + Table::CUSHION_WIDTH) < 1e-12,
		'Logical side throat moved away from its rendered position');
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
			'Physical facing missed the rendered tabletop circle');
		$expect(abs($radiusX * $facingX + $radiusY * $facingY) < 1e-12,
			'Physical pocket facing is not tangent to the rendered tabletop circle');
	}
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

$test('crowded pocket entries cannot skip the circular drop boundary', static function() use ($expect): void {
	$game = new Simulation;
	foreach($game->balls as $ball) $ball->active = false;
	$states = [
		[0,-.055856477510466,.604425,3.0952740570054,3.7948976588758]
		, [1,-.0011575308680337,.604425,-.38633643755657,7.0377743110714]
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
	$ball->x = $ball->previousX = ($startX + $endX) / 2 + $normalX * (Ball::RADIUS + .004);
	$ball->y = $ball->previousY = ($startY + $endY) / 2 + $normalY * (Ball::RADIUS + .004);
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
	$ball->x = $ball->previousX = 0;
	$copy->x = $copy->previousX = 0;
	$ball->y = $ball->previousY = Table::HEIGHT / 2 - Ball::RADIUS - .005;
	$copy->y = $copy->previousY = $ball->y;
	$ball->vy = 5;
	$copy->vy = 5;
	$game->phase = 'moving';
	$replay->phase = 'moving';
	$game->consumeEvents();
	$replay->consumeEvents();
	$rejected = false;

	for($tick = 0; $tick < 30 && !$rejected; $tick++)
	{
		$game->step();
		$replay->step();

		foreach($game->consumeEvents() as $event)
		if($event['type'] === 'pocket-reject') $rejected = true;
	}

	$expect($rejected, 'Energetic ball could not bounce out of the pocket liner');
	$expect($ball->active && $ball->pocket === null && $ball->drop === 0.0,
		'Rejected ball did not return to the cloth');
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

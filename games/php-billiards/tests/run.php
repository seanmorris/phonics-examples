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

$test('standard rack is complete and separated', static function() use ($expect): void {
	$game = new Simulation;
	$expect(count($game->balls) === 16, 'Expected cue ball and fifteen object balls');
	$expect(count(array_filter($game->balls, static fn(Ball $ball): bool => $ball->active)) === 16);

	foreach($game->balls as $index => $a)
	foreach(array_slice($game->balls, $index + 1) as $b)
	$expect(hypot($a->x - $b->x, $a->y - $b->y) >= Ball::RADIUS * 1.99, 'Rack balls overlap');
});

$test('rack variation is imperceptible and deterministic', static function() use ($expect): void {
	$left = new Simulation;
	$right = new Simulation;
	$spacing = Ball::RADIUS * 2.015;
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
		$expect($dx <= .000061 && $dy <= .000061, 'Rack noise became visible');
		$varied = $varied || $dx > 1e-9 || $dy > 1e-9;
		$index++;
	}

	$expect($varied, 'Rack remained mathematically perfect');
	$expect($left->hash() === $right->hash(), 'Rack noise changed between identical games');
});

$test('pocket mouths use regulation American pool dimensions', static function() use ($expect): void {
	$expect(Table::CORNER_MOUTH >= 4.5 * .0254 && Table::CORNER_MOUTH <= 4.625 * .0254);
	$expect(Table::SIDE_MOUTH >= 5 * .0254 && Table::SIDE_MOUTH <= 5.25 * .0254);
	$expect(abs(Ball::RADIUS * 2 - .05715) < 1e-12, 'Ball diameter is not 2 1/4 inches');
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
	$cue->x = -Table::WIDTH / 2 + .03;
	$cue->y = -Table::HEIGHT / 2 + .03;
	$game->phase = 'moving';
	$game->step();
	$expect(!$cue->active && $game->scratched, 'Cue ball did not scratch');
	$expect($game->phase === 'placing', 'Scratch did not enter placement mode');
	$expect(!$game->placeCue(Table::WIDTH, 0), 'Placement accepted a point beyond the cloth');
	$expect($game->placeCue(-.65, 0), 'Legal cue placement failed');
	$expect($cue->active && $game->phase === 'aim' && !$game->scratched);
});

$test('pocket mouths capture balls before they can leave the table', static function() use ($expect): void {
	foreach([
		[-Table::WIDTH / 2 + Ball::RADIUS + .005,-Table::HEIGHT / 2 + .075,-2.0,0.0,0]
		, [.05,Table::HEIGHT / 2 - Ball::RADIUS - .005,0.0,2.0,4]
	] as [$x,$y,$vx,$vy,$pocketIndex])
	{
		$game = new Simulation;
		foreach($game->balls as $ball) $ball->active = false;
		$ball = $game->balls[1];
		$ball->active = true;
		$ball->x = $ball->previousX = $x;
		$ball->y = $ball->previousY = $y;
		$ball->vx = $vx;
		$ball->vy = $vy;
		$game->phase = 'moving';
		$game->consumeEvents();
		$game->step();
		$pocket = Table::pockets()[$pocketIndex];
		$events = $game->consumeEvents();

		$expect(!$ball->active, 'Ball escaped through an open pocket mouth');
		$expect(abs($ball->x - $pocket[0]) < 1e-9 && abs($ball->y - $pocket[1]) < 1e-9,
			'Captured ball was not retained at its pocket');
		$expect(count($events) === 1 && $events[0]['pocket'] === $pocketIndex,
			'Pocket event did not identify the capture point');
	}
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

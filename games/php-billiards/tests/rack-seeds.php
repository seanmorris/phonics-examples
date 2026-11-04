<?php
declare(strict_types=1);

use PhpBilliards\Game;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Simulation;

$test('free play records fresh rack seeds while undo and replay reuse their seed', static function() use ($expect): void {
	$calls = 0;
	$game = new Game(static function() use (&$calls): string { return hash('sha256', 'free-play-test-' . ++$calls); });
	$frame = static fn(string $action) => $game->input(['actions' => [$action]], [], [], [], [0,0], 0, 1280, 720);
	$expect($calls === 0 && $game->simulation->snapshot()['rackSeed'] === null, 'Title screen consumed a free-play seed');
	$frame('start');
	$initial = $game->simulation->snapshot();
	$expect($calls === 1 && $initial['rackSeed'] === hash('sha256', 'free-play-test-1'));
	$game->simulation->shoot(.01, .8);
	for($i = 0; $i < 180; $i++) $game->simulation->step();
	$shot = $game->simulation->exactHash();
	$expect($calls === 1, 'Simulation requested entropy during a shot');
	$expect($game->simulation->resetShot() && $game->simulation->snapshot() === $initial, 'Undo changed the rack seed or poses');
	$game->simulation->shoot(.01, .8);
	for($i = 0; $i < 180; $i++) $game->simulation->step();
	$expect($game->simulation->exactHash() === $shot, 'Recorded free-play seed did not replay exactly');
	$frame('rack');
	$next = $game->simulation->snapshot();
	$expect($calls === 2 && $next['rackSeed'] !== $initial['rackSeed'] && $next['balls'] !== $initial['balls'], 'New Rack reused its previous layout');
	$expect($next['rackIndex'] === 0 && $next['shots'] === 0);
	$frame('main-menu'); $frame('start');
	$expect($calls === 3, 'Restarting Free Play did not generate a fresh rack');
});

$test('online startup does not consult the local rack seed factory', static function() use ($expect): void {
	$game = new Game(static fn(): string => throw new RuntimeException('Online game requested local entropy'));
	$seed = str_repeat('ad', 32);
	$game->input(['network' => [['type' => 'start','detail' => [
		'players' => ['@alice:example.org','@bob:example.org'],'seed' => $seed,'breaker' => '@alice:example.org'
		,'localPlayer' => '@alice:example.org','role' => 'player'
	]]]], [], [], [], [0,0], 0, 1280, 720);
	$expect($game->simulation->snapshot()['rackSeed'] === $seed, 'Online startup discarded its agreed seed');
});

$test('rack placement shrinks the whole perturbation when any pair would overlap', static function() use ($expect): void {
	$spacing = 2 * Ball::RADIUS + .00004;
	$layout = [[0.0,0.0,.00003,0.0],[$spacing,0.0,-.00003,0.0],[.5,0.0,.00003,.00002]];
	$positions = (new ReflectionMethod(Simulation::class, 'rackPositions'))->invoke(null, $layout);
	foreach($positions as $i => [$x,$y]) foreach(array_slice($positions, $i + 1) as [$bx,$by])
	$expect(hypot($x - $bx, $y - $by) >= 2 * Ball::RADIUS + .000005, 'Candidate rack still overlaps');
	$scale = $positions[0][0] / .00003;
	$expect($scale > 0 && $scale < 1 && abs(($positions[2][0] - .5) / .00003 - $scale) < 1e-10
		&& abs($positions[2][1] / .00002 - $scale) < 1e-10, 'Only the colliding ball was moved');
});

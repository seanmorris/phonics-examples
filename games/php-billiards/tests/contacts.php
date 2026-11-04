<?php
declare(strict_types=1);

use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\ContactModel;
use PhpBilliards\Physics\Simulation;

$contactEnergy = static function(array $balls): float {
	$total = ContactModel::elasticEnergy($balls);
	foreach($balls as $b) if($b->active)
	$total += Ball::MASS * (.5 * ($b->vx ** 2 + $b->vy ** 2 + $b->vDrop ** 2)
		+ .2 * Ball::RADIUS ** 2 * ($b->spinX ** 2 + $b->spinY ** 2 + $b->spinZ ** 2)
		- ContactModel::GRAVITY * $b->drop);
	return $total;
};

$test('Hertz contacts have no force across a gap and never attract', static function() use ($expect): void {
	$expect(ContactModel::response(-.00004, 8.75) === [0.0,0.0]);
	$expect(ContactModel::response(0, 8.75) === [0.0,0.0]);
	$expect(ContactModel::response(.0001, -100)[0] === 0.0, 'Separating balls attracted each other');
	$a = new Ball(0, 0, 0); $b = new Ball(1, 2 * Ball::RADIUS, 0);
	$c = new Ball(2, 4 * Ball::RADIUS + .00004, 0);
	$a->vx = 1;
	ContactModel::advance([$a,$b,$c], ContactModel::MAX_STEP);
	$expect($b->vx > 0 && $b->vx < .1, 'First contact was not a gradual compression');
	$expect($c->vx === 0.0 && $c->x === 4 * Ball::RADIUS + .00004, 'Force crossed the untouched rack gap');
	for($i = 0; $i < 200; $i++) ContactModel::advance([$a,$b,$c], ContactModel::MAX_STEP);
	$expect($c->vx > .5, 'Contact failed to propagate after the gap closed');
});

$test('isolated contacts match restitution, conserve momentum and converge with timestep', static function() use ($expect, $contactEnergy): void {
	foreach([.01,.1,.5,1.0,4.0,8.75,17.5] as $speed)
	{
		$answers = [];
		foreach([ContactModel::MAX_STEP,ContactModel::MAX_STEP / 2] as $dt)
		{
			$a = new Ball(0, 0, 0); $b = new Ball(1, 2 * Ball::RADIUS, 0); $a->vx = $speed;
			$initial = $contactEnergy([$a,$b]);
			$peak = 0.0;
			for($i = 0; $i < 1500; $i++)
			{
				ContactModel::advance([$a,$b], $dt);
				$peak = max($peak, ContactModel::elasticEnergy([$a,$b]));
				$expect($contactEnergy([$a,$b]) <= $initial * 1.00001, "Contact at $speed m/s added energy");
				$expect(abs($a->vx + $b->vx - $speed) < 1e-11, 'Normal momentum changed');
				if($b->x - $a->x >= 2 * Ball::RADIUS) break;
			}
			$e = ($b->vx - $a->vx) / $speed;
			$expect($i < 1500 && $peak > 0, 'Impact never compressed and released');
			$expect(abs($e - .94) < .002, "Restitution at $speed m/s was $e");
			$answers[] = $e;
		}
		$expect(abs($answers[0] - $answers[1]) < .0001, "Contact at $speed m/s did not converge");
	}
});

$test('oblique spinning contacts dissipate energy and preserve horizontal momentum', static function() use ($expect, $contactEnergy): void {
	$a = new Ball(0, 0, 0); $b = new Ball(1, 2 * Ball::RADIUS, 0);
	$a->vx = 4; $a->vy = 1; $a->spinX = 30; $a->spinY = 120; $a->spinZ = -40;
	$initial = $contactEnergy([$a,$b]);
	for($i = 0; $i < 200; $i++)
	{
		ContactModel::advance([$a,$b], ContactModel::MAX_STEP);
		$expect($contactEnergy([$a,$b]) <= $initial * 1.00001, 'Tangential contact created energy');
		$expect(abs($a->vx + $b->vx - 4) < 1e-11 && abs($a->vy + $b->vy - 1) < 1e-11, 'Tangential momentum changed');
	}
	$expect(abs($b->spinY) > 1 && abs($b->spinZ) > .1, 'Surface friction transferred no spin');
	$expect($a->drop === 0.0 && $b->drop === 0.0, 'Cloth contacts introduced ball jumping');
});

$test('touching chains and triangles are mirror symmetric and timestep stable', static function() use ($expect, $contactEnergy): void {
	foreach([[[0,0],[2 * Ball::RADIUS,0],[4 * Ball::RADIUS,0]],
		[[0,0],[sqrt(3) * Ball::RADIUS,-Ball::RADIUS],[sqrt(3) * Ball::RADIUS,Ball::RADIUS]]] as $positions)
	{
		$results = [];
		foreach([[1,1],[-1,1],[1,2]] as [$mirror,$refinement])
		{
			$balls = [];
			foreach($positions as $n => [$x,$y]) $balls[] = new Ball($n, $x * $mirror, $y);
			$balls[0]->vx = 4 * $mirror;
			$initial = $contactEnergy($balls);
			for($i = 0; $i < 240 * $refinement; $i++)
			{
				ContactModel::advance($balls, ContactModel::MAX_STEP / $refinement);
				$expect($contactEnergy($balls) <= $initial * 1.00002, 'Multiple contacts created energy');
			}
			$results[] = array_map(static fn(Ball $b): array => [$b->x * $mirror,$b->y,$b->vx * $mirror,$b->vy], $balls);
		}
		foreach($results[0] as $i => $row) foreach($row as $k => $value)
		{
			$expect(abs($value - $results[1][$i][$k]) < 1e-12, 'Contact geometry lost mirror symmetry');
			$expect(abs($value - $results[2][$i][$k]) < .001, 'Crowded contacts did not converge');
		}
	}
});

$test('compressed checkpoints resume exactly and settlement releases stored elastic energy', static function() use ($expect): void {
	$a = new Simulation;
	$a->balls = [new Ball(0, 0, 0),new Ball(1, 2 * Ball::RADIUS - .00005, 0)];
	$a->phase = 'moving';
	$expect($a->moving(), 'A compressed but stationary contact was treated as settled');
	$b = new Simulation; $b->restore($a->snapshot());
	for($i = 0; $i < 240 && $a->moving(); $i++)
	{
		$a->step(); $b->step();
		$expect($a->exactHash() === $b->exactHash(), 'A mid-contact restore changed deterministic state');
	}
	$expect(!$a->moving() && $a->phase === 'aim', 'Elastic contact did not settle');
	$expect(ContactModel::elasticEnergy($a->balls) === 0.0, 'Sleeping balls retained compression');
	$state = $a->snapshot(); $a->step();
	$expect($a->snapshot() === $state, 'Settled contacts continued dirtying physical state');
});

$test('an isolated impact emits one sound through continuous compression', static function() use ($expect): void {
	$game = new Simulation;
	$game->balls = [new Ball(0, 0, 0),new Ball(1, 2 * Ball::RADIUS, 0)];
	$game->balls[0]->vx = 4; $game->phase = 'moving'; $game->consumeEvents();
	$game->step(.001);
	$impacts = array_values(array_filter($game->consumeEvents(), static fn(array $e): bool => $e['type'] === 'impact'));
	$expect(count($impacts) === 1, 'One compression emitted multiple sounds');
	$expect(abs($impacts[0]['strength'] - 4 / Simulation::MAX_SPEED) < 1e-12, 'Impact strength normalization changed');
});

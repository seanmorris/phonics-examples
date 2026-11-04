<?php
declare(strict_types=1);

use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\ContactModel;
use PhpBilliards\Physics\Simulation;
use PhpBilliards\Physics\Table;

$test('falling balls collide in three dimensions without transferring force across depth gaps', static function() use ($expect, $contactEnergy): void {
	$a = new Ball(0, 0, 0); $b = new Ball(1, 0, 0);
	$a->pocket = $b->pocket = 1;
	$a->drop = .001; $b->drop = .065;
	$a->vDrop = 2;
	$initial = $contactEnergy([$a,$b]);
	ContactModel::advance([$a,$b], ContactModel::MAX_STEP);
	$expect(abs($b->vDrop - ContactModel::GRAVITY * ContactModel::MAX_STEP) < 1e-12, 'Projected XY overlap caused a false collision');
	for($i = 1; $i < 1500; $i++)
	{
		ContactModel::advance([$a,$b], ContactModel::MAX_STEP);
		$expect($contactEnergy([$a,$b]) <= $initial + 1e-6, 'Falling contact added mechanical energy');
	}
	$expect($b->vDrop > 1.8, 'The lower ball received no impulse');
	$expect($b->drop - $a->drop >= 2 * Ball::RADIUS, 'The balls passed through each other');
});

$test('two and three balls stay solid through every pocket until sinking', static function() use ($expect): void {
	foreach(Table::pockets() as $index => [$px,$py])
	foreach([2,3] as $count)
	{
		$game = new Simulation;
		[$ox,$oy] = Table::pocketOutward($index);
		$game->balls = [];
		for($n = 0; $n < $count; $n++)
		{
			$distance = [0,.04,.101][$n];
			$b = new Ball($n, $px - $ox * $distance, $py - $oy * $distance);
			if($n < 2) $b->pocket = $index;
			$b->drop = $n === 0 ? .045 : 0.0;
			$b->vx = $ox * [.1,1,2][$n]; $b->vy = $oy * [.1,1,2][$n];
			$game->balls[] = $b;
		}
		$game->phase = 'moving'; $game->consumeEvents();
		$events = []; $maxCompression = 0.0;
		for($tick = 0; $tick < 2400 && $game->moving(); $tick++)
		{
			$game->step(1 / 240);
			array_push($events, ...$game->consumeEvents());
			foreach($game->balls as $i => $a) if($a->active)
			foreach(array_slice($game->balls, $i + 1) as $b) if($b->active)
			{
				$distance = sqrt(($a->x - $b->x) ** 2 + ($a->y - $b->y) ** 2 + ($a->drop - $b->drop) ** 2);
				$maxCompression = max($maxCompression, 2 * Ball::RADIUS - $distance);
				$expect($distance > 2 * Ball::RADIUS - .002, "Persistent overlap in pocket $index with $count balls");
			}
			foreach($game->balls as $b) $expect(is_finite($b->x + $b->y + $b->drop + $b->vx + $b->vy + $b->vDrop), 'Pocket contact became nonfinite');
		}
		$expect($tick < 2400, "Crowded pocket $index with $count balls never settled");
		$expect($game->scratched && !$game->cue()->active, 'A sinking cue did not record a scratch');
		$sunk = array_values(array_filter($events, static fn(array $e): bool => $e['type'] === 'pocket'));
		$expect(count($sunk) >= 2, "Pocket $index failed to clear its first two balls");
		$expect($sunk[0]['ball'] === 0, 'The lower ball did not sink first');
	}
});

$test('pocket sink events are ordered by crossing time then number', static function() use ($expect): void {
	$game = new Simulation; $game->balls = [];
	foreach([[4,0,.114],[2,1,.113],[1,2,.114]] as [$number,$pocket,$drop])
	{
		[$x,$y] = Table::pockets()[$pocket];
		$b = new Ball($number, $x, $y); $b->pocket = $pocket; $b->drop = $drop; $b->vDrop = 1;
		$game->balls[] = $b;
	}
	$game->phase = 'moving'; $game->consumeEvents(); $game->step();
	$sunk = array_values(array_filter($game->consumeEvents(), static fn(array $e): bool => $e['type'] === 'pocket'));
	$expect(array_column($sunk, 'ball') === [1,4,2], 'Sinks followed storage order instead of crossing time');
});

$test('a crowded pocket can knock a ball back onto the cloth without a false scratch', static function() use ($expect): void {
	foreach(Table::pockets() as $index => [$px,$py])
	{
		[$ox,$oy] = Table::pocketOutward($index);
		$game = new Simulation; $game->balls = [];
		foreach([[0,.05,.001,0,0],[1,.02,.05,1,-1]] as [$number,$distance,$drop,$speed,$fall])
		{
			$b = new Ball($number, $px - $ox * $distance, $py - $oy * $distance);
			$b->pocket = $index; $b->drop = $drop;
			$b->vx = -$ox * $speed; $b->vy = -$oy * $speed; $b->vDrop = $fall;
			$game->balls[] = $b;
		}
		$game->phase = 'moving'; $game->consumeEvents(); $rejected = false;
		for($i = 0; $i < 1600 && $game->moving(); $i++)
		{
			$game->step(1 / 240);
			foreach($game->consumeEvents() as $event)
			if($event['type'] === 'pocket-reject' && $event['ball'] === 0) $rejected = true;
		}
		$expect($i < 1600 && $rejected && $game->cue()->active && !$game->scratched, "Crowded pocket $index could not return the upper ball");
		$expect($game->cue()->pocket === null && $game->cue()->drop === 0.0, 'Rejected ball retained pocket depth');
	}
});

$test('resting rim contact does not multiply spin loss with integration frequency', static function() use ($expect): void {
	$results = [];
	foreach([1,4,16] as $subdivision)
	{
		$game = new Simulation;
		[$px,$py] = Table::pockets()[1];
		$b = new Ball(0, $px, $py + Table::THROAT_RADIUS - .001);
		$b->pocket = 1; $b->spinY = 10;
		$game->balls = [$b]; $game->phase = 'moving';
		for($i = 0; $i < $subdivision; $i++) $game->step(.004 / $subdivision);
		$results[] = $b->spinY;
	}
	$expect(max($results) - min($results) < 1e-10 && min($results) > 9.9, 'Microsteps multiplied a resting pocket spin loss');
});

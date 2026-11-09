<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use Phonics\Input\Source;
use PhlappyPhant\Barrel;
use PhlappyPhant\Input\InputSettings;
use PhlappyPhant\Input\PhantActions;
use PhlappyPhant\Random;
use PhlappyPhant\Simulation;

$checks = 0;

function check(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

$random = new Random(1);
check($random->next() === 16807, 'first Park-Miller value');
check($random->next() === 282475249, 'second Park-Miller value');
check($random->next() === 1622650073, 'third Park-Miller value');

$idle = new Simulation(1);
for($i = 0; $i < 120; $i++) $idle->step();
check($idle->phase === 'ready', 'the phant waits for the first flap');
check(abs($idle->y - Simulation::PHANT_START_Y) <= 6.0001, 'the idle bob stays within six units of the start');
check($idle->scroll > 0, 'the ground scrolls while waiting');
check($idle->score === 0 && $idle->barrels[0]->x === (float)Simulation::FIRST_BARREL_X, 'barrels wait too');

$idle->step(true);
check($idle->phase === 'playing', 'the first flap starts the run');
check(abs($idle->vy - (Simulation::FLAP_VY + Simulation::GRAVITY * Simulation::STEP)) < 1e-9, 'a flap sets the rise before gravity applies');
check($idle->consumeEvents() === ['flap'], 'the first flap is reported once');
check($idle->consumeEvents() === [], 'events are cleared once consumed');

$falling = new Simulation(1);
$falling->step(true);
$steps = 0;
while($falling->phase === 'playing' && $steps < 600) { $falling->step(); $steps++; }
check($falling->phase === 'dead', 'a phant that never flaps again hits the ground');
check(in_array('bonk', $falling->events, true), 'hitting the ground raises the bonk');
check($falling->vy <= 0, 'death stops the fall velocity carrying on');
$settle = 0;
while(!$falling->finished && $settle < 600) { $falling->step(); $settle++; }
check($falling->finished, 'the dead phant settles');
check($settle * Simulation::STEP >= Simulation::DEAD_SETTLE - 1e-9, 'the run ends no sooner than the settle time');
check(abs($falling->y + Simulation::PHANT_H - Simulation::HITBOX_INSET_Y - Simulation::GROUND_Y) < 1e-9, 'the dead phant rests its feet on the ground');

$clamp = new Simulation(2);
$clamp->step(true);
for($i = 0; $i < 60; $i++) if($clamp->phase === 'playing') $clamp->step();
check($clamp->vy <= Simulation::MAX_FALL, 'falling speed is clamped');

$a = new Simulation(7);
$b = new Simulation(7);
$c = new Simulation(8);
$gaps = static fn(Simulation $game): array => array_map(static fn(Barrel $barrel): float => $barrel->gapTop, $game->barrels);
check($gaps($a) === $gaps($b), 'equal seeds place equal gaps');
check($gaps($a) !== $gaps($c), 'different seeds place different gaps');
check(count($a->barrels) >= 4, 'barrels are placed up to the lookahead');

foreach($a->barrels as $barrel)
{
	check($barrel->gapTop >= Simulation::GAP_MARGIN, 'gap keeps clear of the ceiling');
	check($barrel->gapTop + $barrel->gap <= Simulation::GROUND_Y - Simulation::GAP_MARGIN, 'gap keeps clear of the ground');
}

$scoring = new Simulation(3);
$scoring->step(true);
$scoring->barrels[0]->x = -Simulation::BARREL_W + 1;
$scoring->barrels[0]->gapTop = 0;
$scoring->barrels[0]->gap = Simulation::GROUND_Y;
$scoring->step();
check($scoring->score === 1 && in_array('score', $scoring->events, true), 'passing a barrel scores once');
$scoring->step();
check($scoring->score === 1, 'a passed barrel does not score again');
check(Simulation::paletteFor(9) === 0 && Simulation::paletteFor(10) === 1 && Simulation::paletteFor(20) === 0, 'day and night alternate every ten barrels');
check(Simulation::speedFor(0) === 120.0 && Simulation::speedFor(100) === 200.0, 'speed ramps to its cap');
check(Simulation::gapFor(0) === 140.0 && Simulation::gapFor(50) === 100.0, 'gaps shrink to their floor');

$wall = new Simulation(4);
$wall->step(true);
$wall->barrels = [new Barrel(0, 0, 10)];
$wall->step();
check($wall->phase === 'dead', 'a stack across the phant kills it');

$through = new Simulation(4);
$through->step(true);
[$left, $top, $width, $height] = $through->hitbox();
$through->barrels = [new Barrel(0, $top - 40, $height + 80)];
$through->step();
check($through->phase === 'playing', 'a gap around the hitbox lets the phant through');
check($through->hitbox()[2] === 48.0 && $through->hitbox()[3] === 30.0, 'the hitbox is inset from the sprite');

$settings = new InputSettings;
$flap = $settings->bindings->sources(PhantActions::FLAP);
check(count(array_filter($flap, fn(Source $source): bool => $source->device() === Source::KEYBOARD)) === 3, 'flap has three keyboard defaults');
check(count(array_filter($flap, fn(Source $source): bool => $source->device() === Source::CONTROLLER)) === 2, 'flap has two controller defaults');
check(PhantActions::FLAP > 0xFFFF, 'the flap action is above the engine-reserved range');

$broken = new InputSettings(static function(): string { throw new RuntimeException('no storage'); });
check($broken->sessionOnly, 'a failing reader leaves the profile session-only');
$saved = null;
$writable = new InputSettings(static fn(): string => '', static function(string $json) use (&$saved): bool { $saved = $json; return true; });
$writable->save();
check(is_string($saved) && json_decode($saved, true)['version'] === 1, 'saving writes a versioned profile');
$restored = new InputSettings(static fn(): string => $saved);
check(!$restored->sessionOnly && count($restored->bindings->sources(PhantActions::FLAP)) === 5, 'a saved profile restores');

echo "Phlappy Phant: $checks checks passed.\n";

<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use Phonics\Input\Source;
use PhpBuster\Actor;
use PhpBuster\Input\BusterActions;
use PhpBuster\Input\InputSettings;
use PhpBuster\Random;
use PhpBuster\Simulation;

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

$motion = new Simulation(1);
$motion->step(1, 1);
check(abs($motion->player->x - (130 + 4 / sqrt(2))) < 1e-9, 'diagonal keyboard motion is normalized');
check(abs($motion->player->y - (400 + 4 / sqrt(2))) < 1e-9, 'vertical keyboard motion uses seconds');
$motion->step(pointerX: 10, pointerY: 10);

check(
	$motion->player->x === 0.0 && $motion->player->y === 0.0
	, 'pointer targets clamp the complete craft inside the playfield'
);

$motion->step(fire: true);
check($motion->shots === 1, 'held fire emits the first shot immediately');

check(
	count(array_filter($motion->actors, fn(Actor $actor): bool => $actor->type === 'missile')) === 1
	, 'shot creates a missile'
);

$wave = new Simulation(1);
$wave->step();
$wave->step();
check(count($wave->actors) === 1, 'first fighter waits for the original scheduled time');
$wave->step();

check(
	count($wave->actors) === 2 && $wave->actors[1]->type === 'midi'
	, 'first fighter appears at the original wave tick'
);

$damage = new Simulation(2);
$damage->actors[] = new Actor(999, 'rock', $damage->player->x, $damage->player->y, 50, 50, team: 1, hits: 100);
$damage->step();
check($damage->shields === 4, 'player collision removes one shield');
check(abs($damage->hitGrace - Simulation::HIT_GRACE) < 1e-9, 'player collision starts the bounded hit grace period');
$damage->step();
check($damage->shields === 4, 'overlap cannot drain shields again during hit grace');

$accuracy = new Simulation(3);
$accuracy->actors[] = new Actor(1000, 'maxi', 0, 100, 62, 54, team: 1, hits: 1, strength: 50);
$accuracy->actors[] = new Actor(1001, 'missile', 0, 100, 36, 18, team: 0);
$accuracy->step();
check($accuracy->phase === 'intermission', 'destroying the heavy fighter completes the stage');
check($accuracy->accuracy === 0 && $accuracy->bonus === 0, 'zero recorded shots produce a safe zero accuracy bonus');

$settings = new InputSettings;
$fire = $settings->bindings->sources(BusterActions::FIRE);

check(
	count(array_filter($fire, fn(Source $source): bool => $source->device() === Source::KEYBOARD)) === 2
	, 'fire has keyboard and mouse defaults'
);

check(
	count(array_filter($fire, fn(Source $source): bool => $source->device() === Source::CONTROLLER)) === 2
	, 'fire has controller defaults'
);

check(
	BusterActions::MOVE_X > 0xFFFF && BusterActions::MOVE_Y > 0xFFFF && BusterActions::FIRE > 0xFFFF
	, 'game actions are above the engine-reserved range'
);

echo "PHP Buster: $checks checks passed.\n";

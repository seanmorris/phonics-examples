<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use Phonics\Input\Source;
use PhpSlalom\Input\InputSettings;
use PhpSlalom\Input\SlalomActions;
use PhpSlalom\Obstacle;
use PhpSlalom\Random;
use PhpSlalom\Replay;
use PhpSlalom\Simulation;

$checks = 0;

function check(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

$random = new Random(1);
check($random->next() === 24, 'first Java-compatible random value');
check($random->next() === 1027, 'second Java-compatible random value');
check($random->next() === 6761, 'third Java-compatible random value');
$maximum = new Random(2147483647);
check($maximum->next() === 32743, 'generator remains exact at the portable seed limit');

$game = new Simulation(1);
$game->step(true, false);
check(abs($game->velocity - .1) < 1e-9, 'left accelerates toward positive course motion');
$game->step(true, true);
check(abs($game->velocity - .1) < 1e-9, 'opposing steering cancels without drag');
$game->step(false, false);
check(abs($game->velocity - .075) < 1e-9, 'neutral steering applies original drag');

$spawn = new Simulation(1);

for($i = 0; $i < 3; $i++) $spawn->step(false, false);
check(count($spawn->obstacles) === 0, 'stage one waits four ticks before generating');
$spawn->step(false, false);
check(count($spawn->obstacles) === 1, 'stage one generates on its fourth tick');
check(abs($spawn->obstacles[0]->x - -13.0) < 1e-9, 'course center follows original random order');
check($spawn->obstacles[0]->color === [240 / 255, 210 / 255, 100 / 255], 'course color follows original random order');

$transition = new Simulation(9);
$transition->score = 8000;
$transition->step(false, false);
check($transition->stage === 1, 'score threshold advances the stage before generation');
check($transition->sky() === [7,160,252], 'new stage sky begins its 32-tick blend');
check($transition->ground() === [64,180,64], 'ground changes immediately at a stage boundary');

$impact = new Simulation(2);
$impact->obstacles[] = new Obstacle(0, .6, 1.6, [1,1,1]);
$impact->step(false, false);
check($impact->impact && $impact->crashed && $impact->score === 20, 'impact latches after the scoring tick');
$frozen = [$impact->score, count($impact->obstacles), $impact->ticks];

for($i = 0; $i < 19; $i++) $impact->step(true, false);
check(!$impact->finished, 'crash animation retains twenty complete ticks');
$impact->step(true, false);
check($impact->finished, 'crash animation terminates after twenty ticks');
check([$impact->score, count($impact->obstacles), $impact->ticks] === $frozen, 'course and score freeze after impact');

$inputs = [0,1,2,3,3,2,1,0,1];
$bytes = Replay::encode(3, 0x12345678, Simulation::START_SCORES[3], $inputs);
$decoded = Replay::decode($bytes);
check($decoded['stage'] === 3 && $decoded['seed'] === 0x12345678, 'replay header round trip');
check($decoded['inputs'] === $inputs, 'two-bit replay input round trip');

try
{
	new Random(-1);
	check(false, 'nonportable random seed rejected');
} catch(InvalidArgumentException)
{
	check(true, 'nonportable random seed rejected');
}

try
{
	Replay::decode(substr_replace($bytes, chr(ord($bytes[10]) ^ 1), 10, 1));
	check(false, 'corrupt replay rejected');
} catch(UnexpectedValueException)
{
	check(true, 'corrupt replay rejected');
}

$settings = new InputSettings;
$left = $settings->bindings->sources(SlalomActions::LEFT);
$right = $settings->bindings->sources(SlalomActions::RIGHT);

check(
	count(array_filter($left, fn(Source $source): bool => $source->device() === Source::KEYBOARD)) === 4
	, 'left keyboard and mouse defaults'
);

check(
	count(array_filter($right, fn(Source $source): bool => $source->device() === Source::CONTROLLER)) === 2
	, 'right controller defaults'
);

check(
	SlalomActions::LEFT > 0xFFFF && SlalomActions::RIGHT > 0xFFFF
	, 'game actions are above the engine-reserved range'
);

echo "PHP Slalom: $checks checks passed.\n";

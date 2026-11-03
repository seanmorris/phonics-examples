<?php
declare(strict_types=1);
namespace PhpNoid\Benchmarks;

use Phonics\Package\Context;
use Phonics\Tiled\Loader;
use PhpNoid\Module;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Projectile;
use PhpNoid\System\BallSystem;
use PhpNoid\System\ProjectileSystem;

/**
 * Create a deterministic collision workload that also runs against pre-cache source trees.
 * @param string $root Source tree containing core and PHP-Noid.
 * @param int $ballCount Active player balls.
 * @param int $shotCount Active laser shots.
 * @param string $mode Empty-space casts, wall bounces, moving targets, or destruction.
 * @return array Work callback and its owning field, closed after measurement.
 */
function workload(string $root, int $ballCount, int $shotCount, string $mode): array
{
	$context = new Context;
	Module::factories($context);
	$field = new Playfield($context);
	$field->stage((new Loader)->map($root . '/packages/php-noid/worlds/stage-01.tmj'), 0);
	$r = $field->registry();

	// Freeze the original 40-brick workload independently of subsequent campaign design changes.
	foreach($r->query(Brick::class) as $id) $r->destroy($id);

	for($row = 0; $row < 4; $row++)
	for($column = 0; $column < 10; $column++)
	$r->create(new Brick(24 + $column * 59, 70 + $row * 27, 54, 20));

	foreach($r->query(Ball::class) as $id) $r->destroy($id);
	$balls = $shots = $ships = [];

	for($i = 0; $i < $ballCount; $i++)
	{
		$ball = new Ball(320 + $i * 20, 400);
		$ball->attached = false;
		$r->create($ball);
		$balls[] = $ball;
	}

	if($mode !== 'impacts')
	for($i = 0; $i < $shotCount; $i++)
	{
		$shot = new Projectile(290 + $i * 12, 400);
		$r->create($shot);
		$shots[] = $shot;
	}

	if($mode === 'moving')
	for($i = 0; $i < 3; $i++)
	{
		$ship = new Enemy(80 + $i * 200, 240, 'floater');
		$r->create($ship);
		$ships[] = $ship;
	}

	$paddle = $r->get($field->paddle, Paddle::class);
	$ballSystem = new BallSystem($field);
	$shotSystem = new ProjectileSystem($field);
	$tick = 0;

	/**
	 * Reset deterministic positions and measure all synchronization, casts, and impact work.
	 * @return int Checksum of surviving bricks, balls, and shots; 40 bricks always remain.
	 */
	$run = static function() use ($field, $r, $balls, $shots, $ships, $paddle, $ballSystem, $shotSystem, $mode
		, $shotCount, &$tick
	): int {
		$field->state->events = [];
		$field->state->score = 0;

		foreach($balls as $i => $ball)
		{
			$ball->x = $mode === 'bounces' ? 20 : ($mode === 'impacts' ? 90 + $i * 60 : 320 + $i * 20);
			$ball->y = in_array($mode, ['bounces', 'impacts'], true) ? 350 : 400;
			$ball->vx = $mode === 'bounces' ? -5000 : ($mode === 'impacts' ? 0 : 180);
			$ball->vy = $mode === 'bounces' ? 0 : ($mode === 'impacts' ? -4000 : -300);
		}

		foreach($shots as $shot) $shot->y = 400;

		foreach($ships as $i => $ship) $ship->x = 80 + $i * 200 + ($tick % 2) * 3;

		if($mode === 'moving') $paddle->x = 320 + ($tick % 2) * 12;

		$tick++;

		if($mode === 'impacts')
		{
			for($i = 0; $i < count($balls) + $shotCount; $i++)
			$r->create(new Brick(70 + $i * 60, 300, 40, 8));

			for($i = 0; $i < $shotCount; $i++)
			$r->create(new Projectile(90 + (count($balls) + $i) * 60, 350, 'laser', 4000));
		}

		$dt = $mode === 'bounces' ? .25 : 1 / 60;
		$ballSystem->update($r, $dt);
		$shotSystem->update($r, $dt);

		foreach($balls as $ball)
		{
			if($ball->attached || $ball->x < 13 || $ball->x > 627 || $ball->y === 400)
			throw new \RuntimeException('Ball failed to advance or escaped a swept wall');

			if($mode === 'impacts' && $ball->vy <= 0)
			throw new \RuntimeException('Ball failed to reflect at an impact');
		}

		foreach($shots as $shot)
		if($shot->y >= 400) throw new \RuntimeException('Laser failed to advance');

		if($mode === 'impacts' && $field->state->score !== (count($balls) + $shotCount) * 100)
		throw new \RuntimeException('Impact workload failed to destroy every target exactly once');

		return count($r->query(Brick::class)) * 10000
			+ count($r->query(Ball::class)) * 100 + count($r->query(Projectile::class));
	};

	return [$run, $field];
}

/**
 * Measure individual updates with a monotonic clock after warmup, verifying every checksum.
 * @param callable():int $run Deterministic simulation callback.
 * @param int $expected Required result of every invocation.
 * @param int $iterations Updates in each sample group.
 * @param int $samples Number of sample groups.
 * @return array Timings in milliseconds and validated workload configuration.
 */
function measure(callable $run, int $expected, int $iterations, int $samples): array
{
	for($i = 0; $i < 10; $i++)
	if($run() !== $expected) throw new \RuntimeException('Warmup checksum mismatch');
	$times = [];

	for($i = 0; $i < $samples * $iterations; $i++)
	{
		$start = hrtime(true);
		$checksum = $run();
		$times[] = (hrtime(true) - $start) / 1000000;

		if($checksum !== $expected) throw new \RuntimeException('Timed checksum mismatch');
	}

	$sorted = $times;
	sort($sorted);

	return [
		'median_ms' => $sorted[intdiv(count($sorted), 2)]
		, 'p95_ms' => $sorted[(int)ceil(count($sorted) * .95) - 1]
		, 'min_ms' => min($times)
		, 'max_ms' => max($times)
		, 'updates' => count($times)
		, 'checksum' => $expected
	];
}

$options = $benchmarkOptions ?? getopt('', ['source-root:', 'iterations:', 'samples:']);
$root = $options['source-root'] ?? dirname(__DIR__, 3);
require $root . '/packages/php-noid/autoload.php';
$iterations = max(1, min(10000, (int)($options['iterations'] ?? 40)));
$samples = max(1, min(100, (int)($options['samples'] ?? 5)));
$result = ['php' => PHP_VERSION, 'int_bytes' => PHP_INT_SIZE, 'bricks' => 40, 'cases' => []];

foreach([
	'one_ball' => [1, 0, 'casts']
	, 'three_balls' => [3, 0, 'casts']
	, 'three_balls_six_lasers' => [3, 6, 'casts']
	, 'repeated_bounces' => [3, 0, 'bounces']
	, 'moving_targets' => [3, 6, 'moving']
	, 'destructible_targets' => [3, 6, 'impacts']
] as $name => [$balls, $shots, $mode])
{
	[$run, $field] = workload($root, $balls, $shots, $mode);

	try
	{
		$result['cases'][$name] = measure(
			$run
			, 400000 + $balls * 100 + ($mode === 'impacts' ? 0 : $shots)
			, $iterations
			, $samples
		);
	}
	finally
	{
		$field->context->close();
	}
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";

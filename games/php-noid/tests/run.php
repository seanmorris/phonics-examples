<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Phonics\Package\Context;
use Phonics\Tiled\Loader;
use PhpNoid\Module;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Projectile;
$checks = 0;

function expect(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

$context = new Context;
Module::factories($context);
$field = new Playfield($context);
Module::systems($field);

$field->loadStage = static function(int $index) use ($field): void {
	$field->stage(
		(new Loader)->map(dirname(__DIR__) . '/worlds/stage-' . sprintf('%02d', $index + 1) . '.tmj')
		, $index
	);
};

($field->loadStage)(0);
$registry = $context->entities;
$ball = $registry->get($registry->query(Ball::class)[0], Ball::class);
expect($ball->attached, 'serve attaches a ball');
$field->input->set([0 => true]);
$context->systems->update(1 / 60);
expect(!$ball->attached && $ball->y < 436, 'launch uses the logical input edge');
$field->input->set([]);
$field->power('split');
expect(count($registry->query(Ball::class)) === 3, 'split creates player multiball');

foreach($registry->query(Ball::class) as $id) $registry->destroy($id);
$enemy = new Ball(320, 500, 'enemy', 'cyan');
$enemy->attached = false;
$registry->create($enemy);
$field->serve();
$lives = $field->state->lives;
$context->systems->update(1 / 60);
expect($field->state->lives === $lives, 'losing an enemy ball never consumes a life');

foreach($registry->query(Brick::class) as $id) $registry->destroy($id);
$brickId = $registry->create(new Brick(250, 200, 80, 8, 2));
$ball = $registry->get($registry->query(Ball::class)[0], Ball::class);
$ball->attached = false;
$ball->x = 290;
$ball->y = 300;
$ball->vx = 0;
$ball->vy = -5000;
$context->systems->update(.03);
expect($registry->get($brickId, Brick::class)->hits === 1 && $ball->vy > 0, 'continuous cast hits a thin brick');
$solid = $registry->create(new Brick(400, 200, 40, 10, 1, true));
$field->damage($solid, 100);
expect($registry->alive($solid), 'unbreakable bricks resist weapon damage');
$field->power('catch');
$paddle = $registry->get($field->paddle, Paddle::class);
$ball->x = $paddle->x;
$ball->y = 420;
$ball->vy = 400;
$ball->vx = 0;
$context->systems->update(.06);
expect($ball->attached, 'catch attaches a returning player ball');
$field->power('laser');
$field->input->set([0 => true]);
$context->systems->update(1 / 60);
expect(count($registry->query(Projectile::class)) === 2, 'laser fires a pair');
$field->input->set([]);

foreach($registry->query(Projectile::class) as $id) $registry->destroy($id);
$registry->create(new Brick(260, 100, 54, 20, 3));
$registry->create(new Brick(480, 140));
$registry->create(new Projectile(280, 230, 'missile'));
$context->systems->update(.1);
expect(!$registry->alive($brickId), 'column missile clears multi-hit bricks in its column');
expect($registry->alive($solid), 'column missile keeps unbreakable geometry');
$field->state->lives = 1;
$field->loseLife();
expect($field->state->phase === 'gameover', 'game over at zero lives');
$field->restart(true);
expect($field->state->lives === 3 && $field->state->stage === 0, 'continue reloads current stage');

for($stage = 0; $stage < 25; $stage++)
{
	expect($field->state->stage === $stage, 'campaign stage order');

	foreach($registry->query(Brick::class) as $id)
	{
		$b = $registry->get($id, Brick::class);

		if(!$b->unbreakable) $field->damage($id, $b->hits, true, 'red');
	}

	$context->systems->update(1 / 60);

	for($step = 0; $step < 180 && !in_array($field->state->phase, ['playing', 'complete'], true); $step++)
	$context->systems->update(1 / 60);
}

expect($field->state->phase === 'complete', '25-stage campaign completes');
$field->restart();
expect($field->state->stage === 0 && $field->state->score === 0, 'restart clears campaign score');
$context->factory('Fixture.Entity', static fn(array $object): array => [new \Phonics\Physics\Pose]);
$authored = (new Loader)->map(dirname(__DIR__) . '/worlds/stage-01.tmj');
$authored->layer('Entities')->objects[] = ['id' => 9999, 'type' => 'Fixture.Entity'];
$field->stage($authored, 0);
expect(count($registry->query(\Phonics\Physics\Pose::class)) === 1, 'imported factories share the stage registry');
($field->loadStage)(1);
expect($registry->query(\Phonics\Physics\Pose::class) === [], 'stage transitions retire imported entities');
$context->close();
$context->close();
expect(!class_exists('SanePhonics\\Physics\\Body'), 'PhpNoid has no Sane-Phonics dependency');
require __DIR__ . '/mechanics.php';
require __DIR__ . '/transitions.php';
require __DIR__ . '/collisions.php';
require __DIR__ . '/assets.php';
echo "$checks PHP-Noid checks passed\n";

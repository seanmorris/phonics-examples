<?php
declare(strict_types=1);

use Phonics\Tiled\Loader;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Projectile;
use PhpNoid\System\StageSystem;

[$f, $r, $p, $b] = arena();
$ship = new Enemy(100, 50);
$r->create($ship);
$f->power('expand');
$f->power('laser');
$f->power('exit');
(new StageSystem($f))->update($r, 1 / 60);
expect($f->state->phase === 'clearing' && $f->state->stage === 0, 'clearing retains the old stage for its fade');
$powers = $p->powers;
$position = [$b->x, $b->y, $ship->x, $ship->y, $p->x];
$requested = [];
$f->loadStage = static function(int $index) use (&$requested): void { $requested[] = $index; };

$f->input->set(['right' => true, 0 => true]);
$f->pointer->held = $f->pointer->tap = true;
$f->context->systems->update(.15);
expect($f->state->fade > 0 && $f->state->fade < 1 && $requested === [], 'fade advances before any load request');

expect(
	$powers === $p->powers && $position === [$b->x, $b->y, $ship->x, $ship->y, $p->x]
	, 'transition freezes powers, paddle, balls and enemies'
);

expect($r->query(Projectile::class) === [] && !$f->pointer->tap, 'transition consumes fire without shooting');
$f->input->set([0 => true]);
$f->context->systems->update(.45);

expect(
	$requested === [1] && $f->state->phase === 'loading' && $f->state->fade === 1.0
	, 'next map loads once after full cover'
);

$f->context->systems->update(2);

expect(
	$requested === [1] && $f->state->stage === 0 && $f->state->fade === 1.0
	, 'delayed load holds the covered old map'
);

$f->stage((new Loader)->map(dirname(__DIR__) . '/worlds/stage-02.tmj'), 1);
$p = $r->get($f->paddle, Paddle::class);
$b = $r->get($r->query(Ball::class)[0], Ball::class);

expect(
	$f->state->phase === 'entering' && $f->state->fade === 1.0 && $p->powers === []
	, 'new map starts behind the title with reset equipment'
);

$f->input->set([0 => true]);
$f->context->systems->update(.45);
expect($f->state->fade === 1.0 && $b->attached, 'level title holds before revealing the new board');
$f->input->set([0 => true]);
$f->context->systems->update(.3);
expect($f->state->fade > 0 && $f->state->fade < 1 && $b->attached, 'board fades in while gameplay stays frozen');
$f->input->set([0 => true]);
$f->context->systems->update(.3);
expect($f->state->phase === 'playing' && $f->state->fade === 0.0, 'completed reveal resumes normal play');
$f->input->set([0 => true]);
$f->context->systems->update(1 / 60);

expect(
	$b->attached && $r->query(Projectile::class) === []
	, 'held fire through a transition cannot launch the new serve'
);

$f->input->set([]);
$f->context->systems->update(1 / 60);
$f->input->set([0 => true]);
$f->context->systems->update(1 / 60);
expect(!$b->attached, 'fresh fire after the reveal launches normally');

[$f, $r, $p, $b] = arena();
$f->power('exit');
$f->context->systems->update(.1);
$f->restart();

expect(
	$f->state->stage === 0 && $f->state->phase === 'playing' && $f->state->fade === 0.0 && $f->state->nextStage === null
	, 'restart cancels an in-progress transition'
);

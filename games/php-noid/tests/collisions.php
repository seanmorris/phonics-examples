<?php
declare(strict_types=1);
use Phonics\Physics\Pose;
use Phonics\Physics\Terrain;
use PhpNoid\CollisionWorld;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Projectile;
use PhpNoid\System\BallSystem;
use PhpNoid\System\CapsuleSystem;
use PhpNoid\System\EnemySystem;
use PhpNoid\System\ProjectileSystem;

// Parallel indestructible faces must not trap an axis-aligned player ball forever.
foreach([false, true] as $vertical)
foreach([0.0, .01] as $drift)
{
	[$f, $r, $p, $b] = arena();
	$bricks = $vertical
		? [new Brick(280, 180, 80, 20, 1, true), new Brick(280, 300, 80, 20, 1, true)]
		: [new Brick(200, 200, 20, 80, 1, true), new Brick(420, 200, 20, 80, 1, true)];

	foreach($bricks as $brick) $r->create($brick);
	$b->x = 320;
	$b->y = 250;
	$b->vx = $vertical ? $drift : 400;
	$b->vy = $vertical ? 400 : $drift;
	$b->attached = false;
	$speed = hypot($b->vx, $b->vy);
	$system = new BallSystem($f);
	$escaped = false;

	for($step = 0; $step < 240; $step++)
	{
		$system->update($r, 1 / 60);
		$escaped = $vertical ? abs($b->x - 320) > 46 : $b->y > 286;

		if($escaped) break;
	}

	expect($escaped, 'player balls escape parallel gold faces with zero or tiny sideways drift');
	expect(abs(hypot($b->vx, $b->vy) - $speed) < .001, 'trap recovery preserves ball speed');

	expect(
		$f->state->lives === 3 && $f->state->score === 0 && $bricks[0]->hits === 1 && $bricks[1]->hits === 1
		, 'recovery costs no life and leaves gold intact'
	);
}

// Use Column Fire's authored lane, not just the isolated geometry fixture.
[$f, $r, $p, $b] = arena();
($f->loadStage)(5);
$p = $r->get($f->paddle, Paddle::class);
$b = $r->get($r->query(Ball::class)[0], Ball::class);
$b->x = 200;
$b->y = 188;
$b->vx = 400;
$b->vy = 0;
$b->attached = false;
$system = new BallSystem($f);
$gold = array_filter($r->query(Brick::class), fn($id) => $r->get($id, Brick::class)->unbreakable);

for($step = 0; $step < 180 && abs($b->y - 188) <= 16; $step++) $system->update($r, 1 / 60);

expect(
	abs($b->y - 188) > 16 && $f->state->lives === 3
	, 'Level 6 gold lane releases a horizontal ball without losing a life'
);

expect(
	count(array_filter($gold, fn($id) => $r->alive($id))) === count($gold)
	, 'Level 6 recovery leaves the gold intact'
);

[$f, $r, $p, $b] = arena();
$r->create(new Brick(200, 200, 20, 80, 1, true));
$r->create(new Brick(420, 200, 20, 80, 1, true));
$enemyBall = new Ball(320, 250, 'enemy', 'pewter');
$enemyBall->attached = false;
$enemyBall->vx = 400;
$enemyBall->vy = 0;
$r->create($enemyBall);
$system = new BallSystem($f);

for($step = 0; $step < 180; $step++) $system->update($r, 1 / 60);
expect($enemyBall->y === 250.0 && $enemyBall->vy === 0.0, 'recovery does not redirect enemy energy balls');

// Level 5 can sustain a diagonal four-contact orbit above its rightmost gold brick.
[$f, $r, $p, $b] = arena();
($f->loadStage)(4);

foreach($r->query(Brick::class) as $id) if(!$r->get($id, Brick::class)->unbreakable) $r->destroy($id);

foreach($r->query(Enemy::class) as $id) $r->destroy($id);
$b = $r->get($r->query(Ball::class)[0], Ball::class);
$b->x = 523;
$b->y = 173 - 1 / 256;
$b->vx = -400;
$b->vy = -400 * 160 / 614;
$b->attached = false;
$speed = hypot($b->vx, $b->vy);
$gold = $r->query(Brick::class);
$system = new BallSystem($f);

for($step = 0; $step < 720 && $b->y <= 204; $step++) $system->update($r, 1 / 60);
expect($b->y > 204, 'Level 5 diagonal corner-to-gold orbit breaks without player intervention');
expect(abs(hypot($b->vx, $b->vy) - $speed) < .001, 'diagonal recovery preserves speed');
expect($r->query(Brick::class) === $gold && $f->state->lives === 3, 'diagonal recovery leaves gold and lives intact');

// Existing entities and reentrant lifecycle listeners must not leave missing or ghost shapes.
$existing = new \Phonics\Ecs\Registry;
$existingId = $existing->create(new Brick(280, 200, 80, 20));

$existing->on('added', static function($registry, $id, $component): void {
	if($component instanceof Brick && $component->score === -1) $registry->destroy($id);
});

$cache = new CollisionWorld($existing);

expect(
	$cache->projectile(new Pose(320, 300, 3, 4), 0, -200, false)?->metadata['entity'] === $existingId
	, 'cache indexes pre-existing targets'
);

$existing->create(new Brick(280, 250, 80, 20, 1, false, '', -1));

expect(
	$cache->projectile(new Pose(320, 300, 3, 4), 0, -200, false)?->metadata['entity'] === $existingId
	, 'reentrant removal cannot leave an added-event ghost shape'
);

$cache->close();

/** Inspect allocation counters without adding production instrumentation or a test-only API. */
function collisionIndexes(CollisionWorld $world): array
{
	$result = [];

	foreach(['targets', 'arena', 'paddleBody'] as $name)
	{
		$property = new ReflectionProperty(CollisionWorld::class, $name);
		$property->setAccessible(true);
		$terrain = $property->getValue($world);
		$counter = new ReflectionProperty(Terrain::class, 'nextShape');
		$counter->setAccessible(true);
		$shapes = new ReflectionProperty(Terrain::class, 'shapes');
		$shapes->setAccessible(true);
		$result[$name] = [$terrain, $counter->getValue($terrain), count($shapes->getValue($terrain))];
	}

	return $result;
}

[$f, $r, $p, $b] = arena();
$r->create(new Brick(280, 180, 80, 20, 20));
$f->power('split');
$shots = [];

for($i = 0; $i < 6; $i++) $shots[] = new Projectile(280 + $i * 12, 400);

foreach($shots as $shot) $r->create($shot);
$ballSystem = new BallSystem($f);
$shotSystem = new ProjectileSystem($f);
$ballSystem->update($r, 0);
$shotSystem->update($r, 0);
$before = collisionIndexes($f->collisions);

for($step = 0; $step < 30; $step++)
{
	foreach($r->query(Ball::class) as $id)
	{
		$ball = $r->get($id, Ball::class);
		$ball->x = 320;
		$ball->y = 380;
	}

	foreach($shots as $shot) $shot->y = 400;
	$ballSystem->update($r, 1 / 60);
	$shotSystem->update($r, 1 / 60);
}

expect(collisionIndexes($f->collisions) === $before, 'multiball and six lasers reuse every unchanged collision shape');

// Mutable bounds need no special setter or scheduler invocation; damage needs no reindexing.
[$f, $r, $p, $b] = arena();
$brick = new Brick(280, 200, 80, 20, 3);
$id = $r->create($brick);
$f->collisions->sync(640, 480, $p);
$hit = $f->collisions->projectile(new Pose(320, 300, 3, 4), 0, -200, false);
expect($hit->metadata['entity'] === $id, 'new targets are immediately available to weapon casts');
$before = collisionIndexes($f->collisions);
$f->damage($id);
$brick->material = 'silver';
$f->collisions->sync(640, 480, $p);
expect(collisionIndexes($f->collisions) === $before, 'damage and transmutation retain geometry');
$brick->x = 400;
$brick->width = 100;
$f->collisions->sync(640, 480, $p);
expect(!$f->collisions->projectile(new Pose(320, 300, 3, 4), 0, -200, false), 'moved brick vacates its old cells');

expect(
	$f->collisions->projectile(new Pose(490, 300, 3, 4), 0, -200, false)?->metadata['entity'] === $id
	, 'resized brick occupies its new cells'
);

$after = collisionIndexes($f->collisions);

expect(
	$after['targets'][0] === $before['targets'][0] && $after['targets'][1] === $before['targets'][1] + 1
	, 'moving one brick replaces only its shape'
);

$r->add($id, new Brick(120, 200, 40, 20));

expect(
	!$f->collisions->projectile(new Pose(490, 300, 3, 4), 0, -200, false)
	, 'component replacement immediately removes the previous shape'
);

expect(
	$f->collisions->projectile(new Pose(140, 300, 3, 4), 0, -200, false)?->metadata['entity'] === $id
	, 'component replacement immediately installs the new shape'
);

$r->remove($id, Brick::class);

expect(
	!$f->collisions->projectile(new Pose(140, 300, 3, 4), 0, -200, false)
	, 'direct component removal immediately retires geometry'
);

// The second ball/laser must continue through a shape destroyed earlier in the same update.
foreach(['balls', 'lasers', 'missile'] as $mode)
{
	[$f, $r, $p, $b] = arena();
	$near = $r->create(new Brick(280, 270, 80, 10));
	$far = $r->create(new Brick(280, 220, 80, 10));

	if($mode === 'balls')
	{
		foreach([$b, new Ball(320, 320)] as $index => $ball)
		{
			$ball->x = 320;
			$ball->y = 320;
			$ball->vx = 0;
			$ball->vy = -1200;
			$ball->attached = false;

			if($index) $r->create($ball);
		}

		(new BallSystem($f))->update($r, .1);
	}
	else
	{
		$r->create(new Projectile(320, 320, $mode === 'missile' ? 'missile' : 'laser', 1200));
		$second = $r->create(new Projectile(320, 320, 'laser', 1200));
		(new ProjectileSystem($f))->update($r, .1);

		if($mode === 'missile')
		expect($r->alive($second), 'later laser passes through the column cleared by a missile');
	}

	expect(
		!$r->alive($near) && !$r->alive($far) && $f->state->score === 200
		, "$mode observe same-update removals without duplicate score"
	);
}

[$f, $r, $p, $b] = arena();
$ship = new Enemy(320, 260, 'floater');
$shipId = $r->create($ship);
$brickId = $r->create(new Brick(280, 180, 80, 20));
$f->collisions->sync(640, 480, $p);

expect(
	$f->collisions->ball(new Pose(320, 350, 5, 5), 0, -200, true)?->metadata['entity'] === $shipId
	, 'player ball selects the nearer ship'
);

expect(
	$f->collisions->ball(new Pose(320, 350, 5, 5), 0, -200, false)?->metadata['entity'] === $brickId
	, 'enemy ball excludes ships while retaining bricks'
);

$ship->x = 420;
(new ProjectileSystem($f))->update($r, 0);

expect(
	$f->collisions->projectile(new Pose(320, 350, 3, 4), 0, -200, false)?->metadata['entity'] === $brickId
	, 'ship movement clears its previous weapon obstruction'
);

expect(
	$f->collisions->projectile(new Pose(420, 350, 3, 4), 0, -200, false)?->metadata['entity'] === $shipId
	, 'ship movement installs current weapon geometry'
);

$r->destroy($shipId);

expect(
	!$f->collisions->projectile(new Pose(420, 350, 3, 4), 0, -200, false)
	, 'direct ship destruction immediately removes its shape'
);

// A collected capsule changes hostile-shot bounds after the ball system has already run.
[$f, $r, $p, $b] = arena();
(new BallSystem($f))->update($r, 1 / 60);
$r->create(new \PhpNoid\Component\Capsule($p->x, $p->y - 15, 'expand', 500));
(new CapsuleSystem($f))->update($r, .02);
$r->create(new Projectile($p->x + 60, $p->y - 30, 'killer', 500, 'enemy'));
(new ProjectileSystem($f))->update($r, .1);
expect($f->state->lives === 2, 'hostile shots see paddle expansion collected between collision systems');
$f->collisions->sync(640, 480, $p);

expect(
	!$f->collisions->ball(new Pose($p->x, 470, 5, 5), 0, -60, true)
	, 'rising balls pass through the paddle underside'
);

expect(
	$f->collisions->projectile(new Pose($p->x - 100, $p->y, 3, 4), 100, 0, true)?->metadata['kind'] === 'paddle'
	, 'hostile projectiles retain full paddle-side collision'
);

// Removing either side of a touching pair exposes the surviving shared edge.
[$f, $r, $p, $b] = arena();
$left = $r->create(new Brick(280, 200, 40, 20));
$right = $r->create(new Brick(320, 200, 40, 20));
$f->collisions->sync(640, 480, $p);

expect(
	!$f->collisions->projectile(new Pose(300, 210, 3, 4), 30, 0, false)
	, 'touching bricks cancel their internal seam'
);

$r->destroy($left);

expect(
	$f->collisions->projectile(new Pose(300, 210, 3, 4), 30, 0, false)?->metadata['entity'] === $right
	, 'removal exposes the surviving brick edge'
);

$same = $f->collisions->ball(new Pose(320, 300, 5, 5), 0, -100, true);

for($i = 0; $i < 10; $i++)
{
	$f->collisions->sync(640, 480, $p);

	expect(
		$f->collisions->ball(new Pose(320, 300, 5, 5), 0, -100, true) == $same
		, 'identical corner casts remain deterministic'
	);
}

// Stage replacement must release old targets and resize walls without retaining old paddle bounds.
[$f, $r, $p, $b] = arena();
$wallBrick = $r->create(new Brick(0, 200, 8, 80));
$paddleBrick = $r->create(new Brick(280, $p->y - 7, 80, 20));
$f->collisions->sync(640, 480, $p);

expect(
	$f->collisions->ball(new Pose(50, 240, 5, 5), -100, 0, true)?->metadata['kind'] === 'wall'
	, 'wall wins a simultaneous wall and target contact'
);

expect(
	$f->collisions->ball(new Pose(320, 380, 5, 5), 0, 100, true)?->metadata['entity'] === $paddleBrick
	, 'target wins a simultaneous target and paddle contact'
);

[$f, $r, $p, $b] = arena();
$old = $r->create(new Brick(280, 300, 80, 20));
$oldPaddle = WeakReference::create($p);
$oldBrick = WeakReference::create($r->get($old, Brick::class));
($f->loadStage)(1);
unset($p);

expect(
	$oldPaddle->get() === null && $oldBrick->get() === null
	, 'stage replacement releases cached component references'
);

$p = $r->get($f->paddle, Paddle::class);
$f->collisions->sync(800, 600, $p);
expect(!$f->collisions->ball(new Pose(620, 350, 5, 5), 30, 0, true), 'arena resize removes the old side wall');

expect(
	$f->collisions->ball(new Pose(780, 350, 5, 5), 30, 0, true)?->metadata['kind'] === 'wall'
	, 'arena resize installs the new side wall'
);

$targets = collisionIndexes($f->collisions)['targets'][0];
$weakTerrain = WeakReference::create($targets);
unset($targets);
$cache = $f->collisions;
$weakCache = WeakReference::create($cache);
$f->context->close();
$f->context->close();
expect($weakTerrain->get() === null, 'session cleanup releases terrain');
unset($cache, $f);
gc_collect_cycles();
expect($weakCache->get() === null, 'session cleanup detaches registry listeners');
$r->create(new Brick(50, 50));

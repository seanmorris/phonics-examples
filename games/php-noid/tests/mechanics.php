<?php
declare(strict_types=1);
use Phonics\Package\Context;
use Phonics\Tiled\Loader;
use PhpNoid\Module;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Capsule;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Projectile;
use PhpNoid\System\BallSystem;
use PhpNoid\System\CapsuleSystem;
use PhpNoid\System\EnemySystem;
use PhpNoid\System\PaddleSystem;
use PhpNoid\System\ProjectileSystem;
use PhpNoid\System\StageSystem;
use PhpNoid\System\TransitionSystem;

/** Isolate physics checks from campaign loading, keeping an unreachable required brick. */
function arena(): array
{
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
	$r = $field->registry();

	foreach([Brick::class, Enemy::class] as $type)
	foreach($r->query($type) as $id) $r->destroy($id);
	$r->create(new Brick(20, 60, 10, 10, 100));

	return [$field, $r, $r->get($field->paddle, Paddle::class), $r->get($r->query(Ball::class)[0], Ball::class)];
}

[$f, $r, $p, $b] = arena();
expect(count($f->capsules) === 11, 'all eleven documented pellet actions are defined');
$f->power('expand');
expect($p->width === 132.0, 'expand changes the physical paddle width');
$p->x = 5;
(new PaddleSystem($f))->update($r, .01);
expect($p->x === 74.0, 'expanded paddle is constrained by arena bounds');
$f->power('shrink');
expect($p->width === 52.0, 'shrink replaces expansion');
$f->power('catch');
expect($p->width === 52.0 && isset($p->powers['shrink'], $p->powers['catch']), 'catch stacks with paddle size');
$f->power('expand');
(new PaddleSystem($f))->update($r, 26);
expect($p->width === 84.0 && $p->powers === [], 'power expiration restores paddle geometry');
$f->power('extra');
expect($f->state->lives === 4, 'extra pellet grants a life');
$f->power('split');
expect(count($r->query(Ball::class)) === 3 && !$b->attached, 'split launches exactly three balls from a held serve');
$f->power('laser');
expect(count($r->query(Ball::class)) === 3, 'equipment preserves existing multiball');
$f->power('exit');
(new StageSystem($f))->update($r, .01);
expect($f->state->stage === 0 && $f->state->phase === 'clearing', 'Exit begins the transition before changing maps');
(new TransitionSystem($f))->update($r, .6);
(new TransitionSystem($f))->update($r, 1.05);
expect($f->state->stage === 1 && $f->state->phase === 'playing', 'Exit advances despite remaining bricks');
expect($r->get($f->paddle, Paddle::class)->powers === [], 'stage transitions clear the equipment stack');

[$f, $r, $p, $b] = arena();

foreach(['expand', 'catch', 'power', 'fast', 'laser'] as $kind) $f->power($kind);
expect(count($p->powers) === 5 && $p->width === 132.0, 'all five compatible timed powers stack');
(new PaddleSystem($f))->update($r, 2);
$timers = $p->powers;
$f->power('extra');
$f->power('split');
$f->power('exit');
expect($p->powers === $timers && $p->width === 132.0, 'instant capsules preserve equipment and all timers');
expect(count($r->query(Ball::class)) === 3 && $f->state->lives === 4, 'instant capsules still apply their effects');
$f->power('catch');
$timers['catch'] = 25.0;
expect($p->powers === $timers, 'repeat collection refreshes only its own duration');
$p->cooldown = .15;
$f->power('expand');
$f->pointer->held = true;
(new PaddleSystem($f))->update($r, .01);
expect($r->query(Projectile::class) === [], 'unrelated pickups cannot bypass weapon cooldown');
(new PaddleSystem($f))->update($r, .2);
$shots = $r->query(Projectile::class);
expect(count($shots) === 2, 'lasers fire while catch, size, speed and power ball are equipped');
expect($r->get($shots[0], Projectile::class)->x === $p->x - 58, 'stacked lasers use expanded paddle geometry');
$f->pointer->held = false;

foreach(['expand' => 'shrink', 'fast' => 'slow', 'laser' => 'missile'] as $previous => $next)
{
	$others = $p->powers;
	unset($others[$previous]);
	$f->power($next);
	$expected = $others + [$next => (float)$f->capsules[$next]['duration']];
	expect($p->powers === $expected, "$next replaces only its conflicting power");
}

$p->powers['shrink'] = .1;
$p->powers['missile'] = .3;
(new PaddleSystem($f))->update($r, .2);

expect(
	$p->width === 84.0 && !isset($p->powers['shrink']) && count($p->powers) === 4
	, 'size expiration preserves the other powers'
);

(new PaddleSystem($f))->update($r, .2);

expect(
	!isset($p->powers['missile']) && isset($p->powers['catch'], $p->powers['power'], $p->powers['slow'])
	, 'weapons expire independently'
);

$f->state->lives = 1;
$f->loseLife();
expect($p->powers === [] && $f->state->phase === 'gameover', 'fatal life loss clears the entire equipment stack');

[$f, $r, $p, $b] = arena();
$f->power('split');
$ids = $r->query(Ball::class);
$r->destroy($ids[0]);
$r->destroy($ids[1]);
(new BallSystem($f))->update($r, .01);
expect($f->state->lives === 3, 'one surviving player ball preserves a life');
$r->destroy($ids[2]);
$decoy = new Ball(400, 300, 'enemy', 'pewter');
$decoy->attached = false;
$r->create($decoy);
(new BallSystem($f))->update($r, .01);
expect($f->state->lives === 2 && count($r->query(Ball::class)) === 1, 'enemy balls cannot sustain a player life');

[$f, $r, $p, $b] = arena();
$b->attached = false;
$b->x = 320;
$b->y = 350;
$b->vx = 100;
$b->vy = 0;
$f->power('fast');

foreach(['expand', 'catch', 'power', 'laser'] as $kind) $f->power($kind);
$enemyBall = new Ball(400, 350, 'enemy', 'pewter');
$enemyBall->attached = false;
$enemyBall->vx = 100;
$enemyBall->vy = 0;
$r->create($enemyBall);
(new BallSystem($f))->update($r, .05);
expect(abs($b->x - 326.75) < .001, 'fast pellet changes displacement without corrupting base velocity');
expect(abs($enemyBall->x - 405) < .001, 'stacked player speed powers leave enemy balls unchanged');
$f->power('slow');
(new BallSystem($f))->update($r, .05);
expect(abs($b->x - 330.25) < .001, 'slow replaces fast instead of stacking indefinitely');
(new PaddleSystem($f))->update($r, 21);
(new BallSystem($f))->update($r, .05);
expect(abs($b->x - 335.25) < .001, 'speed expiration restores normal movement');

[$f, $r, $p, $b] = arena();
$f->power('catch');

foreach(['expand', 'power', 'laser'] as $kind) $f->power($kind);
$b->attached = false;
$b->x = $p->x + $p->width / 2 - 2;
$b->y = 420;
$b->vx = 0;
$b->vy = 400;
(new BallSystem($f))->update($r, .08);
expect(!$b->attached && $b->vy < 0, 'catch leaves the paddle tips reflective');
$b->x = $p->x;
$b->y = 420;
$b->vx = 0;
$b->vy = 400;
(new BallSystem($f))->update($r, .08);
expect($b->attached && $p->catchFlash === .24, 'catch locks a center contact and flashes the paddle');
$flash = $p->catchFlash;
(new PaddleSystem($f))->update($r, .1);
expect($p->catchFlash < $flash && $p->catchFlash > 0, 'catch flash fades through simulation time');
$f->pointer->tap = true;
(new BallSystem($f))->update($r, .01);
expect(!$b->attached && $b->vy < 0, 'mouse tap releases a caught ball');
(new StageSystem($f))->update($r, .01);
expect(!$f->pointer->tap, 'mouse tap is consumed once per fixed simulation step');

[$f, $r, $p, $b] = arena();
$r->create(new Capsule($p->x + 60, 390, 'expand', 1000));
(new CapsuleSystem($f))->update($r, .1);
expect($p->width === 84.0, 'a capsule outside the paddle is not collected');
$r->create(new Capsule($p->x, 390, 'expand', 1000));
(new CapsuleSystem($f))->update($r, .1);
expect($p->width === 132.0, 'falling capsules use swept paddle collection');
$f->pointer->x = 400;
(new PaddleSystem($f))->update($r, .01);
expect($p->x === 400.0, 'pointer positions the paddle');
$f->input->set(['left' => true]);
(new PaddleSystem($f))->update($r, .1);
expect($p->x === 348.0 && $f->pointer->x === null, 'keyboard movement takes over from the pointer');

foreach(['copper' => 2, 'silver' => 3, 'green' => 1, 'pewter' => 1] as $effect => $hits)
{
	[$f, $r, $p, $b] = arena();
	$id = $r->create(new Brick(280, 200, 80, 20, 1, false, 'laser'));
	$energy = new Ball(320, 300, 'enemy', $effect);
	$energy->attached = false;
	$energy->vx = 0;
	$energy->vy = -500;
	$r->create($energy);
	(new BallSystem($f))->update($r, .2);
	$brick = $r->get($id, Brick::class);
	expect($brick->hits === $hits && $f->state->score === 0, "$effect energy changes bricks without player scoring");
	expect($effect === 'pewter' ? $brick->capsule === 'laser' : $brick->capsule === '', "$effect capsule inheritance");
}

[$f, $r, $p, $b] = arena();
$energy = new Ball($p->x, 420, 'enemy', 'cyan');
$energy->attached = false;
$energy->vx = 0;
$energy->vy = 400;
$r->create($energy);
(new BallSystem($f))->update($r, .08);

expect(
	$p->invisible === 5.0 && $energy->vy < 0 && $energy->team === 'enemy'
	, 'cyan contact hides the paddle but stays an enemy ball'
);

(new PaddleSystem($f))->update($r, 6);
expect($p->invisible === 0.0, 'paddle visibility recovers');

[$f, $r, $p, $b] = arena();
$green = $r->create(new Brick(280, 200, 80, 20, 1, false, '', 100, 'green'));
$f->damage($green, 1000);
expect($r->alive($green), 'green metal resists ordinary ball and weapon damage');
$energy = new Ball(320, 300, 'enemy', 'red');
$energy->attached = false;
$energy->vx = 0;
$energy->vy = -500;
$r->create($energy);
(new BallSystem($f))->update($r, .2);
expect(!$r->alive($green) && $f->state->score === 0, 'red energy destroys green bricks without points');
$plain = $r->create(new Brick(280, 200, 80, 20));
$energy->y = 300;
$energy->vy = -500;
(new BallSystem($f))->update($r, .2);
expect($r->alive($plain), 'red energy leaves ordinary bricks intact');

[$f, $r, $p, $b] = arena();
$f->power('power');

foreach(['expand', 'catch', 'laser'] as $kind) $f->power($kind);
$first = $r->create(new Brick(280, 250, 80, 10, 3));
$second = $r->create(new Brick(280, 200, 80, 10, 2));
$ship = $r->create(new Enemy(320, 150));
$b->attached = false;
$b->x = 320;
$b->y = 350;
$b->vx = 0;
$b->vy = -1000;
(new BallSystem($f))->update($r, .25);

expect(
	!$r->alive($first) && !$r->alive($second) && !$r->alive($ship) && $b->vy < 0
	, 'power ball pierces tough bricks and ships in one sweep'
);

$gold = $r->create(new Brick(280, 40, 80, 10, 1, true));
(new BallSystem($f))->update($r, .08);
expect($r->alive($gold) && $b->vy > 0, 'power ball reflects from gold obstacles');

foreach(['cyan', 'red', 'green', 'copper', 'silver', 'pewter'] as $effect)
{
	[$f, $r, $p, $b] = arena();
	$r->create(new Enemy(160, 40, 'menacer', $effect, .25));
	(new EnemySystem($f))->update($r, .3);
	$energy = $r->get($r->query(Ball::class)[1], Ball::class);

	expect(
		$energy->team === 'enemy' && $energy->effect === $effect && !$energy->attached
		, "$effect Menacer emits distinct energy"
	);
}

[$f, $r, $p, $b] = arena();
$enemyId = $r->create(new Enemy(160, 40, 'destroyer', '', .25));
(new EnemySystem($f))->update($r, .3);
$shot = $r->get($r->query(Projectile::class)[0], Projectile::class);

expect(
	count($r->query(Ball::class)) === 1 && $shot->team === 'enemy' && $shot->kind === 'killer'
	, 'Destroyers emit lethal projectiles, never passive balls'
);

expect(
	$shot->vx > 0 && $shot->vy > 0 && $r->get($enemyId, Enemy::class)->y > 40
	, 'Destroyers descend and aim toward the paddle'
);

$f->power('split');
$f->power('catch');
$shot->x = $p->x;
$shot->y = 390;
$shot->vx = 0;
$shot->vy = 2000;
(new ProjectileSystem($f))->update($r, .05);

expect(
	$f->state->lives === 2 && count($r->query(Ball::class)) === 1
	, 'a swept killer hit consumes one life even with catch and multiball'
);

expect($r->query(Projectile::class) === [], 'life loss removes all hostile and player projectiles');

$protected = new Projectile($p->x, 390, 'killer', 2000, 'enemy');
$protectedId = $r->create($protected);
(new ProjectileSystem($f))->update($r, .05);

expect($f->state->lives === 2 && !$r->alive($protectedId), 'respawn protection absorbs a following lethal projectile');

(new PaddleSystem($f))->update($r, 2.1);
$exposed = $r->create(new Projectile($p->x, 390, 'killer', 2000, 'enemy'));
(new ProjectileSystem($f))->update($r, .05);

expect($f->state->lives === 1 && !$r->alive($exposed), 'lethal projectiles resume after respawn protection expires');

[$f, $r, $p, $b] = arena();
$brickId = $r->create(new Brick(280, 200, 80, 20, 3));
$ship = $r->create(new Enemy(320, 270));

for($i = 0; $i < 3; $i++)
{
	$r->create(new Projectile(320, 350, 'laser', 3000));
	(new ProjectileSystem($f))->update($r, .06);

	if($i < 2) expect($r->alive($ship), 'ships survive fewer than three laser hits');
}

expect(!$r->alive($ship) && $f->state->score === 250, 'third laser hit destroys and scores a Menacer');
expect($r->get($brickId, Brick::class)->hits === 3, 'swept weapons hit a nearer ship before a farther brick');
$ship = $r->create(new Enemy(320, 270, 'destroyer'));
$r->create(new Projectile(320, 350, 'missile', 3000));
(new ProjectileSystem($f))->update($r, .06);

expect(
	!$r->alive($ship) && !$r->alive($brickId) && $f->state->score === 850
	, 'one missile destroys a ship and clears its brick column'
);

[$f, $r, $p, $b] = arena();
$ship = $r->create(new Enemy(320, 270, 'destroyer', '', 5, 9));
$r->create(new Projectile(320, 350, 'missile', 3000));
(new ProjectileSystem($f))->update($r, .06);
expect($r->get($ship, Enemy::class)->hits === 6, 'missiles deal three laser units to authored heavy ships');

[$f, $r, $p, $b] = arena();
$green = $r->create(new Brick(280, 200, 80, 20, 1, false, '', 100, 'green'));
$gold = $r->create(new Brick(280, 150, 80, 20, 1, true));
$adjacent = $r->create(new Brick(400, 240, 40, 20));
$r->create(new Projectile(320, 350, 'missile', 3000));
(new ProjectileSystem($f))->update($r, .06);

expect(
	$r->alive($green) && $r->alive($gold) && $r->alive($adjacent)
	, 'missile columns respect green, gold, and adjacent bricks'
);

[$f, $r, $p, $b] = arena();
$p->x = 500;
$homing = new Projectile(320, 300, 'homing', 230, 'enemy');
$r->create($homing);
(new ProjectileSystem($f))->update($r, .05);

expect(
	$homing->vx > 0 && abs(hypot($homing->vx, $homing->vy) - 230) < .001
	, 'Annihilator shots steer while keeping bounded speed'
);

$r->create(new Enemy(160, 40, 'sympathizer', '', .25, 3, 'laser'));
$r->create(new Enemy(400, 40, 'floater', '', .25));
(new EnemySystem($f))->update($r, .3);

expect(
	count($r->query(Capsule::class)) === 1 && $r->get($r->query(Capsule::class)[0], Capsule::class)->kind === 'laser'
	, 'Sympathizer drops its authored helpful capsule'
);

expect(
	count($r->query(Projectile::class)) === 1 && count($r->query(Ball::class)) === 1
	, 'Floaters and friendly ships emit no harmful attacks'
);

[$f, $r, $p, $b] = arena();
$r->create(new Brick(280, 200, 80, 20, 1, false, '', 100, 'green'));
$redShip = $r->create(new Enemy(320, 40, 'menacer', 'red'));
(new StageSystem($f))->update($r, .01);
expect(count($r->query(Ball::class)) === 1, 'a living Red Menacer provides the required red source');
$r->destroy($redShip);
(new StageSystem($f))->update($r, .01);
(new StageSystem($f))->update($r, .01);
$rescue = $r->get($r->query(Ball::class)[1], Ball::class);

expect(
	count($r->query(Ball::class)) === 2 && $rescue->effect === 'red' && $rescue->attached && $rescue->team === 'enemy'
	, 'losing the red source creates exactly one launchable rescue ball'
);

$f->power('expand');
$p->invisible = 3;
$f->loseLife();

expect(
	$p->width === 84.0 && $p->invisible === 0.0 && $p->powers === []
	, 'life loss clears all temporary paddle modifications'
);

(new StageSystem($f))->update($r, .01);
expect(count($r->query(Ball::class)) === 2, 'green recovery still works after losing a life');

[$f, $r, $p, $b] = arena();
$f->state->lives = 1;
$f->state->score = 1234;
$f->input->set([0 => true]);
$f->loseLife();
(new StageSystem($f))->update($r, .01);
expect($f->state->phase === 'gameover' && $f->state->score === 1234, 'firing on the fatal frame cannot skip game over');
$stageSystem = new StageSystem($f);
$f->input->set([0 => true]);
$stageSystem->update($r, .01);
expect($f->state->phase === 'gameover', 'held fire cannot auto-continue');
$f->input->set([]);
$stageSystem->update($r, .01);
$f->input->set([0 => true]);
$stageSystem->update($r, .01);
expect($f->state->phase === 'playing' && $f->state->lives === 3, 'a new press after release continues the run');

$capsules = $families = $effects = [];
$silhouettes = [];

for($index = 0; $index < 25; $index++)
{
	$map = (new Loader)->map(dirname(__DIR__) . '/worlds/stage-' . sprintf('%02d', $index + 1) . '.tmj');
	$f->stage($map, $index);
	$shape = $stageCapsules = $openingDrops = [];
	$lowest = max(array_map(fn($id) => $r->get($id, Brick::class)->y, $r->query(Brick::class)));

	foreach($r->query(Brick::class) as $id)
	{
		$brick = $r->get($id, Brick::class);
		$shape[] = [$brick->x, $brick->y, $brick->width, $brick->height];

		if(!$brick->unbreakable && $brick->material !== 'green' && $brick->capsule !== '')
		{
			$capsules[$brick->capsule] = $stageCapsules[$brick->capsule] = true;

			if($brick->y === $lowest) $openingDrops[$brick->capsule] = true;
		}
	}

	$silhouettes[json_encode($shape)] = true;
	expect(count($stageCapsules) > 1, 'every stage offers multiple capsule types');

	if($index === 0)
	{
		expect(count($shape) === 40, 'First Light retains a moderate forty-brick workload');

		expect(
			count(array_intersect(['expand', 'catch', 'split', 'laser'], array_keys($openingDrops))) === 4
			, 'First Light offers four different helpful powers on its approachable lowest row'
		);
	}

	foreach($r->query(Enemy::class) as $id)
	{
		$enemy = $r->get($id, Enemy::class);
		$families[$enemy->family] = true;

		if($enemy->family === 'menacer') $effects[$enemy->effect] = true;
	}
}

[$f, $r, $p] = arena();
$f->power('laser');
expect($p->powerFlashes['laser'] === .8, 'new equipment starts a HUD flash');
(new PaddleSystem($f))->update($r, .3);
$f->power('laser');
expect($p->powerFlashes['laser'] === .8, 'repeat pickup restarts the HUD flash');
$f->power('extra');

expect(
	$p->powerFlashes['extra'] === .8 && !isset($p->powers['extra'])
	, 'instant pickups flash without becoming timed equipment'
);

(new PaddleSystem($f))->update($r, .81);
expect($p->powerFlashes === [] && isset($p->powers['laser']), 'HUD flashes expire independently of equipment');
$f->power('catch');
$f->loseLife();
expect($p->powerFlashes === [], 'life loss clears pickup flashes');

expect(count($capsules) === 11, 'all eleven pellets occur on destructible campaign bricks');
expect(count($silhouettes) === 25, 'the campaign has 25 distinct authored silhouettes');

expect(
	count($families) === 5 && count($effects) === 6
	, 'Tiled campaign introduces all ship families and six Menacer effects'
);

[$f, $r, $p, $b] = arena();
$f->state->infiniteLives = true;
$f->state->lives = 1;

for($i = 0; $i < 10; $i++) $f->loseLife();
expect($f->state->lives === 1 && $f->state->phase === 'playing', 'infinite lives survives repeated losses');
expect(count($r->query(Ball::class)) === 1, 'infinite lives serves one fresh ball after each loss');
($f->loadStage)(1);
expect($f->state->infiniteLives, 'infinite lives persists across stages');
$f->restart();
expect(!$f->state->infiniteLives && $f->state->lives === 3, 'restart resets infinite lives');
$f->state->lives = 1;
$f->loseLife();
expect($f->state->phase === 'gameover', 'ordinary last-life loss still ends the game');
$f->context->close();

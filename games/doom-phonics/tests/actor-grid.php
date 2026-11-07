<?php
declare(strict_types=1);

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\ActorObstacles;
use Phonics\Ecs\Registry;

// The blockmap grid must answer every sweep and push-out exactly as the full scan does; it only skips bodies that
// cannot be reached. A seeded crowd of mixed radii (Doom's 16-unit players up to the Spider Mastermind's 128) and
// solidity, with probes from anywhere in the crowd, compares both modes over the same registry.
(static function(): void {
	mt_srand(2704);
	$registry = new Registry;
	$bodies = [];

	foreach(range(1, 220) as $index)
	{
		$radius = [8, 16, 20, 30, 40, 128][mt_rand(0, 5)];
		$bodies[] = $registry->create(
			new Transform(mt_rand(-1500, 1500) + mt_rand() / mt_getrandmax(), mt_rand(-1500, 1500), mt_rand(0, 40))
			, new Body($radius, mt_rand(16, 100), mt_rand(0, 5) > 0)
		);
	}

	$scan = new ActorObstacles($registry);
	$grid = new ActorObstacles($registry, null, true);
	$grid->beginStep();
	$same = static function(?object $a, ?object $b): bool {
		return $a === $b || ($a && $b && $a->entity === $b->entity && abs($a->time - $b->time) < 1e-12
			&& $a->normalX === $b->normalX && $a->normalY === $b->normalY);
	};

	$compare = static function(string $when) use ($registry, $bodies, $scan, $grid, $same): void {
		$sweeps = $pushes = 0;

		foreach(range(1, 400) as $probe)
		{
			$mover = $bodies[mt_rand(0, count($bodies) - 1)];
			$pose = $registry->get($mover, Transform::class);
			$body = $registry->get($mover, Body::class);
			$dx = mt_rand(-300, 300);
			$dy = mt_rand(-300, 300);
			$sweeps += (int)$same($scan->sweep($mover, $pose, $body, $dx, $dy), $grid->sweep($mover, $pose, $body, $dx, $dy));
			$x = $pose->x + mt_rand(-40, 40);
			$y = $pose->y + mt_rand(-40, 40);
			$pushes += (int)($scan->resolve($mover, $pose, $body, $x, $y) === $grid->resolve($mover, $pose, $body, $x, $y));
		}

		verifyWad($sweeps === 400 && $pushes === 400, "the actor grid matches the full scan $when");
	};

	$compare('for a seeded crowd');

	// Collision reports each move; the grid re-bins only that body.
	foreach(array_slice($bodies, 0, 60) as $entity)
	{
		$pose = $registry->get($entity, Transform::class);
		$pose->x += mt_rand(-400, 400);
		$pose->y += mt_rand(-400, 400);
		$grid->moved($entity, $pose);
	}

	$compare('after moved() re-bins bodies');

	// A spawn changes the registry's body set, which rebuilds the grid without waiting for the next step.
	$bodies[] = $registry->create(new Transform(10, 10, 0), new Body(64, 56, true));
	$compare('after a body spawns mid-step');

	// Other writers move bodies directly; the next step's rebuild picks them up.
	foreach(array_slice($bodies, 60, 60) as $entity) $registry->get($entity, Transform::class)->x += 900;

	$grid->beginStep();
	$compare('after a step rebuild');

	// The browser runs 32-bit PHP. A key past 2^31 became a float there, and the int return type threw on E1M1's
	// first movement step, freezing the map; 64-bit CLI runs never saw it.
	$key = new ReflectionMethod(ActorObstacles::class, 'key');
	$keys = [];

	foreach([0.0, 1056.0, -3616.0, 2.1e6, -2.1e6, 1e12, -1e12, INF, -INF, NAN] as $x)
	foreach([0.0, -3616.0, 2.1e6, -2.1e6, 1e12, -INF, NAN] as $y)
	$keys[] = $key->invoke(null, $x, $y);

	verifyWad(
		array_filter($keys, static fn(mixed $value): bool => !is_int($value) || $value < 0 || $value > 2147483647) === []
		, 'actor grid cell keys stay within 32-bit PHP integers for any position'
	);

	verifyWad(
		count(array_unique([
			$key->invoke(null, -2.1e6, -2.1e6), $key->invoke(null, -2.1e6, 2.1e6)
			, $key->invoke(null, 2.1e6, -2.1e6), $key->invoke(null, 2.1e6, 2.1e6)
		])) === 4
		, 'the clamped grid keeps its extreme corner cells distinct'
	);

	$far = new ActorObstacles($registry, null, true);
	$far->beginStep();
	$farMover = $bodies[0];
	$farPose = $registry->get($farMover, Transform::class);

	verifyWad(
		$same(
			$scan->sweep($farMover, $farPose, $registry->get($farMover, Body::class), 1e7, -1e7)
			, $far->sweep($farMover, $farPose, $registry->get($farMover, Body::class), 1e7, -1e7)
		)
		, 'a sweep spanning more cells than bodies answers from the bodies, as the scan does'
	);
})();

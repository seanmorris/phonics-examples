<?php
declare(strict_types=1);

use PhpBilliards\Game;
use PhpBilliards\Geometry\TableGeometry;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Simulation;
use PhpBilliards\Render\Camera;

$cameraFrame = static function(Game $game, array $browser = [], array $events = [], array $controls = [], array $stick = [0,0]): void {
	$game->input($browser, $controls, $events, [], $stick, 1 / 60, 1280, 720);
};
$cameraGame = static function() use ($cameraFrame): Game {
	$game = new Game;
	$cameraFrame($game, ['actions' => ['start']]);
	$game->camera->animate(2);
	return $game;
};
$project = static function(Camera $camera, array $point, int $width = 1280, int $height = 720): array {
	$matrix = $camera->viewProjection($width, $height);
	$point[] = 1;
	$clip = array_fill(0, 4, 0.0);
	for($row = 0; $row < 4; $row++) for($column = 0; $column < 4; $column++)
	$clip[$row] += $matrix[$column * 4 + $row] * $point[$column];
	return [$clip[0] / $clip[3],$clip[1] / $clip[3],$clip[2] / $clip[3],$clip[3]];
};

$test('camera suspension ignores stale follow error and resumes the latest desired pose', static function() use ($expect): void {
	$camera = new Camera;
	$camera->reset();
	$camera->resumeFollow();
	$camera->follow(-.8, .3, 1.2, 1 / 60);
	$expect($camera->settling());
	$camera->suspendFollow(true);
	$before = $camera->state();
	for($frame = 0; $frame < 120; $frame++) $camera->follow(.8, -.3, -.9, 1 / 60);
	$expect(!$camera->settling() && $camera->state() === $before, 'Suspended follow animated or stayed dirty');
	$camera->suspendFollow(false);
	$expect($camera->settling(), 'Resuming lost the desired pose');
	for($frame = 0; $frame < 180; $frame++) $camera->follow(.8, -.3, -.9, 1 / 60);
	$expect(!$camera->settling());
	$before = $camera->state();
	for($frame = 0; $frame < 120; $frame++) $camera->follow(.8, -.3, -.9, 1 / 60);
	$expect($before === $camera->state(), 'Settled follow continued changing floats');
	$camera->follow(1, 0, 2, .01);
	$camera->reset();
	$expect(!$camera->settling(), 'Reset retained a stale follow pose');
	$camera->behind(-.6, 0, 0);
	$expect(!$camera->settling(), 'Behind-cue reset retained a stale follow pose');
});

$test('pointer aiming suspends camera follow only for the gesture', static function() use ($expect, $cameraGame, $cameraFrame, $project): void {
	$game = $cameraGame();
	$hash = $game->simulation->exactHash();
	$cameraFrame($game, [], [], ['right' => 1]);
	$expect($game->camera->settling());
	[$x,$y] = $project($game->camera, [0,.012,.2]);
	$pointer = ['type' => 'pointer','button' => 1,'x' => ($x + 1) * 640,'y' => (1 - $y) * 360];
	$cameraFrame($game, [], [$pointer + ['action' => 'down']]);
	$before = $game->camera->state();
	for($frame = 0; $frame < 120; $frame++) $cameraFrame($game);
	$expect(!$game->camera->settling() && $before === $game->camera->state(), 'Pointer hold kept the camera active');
	$cameraFrame($game, [], [$pointer + ['action' => 'up']]);
	$expect($game->camera->settling() && $before !== $game->camera->state(), 'Pointer release did not resume follow');
	for($frame = 0; $frame < 180; $frame++) $cameraFrame($game);
	$expect(!$game->camera->settling());
	$before = $game->camera->state();
	for($frame = 0; $frame < 60; $frame++) $cameraFrame($game);
	$expect($before === $game->camera->state(), 'Idle follow still changed rendered state');
	$expect($hash === $game->simulation->exactHash(), 'Camera gestures changed deterministic state');
});

$test('manual orbit survives aim input and wheel zoom until a gameplay boundary', static function() use ($expect, $cameraGame, $cameraFrame): void {
	foreach(['rack','reset','camera-reset','shot-end','placement'] as $boundary)
	{
		$game = $cameraGame();
		$cameraFrame($game, ['actions' => ['camera-reset']]);
		$cameraFrame($game, [], [], [], [.5,.2]);
		$before = $game->camera->state();
		for($frame = 0; $frame < 120; $frame++) $cameraFrame($game, [], [], ['right' => .5]);
		$expect($game->camera->state() === $before && !$game->camera->settling(), 'Aim input released manual orbit');
		$cameraFrame($game, [], [['type' => 'wheel','y' => 1]]);
		$expect($game->camera->distance < $before['distance'] && $game->camera->yaw === $before['yaw']);
		$expect(!$game->camera->settling(), 'Wheel zoom released manual orbit');
		if($boundary === 'shot-end')
		{
			$game->simulation->phase = 'moving'; $cameraFrame($game);
			$game->simulation->phase = 'aim'; $cameraFrame($game);
		}
		elseif($boundary === 'placement')
		{
			$game->simulation->cue()->active = false;
			$game->simulation->phase = 'placing'; $cameraFrame($game);
			$cameraFrame($game, ['actions' => ['primary']]);
		}
		else $cameraFrame($game, ['actions' => [$boundary]]);
		$expect($game->camera->yaw !== $before['yaw'], "$boundary did not resume follow");
	}
});

$test('wheel zoom keeps active follow and preserves an inactive canonical view', static function() use ($expect, $cameraGame, $cameraFrame): void {
	$game = $cameraGame();
	$canonical = $game->camera->state();
	$cameraFrame($game, [], [['type' => 'wheel','y' => 1]]);
	$expect($game->camera->yaw === $canonical['yaw'] && !$game->camera->settling());
	$cameraFrame($game, [], [], ['right' => 1]);
	$before = $game->camera->yaw;
	$cameraFrame($game, [], [['type' => 'wheel','y' => 1]]);
	$expect($game->camera->yaw !== $before && $game->camera->settling(), 'Wheel zoom suspended active follow');
});

$test('zoom and both orbit inputs modify the opening destination without cancelling it', static function() use ($expect): void {
	$camera = new Camera;
	$camera->beginGameplayView();
	$camera->animate(.4);
	$before = $camera->state();
	$camera->zoom(-500);
	$camera->orbit(20, 20, .6);
	$camera->orbitStick(.5, -.2, .1);
	$expect($camera->transitioning() && $camera->settling() && $before === $camera->state(), 'Input cancelled or jumped the opening pose');
	$camera->animate(.95);
	$expect(!$camera->transitioning() && !$camera->settling());
	$expect(abs($camera->distance - 3.35 * exp(-.5)) < 1e-12);
	$expect(abs($camera->yaw - (-.42 + .12 + .095)) < 1e-12);
	$expect(abs($camera->pitch - (.72 + .1 - .029)) < 1e-12);
	$expect($camera->target === [0.0,0.0,0.0]);
	$camera->beginGameplayView(); $camera->zoom(-10000); $camera->animate(2);
	$expect($camera->distance === .85 && $camera->pitch === .72 && $camera->yaw === -.42);
	$camera = new Camera;
	$camera->beginGameplayView(); $camera->animate(.675);
	$before = $camera->yaw;
	$camera->orbit(100, 0, .6); $camera->animate(.001);
	$expect($camera->yaw > $before && $camera->yaw - $before < .31, 'Editing the opening reversed its chosen yaw arc');
});

$test('camera eye clears rails at legal and diagnostic pose bounds', static function() use ($expect): void {
	$camera = new Camera;
	foreach([.05,.28,.72,1.18,1.5] as $pitch) foreach([.05,.85,2,3.35,5.2] as $distance) foreach([-1,0,.04] as $height)
	{
		$camera->patch(['pitch' => $pitch,'distance' => $distance,'target' => [.4,$height,-.2]]);
		$expect($camera->eye()[1] >= TableGeometry::RAIL_TOP + .03, 'Camera eye entered the rails');
	}
});

$test('shot pullback eases with independent rise and return half-lives and stops exactly', static function() use ($expect): void {
	$camera = new Camera;
	$camera->reset();
	$distance = static function(Camera $camera): float {
		$eye = $camera->eye();
		return sqrt(array_sum(array_map(static fn(float $value, float $target): float => ($value - $target) ** 2, $eye, $camera->target)));
	};
	$state = $camera->state();
	$pose = $camera->viewPose();
	$camera->animate(.1, true);
	$expect(abs($distance($camera) - 3.51) < 1e-12 && $camera->settling(), 'Pullback did not use a .10 s half-life');
	$expect($state === $camera->state() && $pose !== $camera->viewPose(), 'Pullback changed stored controls or missed the render signature');
	$camera->animate(.16, false);
	$expect(abs($distance($camera) - 3.43) < 1e-12, 'Return did not use a .16 s half-life');
	foreach([30,60,144] as $fps)
	{
		$camera->reset();
		for($frame = 0; $frame < $fps / 2; $frame++) $camera->animate(1 / $fps, true);
		$expect(abs($distance($camera) - 3.66) < 1e-12, 'Pullback depends on frame rate');
		for($frame = 0; $frame < $fps; $frame++) $camera->animate(1 / $fps, true);
		$expect(!$camera->settling() && abs($distance($camera) - 3.67) < 1e-12);
		for($frame = 0; $frame < $fps * 2; $frame++) $camera->animate(1 / $fps);
		$expect(!$camera->settling() && $pose === $camera->viewPose(), 'Pullback did not return to an exact idle pose');
	}
});

$test('canonical and far camera composition retain their established orbit and debug fields', static function() use ($expect): void {
	$camera = new Camera;
	$camera->beginGameplayView(); $camera->animate(2);
	$expect($camera->state() === ['yaw' => -.42,'pitch' => .72,'distance' => 3.35,'target' => [0.0,0.0,0.0],'transitioning' => false]);
	foreach([2.0,3.35,5.2] as $distance) foreach([.28,.72,1.18] as $pitch) foreach([-2.8,0,1.2] as $aim)
	{
		$camera->behind(-1.1, .5, $aim);
		$camera->patch(['pitch' => $pitch,'distance' => $distance]);
		$camera->follow(-1.1, .5, $aim, 10);
		$pivot = [-1.1 * .55,0.0,.5 * .55];
		$expect($camera->target === $pivot && $camera->viewPose()[1] === $pivot, 'Far framing changed');
		$expected = [$pivot[0] + cos($camera->yaw) * cos($pitch) * $distance,sin($pitch) * $distance,
			$pivot[2] + sin($camera->yaw) * cos($pitch) * $distance];
		$expect($expected === $camera->eye(), 'Far orbit changed');
	}
});

$test('projection sweep keeps close cue balls inside the safe frame and approaches the solved composition', static function() use ($expect, $project): void {
	[$limitX,$limitY] = Simulation::placementLimits();
	$simulation = new Simulation;
	foreach($simulation->balls as $ball) $ball->active = false;
	$positions = [];
	foreach([-1,-.75,-.25,0,.25,.75,1] as $x) foreach([-1,-.5,0,.5,1] as $z)
	if($simulation->placementProblem($x * $limitX, $z * $limitY) === null) $positions[] = [$x * $limitX,$z * $limitY];
	$expect(count($positions) >= 25, 'Projection sweep lost its legal corner/rail/pocket positions');
	$checked = 0;
	foreach($positions as [$x,$z]) for($angle = 0; $angle < 16; $angle++)
	foreach([.28,.5,.72,1.18] as $pitch) foreach([.85,.9,1.0,1.2,1.5,1.999,2.0,3.35,5.2] as $distance)
	{
		$aim = $angle * M_PI / 8;
		$camera = new Camera;
		$camera->behind($x, $z, $aim);
		$camera->patch(['pitch' => $pitch,'distance' => $distance]);
		$camera->follow($x, $z, $aim, 10);
		$expect(!$camera->settling() && $camera->eye()[1] >= TableGeometry::RAIL_TOP + .03);
		foreach([[720,1280],[800,800],[1024,768],[1280,720],[1680,720]] as [$width,$height])
		{
			$point = [$x,TableGeometry::TABLETOP_TOP + Ball::RADIUS,$z];
			[$ndcX,$ndcY,$ndcZ,$w] = $project($camera, $point, $width, $height);
			$case = "cue=($x,$z), aim=$aim, pitch=$pitch, zoom=$distance, viewport={$width}x$height";
			$expect(is_finite($ndcX) && is_finite($ndcY) && $ndcZ > -1 && $ndcZ < 1 && $w > 0, $case);
			if($distance <= 1.2)
			{
				// Include the ball's silhouette, not just its centre, inside a 5% inset on every edge.
				foreach([[0,0,0],[Ball::RADIUS,0,0],[-Ball::RADIUS,0,0],[0,Ball::RADIUS,0],[0,-Ball::RADIUS,0],
					[0,0,Ball::RADIUS],[0,0,-Ball::RADIUS]] as $offset)
				{
					[$sx,$sy] = $project($camera, array_map(static fn(float $a, float $b): float => $a + $b, $point, $offset), $width, $height);
					$expect(abs($sx) < .9 && abs($sy) < .9, "Close cue left the safe frame: $case, ndc=($sx,$sy)");
				}
			}
			if($distance === .9) $expect(abs($ndcY + .45) < .02, "Close composition failed to approach -.45: $case");
			if($distance === .85) $expect(abs($ndcY + .45) < 1e-10 && abs($ndcX) < 1e-10, "Solved composition missed: $case");
			[$px,$py] = $project($camera, [$x,.012,$z], $width, $height);
			$picked = $camera->tablePoint(($px + 1) / 2, (1 - $py) / 2, $width, $height);
			$expect($picked !== null && hypot($picked[0] - $x, $picked[1] - $z) < 1e-9, "Look target broke cloth picking: $case");
			$checked++;
		}
	}
	$expect($checked >= 72000, 'Projection sweep did not cover the full pose grid');
});

$test('close orbit and wheel framing preserve follow intent and diagnostic target semantics', static function() use ($expect, $cameraGame, $cameraFrame, $project): void {
	$game = $cameraGame();
	$camera = $game->camera;
	$yaw = $camera->yaw;
	$cue = $game->simulation->cue();
	$cameraFrame($game, [], [['type' => 'wheel','y' => 100]]);
	$expect($camera->distance === .85 && $camera->yaw === $yaw && !$camera->settling());
	$point = [$cue->x,Ball::RADIUS + .012,$cue->y];
	[$x,$y] = $project($camera, $point);
	$expect(abs($x) < 1e-10 && abs($y + .45) < 1e-10, 'Zooming the canonical view did not frame the cue');
	for($step = 0; $step < 16; $step++)
	{
		$camera->orbit(50, $step % 2 ? 30 : -30, .6);
		[$x,$y] = $project($camera, $point);
		$expect(abs($x) < 1e-10 && abs($y + .45) < 1e-10, 'Close manual orbit lost the cue pivot');
	}
	$camera->zoom(10000);
	$expect($camera->target === [0.0,0.0,0.0] && $camera->viewPose()[1] === $camera->target);
	$camera->zoom(-10000);
	$camera->patch(['target' => [.4,.1,-.2]]);
	$expect($camera->target === [.4,.1,-.2] && $camera->viewPose()[1] === $camera->target, 'Debug target did not set pivot and look target together');
	$camera->resumeFollow(); $camera->follow($cue->x, $cue->y, .4, 10);
	$expect($camera->viewPose()[1] !== $camera->target, 'Follow did not recover the separated look target');
	$before = $camera->viewPose();
	$camera->suspendFollow(true);
	for($frame = 0; $frame < 120; $frame++) $camera->follow(1, .5, 2, 1 / 60);
	$expect(!$camera->settling() && $before === $camera->viewPose(), 'Close framing animated suspended follow');
});

$test('close framing joins the far view smoothly and survives zoom during the opening', static function() use ($expect, $cameraGame, $cameraFrame, $project): void {
	$game = $cameraGame();
	$camera = $game->camera;
	$cue = $game->simulation->cue();
	$camera->behind($cue->x, $cue->y, .4);
	$camera->patch(['distance' => 2.0]); $camera->follow($cue->x, $cue->y, .4, 10);
	$far = $camera->viewPose();
	$camera->patch(['distance' => 2.0 - 1e-6]); $camera->follow($cue->x, $cue->y, .4, 10);
	foreach($far[1] as $index => $value) $expect(abs($camera->viewPose()[1][$index] - $value) < 1e-10, 'Framing popped at two metres');
	$game = new Game;
	$cameraFrame($game, ['actions' => ['start']]);
	$cameraFrame($game, [], [['type' => 'wheel','y' => 100]]);
	$expect($game->camera->transitioning());
	for($frame = 0; $frame < 100; $frame++) $cameraFrame($game);
	$expect(!$game->camera->settling() && $game->camera->distance === .85);
	$cue = $game->simulation->cue();
	[$x,$y] = $project($game->camera, [$cue->x,Ball::RADIUS + .012,$cue->y]);
	$expect(abs($x) < 1e-10 && abs($y + .45) < 1e-10, 'Interrupted opening did not reach its close composition');
});

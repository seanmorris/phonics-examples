<?php
declare(strict_types=1);

use PhpBilliards\Game;
use PhpBilliards\Geometry\TableGeometry;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Simulation;
use PhpBilliards\Physics\Table;
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
		// Wheel zoom may ease the held view's table framing, but it never hands the camera back to follow.
		for($frame = 0; $frame < 60; $frame++) $cameraFrame($game, [], [], ['right' => .5]);
		$expect($game->camera->yaw === $before['yaw'], 'Wheel zoom released manual orbit');
		if($boundary === 'shot-end')
		{
			// The shot's end resumes follow by aiming along the held view, so the camera keeps its angle until aim moves.
			$game->simulation->phase = 'moving'; $cameraFrame($game);
			$game->simulation->phase = 'aim'; $cameraFrame($game);
			$expect($game->camera->yaw === $before['yaw'], 'shot-end swung the held view round');
			for($frame = 0; $frame < 30; $frame++) $cameraFrame($game, [], [], ['right' => .5]);
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

$test('manual wheel composition eases at every frame rate and returns to idle', static function() use ($expect): void {
	foreach([false,true] as $held) foreach([[0.0,0.0],[1.1,.5]] as [$x,$z])
	{
		$camera = new Camera;
		if($held) { $camera->behind($x, $z, .4); $camera->orbit(30, 0, .6); }
		else { $camera->reset(); $camera->follow($x, $z, .4, 0); }
		$camera->patch(['distance' => 2.0]);
		$yaw = $camera->yaw;
		$before = [$camera->target,$camera->viewPose()[1]];
		$camera->zoom(-120);
		$expect($before === [$camera->target,$camera->viewPose()[1]] && $camera->settling(), 'Manual wheel composition jumped');
		$destination = clone $camera;
		$destination->animate(10);
		$sample = null;
		foreach([30,60,144] as $fps)
		{
			$animated = clone $camera;
			for($frame = 0; $frame < $fps / 2; $frame++) $animated->animate(1 / $fps);
			$pose = [$animated->target,$animated->viewPose()[1]];
			$expect($pose !== $before && $pose !== [$destination->target,$destination->viewPose()[1]], 'Manual composition did not ease');
			if($sample !== null) foreach($sample as $part => $vector) foreach($vector as $index => $value)
			$expect(abs($value - $pose[$part][$index]) < 1e-12, 'Manual composition depends on frame rate');
			$sample = $pose;
			for($frame = 0; $frame < $fps * 2; $frame++) $animated->animate(1 / $fps);
			$expect(!$animated->settling() && $animated->viewPose() === $destination->viewPose(), 'Manual zoom never reached idle');
			$animated->follow(-$x, -$z, 2, 1);
			$expect($animated->yaw === $yaw && !$animated->settling(), 'Wheel enabled or released follow');
		}
		$camera->animate(.05);
		$camera->zoom(120);
		$camera->animate(10);
		$expect(!$camera->settling() && $camera->target === $before[0] && $camera->viewPose()[1] === $before[1], 'Second wheel event retained an old destination');
		foreach(['reset','patch','opening','follow'] as $boundary)
		{
			$cancelled = clone $camera;
			$cancelled->zoom(-120);
			if($boundary === 'patch') $cancelled->patch(['target' => [.4,.1,-.2]]);
			elseif($boundary === 'opening') { $cancelled->beginGameplayView(); $cancelled->animate(2); }
			elseif($boundary === 'follow') { $cancelled->resumeFollow(); $cancelled->follow($x, $z, .4, 10); }
			else $cancelled->reset();
			$pose = $cancelled->viewPose();
			$cancelled->animate(1);
			$expect(!$cancelled->settling() && $cancelled->viewPose() === $pose, "$boundary retained manual zoom motion");
		}
	}
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
		// The far view frames the table at a point on the line from the cue ball to the table centre.
		$pivot = $camera->target;
		$blend = 1 - $pivot[0] / -1.1;
		$expect($camera->viewPose()[1] === $pivot && $pivot[1] === 0.0 && abs($pivot[2] - .5 * (1 - $blend)) < 1e-12
			&& $blend >= -1e-12 && $blend <= 1 + 1e-12, 'Far framing left the line from the cue ball to the table centre');
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
	$expect($camera->distance === .85 && $camera->yaw === $yaw && $camera->settling());
	for($frame = 0; $frame < 180; $frame++) $cameraFrame($game);
	$expect(!$camera->settling());
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
	$camera->animate(10);
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
	// The table framing is solved numerically, so continuity holds to well under a micron rather than exactly.
	foreach($far[1] as $index => $value) $expect(abs($camera->viewPose()[1][$index] - $value) < 1e-6, 'Framing popped at two metres');
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

$test('the table view stays fitted around a corner ball through a full orbit', static function() use ($expect, $cameraGame, $cameraFrame, $project): void {
	// A fixed blend between the ball and the table centre framed the corner: facing out over the rails left most of
	// the cloth off screen. The framing is now fitted to each heading, and orbiting refits it as the view turns.
	$game = $cameraGame();
	$cue = $game->simulation->cue();
	$cue->x = $cue->previousX = -1.15;
	$cue->y = $cue->previousY = .55;
	$game->camera->resumeFollow();
	for($frame = 0; $frame < 240; $frame++) $cameraFrame($game);
	$onScreen = static fn(array $point): bool => abs($point[0]) <= 1 && abs($point[1]) <= 1;
	$cloth = [];
	for($i = 0; $i <= 24; $i++) for($j = 0; $j <= 12; $j++)
	$cloth[] = [-Table::WIDTH / 2 + Table::WIDTH * $i / 24,TableGeometry::TABLETOP_TOP,-Table::HEIGHT / 2 + Table::HEIGHT * $j / 12];
	$ball = [$cue->x,TableGeometry::TABLETOP_TOP + Ball::RADIUS,$cue->y];
	$cameraFrame($game, [], [['type' => 'pointer','button' => 3,'action' => 'down','x' => 400,'y' => 500]]);
	$worstCloth = 1.0;
	$inBand = true;
	$onLine = true;
	$largestStep = 0.0;
	$last = $game->camera->target;
	for($step = 1; $step <= 72; $step++)
	{
		// 5 degrees a step: a full turn.
		$cameraFrame($game, [], [['type' => 'pointer','button' => 3,'action' => 'move','x' => 400 + $step * 5 / .006 * M_PI / 180,'y' => 500]]);
		$shown = count(array_filter($cloth, static fn(array $point): bool => $onScreen($project($game->camera, $point))));
		$worstCloth = min($worstCloth, $shown / count($cloth));
		[$x,$y] = $project($game->camera, $ball);
		$inBand = $inBand && abs($x) <= .8 + 1e-9 && $y <= .8 + 1e-9 && $y >= -.5 - 1e-9;
		[$targetX,,$targetZ] = $game->camera->target;
		$onLine = $onLine && abs($targetX * $cue->y - $targetZ * $cue->x) < 1e-9 && $targetX / $cue->x >= -1e-9 && $targetX / $cue->x <= 1 + 1e-9;
		$largestStep = max($largestStep, hypot($targetX - $last[0], $targetZ - $last[2]));
		$last = $game->camera->target;
	}
	$cameraFrame($game, [], [['type' => 'pointer','button' => 3,'action' => 'up','x' => 400,'y' => 500]]);
	$expect($inBand, 'The cue ball left its band on screen during the turn');
	$expect($onLine, 'The framing left the line from the cue ball to the table centre');
	$expect($worstCloth >= .9, 'Only ' . round($worstCloth * 100) . '% of the cloth stayed in view during the turn');
	$expect($largestStep < .2, 'The framing jumped ' . round($largestStep, 3) . ' m in one 5 degree step');
	// With the cue ball off the table there is nothing to fit around, and the view keeps its own pivot.
	$cue->active = false;
	$target = $game->camera->target;
	$game->camera->orbit(40, 0, .6, null);
	$expect($game->camera->target === $target, 'An orbit without a cue ball moved the pivot');
});

$test('orbiting tilts up to a steep view from above, short of straight down', static function() use ($expect, $cameraGame, $cameraFrame): void {
	$game = $cameraGame();
	$cameraFrame($game, [], [['type' => 'pointer','button' => 3,'action' => 'down','x' => 640,'y' => 300]]);
	for($step = 1; $step <= 40; $step++)
	$cameraFrame($game, [], [['type' => 'pointer','button' => 3,'action' => 'move','x' => 640,'y' => 300 + 10 * $step]]);
	$expect($game->camera->pitch === 1.4, 'Dragging over did not stop at the 80 degree limit');
	for($step = 1; $step <= 80; $step++)
	$cameraFrame($game, [], [['type' => 'pointer','button' => 3,'action' => 'move','x' => 640,'y' => 700 - 10 * $step]]);
	$expect($game->camera->pitch === .28, 'Dragging back did not stop at the low limit');
});

$test('when the balls stop, the next aim runs the way the camera faces, so the view does not swing round', static function() use ($expect, $cameraGame): void {
	$frame = static function(Game $game, array $events = []): void {
		$game->input([], [], $events, [], [0,0], 1 / 60, 1280, 720);
		$game->step(1 / 60);
	};
	$game = $cameraGame();
	$game->camera->resumeFollow();
	for($step = 0; $step < 240; $step++) $frame($game);
	// Orbit well away from behind the cue, then shoot from that view.
	$frame($game, [['type' => 'pointer','button' => 3,'action' => 'down','x' => 400,'y' => 500]]);
	for($step = 1; $step <= 10; $step++) $frame($game, [['type' => 'pointer','button' => 3,'action' => 'move','x' => 400 + 20 * $step,'y' => 500]]);
	$frame($game, [['type' => 'pointer','button' => 3,'action' => 'up','x' => 600,'y' => 500]]);
	// Away from the rack, off the head rail: the cue ball comes back to rest on the cloth.
	$shotAim = M_PI;
	$expect(abs(Game::wrapAngle($game->camera->yaw + M_PI - $shotAim)) > .5, 'The orbit did not leave the shot line');
	$game->simulation->shoot($shotAim, .3);
	for($step = 0; $step < 60 * 30 && $game->simulation->phase === 'moving'; $step++) $frame($game);
	$expect($game->simulation->phase === 'aim', 'The shot did not come to rest');
	$yaw = $game->camera->yaw;
	$state = json_decode(json_encode($game->status(1)), true);
	$expect(abs(Game::wrapAngle($state['aim'] - $yaw - M_PI)) < 1e-12, 'The next aim did not take the camera direction');
	$turned = 0.0;
	for($step = 0; $step < 180; $step++) { $frame($game); $turned = max($turned, abs(Game::wrapAngle($game->camera->yaw - $yaw))); }
	$expect($turned < 1e-9, 'The camera swung ' . round(rad2deg($turned), 1) . ' degrees after the balls stopped');
	$cue = $game->simulation->cue();
	[$x,,$z] = $game->camera->target;
	$expect(!$game->camera->settling() && abs($x * $cue->y - $z * $cue->x) < 1e-9 && $x / $cue->x >= 0 && $x / $cue->x <= 1,
		'The view did not settle on the table framing for the cue ball');
});

$test('undoing a shot restores the stick line it was played along and leaves the camera where it is', static function() use ($expect, $cameraGame): void {
	$frame = static function(Game $game, array $browser = [], array $events = []): void {
		$game->input($browser, [], $events, [], [0,0], 1 / 60, 1280, 720);
		$game->step(1 / 60);
	};
	$orbit = static function(Game $game, int $pixels) use ($frame): void {
		$frame($game, [], [['type' => 'pointer','button' => 3,'action' => 'down','x' => 400,'y' => 500]]);
		for($step = 1; $step <= 10; $step++) $frame($game, [], [['type' => 'pointer','button' => 3,'action' => 'move','x' => 400 + $pixels * $step / 10,'y' => 500]]);
		$frame($game, [], [['type' => 'pointer','button' => 3,'action' => 'up','x' => 400 + $pixels,'y' => 500]]);
	};
	foreach(['while the balls run' => false,'after they stop' => true] as $when => $atRest)
	{
		$game = $cameraGame();
		// Away from the rack, off the head rail, so the cue ball comes back to rest on the cloth.
		$frame($game, ['values' => ['aim' => 175,'power' => .3]]);
		for($step = 0; $step < 120; $step++) $frame($game);
		$played = $game->aim;
		$frame($game, ['actions' => ['shoot']]);
		for($step = 0; $step < 120 && $game->simulation->shots === 0; $step++) $frame($game);
		$expect($game->simulation->shots === 1, "The stroke never struck the cue ball ($when)");
		// Turn the camera while the balls run.
		$orbit($game, 200);
		if($atRest)
		{
			for($step = 0; $step < 60 * 30 && $game->simulation->phase === 'moving'; $step++) $frame($game);
			$expect(abs(Game::wrapAngle($game->aim - $played)) > .5, 'The shot did not end aiming along the turned view');
		}
		else $expect($game->simulation->phase === 'moving', 'The balls had already stopped');
		$view = $game->camera->state();
		$frame($game, ['actions' => ['reset']]);
		for($step = 0; $step < 60; $step++) $frame($game);
		$expect($game->simulation->shots === 0, "Undo did not restore the shot ($when)");
		$expect($game->aim === $played, "Undo left the stick off the line it was played along ($when)");
		$expect($game->camera->yaw === $view['yaw'] && $game->camera->pitch === $view['pitch']
			&& $game->camera->distance === $view['distance'], "Undo moved the camera ($when)");
	}
});

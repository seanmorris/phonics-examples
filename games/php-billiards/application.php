<?php
declare(strict_types=1);

require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
require_once __DIR__ . '/autoload.php';

use Phonics\Application\Context;
use Phonics\Ecs\Registry;
use Phonics\Engine;
use Phonics\FixedStep;
use Phonics\Render\Device;
use PhpBilliards\Ecs\BilliardsEntities;
use PhpBilliards\Game;
use PhpBilliards\Physics\Simulation;
use PhpBilliards\Render\FramePacer;
use PhpBilliards\Render\Hud;
use PhpBilliards\Render\Scene;

$app = new Context;
$app->lobby([
	'provider' => '@matrix/lobby'
	, 'title' => 'PHP Billiards Online'
	, 'options' => [
		'homeserver' => 'https://matrix.org'
		, 'room' => '#php-billiards:matrix.org'
		, 'configurationKey' => 'php-billiards'
	]
	, 'game' => [
		'id' => 'org.seanmorris.phonics.php-billiards.eight-ball'
		, 'protocol' => '1.0.0'
		, 'rules' => 'casual-eight-ball-v1'
		, 'players' => 2
		, 'spectators' => true
		, 'aspectRatio' => '16/9'
	]
]);
$app->browser(__DIR__ . '/browser/game.mjs');
// Browser code cannot read PHAR members, so hand the recorded samples to the bridge once.
$samples = [];
foreach(['clack' => 'clack','impact' => 'impact','drop' => 'pocket-drop','sink' => 'pocket-sink','rail' => 'rail-hit'] as $name => $file)
$samples[$name] = base64_encode((string)file_get_contents(__DIR__ . "/assets/audio/$file.mp3"));
(vrzno_env('billiardsSamples'))(json_encode($samples, JSON_THROW_ON_ERROR));
$engine = new Engine(new Device(1280, 720, 'PHP Billiards'));
$engine->clock = new FixedStep(Simulation::STEP, 6);
$engine->input->bindButton(0, [SDLK_SPACE,SDLK_RETURN,SDLK_KP_ENTER]);
$engine->input->bindButton(1, [SDLK_BACKSPACE]);
$engine->input->bindButton(2, [SDLK_x]);
$engine->input->bindButton(3, [SDLK_r]);
$engine->input->bindButton(4, [SDLK_z]);
$engine->input->bindButton(5, [SDLK_c]);
$engine->input->bindButton(6, [SDLK_q]);
$engine->input->bindButton(7, [SDLK_e]);
// The game owns its pause menu: Escape, P, and Start open it and the input handler captures input while it is up.
$engine->input->bindButton(9, [SDLK_ESCAPE,SDLK_p]);
$engine->input->bindGamepad(9, [SDL_CONTROLLER_BUTTON_START]);
$engine->input->bindButton(17, [SDLK_f]);
$engine->input->bindButton(18, [SDLK_m]);
$engine->input->bindGamepad(18, [SDL_CONTROLLER_BUTTON_BACK]);
$engine->input->bindButton(19, [SDLK_0]);
$engine->input->bindGamepad(19, [SDL_CONTROLLER_BUTTON_RIGHTSTICK]);
$engine->input->bindButton(20, [SDLK_LSHIFT,SDLK_RSHIFT]);
$engine->input->bindGamepad(20, [SDL_CONTROLLER_BUTTON_LEFTSTICK]);
$engine->input->bindButton(21, [SDLK_SLASH]);
$engine->input->bindButton(22, [SDLK_h]);
$ecs = new BilliardsEntities($engine->entities, new Game);
$scene = new Scene(__DIR__);
$hud = new Hud(__DIR__);
$width = 1280;
$height = 720;
$dpr = 1.0;
$lastFrame = null;
$frameDirty = true;
$framePacer = new FramePacer;

$engine->systems->add('php-billiards.simulation', static function(Registry $entities, float $seconds) use ($ecs, &$frameDirty): void {
	$game = $ecs->game($entities);
	$animated = $game->stroking() || $game->simulation->moving();
	$game->step($seconds);
	$ecs->reconcile($entities, $game->simulation);
	if($animated || $game->stroking() || $game->simulation->moving()) $frameDirty = true;
});

$engine->setInputHandler(static function(Engine $engine, float $elapsed) use ($ecs, $hud, &$width, &$height, &$dpr, &$frameDirty): bool {
	$game = $ecs->game($engine->entities);

	try
	{
		$viewport = json_decode((string)(vrzno_env('billiardsViewport'))(), true, flags: JSON_THROW_ON_ERROR);
		if(is_array($viewport) && count($viewport) >= 2)
		{
			$nextWidth = max(1, min(8192, (int)$viewport[0]));
			$nextHeight = max(1, min(8192, (int)$viewport[1]));
			$nextDpr = isset($viewport[2]) && is_numeric($viewport[2]) ? max(.5, min(4, (float)$viewport[2])) : 1.0;
			if($nextWidth !== $width || $nextHeight !== $height || $nextDpr !== $dpr) $frameDirty = true;
			[$width,$height,$dpr] = [$nextWidth,$nextHeight,$nextDpr];
		}

		$browser = json_decode((string)(vrzno_env('billiardsInput'))(), true, flags: JSON_THROW_ON_ERROR);
		if(!is_array($browser)) $browser = [];
	}
	catch(\Throwable)
	{
		$browser = [];
	}

	$ui = $engine->input->uiFrame();
	$controls = $engine->input->snapshot();
	$rightStick = [
		$engine->input->gamepads->axis(SDL_CONTROLLER_AXIS_RIGHTX)
		, $engine->input->gamepads->axis(SDL_CONTROLLER_AXIS_RIGHTY)
	];
	$browserChanged = isset($browser['debugCamera']);
	foreach(['values','actions','network','networkAcks'] as $name)
	$browserChanged = $browserChanged || !empty($browser[$name]);
	$controlsActive = false;
	foreach($controls as $value) if(is_numeric($value) && abs((float)$value) > 1e-6)
	{
		$controlsActive = true;
		break;
	}
	if($browserChanged || !empty($ui['events']) || $controlsActive
		|| abs($rightStick[0]) > 1e-6 || abs($rightStick[1]) > 1e-6 || $game->camera->transitioning()
		|| $game->camera->settling() || $hud->animating() || $game->idleCue() !== null
	) $frameDirty = true;
	$game->inputTheme = $engine->input->inputTheme;
	$overlayBefore = $game->overlay;
	$game->input(
		$browser
		, $controls
		, $ui['events']
		, Hud::regionsFor($game, $width, $height, $dpr)
		, $rightStick
		, $elapsed, $width, $height
	);
	$ecs->reconcile($engine->entities, $game->simulation);
	if($game->consumeFullscreenRequest()) (vrzno_env('billiardsFullscreen'))();
	$engine->userPaused = $game->overlay !== null;

	// Capture whenever a menu is or was open, or the pause key is down, so the engine never toggles its own pause.
	return $overlayBefore !== null || $game->overlay !== null || (float)($controls['button9'] ?? 0) >= .5;
});

$engine->start(
	static function(Engine $engine, float $alpha) use (
		$ecs, $scene, $hud, $framePacer, &$width, &$height, &$dpr, &$lastFrame, &$frameDirty
	): void {
		$game = $ecs->game($engine->entities);

		if(!$frameDirty && $lastFrame !== null)
		{
			$framePacer->idle();
			return;
		}
		$briefAnimation = $game->stroking() || $game->camera->transitioning() || $game->camera->settling() || $hud->animating();
		if($lastFrame !== null && !$briefAnimation && !$framePacer->ready()) return;
		$frameDirty = false;
		$status = $game->status($alpha);
		$status['inputTheme'] = $engine->input->inputTheme;
		$status['paused'] = $engine->userPaused;

		if(!Game::finite($status))
		{
			$game->simulation->rack();
			$ecs->reconcile($engine->entities, $game->simulation);
			$status = $game->status(1);
			$status['status'] = 'Recovered invalid simulation state';
		}

		$json = json_encode($status, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
		// Pullback is presentation-only: include the actual view without adding fields to published game state.
		$signature = $width . 'x' . $height . '@' . $dpr . ':' . $json
			. ':' . json_encode($game->camera->viewPose(), JSON_THROW_ON_ERROR);
		if($signature === $lastFrame && !$hud->animating()) return;

		$renderStarted = microtime(true);
		$engine->device->resize($width, $height);
		$engine->device->begin([.012,.021,.017,1]);
		$scene->render($game, $width, $height, $alpha);
		$hud->render($game, $width, $height, $dpr);
		$engine->device->present();
		$framePacer->complete($renderStarted);
		$lastFrame = $signature;
		(vrzno_env('billiardsStatus'))($json);
	}
	, static function() use ($engine, $ecs, $scene, $hud): void {
		$engine->systems->remove('php-billiards.simulation');
		$ecs->close($engine->entities);
		$hud->close();
		$scene->close();
	}
	, static function() use ($scene, $hud, $framePacer, &$lastFrame, &$frameDirty): void {
		$scene->restore();
		$hud->restore();
		$framePacer->reset();
		$lastFrame = null;
		$frameDirty = true;
	}
);

$app->ready();

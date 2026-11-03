<?php
declare(strict_types=1);

use DoomPhonics\Game\EpisodeGame;
use DoomPhonics\Game\DoomInitialization;
use DoomPhonics\Game\GameSettings;
use DoomPhonics\Menu\DoomMenuController;
use DoomPhonics\Menu\DoomOptions;
use DoomPhonics\Menu\NetworkMenuSettings;
use DoomPhonics\Render\ScreenWipe;
use Phonics\Engine;
use Phonics\Asset\Resource;
use Phonics\FixedStep;
use Phonics\Input\InputCode;
use Phonics\Render\BitmapFont;
use Phonics\Render\Device;
use Phonics\Render\FramePipeline;
use Phonics\Ui\DomTextureModal;
use Phonics\Ui\MenuRenderer;

$width = (int)($phonics->world['view']['width'] ?? 960);
$height = (int)($phonics->world['view']['height'] ?? 540);
$engine = new Engine(new Device($width, $height, 'Doom-Phonics'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$engine->clock = new FixedStep(1 / 35, 4);
$pipeline = new FramePipeline($engine->device);
$start = $phonics->world['start'] ?? ['source' => 'episode','scope' => 'E1M1'];
$initialization = DoomInitialization::fromWorld($phonics->world);
$startMap = $initialization->startMap ?? (string)$start['scope'];
$settings = new GameSettings;
$options = new DoomOptions($settings, vrzno_env('doomReadOptions'), vrzno_env('doomWriteOptions'));

$inputSettings = new \DoomPhonics\Input\InputSettings(
	$settings
	, vrzno_env('doomReadControls')
	, vrzno_env('doomWriteControls')
);

$engine->input->useBindings($inputSettings->bindings);

// In the browser, printable P reaches the cheat parser first so IDSPISPOPD and
// IDOOPSALLGIBS cannot race the fixed pause shortcut. Unmatched P presses are
// returned as pause requests by the same bridge; Escape and Start remain direct.
if(vrzno_env('doomInput')) $engine->input->bindButton(InputCode::PAUSE, [SDLK_ESCAPE]);

$engine->input->lookDeadZone = $settings->controllerDeadZone;
$wipe = new ScreenWipe($width, $height);
$game = new EpisodeGame($phonics, $engine, $pipeline, (string)$start['source'], $settings, $wipe, $initialization);
$engine->setFrameInputHandler([$game, 'frameInput']);

$phpInfoHints = new MenuRenderer(
	new BitmapFont($phonics->loader, Resource::framework('resources/font.tsj'), [
		Resource::framework('resources/buttons.tsj')
	])
);

$domTexture = vrzno_env('domTexture');
$phpInfo = new DomTextureModal($domTexture, $phpInfoHints);
$phpInfoSurface = $domTexture->fork();
ob_start();
phpinfo();
$phpInfoMarkup = (string)ob_get_clean();
$phpInfoMarkup = str_replace('</head>', '<style>.center table{margin:0 auto!important}</style></head>', $phpInfoMarkup);
$phpInfoSurface->showSurface($phpInfoMarkup);
$phonics->runtime->service('doom-dom-textures', ['PHPINFO' => $phpInfoSurface]);
$networkSettings = null;

if(isset($phonics->world['network']))
{
	$readNetworkSettings = vrzno_env('phonicsNetworkConfiguration');
	$writeNetworkSettings = vrzno_env('phonicsNetworkConfigure');

	if($readNetworkSettings && $writeNetworkSettings)
	$networkSettings = new NetworkMenuSettings(
		static fn(): string => (string)$readNetworkSettings()
		, static fn(string $value): string => (string)$writeNetworkSettings($value)
	);
}

$menu = new DoomMenuController(
	$engine
	, $game
	, $settings
	, $startMap
	, $phpInfo
	, $phpInfoHints
	, static fn(): string => (string)(vrzno_env('doomAction'))()
	, isset($phonics->world['network'])
	, $networkSettings
	, $inputSettings
	, $options
);

$phonics->runtime->service('doom-menu', $menu);
$game->enter($startMap, false, false);
$game->playTitleMusic();
(vrzno_env('sceneReady'))(null);
$lastStatus = 0;

$engine->start(static function(Engine $engine, float $alpha) use ($pipeline, $game, $menu, &$lastStatus): void {
	$viewport = vrzno_env('doomViewport');

	if($viewport)
	{
		$dimensions = json_decode((string)$viewport(), true);

		if(is_array($dimensions) && isset($dimensions[0], $dimensions[1]))
		$engine->device->resize(max(1, (int)$dimensions[0]), max(1, (int)$dimensions[1]));
	}

	$started = hrtime(true);
	$game->update();
	$game->updateMs = (hrtime(true) - $started) / 1e6;
	$started = hrtime(true);
	$pipeline->render($alpha, [0,0,0,1]);
	$game->renderMs = (hrtime(true) - $started) / 1e6;
	$now = hrtime(true);

	if($now - $lastStatus >= 100_000_000)
	{
		$lastStatus = $now;
		$status = vrzno_env('doomStatus');

		if($status) $status(json_encode($game->status() + ['menu' => $menu->status()], JSON_THROW_ON_ERROR));
	}
}, static function() use ($menu, $game, $pipeline, $phonics): void {
	$menu->close();
	$game->close();
	$pipeline->close();
	$phonics->close();
});

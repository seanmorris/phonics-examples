<?php
declare(strict_types=1);
use Phonics\Engine;
use Phonics\Render\Device;
use Phonics\Render\Camera;
use Phonics\Render\BitmapFont;
use Phonics\Render\ImageRenderer;
use Phonics\Render\RectRenderer;
use Phonics\Asset\Assets;
use Phonics\Asset\Resource;
use Phonics\Tiled\WorldStream;
use Phonics\Ui\DomTextureModal;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuInput;
use Phonics\Ui\MenuRenderer;
use PhpNoid\Playfield;
use PhpNoid\Module;
use PhpNoid\Component\Paddle;
use PhpNoid\Render\Hud;

$engine = new Engine(new Device(Hud::SCREEN_WIDTH, Hud::HEIGHT, 'PHP-Noid'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$field = new Playfield($phonics, count($phonics->world['maps']));
$field->input = $engine->controls;
// Extra logical buttons preserve A/D movement and existing fire bindings.
$engine->input->bindButton(17, [SDLK_b]);
$engine->input->bindButton(18, [SDLK_a]);
$konami = ['up', 'up', 'down', 'down', 'left', 'right', 'left', 'right'];
$engine->input->sequences->add('php-noid.konami.keyboard', [...$konami, 17, 18], 2);
$engine->input->sequences->add('php-noid.konami.pad', [...$konami, 1, 0], 2);
Module::systems($field);
$rects = new RectRenderer;
$camera = new Camera(Hud::PLAYFIELD_LEFT, 0, Hud::PLAYFIELD_WIDTH, Hud::HEIGHT);
$hud = new Hud(new BitmapFont($phonics->loader, __DIR__ . '/assets/font.tsj', [__DIR__ . '/assets/hud.tsj']));
$background = null;
$backgroundMap = null;
$assets = (vrzno_env('createAssetScope'))();

$hintRenderer = new MenuRenderer(
	new BitmapFont($phonics->loader, Resource::framework('resources/font.tsj'), [
		Resource::framework('resources/buttons.tsj')
	])
);

$phpInfo = new DomTextureModal(vrzno_env('domTexture'), $hintRenderer);
$title = true;
$ready = false;
$engine->userPaused = true;
$titleInput = new MenuInput;

$titleMenu = new Menu([
	'start' => [
		'label' => 'Start game'
		, 'disabled' => static function() use (&$ready): bool { return !$ready; }
		, 'callback' => static function(Menu $menu) use (&$title, $engine): void {
			$title = false;
			$menu->close();
			$engine->userPaused = false;
			$engine->input->suppressHeldUi();
		}
	]
	, 'php-info' => [
		'label' => 'PHP info'
		, 'callback' => static function() use ($phpInfo): void {
			ob_start();
			phpinfo();
			$phpInfo->show('PHP info', (string)ob_get_clean());
		}
	]
], 'PHP-Noid'
);

$titleMenu->open();

$stream = new WorldStream(
	$phonics->loader->world($phonics->world['sourceUrl'])
	, static fn(string $path, bool $retry) => $assets->request($path, $retry)
	, static fn(string $path) => $assets->release($path)
	, 0
	, 0
);

$pendingStage = null;
$field->loadStage = static function(int $index) use (&$pendingStage, $field): void {
	$pendingStage = $index;
	$field->state->phase = 'loading';
};

$start = $phonics->world['start'];
$index = 0;

foreach($phonics->world['maps'] as $i => $entry)
{
	if(Assets::relative($phonics->world['sourceUrl'], $entry['fileName']) !== $start) continue;

	$index = $i;
	break;
}

($field->loadStage)($index);

$engine->setInputHandler(
	static function(
		Engine $engine
		, float $elapsed
	) use (
		&$pendingStage
		, &$ready
		, &$title
		, $titleMenu
		, $titleInput
		, $phpInfo
		, $field
		, $phonics
		, $stream
	): bool {
		$pointer = $field->pointer;
		$captured = false;
		$action = (string)(vrzno_env('phpNoidAction'))();

		foreach($engine->input->sequences->drain() as $match)
		{
			if(!str_starts_with($match['name'], 'php-noid.konami.') || $phpInfo->isOpen()) continue;

			$field->state->infiniteLives = true;
			$field->state->notice = 'INFINITE LIVES';
			$field->state->banner = 3;
		}

		if($phpInfo->isOpen())
		{
			if($action === 'close-info')
			{
				$phpInfo->close();
				$engine->input->suppressHeldUi();
				$engine->userPaused = true;
				$captured = true;
			}
			else
			{
				$captured = $phpInfo->update($engine, $elapsed, $titleMenu);
			}

			$pointer->reset();
		}
		elseif($title)
		{
			if(in_array($action, ['start', 'php-info'], true))
			{
				$titleMenu->select($action);
				$titleMenu->activate();
			}

			$ui = $engine->input->uiFrame(false, true);
			$buttons = $ui['buttons'];
			$buttons['confirm'] = ($buttons['confirm'] ?? false) || ($buttons['pause'] ?? false);
			$buttons['pause'] = $buttons['back'] = false;
			$titleInput->update($titleMenu, $buttons, min(.25, max(0, $elapsed)), $ui['events']);
			$engine->userPaused = $title;
			$pointer->reset();
			$captured = true;
		}
		else
		{
			$ui = $engine->input->uiFrame();

			if($pointer->generation !== $ui['generation'] || !$ui['focused']) $pointer->reset();

			$pointer->generation = $ui['generation'];

			foreach($ui['events'] as $event)
			{
				if($event['type'] !== 'pointer') continue;

				if($event['action'] === 'down' && $event['button'] === 3)
				{
					$engine->userPaused = !$engine->userPaused;
					$pointer->reset();
					continue;
				}

				if($engine->userPaused || in_array($field->state->phase, ['clearing', 'loading', 'entering'], true))
				continue;

				if((float)$event['x'] < Hud::WIDTH)
				{
					$pointer->reset();
					continue;
				}

				$pointer->x = Hud::PLAYFIELD_LEFT + (float)$event['x'] - Hud::WIDTH;

				if(($event['button'] ?? 0) !== 1) continue;

				if($event['action'] === 'down') $pointer->held = $pointer->tap = true;
				elseif($event['action'] === 'up') $pointer->held = false;
			}

			if($engine->userPaused) $pointer->reset();
		}

		if($pendingStage === null) return $captured;

		$entry = $phonics->world['maps'][$pendingStage];
		$stream->update((float)$entry['x'], (float)$entry['y'], (float)$entry['width'], (float)$entry['height']);

		if($stream->errors)
		{
			$field->state->error = implode('; ', $stream->errors);

			if(!$ready) (vrzno_env('sceneReady'))($field->state->error);

			return true;
		}

		$maps = $stream->loaded();
		$key = $stream->world->identity($entry);

		if(!isset($maps[$key])) return true;

		$map = clone $maps[$key];
		$map->x = $map->y = 0;
		$field->stage($map, $pendingStage);
		$pendingStage = null;

		if(!$ready)
		{
			$ready = true;
			(vrzno_env('sceneReady'))(null);
		}

		return $captured;
	}
);

$engine->start(static function() use (
	$field
	, $rects
	, $camera
	, $hud
	, $engine
	, $phpInfo
	, $titleMenu
	, &$title
	, &$background
	, &$backgroundMap
): void {
	[$width, $height, $pixelRatio, $displayWidth] = $title
		? json_decode((string)(vrzno_env('phpNoidTitleSize'))(), true)
		: [Hud::SCREEN_WIDTH, Hud::HEIGHT, 1, Hud::SCREEN_WIDTH];

	$engine->device->resize($width, $height);

	$engine->device->begin([.025, .035, .085, 1]);

	if($title)
	{
		glViewport(0, 0, $width, $height);
		$hud->renderTitle($titleMenu->selected, $width, $height, $pixelRatio, $displayWidth);
	}
	elseif(!$title)
	{
		glViewport(Hud::WIDTH, 0, Hud::PLAYFIELD_WIDTH, Hud::HEIGHT);

		if($field->map !== null && $field->map !== $backgroundMap)
		{
			$background?->close();
			$backgroundMap = $field->map;
			$background = new ImageRenderer($backgroundMap);
		}

		$background?->render($camera);
		\PhpNoid\Render\Scene::draw($field, $rects, Hud::PLAYFIELD_LEFT, Hud::PLAYFIELD_WIDTH);
		glViewport(0, 0, Hud::WIDTH, Hud::HEIGHT);
		$hud->render($field, $engine->userPaused, (int)(vrzno_env('phpNoidBest'))());
	}

	$r = $field->registry();
	$paddle = isset($field->paddle) ? $r->get($field->paddle, Paddle::class) : null;
	glViewport(0, 0, $width, $height);
	$phpInfo->render($width, $height, max(2, (int)round(2 * $pixelRatio)));

	(vrzno_env('phpNoidStatus'))(json_encode((array)$field->state + [
		'paused' => $engine->userPaused
		, 'powers' => $paddle?->powers ?? []
		, 'capsules' => $field->capsules
		, 'title' => $title
		, 'titleSelection' => $titleMenu->selected
		, 'phpInfo' => $phpInfo->state()
		, 'hud' => $hud->snapshot()
	]));

	$field->state->events = [];
}, static function() use (
	$assets
	, $rects
	, $hud
	, $hintRenderer
	, $phonics
	, $stream
	, $phpInfo
	, $titleMenu
	, &$background
): void {
	$stream->close();
	$assets->close();
	$phpInfo->close();
	$titleMenu->close();
	$hintRenderer->close();
	$background?->close();
	$hud->close();
	$rects->close();
	$phonics->close();
}, static function() use ($rects, $hud, $hintRenderer, &$background): void {
	$background?->restore();
	$hud->restore();
	$hintRenderer->restore();
	$rects->restore();
});

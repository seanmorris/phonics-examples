<?php
declare(strict_types=1);

use Phonics\Asset\Resource;
use Phonics\Engine;
use Phonics\Render\BitmapFont;
use Phonics\Render\Device;
use Phonics\Ui\Menu;
use Phonics\Ui\MenuInput;
use Rpsc\Board;
use Rpsc\Bot;
use Rpsc\Render\Scene;
use Rpsc\Session;
use Rpsc\Ui\Table;

$engine = new Engine(new Device(720, 960, 'RPSC'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;

$font = new BitmapFont(
	$phonics->loader
	, Resource::framework('resources/font.tsj')
	, [Resource::framework('resources/buttons.tsj')]
);

$scene = new Scene($font, $phonics->loader->map(__DIR__ . '/worlds/rpsc.tmj'));
$menu = new Menu([], 'Rock Paper Scissors Checkers');
$navigation = new MenuInput;
$table = new Table;

/**
 * Read one stored option.
 *
 * @param string $name level, side, or effects.
 * @return string The stored value, or the browser module's default.
 */
$option = static fn(string $name): string => (string)(vrzno_env('rpscOptionGet'))($name);

/**
 * Store one option.
 *
 * @param string $name level, side, or effects.
 * @param string $value Value to store; the browser module refuses one it does not know.
 * @return bool Whether the value was accepted and saved.
 */
$setOption = static fn(string $name, string $value): bool => (bool)(vrzno_env('rpscOptionSet'))($name, $value);

// A game left unfinished comes back as Continue; anything else in storage is ignored.
$session = Session::restore((string)(vrzno_env('rpscLoad'))());
$page = 'title';
$uiTime = 0.0;
$counts = [];
$held = [];
$setPage = null;
// While an online table is live the browser holds the game, and this is its view as last published.
$online = null;
$onlineJson = '';

if($session !== null && $session->game->phase === 'over') $session = null;

/**
 * Put the local game on the table.
 *
 * @return void The table shows the unfinished game, or a fresh placement when there is none.
 */
$show = static function() use (&$session, $table): void {
	// Behind the title, the table shows a fresh placement so the board is never empty.
	$table->show(($session ?? new Session(1))->view());
};

/**
 * Start a game against the computer with the stored level and side.
 *
 * @return void A new session replaces the old one, and the table opens on its placement.
 */
$start = static function() use (&$session, &$setPage, $option, $table, $show): void {
	$level = in_array($option('level'), Bot::LEVELS, true) ? $option('level') : 'normal';
	$seed = (int)(vrzno_env('rpscSeed'))();
	$side = match($option('side'))
	{
		'red' => Board::RED,
		'random' => $seed % 2,
		default => Board::BLACK
	};
	$session = new Session($seed, $level, $side);
	// A new game is dealt, not slid into place from the last one.
	$table->reset();
	$show();
	$setPage('game');
};

$helpText = [
	['heading' => 'Goal']
	, 'Take every opposing piece, or fill the four crowned squares on the far row.'
	, ['heading' => 'The board']
	, 'Each side plays on the squares of the other side\'s colour. Pieces move diagonally, so the two sides never block or jump each other. They only meet edge to edge.'
	, ['heading' => 'The pieces']
	, 'Each side places four rocks, four papers and four scissors face down. You always see your own.'
	, ['heading' => 'A turn']
	, 'First you may challenge once. Pick one of your pieces, then an opposing piece beside it. Both are shown: rock beats scissors, scissors beats paper, paper beats rock. The loser leaves the board. A tie removes nothing.'
	, 'Both pieces are then hidden again, so remember what you saw.'
	, 'Then move one piece one square diagonally forward. A piece that reaches the far row is crowned, and may also move backward.'
	, ['heading' => 'Draw']
	, 'Eighty turns in a row with no piece taken and no uncrowned piece moved end the game in a draw.'
	, ['heading' => 'Online']
	, 'Play online seats two players at a table. Each browser publishes its placement locked, with a proof that it holds four of each kind, and every challenge is checked against it.'
	, ['heading' => 'Controls']
	, ['name' => 'Pick up', 'value' => 'Tap or click a piece']
	, ['name' => 'Move', 'value' => 'Tap a dotted square']
	, ['name' => 'Challenge', 'value' => 'Tap a bracketed piece']
	, ['name' => 'Keyboard', 'value' => 'Arrows, Enter, Escape']
	, ['name' => 'Menu', 'value' => 'P, Escape or Start']
];

/**
 * Rows of the Audio page, shared by the title and pause menus.
 *
 * @return array<string,array<string,mixed>> One slider for the sound level.
 */
$audioItems = static fn(): array => [
	'effects' => [
		'label' => 'SFX level'
		, 'input' => 'number'
		, 'subType' => 'range'
		, 'min' => 0
		, 'max' => 100
		, 'step' => 5
		, 'default' => 60
		, 'valueAlign' => 'right'
		, 'subtext' => 'Moves, challenges, and results.'
		, 'get' => static fn(): int => (int)$option('effects')
		, 'set' => static fn(float $value): bool => $setOption('effects', (string)(int)$value)
	]
];

/**
 * Change page: the title menu, the game with no menu, or the pause menu over the game.
 *
 * @param string $next title, game, or pause.
 * @return void The menu is rebuilt and opened for title and pause, and closed for game.
 */
$setPage = static function(string $next) use (&$setPage, &$page, &$session, &$online, &$start, $menu, $engine, $helpText, $audioItems, $option, $setOption, $show): void {
	$page = $next;
	$engine->input->suppressHeldUi();

	if($next === 'game')
	{
		$menu->close();

		return;
	}

	$levels = ['Easy', 'Normal', 'Hard'];
	$sides = ['Black', 'Red', 'Random'];
	/**
	 * Leave the game for the title, keeping it for Continue.
	 *
	 * @return void The title menu opens over the local game.
	 */
	$quit = static function() use (&$setPage, $show): void {
		$show();
		$setPage('title');
	};

	if($next === 'title') $items = [
		'continue' => [
			'label' => 'Continue'
			, 'hidden' => static fn(): bool => $session === null || $session->game->phase === 'over'
			, 'callback' => static function() use (&$setPage): void { $setPage('game'); }
		]
		, 'new' => [
			'label' => 'New game'
			, 'children' => [
				'level' => [
					'label' => 'Opponent'
					, 'input' => 'select'
					, 'options' => $levels
					, 'default' => 'Normal'
					, 'valueAlign' => 'right'
					, 'get' => static fn(): string => ucfirst($option('level'))
					, 'set' => static fn(string $value): bool => $setOption('level', strtolower($value))
				]
				, 'side' => [
					'label' => 'You play'
					, 'input' => 'select'
					, 'options' => $sides
					, 'default' => 'Black'
					, 'valueAlign' => 'right'
					, 'subtext' => 'Black moves first.'
					, 'get' => static fn(): string => ucfirst($option('side'))
					, 'set' => static fn(string $value): bool => $setOption('side', strtolower($value))
				]
				, 'start' => ['label' => 'Start', 'callback' => static function() use (&$start): void { $start(); }]
			]
		]
		, 'online' => [
			'label' => 'Play online'
			, 'hidden' => static fn(): bool => !(vrzno_env('rpscOnlineReady'))()
			, 'callback' => static function(): void { (vrzno_env('rpscLobby'))(); }
		]
		, 'help' => ['label' => 'How to play', 'text' => $helpText]
		, 'audio' => ['label' => 'Audio', 'children' => $audioItems()]
		, 'about' => [
			'label' => 'About'
			, 'text' => [
				'Rock Paper Scissors Checkers: a checkers board, two hidden armies, and one question asked edge to edge.'
				, 'Built with Phonics.'
				, ['name' => 'Licence', 'value' => 'Apache-2.0']
			]
		]
	];
	else $items = [
		'resume' => ['label' => 'Resume', 'callback' => static function() use (&$setPage): void { $setPage('game'); }]
		, 'help' => ['label' => 'How to play', 'text' => $helpText]
		, 'audio' => ['label' => 'Audio', 'children' => $audioItems()]
		// Offline the rules take the resignation; online the room does, and both players stay to see the board.
		, 'resign' => [
			'label' => 'Resign'
			, 'hidden' => static fn(): bool => $online !== null
				? $online['phase'] === 'over'
				: $session === null || $session->game->phase === 'over'
			, 'children' => [
				'confirm' => [
					'label' => 'Resign this game'
					, 'callback' => static function() use (&$session, &$online, &$setPage): void {
						if($online !== null) (vrzno_env('rpscCommand'))(json_encode(['type' => 'resign'], JSON_THROW_ON_ERROR));
						else $session?->command(['type' => 'resign']);

						$setPage('game');
					}
				]
			]
		]
		, 'title' => ['label' => 'Quit', 'hidden' => static fn(): bool => $online !== null, 'callback' => $quit]
	];

	$menu->setItems($items);
	$menu->open();
};

$show();
$setPage('title');

/**
 * Buttons under the board for the current state, left to right.
 *
 * @return list<array{id:string,label:string,enabled?:bool}> Shuffle and Ready while placing, Play again after the
 * game, and Menu always.
 */
$buttons = static function() use (&$online, &$page, $table): array {
	if($page !== 'game') return [];

	$list = [];
	$view = $table->view();

	if(($view['arrangement'] ?? null) !== null && !($view['locked'] ?? false))
	{
		$list[] = ['id' => 'shuffle', 'label' => 'Shuffle'];
		$list[] = ['id' => 'ready', 'label' => 'Ready'];
	}
	// An online rematch is the lobby's to arrange.
	elseif(($view['phase'] ?? '') === 'over' && !$table->busy() && $online === null) $list[] = ['id' => 'again', 'label' => 'Play again'];

	$list[] = ['id' => 'menu', 'label' => 'Menu'];

	return $list;
};

$engine->setInputHandler(/**
 * Run one frame of input and game time.
 *
 * Follows the online table when one is live, routes presses to the menu or the board, lets the bot act, and moves
 * the table's animation on.
 *
 * @param Engine $engine The engine, for its input.
 * @param float $elapsed Seconds since the last frame.
 * @return bool Always true: the game keeps its own pages, so the engine never shows its pause panel.
 */
static function(
	Engine $engine
	, float $elapsed
) use (
	&$session, &$online, &$onlineJson, &$page, &$uiTime, &$setPage, &$start, &$counts, &$held, $menu, $navigation, $scene, $table, $show
): bool {
	$elapsed = min(.25, max(0, $elapsed));
	$uiTime += $elapsed;
	$action = (string)(vrzno_env('rpscAction'))();
	$frame = $engine->input->uiFrame($menu->editor !== null, $menu->isOpen);

	// An online table replaces the local game while it lasts. The browser holds it and publishes its view; every
	// change of that view may bring events for the table to show.
	$json = (string)(vrzno_env('rpscOnline'))();

	if($json !== $onlineJson)
	{
		$onlineJson = $json;
		$seated = $online !== null;
		$online = $json === '' ? null : json_decode($json, true, 16, JSON_THROW_ON_ERROR);

		if($online === null)
		{
			// The lobby closed: back to the title, where a local game left unfinished is still waiting.
			$table->reset();
			$show();
			$setPage('title');
		}
		else
		{
			if(!$seated)
			{
				$table->reset();
				$setPage('game');
			}

			$table->push(json_decode((string)(vrzno_env('rpscOnlineEvents'))(), true, 16, JSON_THROW_ON_ERROR));
			$table->show($online);
		}
	}

	if($menu->isOpen)
	{
		// The browser's region buttons press rows and a text page's scroll arrows.
		if($action !== '') $menu->press($action);

		$navigation->update($menu, $frame['buttons'], $elapsed, $frame['events'], $scene->regions);
		$held = [];

		// Backing out of the pause menu resumes; the title has nowhere to back out to.
		if(!$menu->isOpen) $setPage($page === 'title' ? 'title' : 'game');
	}
	elseif($session !== null || $online !== null)
	{
		$intents = [];
		/**
		 * Press a square, or let go of the selection for a press off the board.
		 *
		 * @param int $square Square number, or -1 for a press that hit no square.
		 * @return void Any intent the press produced is queued.
		 */
		$press = static function(int $square) use ($table, &$intents): void {
			if($square < 0) $table->cancel();
			elseif(($intent = $table->press($square)) !== null) $intents[] = $intent;
		};

		// A press usually lands on one of the page's buttons over the canvas and arrives as an action. One that reaches
		// the canvas is an empty square or off the board, unless the page has no buttons there: then it is matched
		// against the same regions here.
		foreach($frame['events'] as $event)
		{
			if($event['type'] !== 'pointer' || $event['action'] !== 'down' || ($event['button'] ?? 1) !== 1) continue;

			$table->cursor = -1;
			$hit = null;

			foreach($scene->regions as $region)
			{
				[$x, $y, $w, $h] = $region['rect'];

				if(!($region['disabled'] ?? false) && $event['x'] >= $x && $event['x'] < $x + $w && $event['y'] >= $y && $event['y'] < $y + $h) $hit = (string)$region['id'];
			}

			if($hit !== null) $action = $hit;
			else $press($scene->squareAt((float)$event['x'], (float)$event['y']));
		}

		if(str_starts_with($action, 'square-'))
		{
			$table->cursor = -1;
			$press((int)substr($action, 7));
		}
		elseif($action === 'shuffle' || $action === 'ready') $intents[] = ['type' => $action];
		elseif($action === 'again' && $online === null) $start();
		elseif($action === 'menu') $setPage('pause');

		// Keyboard and controller: a cursor walks the board, with the usual delay before a held direction repeats.
		// Escape is both Back and Pause while no menu is open: it lets go of a held piece, and opens the menu only
		// when there was nothing to let go of.
		$released = false;

		foreach(['left' => [-1, 0], 'right' => [1, 0], 'up' => [0, 1], 'down' => [0, -1], 'confirm' => null, 'back' => null, 'pause' => null] as $name => $step)
		{
			$down = ($frame['buttons'][$name] ?? 0) >= .5;
			$fire = false;

			if(!$down) unset($held[$name]);
			elseif(!isset($held[$name])) [$held[$name], $fire] = [.32, true];
			elseif($step !== null && ($held[$name] -= $elapsed) <= 0) [$held[$name], $fire] = [.11, true];

			if(!$fire) continue;

			if($step !== null) $table->steer(...$step);
			elseif($name === 'confirm' && $table->cursor >= 0) $press($table->cursor);
			elseif($name === 'confirm') $table->steer(0, 0);
			elseif($name === 'back') $released = $table->cancel();
			elseif($page === 'game' && !$released) $setPage('pause');
		}

		foreach($intents as $intent)
		{
			if($online !== null) (vrzno_env('rpscCommand'))(json_encode($intent, JSON_THROW_ON_ERROR));
			else $session->command($intent);
		}
	}

	if($session !== null && $online === null)
	{
		// The bot and the table share one clock: the bot waits until the table has shown everything so far.
		if(!$menu->isOpen) $session->tick($elapsed, $table->busy());

		if($session->consumeDirty())
		{
			$table->push($session->drain());
			$table->show($session->view());
			(vrzno_env('rpscSave'))($session->game->phase === 'over' ? '' : $session->save());
		}
	}

	$table->advance($elapsed);

	foreach($table->cues as $cue) $counts[$cue] = ($counts[$cue] ?? 0) + 1;

	$table->cues = [];

	// The game keeps its own pages and pause; the engine's generic pause panel stays out of the way.
	return true;
});

(vrzno_env('sceneReady'))(null);

$published = '';

$engine->start(/**
 * Draw one frame and publish the status the browser module reads.
 *
 * @param Engine $engine The engine, for its device and input theme.
 * @param float $alpha Unused: nothing here is interpolated between simulation steps.
 * @return void The frame is drawn, and the status is sent when it has changed.
 */
static function(
	Engine $engine
	, float $alpha
) use (
	&$session, &$online, &$page, &$uiTime, &$counts, &$published, $scene, $font, $menu, $table, $buttons
): void {
	[$width, $height, $ratio, $coarse] = json_decode((string)(vrzno_env('rpscViewport'))(), true);
	$engine->device->resize($width, $height);
	$engine->device->begin($scene->background());
	glViewport(0, 0, $width, $height);
	$font->theme = $engine->input->inputTheme;
	// The page's mute and fullscreen buttons sit in the top right corner: 30 CSS pixels tall, 44 on touch screens.
	$scene->reserve = (int)ceil(($coarse ? 52 : 38) * $ratio);
	$scene->render($table, $menu, $buttons(), $page !== 'title', $width, $height, $uiTime);
	$view = $table->view();
	$status = json_encode([
		'page' => $menu->isOpen ? ($page === 'title' ? 'title' : 'pause') : 'game'
		, 'phase' => $session === null && $online === null ? 'none' : $view['phase']
		, 'online' => $online !== null
		, 'turn' => $view['turn']
		, 'human' => $view['human']
		, 'level' => $view['level']
		, 'busy' => $table->busy()
		, 'status' => $table->status()
		, 'selected' => $table->selected
		, 'cursor' => $table->cursor
		, 'targets' => $table->targets()
		, 'victims' => $table->victims()
		, 'counts' => $view['counts']
		, 'lost' => $view['lost']
		, 'filled' => $view['filled']
		, 'winner' => $view['winner']
		, 'reason' => $view['reason']
		, 'challenged' => $view['challenged']
		, 'ply' => $view['ply']
		// Only what the player may know: opposing kinds are null until they are shown.
		, 'pieces' => array_map(static fn(array $piece): array => [$piece['square'], $piece['side'], $piece['kind'], $piece['king']], array_values($table->pieces))
		, 'moves' => $view['moves']
		, 'challenges' => $view['challenges']
		, 'saved' => $session !== null
		, 'sounds' => $counts
		, 'regions' => $scene->regions
		, 'layout' => $scene->layout
		, 'text' => $menu->text === null ? null : ['first' => $menu->text->first, 'last' => $menu->text->last()]
		, 'inputTheme' => $engine->input->inputTheme
		, 'width' => $width
		, 'height' => $height
	], JSON_THROW_ON_ERROR);

	if($status !== $published)
	{
		(vrzno_env('rpscStatus'))($status);
		$published = $status;
	}
}, /**
 * Release everything when the game closes.
 *
 * @return void The menu, the renderers, and the package context are closed.
 */
static function() use ($scene, $menu, $phonics): void {
	$menu->close();
	$scene->close();
	$phonics->close();
}, /**
 * Rebuild graphics resources after a lost WebGL context.
 *
 * @return void The scene's renderers and atlases are restored.
 */
static function() use ($scene): void {
	$scene->restore();
});

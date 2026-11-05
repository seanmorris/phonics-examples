<?php
declare(strict_types=1);

// Presenter and layout tests: no GL context, no session. Views come from fixtures and from the real reducer.
require __DIR__ . '/../autoload.php';
$engineRoot = getenv('PHONICS_ENGINE_ROOT');
foreach(array_filter([$engineRoot ? $engineRoot . '/autoload.php' : null, __DIR__ . '/../../../node_modules/@seanmorris/phonics/autoload.php', __DIR__ . '/../../../../../node_modules/@seanmorris/phonics/autoload.php']) as $engine)
if(is_file($engine)) { require_once $engine; break; }

use PhpPoker\Render\Theme;
use PhpPoker\Ui\Chips;
use PhpPoker\Ui\Layout;
use PhpPoker\Ui\Presenter;

$root = dirname(__DIR__);
$failures = 0;
$passed = 0;
$expect = static function(bool $condition, string $message) use (&$failures, &$passed): void {
	if($condition) { $passed++; return; }
	$failures++;
	fwrite(STDERR, "FAIL: $message\n");
};
$test = static function(string $name, callable $body) use ($expect, &$failures): void {
	try { $body($expect); }
	catch(\Throwable $error) { $failures++; fwrite(STDERR, "FAIL: $name threw {$error->getMessage()} at {$error->getFile()}:{$error->getLine()}\n"); }
};
$fixture = static function(string $name): array {
	return json_decode((string)file_get_contents(__DIR__ . "/fixtures/views/$name.json"), true, flags: JSON_THROW_ON_ERROR);
};
$overlaps = static function(array $a, array $b): bool {
	return $a[0] < $b[0] + $b[2] && $b[0] < $a[0] + $a[2] && $a[1] < $b[1] + $b[3] && $b[1] < $a[1] + $a[3];
};

$test('seats sit on the oval with no two plates overlapping at any seat count', static function(callable $expect) use ($overlaps): void {
	foreach([2,3,5,8] as $seats)
	{
		$plates = [];
		for($seat = 0; $seat < $seats; $seat++)
		{
			$geometry = Layout::fit(1280, 720)->seat($seat, $seats);
			$plates[] = $geometry['plate'];
			$expect($geometry['plate'][1] >= Layout::MENU_BAR + 10, "seat $seat of $seats clears the menu bar");
			$expect($geometry['plate'][0] >= 0 && $geometry['plate'][0] + $geometry['plate'][2] <= Layout::fit(1280, 720)->width(), "seat $seat of $seats stays on the field");
			$last = $geometry['cards'][4];
			$expect($last[1] + $last[3] <= Layout::fit(1280, 720)->height(), "seat $seat of $seats fan stays on the field");
		}
		foreach($plates as $i => $a) foreach($plates as $j => $b) if($i < $j)
		$expect(!$overlaps($a, $b), "plates $i and $j do not overlap at $seats seats");
		$expect(abs(Layout::fit(1280, 720)->seat(0, $seats)['plate'][0] + Layout::fit(1280, 720)->seat(0, $seats)['plate'][2] / 2 - Layout::fit(1280, 720)->width() / 2) < 1e-6, "the human sits at the bottom centre with $seats seats");
	}
});

$test('the action bar holds exactly the legal actions', static function(callable $expect) use ($fixture): void {
	$state = ['discards' => [], 'amount' => 8];
	$ids = static fn(array $view): array => array_map(static fn(array $button): string => $button['id'], Layout::fit(1280, 720)->actions($view, $state));
	$expect($ids($fixture('opening-8')) === ['fold','pass','bet'], 'opening: fold, pass, bet');
	$expect($ids($fixture('facing-raise-5')) === ['fold','call','bet'], 'facing a raise: fold, call, raise');
	$expect($ids($fixture('draw-2')) === ['clear','draw'], 'draw: keep all, draw');
	$expect($ids($fixture('showdown-5')) === ['history','next'], 'showdown: history, next');
	$opening = $fixture('opening-8');
	$opening['legalActions']['bet'] = false;
	$opening['legalActions']['openingRequired'] = true;
	$expect($ids($opening) === ['fold','pass'], 'without jacks or better there is no open button');
	$waiting = $fixture('opening-8');
	$waiting['legalActions'] = [];
	$expect($ids($waiting) === [], 'no buttons while another seat acts');
	$waiting['phase'] = 'ready';
	$expect($ids($waiting) === [], 'automatic online shuffle and deal offer no manual Deal action');
	$waiting['legalActions'] = ['next' => true];
	$expect($ids($waiting) === ['deal'], 'a ready table offers Deal only when the reducer explicitly authorizes it');
	$bet = Layout::fit(1280, 720)->actions($fixture('facing-raise-5'), ['discards' => [], 'amount' => 999])[2];
	$expect($bet['intent']['amount'] === $fixture('facing-raise-5')['legalActions']['maxBet'], 'the raise-to amount is clamped to maxBet');
});

$test('a game intent carries generation and revision and locks the bar until the view moves on', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$view = $fixture('facing-raise-5');
	$presenter->update($view);
	$intents = $presenter->input([['key' => 'c']], null, 1 / 60);
	$expect(count($intents) === 1 && $intents[0]['type'] === 'call', 'C calls');
	$expect(($intents[0]['generation'] ?? null) === $view['generation'] && ($intents[0]['revision'] ?? null) === $view['revision'], 'the intent is bound to the view');
	$expect($presenter->locked(), 'the bar locks after sending');
	$expect($presenter->input([['key' => 'f']], null, 1 / 60) === [], 'a second game intent on the same revision is ignored');
	$expect($presenter->input([['key' => 'h']], null, 1 / 60) === [] && $presenter->state()['history'], 'local controls still work while locked');
	$presenter->input([['key' => 'h']], null, 1 / 60);
	$next = $view;
	$next['revision']++;
	$presenter->update($next);
	$expect(!$presenter->locked(), 'a new revision unlocks');
	$presenter->input([['key' => 'f']], null, 1 / 60);
	$expect($presenter->locked(), 'locked again after folding');
	$errored = $next;
	$errored['error'] = 'That table has already changed';
	$presenter->update($errored);
	$expect(!$presenter->locked(), 'an error unlocks without a new revision');
});

$test('card selection is presentation state until Draw is pressed', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$view = $fixture('draw-2');
	$presenter->update($view);
	$expect($presenter->input([['key' => '1']], null, 1 / 60) === [], 'selecting a card sends nothing');
	$expect($presenter->state()['discards'] === [$view['players'][0]['cards'][0]], 'the first card is selected');
	$presenter->input([['key' => '3'], ['key' => '1']], null, 1 / 60);
	$expect($presenter->state()['discards'] === [$view['players'][0]['cards'][2]], 'toggling twice deselects');
	$intents = $presenter->input([['key' => 'd']], null, 1 / 60);
	$expect(count($intents) === 1 && $intents[0]['type'] === 'draw' && $intents[0]['cards'] === [$view['players'][0]['cards'][2]], 'D draws the selection');
	$layout = Layout::fit(1280, 720);
	$presenter2 = new Presenter($root);
	$presenter2->update($view);
	$rect = Layout::fit(1280, 720)->discardDialog()['cards'][4];
	[$x, $y] = $layout->point($rect[0] + $rect[2] / 2, $rect[1] + $rect[3] / 2);
	$presenter2->input([], ['x' => $x, 'y' => $y, 'down' => false, 'clicks' => [['x' => $x, 'y' => $y]]], 1 / 60);
	$expect($presenter2->state()['discards'] === [$view['players'][0]['cards'][4]], 'clicking a dialog card selects it');
	$stand = $presenter2->input([['key' => 'enter']], null, 1 / 60);
	$expect($stand === [], 'Enter is not bound to draw, so a stray Enter cannot discard');
});

$test('card hit regions follow the raised faces and the current viewport before rendering', static function(callable $expect) use ($fixture, $root): void {
	foreach([[1280,720,1],[780,1688,2],[1688,780,2]] as [$width,$height,$dpr])
	{
		$presenter = new Presenter($root);
		$view = $fixture('draw-2');
		$presenter->update($view);
		$presenter->resize($width, $height, $dpr);
		$layout = $presenter->layout();
		foreach($layout->discardDialog()['cards'] as $index => $rect)
		{
			$click = static function(float $x, float $y) use ($presenter, $layout): array {
				[$x, $y] = $layout->point($x, $y);
				return $presenter->input([], ['x' => $x, 'y' => $y, 'down' => false, 'clicks' => [['x' => $x, 'y' => $y]]], 0);
			};
			$click($rect[0] + $rect[2] / 2, $rect[1] + $rect[3] / 2);
			$expect($presenter->state()['discards'] === [$view['players'][0]['cards'][$index]], 'every visible face selects the corresponding card');
			$click($rect[0] + $rect[2] / 2, $rect[1] + $rect[3] - 2);
			$expect(count($presenter->state()['discards']) === 1, 'the vacated space beneath a raised card is inactive');
			[$x, $y] = $layout->point($rect[0] + $rect[2] / 2, $rect[1] - 6);
			$hover = array_filter($presenter->status()['ui']['hitRegions'], static fn(array $r): bool => Layout::hit([$x / $width, $y / $height], $r));
			$expect((bool)$hover, 'the raised upper edge advertises the same clickable region');
			$click($rect[0] + $rect[2] / 2, $rect[1] - 6);
			$expect($presenter->state()['discards'] === [], 'clicking the raised upper edge deselects');
		}
		$presenter->input([['key' => 'd']], null, 0);
		$expect(count($presenter->status()['ui']['hitRegions']) === 2, 'locked draw offers only Keep all and the menu, with no card or Draw targets');
	}
});

$test('guidance distinguishes opening bets, another seat drawing, and the human draw', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$view = $fixture('opening-8');
	$view['legalActions']['bet'] = false;
	$view['legalActions']['openingRequired'] = true;
	$presenter->update($view);
	$expect($presenter->state()['hint'] === 'Need jacks or better. Pass to stay in.', 'Fold/Pass explains the opening requirement');
	$view = $fixture('draw-2');
	$view['turn'] = 1;
	$view['legalActions'] = [];
	$presenter->update($view);
	$presenter->advance(10);
	$expect($presenter->state()['hint'] === 'Waiting for your turn to choose discards.', 'no invitation to select while another seat draws');
	$view['turn'] = 0;
	$view['legalActions'] = ['draw' => true];
	$presenter->update($view);
	$presenter->advance(10);
	$expect($presenter->state()['hint'] === $view['message'], 'the actual draw invites card selection');
	$view = $fixture('facing-raise-5');
	$view['legalActions'] = [];
	$view['waitingForConfirmation'] = true;
	$view['message'] = 'Waiting for both players to confirm the action.';
	$presenter->update($view);
	$presenter->advance(10);
	$expect($presenter->state()['hint'] === $view['message'], 'an unconfirmed turn explains the settlement wait instead of inviting another bet');
});

$test('the menu, setup and settings flow send only session intents the session knows', static function(callable $expect) use ($root): void {
	$presenter = new Presenter($root);
	$presenter->update(['screen' => 'menu', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => true]);
	$expect($presenter->input([['key' => 'enter']], null, 1 / 60) === [] && $presenter->state()['local'] === 'setup', 'Play Offline opens the local setup card');
	$presenter->input([['key' => 'arrowright'], ['key' => 'arrowright'], ['key' => 'v']], null, 1 / 60);
	$start = $presenter->input([['key' => 'enter']], null, 1 / 60);
	$expect($start === [['type' => 'start', 'players' => 6, 'variant' => 'anything']], 'Deal starts with the chosen seats and variant');
	$presenter->input([['key' => 'escape']], null, 1 / 60);
	$expect($presenter->input([['key' => 'c']], null, 1 / 60) === [['type' => 'resume']], 'C continues a saved game');
	$presenter->update(['screen' => 'settings', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => false, 'pace' => 'normal']);
	$before = $presenter->state()['mode'];
	$presenter->input([['key' => 'm']], null, 1 / 60);
	$expect($presenter->state()['mode'] !== $before, 'M toggles the visual mode');
	$expect($presenter->input([['key' => 'escape']], null, 1 / 60) === [['type' => 'menu']], 'Escape leaves settings for the menu');
});

$test('controller focus navigates menus, setup, cards and table actions through shared SDL controls', static function(callable $expect) use ($fixture, $root): void {
	$release = static fn(Presenter $presenter): array => $presenter->input([], null, 1 / 60, [], 'generic');
	$press = static function(Presenter $presenter, string $button) use ($release): array {
		$result = $presenter->input([], null, 1 / 60, [$button => 1], 'generic');
		$release($presenter);
		return $result;
	};

	$menu = new Presenter($root);
	$menu->update($fixture('menu'));
	$release($menu);
	$expect(($menu->state()['controllerFocus']['id'] ?? null) === 'menu:offline', 'controller focus starts on Play Offline');
	$press($menu, 'down');
	$expect(($menu->state()['controllerFocus']['id'] ?? null) === 'menu:continue', 'Down moves to Continue Offline');
	$expect($press($menu, 'confirm') === [['type' => 'resume']], 'A activates the focused saved-game entry');

	$setup = new Presenter($root);
	$view = $fixture('menu');
	$view['canContinue'] = false;
	$setup->update($view);
	$release($setup);
	$press($setup, 'confirm');
	$expect($setup->state()['local'] === 'setup' && ($setup->state()['controllerFocus']['id'] ?? null) === 'setup:start', 'A opens setup and focuses Deal');
	$press($setup, 'back');
	$expect($setup->state()['local'] === 'menu', 'B backs out of table setup');

	$draw = new Presenter($root);
	$drawView = $fixture('draw-2');
	$draw->update($drawView);
	$release($draw);
	$expect(($draw->state()['controllerFocus']['id'] ?? null) === 'card:0', 'a draw begins with the first card focused');
	$press($draw, 'right');
	$press($draw, 'confirm');
	$expect($draw->state()['discards'] === [$drawView['players'][0]['cards'][1]], 'A toggles the focused discard card');
	$press($draw, 'down');
	$expect(($draw->state()['controllerFocus']['id'] ?? null) === 'action:clear', 'Down leaves the card row for its draw controls');
	$press($draw, 'right');
	$intent = $press($draw, 'confirm');
	$expect(($intent[0]['type'] ?? null) === 'draw' && ($intent[0]['cards'] ?? []) === [$drawView['players'][0]['cards'][1]], 'A draws the selected cards from the focused Draw button');

	$table = new Presenter($root);
	$table->update($fixture('facing-raise-5'));
	$release($table);
	$before = $table->state()['amount'];
	$press($table, 'pageDown');
	$expect($table->state()['amount'] > $before, 'right shoulder increases the raise amount');
	$expect($press($table, 'pause') === [['type' => 'menu']], 'Start opens the game menu from the table');
});

$test('every fixture paints in both modes at four canvas sizes without a GL context', static function(callable $expect) use ($fixture, $root): void {
	foreach(['opening-8','facing-raise-5','draw-2','showdown-5','menu'] as $name)
	foreach([Theme::CLASSIC, Theme::MODERN] as $mode)
	foreach([[1280,720],[1024,768],[390,844],[2560,1440]] as [$width,$height])
	{
		$presenter = new Presenter($root);
		$presenter->update($fixture($name));
		if($presenter->state()['mode'] !== $mode)
		{
			$presenter->update(['screen' => 'settings', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => false]);
			$presenter->input([['key' => 'm']], null, 1 / 60);
			$presenter->update($fixture($name));
		}
		$canvas = $presenter->paint($width, $height);
		$instances = $canvas->instances();
		$expect(count($instances) > 20, "$name paints in $mode at {$width}x{$height}");
		foreach($instances as $instance) foreach($instance as $value) if(!is_finite($value)) { $expect(false, "$name has a non-finite instance value in $mode at {$width}x{$height}"); break 2; }
	}
});

$test('status exposes only presentation state', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$presenter->update($fixture('draw-2'));
	$status = $presenter->status();
	$expect(array_keys($status) === ['ui'], 'status is namespaced under ui');
	$expect(!isset($status['ui']['pointer']) && isset($status['ui']['discards'], $status['ui']['mode'], $status['ui']['locked']), 'status carries discards, mode and lock, not the pointer');
});

$test('no opponent sits behind the action bar at any seat count', static function(callable $expect) use ($overlaps, $fixture): void {
	$view = $fixture('facing-raise-5');
	$bar = [790, 588, 330, 80];
	foreach(Layout::fit(1280, 720)->actions($view, ['discards' => [], 'amount' => 8]) as $button)
	$expect(Layout::hit([$button['rect'][0], $button['rect'][1]], $bar) && Layout::hit([$button['rect'][0] + $button['rect'][2], $button['rect'][1] + $button['rect'][3]], $bar), 'the action bar bounds hold every button');
	foreach([2,3,4,5,6,7,8] as $seats)
	for($seat = 1; $seat < $seats; $seat++)
	{
		$geometry = Layout::fit(1280, 720)->seat($seat, $seats);
		$fan = $geometry['cards'][4];
		$block = [$geometry['plate'][0], $geometry['plate'][1], $geometry['plate'][2], $fan[1] + $fan[3] + 40 - $geometry['plate'][1]];
		$expect(!$overlaps($block, $bar), "seat $seat of $seats clears the action bar");
	}
	$expect(abs(Layout::fit(1280, 720)->seatAngle(1, 5) - (M_PI / 2 + 2 * M_PI / 5)) < 1e-9, 'five seats keep even spacing');
	$expect(Layout::fit(1280, 720)->seatAngle(7, 8) < 2 * M_PI + Layout::ACTION_CLEARANCE + 1e-9, 'the eighth seat stops short of the bar');
});

$test('the human indicator, YOU disc and centered bankroll stay with the hand at a full table', static function(callable $expect): void {
	foreach([[1280,720,1],[390,844,1],[844,390,1]] as [$width,$height,$dpr])
	{
		$layout = Layout::fit($width, $height, $dpr);
		$human = $layout->seat(0, 8);
		$left = $layout->seat(1, 8);
		$handLeft = $human['cards'][0][0];
		$handRight = $human['cards'][4][0] + $human['cards'][4][2];
		if($layout->profile === 'wide')
			$expect($human['chips'][0] > $left['plate'][0] + $left['plate'][2], 'the human bank clears the lower-left player');
		if($layout->profile !== 'landscape')
			$expect($handLeft - $human['chips'][0] < $layout->metric('handChipRadius') * 3, 'the human bank sits beside its own cards');
		$expect(abs($human['plate'][0] - $handLeft) < 1e-9 && abs($human['plate'][2] - ($handRight - $handLeft)) < 1e-9, 'the human indicator is exactly as wide as the hand');
		$size = $layout->metric('emblemSize');
		$emblem = [$human['emblem'][0] - $size / 2, $human['emblem'][1] - $size / 2, $size, $size];
		$expect($size === 32 && abs($human['plate'][0] - $human['emblem'][0] - 22) < 1e-9, 'the stroked YOU disc keeps the same visible indicator gap as CPU icons');
		$expect(abs($human['emblem'][1] - ($human['plate'][1] + $human['plate'][3] / 2)) < 1e-9, 'the YOU disc is vertically centered on the indicator');
		$caption = $layout->caption(0, 8, Chips::bank(500));
		$expect(abs($caption[0] - $human['emblem'][0]) < 1e-9 && $caption[1] > $emblem[1] + $emblem[3], 'the human total is centered below the YOU disc');
		$corner = $layout->profile === 'landscape' ? $handRight + 4 : $handLeft - 4;
		$expect(abs($human['chips'][0] - $corner) < 1e-9, 'the human chip bank hugs the available hand corner');
		$expect(abs($human['chips'][1] - ($human['cards'][0][1] + $human['cards'][0][3] + 4)) < 1e-9, 'the human chip bank uses the same bottom-corner anchor as CPU banks');
		$bounds = $layout->bankBounds(0, 8, Chips::bank(500));
		$limit = $layout->profile === 'tall' ? $layout->metric('actions')[1] - 4 : $layout->height() - 4;
		$expect($bounds !== null && $bounds[1] + $bounds[3] <= $limit + 1e-9, 'the corner bank remains clear of the field edge and phone controls');
	}
});

$test('phones get the tall profile with touch-sized controls and no overlaps', static function(callable $expect) use ($overlaps, $fixture, $root): void {
	$expect(Layout::fit(390, 844, 1)->profile === 'tall', 'a 390x844 canvas is tall');
	$expect(Layout::fit(780, 1688, 2)->profile === 'tall', 'a 2x phone is tall');
	$expect(Layout::fit(1024, 768, 1)->profile === 'wide', 'a tablet in landscape is wide');
	$expect(Layout::fit(1500, 900, 2)->profile === 'landscape', 'a short landscape canvas uses its own profile');
	$layout = Layout::fit(390, 844, 1);
	foreach([2,5,8] as $seats)
	{
		$blocks = [];
		for($seat = 0; $seat < $seats; $seat++)
		{
			$geometry = $layout->seat($seat, $seats);
			$fan = $geometry['cards'][4];
			$blocks[] = [$geometry['plate'][0], $geometry['plate'][1], $geometry['plate'][2], $fan[1] + $fan[3] - $geometry['plate'][1]];
			$expect($geometry['plate'][0] >= 0 && $geometry['plate'][0] + $geometry['plate'][2] <= $layout->width(), "tall seat $seat of $seats stays on the field");
		}
		foreach($blocks as $i => $a) foreach($blocks as $j => $b) if($i < $j) $expect(!$overlaps($a, $b), "tall blocks $i and $j do not overlap at $seats seats");
	}
	$view = $fixture('facing-raise-5');
	$buttons = $layout->actions($view, ['discards' => [], 'amount' => 8]);
	$last = end($buttons);
	$expect($last['rect'][0] + $last['rect'][2] <= $layout->width() - 15, 'the action row fits the field width');
	foreach($buttons as $button) $expect($button['rect'][3] * $layout->scale >= 38, "{$button['id']} is at least 38 device pixels tall on a phone");
	$stepper = $layout->stepper($view);
	$expect($stepper !== null && $stepper['more'][0] + $stepper['more'][2] <= $layout->width() - 15, 'the stepper row fits the field width');
	$expect($stepper['align'] === 'right' && $stepper['less'][0] - $stepper['label'][0] <= 12 && $stepper['label'][0] < $stepper['less'][0], 'the stepper label reads straight into the minus button');
	$expect(abs($stepper['label'][1] - ($stepper['less'][1] + $stepper['less'][3] / 2)) < .01, 'the stepper label is centred on its row');
	$dialog =$layout->discardDialog([1,2,3,4,5], [2,4]);
	$expect($dialog['box'][1] >= Layout::MENU_BAR && $dialog['box'][1] + $dialog['box'][3] <= $layout->height(), 'the discard dialog stays on the field');
	foreach($dialog['cards'] as $card) $expect($card[1] >= $dialog['box'][1] + 22, 'a lifted card stays below the dialog title');
	[$rowX, $rowY, $rowWidth, $rowHeight] = $dialog['buttons'];
	$expect($rowY >= $dialog['numbers'] + 6 && $rowY + $rowHeight <= $dialog['box'][1] + $dialog['box'][3], 'the draw controls have their own row inside the dialog');
	foreach($layout->actions($fixture('draw-2'), ['discards' => [], 'amount' => 0]) as $button)
	$expect($button['rect'][0] >= $rowX - 1e-9 && $button['rect'][0] + $button['rect'][2] <= $rowX + $rowWidth + 1e-9 && abs($button['rect'][1] - $rowY) < 1e-9, "{$button['id']} sits in the dialog's control row");
	foreach(['opening-8','draw-2','showdown-5','menu'] as $name)
	{
		$presenter = new Presenter($root);
		$presenter->update($fixture($name));
		$instances = $presenter->paint(390, 844)->instances();
		$expect(count($instances) > 20, "$name paints in the tall profile");
	}
});

$test('actual CSS touch targets clear every discard card and stay on screen', static function(callable $expect) use ($overlaps, $fixture): void {
	foreach([[320,500,1],[390,780,1],[780,1560,2],[844,326,1],[1688,652,2],[1280,720,1]] as [$width,$height,$dpr])
	{
		$layout = Layout::fit($width, $height, $dpr);
		$draw = $layout->actions($fixture('draw-2'), ['discards' => [], 'amount' => 0]);
		$setup = $layout->setup(8, 'anything');
		$targets = array_merge(array_column($layout->menu(true), 'rect'), array_column($draw, 'rect'), [$setup['less'],$setup['more'],$setup['variant'],$setup['start'],$setup['back']]);
		foreach($targets as $rect)
		{
			[$x,$y,$w,$h] = $layout->rect(...$rect);
			$expect($w / $dpr >= 48 - 1e-6 && $h / $dpr >= 48 - 1e-6, "touch target is 48 CSS pixels at $width x $height / $dpr");
			$expect($x >= 0 && $y >= 0 && $x + $w <= $width + 1e-6 && $y + $h <= $height + 1e-6, "target stays on screen at $width x $height / $dpr");
		}
		foreach($layout->discardDialog()['cards'] as $card) foreach($draw as $button)
		$expect(!$overlaps($card, $button['rect']), 'discard cards do not cover the Draw action');
	}
});

$test('seat indicators match their hands and CPU identity blocks sit below their icons', static function(callable $expect) use ($overlaps): void {
	foreach([[1280,720,1],[390,844,1],[844,390,1]] as [$width,$height,$dpr])
	{
		$layout = Layout::fit($width, $height, $dpr);
		foreach([2,5,8] as $seats) for($seat = 1; $seat < $seats; $seat++)
		{
			$g = $layout->seat($seat, $seats);
			$size = $layout->metric('emblemSize');
			$emblem = [$g['emblem'][0] - $size / 2, $g['emblem'][1] - $size / 2, $size, $size];
			$fan = [$g['cards'][0][0], $g['cards'][0][1], $g['cards'][4][0] + $g['cards'][4][2] - $g['cards'][0][0], $g['cards'][0][3]];
			$right = $g['outward'] > 0;
			// Landscape seats are compact: a small fan under the outer end of a plate wide enough for its text.
			if($layout->profile === 'landscape') $expect($g['plate'][2] >= $fan[2] && abs(($right ? $g['plate'][0] + $g['plate'][2] - $fan[0] - $fan[2] : $g['plate'][0] - $fan[0])) < 1e-9, "compact hand hugs the outer end of its indicator for seat $seat of $seats in {$layout->profile}");
			else $expect(abs($g['plate'][0] - $fan[0]) < 1e-9 && abs($g['plate'][2] - $fan[2]) < 1e-9, "indicator matches the hand width for seat $seat of $seats in {$layout->profile}");
			$expect(abs($fan[1] - ($g['plate'][1] + $g['plate'][3] + 8)) < 1e-9, "hand sits 8px below the indicator for seat $seat of $seats in {$layout->profile}");
			$iconEdge = $right ? $g['plate'][0] + $g['plate'][2] + 4 : $g['plate'][0] - 4;
			$expect($size === 32 && abs(($right ? $emblem[0] : $emblem[0] + $emblem[2]) - $iconEdge) < 1e-9, "icon is 32px on the outer edge for seat $seat of $seats in {$layout->profile}");
			$expect(abs($g['emblem'][1] - ($g['plate'][1] + $g['plate'][3] / 2)) < 1e-9, "icon is vertically centered on the indicator for seat $seat of $seats in {$layout->profile}");
			$expect($emblem[0] >= 4 && $emblem[0] + $emblem[2] <= $layout->width() - 4, "icon clears the field edges for seat $seat of $seats in {$layout->profile}");
			$expect($emblem[1] >= Layout::MENU_BAR + 4, "icon clears the menu bar for seat $seat of $seats in {$layout->profile}");
			$expect(abs($g['caption'][0] - $g['emblem'][0]) < 1e-9, "name and total center on the icon for seat $seat of $seats in {$layout->profile}");
			$expect($g['caption'][1] > $emblem[1] + $emblem[3], "name and total sit below the icon for seat $seat of $seats in {$layout->profile}");
			$expect(!$overlaps($emblem, $g['plate']) && !$overlaps($emblem, $fan), "icon clears plate and fan for seat $seat of $seats in {$layout->profile}");
			if($layout->profile === 'landscape') $expect(abs($g['chips'][0] - ($right ? $fan[0] - 8 : $fan[0] + $fan[2] + 8)) < 1e-9, "chip bank stands beside the compact hand for seat $seat of $seats in {$layout->profile}");
			elseif($layout->profile === 'tall')
			{
				// On a phone the bank stands in front of the fan's inner corner, inside the plate's width.
				$bank = $layout->bankBounds($seat, $seats, [1 => 20, 5 => 4, 25 => 3, 100 => 4]);
				$expect($bank[0] >= $g['plate'][0] - 1e-9 && $bank[0] + $bank[2] <= $g['plate'][0] + $g['plate'][2] + 1e-9, "chip bank stays within the hand's width for seat $seat of $seats in {$layout->profile}");
				$identity = [$g['emblem'][0] - Layout::IDENTITY / 2, $g['plate'][1], Layout::IDENTITY, 70];
				$expect(!$overlaps($bank, $identity), "a full bank clears the name and total for seat $seat of $seats in {$layout->profile}");
			}
			else $expect(abs($g['chips'][0] - ($right ? $g['plate'][0] + $g['plate'][2] + 4 : $g['plate'][0] - 4)) < 1e-9, "chip bank starts at the outer card corner for seat $seat of $seats in {$layout->profile}");
			$one = $layout->chip($seat, $seats, 1, 0);
			$five = $layout->chip($seat, $seats, 5, 0);
			$expect($right ? $five[0] < $one[0] : $five[0] > $one[0], "denomination rows mirror for seat $seat of $seats in {$layout->profile}");
		}
		$headsUp = $layout->seat(1, 2);
		$expect($headsUp['outward'] === 1, "the heads-up opponent uses the right-side identity and stack layout in {$layout->profile}");
		if($layout->profile === 'wide')
		$expect(abs($headsUp['chips'][0] - ($headsUp['plate'][0] + $headsUp['plate'][2] + 4)) < 1e-9, 'the heads-up bank stands at the right corner of its hand');
		if($layout->columns()) continue;
		$left = $layout->seat(4, 8);
		$right = $layout->seat(5, 8);
		$seam = $right['plate'][0] - ($left['plate'][0] + $left['plate'][2]);
		$target = match($layout->profile) {'wide' => 64, 'landscape' => 24, default => 8};
		$expect($seam <= $target + 1e-9, "opposite layouts close up at the eight-seat seam in {$layout->profile}");
		foreach([7,8] as $seats)
		{
			$deltas = [-1 => [], 1 => []];
			[$cardWidth] = $layout->metric('card');
			$plateWidth = $cardWidth + 4 * $layout->metric('cardStep');
			[$centreX] = $layout->metric('centre');
			[$ovalX] = $layout->metric('oval');
			for($seat = 1; $seat < $seats; $seat++)
			{
				$g = $layout->seat($seat, $seats);
				$side = $g['outward'];
				$raw = $centreX + cos($g['angle']) * $ovalX - $plateWidth / 2;
				$base = max($side > 0 ? 0 : 40, min($layout->width() - $plateWidth - ($side > 0 ? 40 : 0), $raw));
				$deltas[$side][] = $g['plate'][0] - $base;
			}
			foreach($deltas as $side => $group)
				$expect(max($group) - min($group) < 1e-9, "every seat on side $side receives one group translation at $seats seats in {$layout->profile}");
			$expect(abs($deltas[-1][0] + $deltas[1][0]) < 1e-9, "the two $seats-seat groups translate symmetrically in {$layout->profile}");
		}
	}
});

$test('column profiles seat everyone without overlap and fill the canvas', static function(callable $expect) use ($overlaps, $fixture): void {
	foreach([[390,844,1],[360,640,1],[1170,2340,3],[1536,1850,2],[844,326,1],[667,375,1],[2532,984,3]] as [$width,$height,$dpr])
	{
		$layout = Layout::fit($width, $height, $dpr);
		$expect($layout->columns(), "$width x $height uses a column profile");
		// The field takes the canvas's free axis instead of being letterboxed.
		$expect(abs($layout->width() * $layout->scale - $width) < 1.5 || abs($layout->height() * $layout->scale - $height) < 1.5, "the field touches two canvas edges at $width x $height");
		$expect($layout->width() * $layout->scale > $width * .93 && $layout->height() * $layout->scale > $height * .8, "the field fills most of the canvas at $width x $height");
		foreach([2,3,4,5,6,7,8] as $seats)
		{
			$size = $layout->metric('emblemSize');
			$blocks = [];
			for($seat = 0; $seat < $seats; $seat++)
			{
				$g = $layout->seat($seat, $seats);
				$bank = $layout->bankBounds($seat, $seats, Chips::bank(500));
				$left = min($g['plate'][0], $g['emblem'][0] - $size / 2, $bank[0]);
				$right = max($g['plate'][0] + $g['plate'][2], $g['emblem'][0] + $size / 2, $bank[0] + $bank[2]);
				$bottom = max($g['cards'][0][1] + $g['cards'][0][3], $bank[1] + $bank[3], $g['caption'][1] + ($seat === 0 ? 8 : 22));
				$blocks[$seat] = [$left, $g['plate'][1], $right - $left, $bottom - $g['plate'][1]];
				$expect($left >= 0 && $right <= $layout->tableRight() + 1e-9 && $g['plate'][1] >= Layout::MENU_BAR + 4 && $bottom <= $layout->height(), "seat $seat of $seats stays on the table at $width x $height");
			}
			foreach($blocks as $i => $a) foreach($blocks as $j => $b) if($i < $j) $expect(!$overlaps($a, $b), "seats $i and $j are apart at $seats seats, $width x $height");
			$centre = $layout->centre();
			$chip = $layout->metric('chipRadius');
			$cluster = [min($centre['pot'][0], $centre['deck'][0], $centre['pile'][0] - $centre['pile'][2] - $chip), $centre['pot'][1], 0, 0];
			$cluster[2] = max($centre['pot'][0] + $centre['pot'][2], $centre['discard'][0] + $centre['discard'][2], $centre['pile'][0] + $centre['pile'][2] + $chip) - $cluster[0];
			$cluster[3] = max($centre['bet'][1] + $centre['bet'][3], $centre['deck'][1] + $centre['deck'][3]) + 8 + 40 - $cluster[1];
			foreach($blocks as $seat => $block) $expect(!$overlaps($block, $cluster), "the pot cluster clears seat $seat at $seats seats, $width x $height");
			// Each of the pot's plates on its own, against each seat's plate, at every table size.
			foreach(['pot','bet'] as $name) for($seat = 1; $seat < $seats; $seat++)
			$expect(!$overlaps($centre[$name], $layout->seat($seat, $seats)['plate']), "the $name plate clears seat $seat's plate at $seats seats, $width x $height");
			$view = $fixture('facing-raise-5');
			foreach($layout->actions($view, ['discards' => [], 'amount' => 8]) as $button) foreach($blocks as $seat => $block)
			$expect(!$overlaps($block, $button['rect']), "{$button['id']} clears seat $seat at $seats seats, $width x $height");
			$stepper = $layout->stepper($view);
			foreach(['less','amount','more'] as $part) foreach($blocks as $seat => $block) $expect(!$overlaps($block, $stepper[$part]), "the stepper clears seat $seat at $seats seats, $width x $height");
		}
		if($layout->profile === 'tall')
		{
			// The pot's plates keep clear air beside each column's plates and sit high in the lane.
			$centre = $layout->centre();
			$leftPlate = $layout->seat(1, 8)['plate'];
			$rightPlate = $layout->seat(7, 8)['plate'];
			$expect($centre['pot'][0] - ($leftPlate[0] + $leftPlate[2]) >= 16 && $rightPlate[0] - ($centre['pot'][0] + $centre['pot'][2]) >= 16, "the pot plates stand apart from both columns at $width x $height");
			$expect($centre['pot'][1] < $layout->height() * .25, "the pot sits in the upper quarter of the phone field at $width x $height");
		}
		// Opponents run clockwise from the human's left: up the left column, then down the right.
		$tops = [];
		for($seat = 1; $seat < 8; $seat++) { $g = $layout->seat($seat, 8); $tops[$seat] = [$g['outward'], $g['plate'][1]]; }
		$expect($tops[1][0] === -1 && $tops[4][0] === -1 && $tops[5][0] === 1 && $tops[7][0] === 1, "four seats sit left and three right at $width x $height");
		$expect($tops[1][1] > $tops[2][1] && $tops[2][1] > $tops[3][1] && $tops[3][1] > $tops[4][1], "the left column climbs away from the human at $width x $height");
		$expect($tops[5][1] < $tops[6][1] && $tops[6][1] < $tops[7][1], "the right column comes back down toward the human at $width x $height");
	}
});

$test('a rejected action stops showing once the table moves on', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$view = $fixture('facing-raise-5');
	$presenter->update($view);
	$rejected = $view;
	$rejected['error'] = 'That action is unavailable';
	$presenter->update($rejected);
	$shown = static fn(Presenter $p): bool => (function(): string { return (string)($this->view['error'] ?? ''); })->call($p) !== '';
	$expect($shown($presenter), 'the error shows on the revision it was raised on');
	$moved = $rejected;
	$moved['revision']++;
	$presenter->update($moved);
	$expect(!$shown($presenter), 'the same error is dropped after the revision advances');
	$again = $moved;
	$again['error'] = 'Choose a legal amount';
	$presenter->update($again);
	$expect($shown($presenter), 'a new error on the new revision shows');
	$cleared = $again;
	$cleared['error'] = '';
	$presenter->update($cleared);
	$expect(!$shown($presenter), 'a cleared error stays cleared');
});

echo "$passed PHP Poker presenter checks passed.\n";
if($failures) { fwrite(STDERR, "$failures presenter checks failed.\n"); exit(1); }

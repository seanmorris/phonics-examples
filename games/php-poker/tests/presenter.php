<?php
declare(strict_types=1);

// Presenter and layout tests: no GL context, no session. Views come from fixtures and from the real reducer.
require __DIR__ . '/../autoload.php';
$engineRoot = getenv('PHONICS_ENGINE_ROOT');
foreach(array_filter([$engineRoot ? $engineRoot . '/autoload.php' : null, __DIR__ . '/../../../node_modules/@seanmorris/phonics/autoload.php', __DIR__ . '/../../../../../node_modules/@seanmorris/phonics/autoload.php']) as $engine)
if(is_file($engine)) { require_once $engine; break; }

use PhpPoker\Render\Theme;
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
			$geometry = Layout::seat($seat, $seats);
			$plates[] = $geometry['plate'];
			$expect($geometry['plate'][1] >= Layout::MENU_BAR + 10, "seat $seat of $seats clears the menu bar");
			$expect($geometry['plate'][0] >= 0 && $geometry['plate'][0] + $geometry['plate'][2] <= Layout::WIDTH, "seat $seat of $seats stays on the field");
			$last = $geometry['cards'][4];
			$expect($last[1] + $last[3] <= Layout::HEIGHT, "seat $seat of $seats fan stays on the field");
		}
		foreach($plates as $i => $a) foreach($plates as $j => $b) if($i < $j)
		$expect(!$overlaps($a, $b), "plates $i and $j do not overlap at $seats seats");
		$expect(abs(Layout::seat(0, $seats)['plate'][0] + Layout::seat(0, $seats)['plate'][2] / 2 - Layout::WIDTH / 2) < 1e-6, "the human sits at the bottom centre with $seats seats");
	}
});

$test('the action bar holds exactly the legal actions', static function(callable $expect) use ($fixture): void {
	$state = ['discards' => [], 'amount' => 8];
	$ids = static fn(array $view): array => array_map(static fn(array $button): string => $button['id'], Layout::actions($view, $state));
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
	$bet = Layout::actions($fixture('facing-raise-5'), ['discards' => [], 'amount' => 999])[2];
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
	$rect = Layout::discardDialog()['cards'][4];
	[$x, $y] = $layout->point($rect[0] + $rect[2] / 2, $rect[1] + $rect[3] / 2);
	$presenter2->input([], ['x' => $x, 'y' => $y, 'down' => false, 'clicks' => [['x' => $x, 'y' => $y]]], 1 / 60);
	$expect($presenter2->state()['discards'] === [$view['players'][0]['cards'][4]], 'clicking a dialog card selects it');
	$stand = $presenter2->input([['key' => 'enter']], null, 1 / 60);
	$expect($stand === [], 'Enter is not bound to draw, so a stray Enter cannot discard');
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
	$bar = [Layout::CENTRE[0] + 150, Layout::CENTRE[1] + Layout::OVAL[1] - 40, 330, 80];
	foreach(Layout::actions($view, ['discards' => [], 'amount' => 8]) as $button)
	$expect(Layout::hit([$button['rect'][0], $button['rect'][1]], $bar) && Layout::hit([$button['rect'][0] + $button['rect'][2], $button['rect'][1] + $button['rect'][3]], $bar), 'the action bar bounds hold every button');
	foreach([2,3,4,5,6,7,8] as $seats)
	for($seat = 1; $seat < $seats; $seat++)
	{
		$geometry = Layout::seat($seat, $seats);
		$fan = $geometry['cards'][4];
		$block = [$geometry['plate'][0], $geometry['plate'][1], $geometry['plate'][2], $fan[1] + $fan[3] + 40 - $geometry['plate'][1]];
		$expect(!$overlaps($block, $bar), "seat $seat of $seats clears the action bar");
	}
	$expect(abs(Layout::seatAngle(1, 5) - (M_PI / 2 + 2 * M_PI / 5)) < 1e-9, 'five seats keep even spacing');
	$expect(Layout::seatAngle(7, 8) < 2 * M_PI + Layout::ACTION_CLEARANCE + 1e-9, 'the eighth seat stops short of the bar');
});

echo "$passed PHP Poker presenter checks passed.\n";
if($failures) { fwrite(STDERR, "$failures presenter checks failed.\n"); exit(1); }

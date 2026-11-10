<?php
declare(strict_types=1);

// Presenter and layout tests: no GL context, no session. Views come from fixtures and from the real reducer.
require __DIR__ . '/../autoload.php';
$engineRoot = getenv('PHONICS_ENGINE_ROOT');
foreach(array_filter([$engineRoot ? $engineRoot . '/autoload.php' : null, __DIR__ . '/../../../node_modules/@seanmorris/phonics/autoload.php', __DIR__ . '/../../../../../node_modules/@seanmorris/phonics/autoload.php']) as $engine)
if(is_file($engine)) { require_once $engine; break; }

use PhpPoker\Render\Theme;
use PhpPoker\Render\UiCanvas;
use PhpPoker\Ui\Chips;
use PhpPoker\Ui\Layout;
use PhpPoker\Ui\Painter;
use PhpPoker\Ui\Presenter;
use PhpPoker\Ui\RoundHistory;

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

$test('the action bar holds exactly the legal actions', static function(callable $expect) use ($fixture, $root): void {
	$state = ['discards' => [], 'amount' => 8];
	$ids = static fn(array $view): array => array_map(static fn(array $button): string => $button['id'], Layout::fit(1280, 720)->actions($view, $state));
	$expect($ids($fixture('opening-8')) === ['fold','pass','bet'], 'opening: fold, pass, bet');
	$expect($ids($fixture('facing-raise-5')) === ['fold','call','bet'], 'facing a raise: fold, call, raise');
	$expect($ids($fixture('draw-2')) === ['clear','draw'], 'draw: keep all, draw');
	$expect($ids($fixture('showdown-5')) === ['next'], 'showdown: the next-deal control stands alone because History is in the Poker menu');
	$opening = $fixture('opening-8');
	$opening['legalActions']['bet'] = false;
	$opening['legalActions']['openingRequired'] = true;
	$expect($ids($opening) === ['fold','pass'], 'without jacks or better there is no open button');
	$waiting = $fixture('opening-8');
	$waiting['legalActions'] = [];
	$expect($ids($waiting) === [], 'no buttons while another seat acts');
	$waiting['phase'] = 'ready';
	$expect($ids($waiting) === [], 'automatic online shuffle and deal offer no manual Deal action');
	$waiting['legalActions'] = ['fold' => false, 'pass' => false, 'check' => false, 'call' => false, 'bet' => false];
	$expect($ids($waiting) === [], 'an all-false online action schema offers no Fold before the initial deal');
	$waiting['legalActions'] = ['next' => true];
	$expect($ids($waiting) === ['deal'], 'a ready table offers Deal only when the reducer explicitly authorizes it');
	$final = $fixture('facing-raise-5');
	$final['phase'] = 'final-betting';
	$final['highest'] = 0;
	$final['legalActions'] = ['fold' => true, 'pass' => false, 'check' => true, 'call' => false, 'callAmount' => 0
		, 'bet' => true, 'minBet' => 2, 'maxBet' => 20, 'betLabel' => 'Bet', 'openingRequired' => false];
	$expect($ids($final) === ['fold','check','bet'], 'after the draw, final betting explicitly offers both Check and Bet');
	$presenter = new Presenter($root);
	$presenter->update($final);
	$expect($presenter->state()['hint'] === 'Check or bet after the draw.', 'post-draw status names the Check/Bet decision');
	$facing = $fixture('facing-raise-5');
	$facing['phase'] = 'final-betting';
	$facing['legalActions']['bet'] = false;
	$expect($ids($facing) === ['fold','call'], 'a capped final bet offers no Raise button');
	$presenter = new Presenter($root);
	$presenter->update($facing);
	$expect($presenter->state()['hint'] === 'Final betting: call or fold.', 'a capped final bet does not advertise an unavailable Raise');
	$checkOnly = $final;
	$checkOnly['legalActions']['bet'] = false;
	$presenter = new Presenter($root);
	$presenter->update($checkOnly);
	$expect($presenter->state()['hint'] === 'Check after the draw.', 'an unavailable Bet is omitted from post-draw guidance');
	$bet = Layout::fit(1280, 720)->actions($fixture('facing-raise-5'), ['discards' => [], 'amount' => 999])[2];
	$expect($bet['intent']['amount'] === $fixture('facing-raise-5')['legalActions']['maxBet'], 'the raise-to amount is clamped to maxBet');
});

$test('round history maps the complete hand into player columns without losing the former transcript', static function(callable $expect) use ($fixture): void {
	$view=$fixture('showdown-5');
	$history=RoundHistory::build($view['players'],$view['history']);
	$rows=$history['rows'];
	$expect(count($rows)===6 && $rows[0]['kind']==='system' && $rows[0]['text']==='Hand 1: You deals.','the dealer line remains a full-width row');
	$expect(($rows[1]['cells'][1]['text']??'')==='OPEN 3','the opening bet starts its own row and retains its amount');
	$expect(($rows[2]['cells'][0]['text']??'')==='CALL 3' && ($rows[2]['cells'][2]['text']??'')==='FOLD'
		&& ($rows[2]['cells'][3]['text']??'')==='CALL 3','responses share their player columns even when turn order wraps past seat zero');
	$expect(($rows[3]['cells'][0]['text']??'')==='D(0)' && ($rows[3]['cells'][1]['text']??'')==='D(4)'
		&& ($rows[3]['cells'][3]['text']??'')==='D(3)','draws share one row and distinguish standing pat');
	$expect(($rows[4]['cells'][0]['text']??'')==='CHECK' && ($rows[4]['cells'][1]['text']??'')==='CHECK'
		&& ($rows[4]['cells'][3]['text']??'')==='CHECK','final checks share the next betting row');
	$expect($rows[5]['kind']==='system' && str_contains($rows[5]['text'],'wins 10 chips with three of a kind'),
		'the winner, pot, and hand name remain visible verbatim');
	$expect($history['lines']===array_slice($view['history'],-8),'the compact fallback retains exactly the former eight transcript entries');

	$players=array_map(static fn(int $seat): array=>['name'=>'P'.$seat],range(0,7));
	$sequence=['Hand 2: P7 deals.','P0: Bets 2.','P1: Calls 2.','P2: Calls 2.','P3: Folds.','P4: Folds.',
		'P5: Calls 2.','P6: Calls 2.','P7: Calls 2.','P0: Draws 1.','P1: Draws 3.','P2: Draws 3.','P5: Draws 3.',
		'P6: Stands pat.','P7: Draws 3.','P1: Checks.','P2: Bets 3.','P5: Raises to 8.','P6: Calls 8.',
		'P7: Raises to 12.','P0: Calls 10.','P1: Folds.','P2: Calls 9.'];
	$matrix=RoundHistory::build($players,$sequence)['rows'];
	$expect(($matrix[4]['cells'][1]['text']??'')==='CHECK' && ($matrix[4]['cells'][2]['text']??'')==='BET 3'
		&& ($matrix[5]['cells'][5]['text']??'')==='RAISE TO 8'
		&& ($matrix[6]['cells'][6]['text']??'')==='CALL 8' && ($matrix[6]['cells'][7]['text']??'')==='RAISE TO 12'
		&& ($matrix[7]['cells'][0]['text']??'')==='CALL 10' && ($matrix[7]['cells'][1]['text']??'')==='FOLD'
		&& ($matrix[7]['cells'][2]['text']??'')==='CALL 9','each bet or raise begins the next response row shown in the updated reference');
});

$test('the raise spinbox initializes when online controls appear at an unchanged revision', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$view = $fixture('facing-raise-5');
	$view['revision'] = $view['generation'] = 0;
	$presenter->update($view);
	$expect($presenter->state()['amount'] === $view['legalActions']['minBet'], 'an initial betting view shows its legal minimum instead of zero');

	$pending = $view;
	$pending['revision']++;
	$pending['legalActions'] = [];
	$presenter->update($pending);
	$expect($presenter->state()['amount'] === 0, 'the provisional online revision has no raise amount');
	$confirmed = $pending;
	$confirmed['legalActions'] = $view['legalActions'];
	$presenter->update($confirmed);
	$expect($presenter->state()['amount'] === $view['legalActions']['minBet'], 'confirmation restores the legal minimum even at the same revision');
	$bet = array_values(array_filter($presenter->layout()->actions($confirmed, $presenter->state()), static fn(array $button): bool => $button['id'] === 'bet'))[0];
	$expect($bet['intent']['amount'] === $view['legalActions']['minBet'], 'the displayed minimum is also the submitted raise-to amount');
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
		$expect(count($presenter->status()['ui']['hitRegions']) === 3, 'locked draw offers only Keep all and the two table menus, with no card or Draw targets');
	}
	$presenter = new Presenter($root);
	$view = $fixture('draw-2');
	$presenter->update($view);
	$presenter->resize(1280, 1207, 1);
	$layout = $presenter->layout();
	$cards = $layout->discardDialog()['cards'];
	// A compressed desktop lays the dialog out at its CSS size, so its five cards stand apart at their natural aspect.
	foreach(range(1, 4) as $index) $expect($cards[$index - 1][0] + $cards[$index - 1][2] < $cards[$index][0], "compressed desktop discard card $index stands clear of the one before it");
	$first = $layout->rect(...$cards[0]);
	$expect(abs($first[2] / $first[3] - 112 / 157) < 1e-6, 'compressed desktop discard cards keep their aspect');
	[$x, $y] = $layout->point($cards[1][0] + 2, max($cards[0][1], $cards[1][1]) + 20);
	$presenter->input([], ['x' => $x, 'y' => $y, 'down' => false, 'clicks' => [['x' => $x, 'y' => $y]]], 0);
	$expect($presenter->state()['discards'] === [$view['players'][0]['cards'][1]], 'a click at a discard card\'s edge selects that card');
	// Narrower still, the dialog shrinks its cards rather than overlapping them or leaving the field.
	foreach([[800, 807], [760, 1100], [1000, 807]] as [$width, $height])
	{
		$narrow = Layout::fit($width, $height, 1, 8);
		$dialog = $narrow->discardDialog();
		[$boxLeft, , $boxWidth] = $narrow->rect(...$dialog['box']);
		$expect($boxLeft >= 0 && $boxLeft + $boxWidth <= $width, "the discard dialog stays inside a $width x $height canvas");
		foreach(range(1, 4) as $index) $expect($dialog['cards'][$index - 1][0] + $dialog['cards'][$index - 1][2] < $dialog['cards'][$index][0], "discard card $index stands clear at $width x $height");
		$card = $narrow->rect(...$dialog['cards'][0]);
		$expect(abs($card[2] / $card[3] - 112 / 157) < 2e-3, "discard cards keep their aspect at $width x $height");
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

$test('a new online match opens its house rules once, over the table', static function(callable $expect) use ($fixture, $root): void {
	$view = $fixture('opening-8');
	$view['mode'] = 'online';
	$view['profile'] = ['variant'=>'anything','ante'=>3,'minimum'=>2,'maximum'=>10,'raiseLimit'=>2,'wild'=>['deuces'],'customWild'=>[]];
	$presenter = new Presenter($root);
	$presenter->update($view);
	$expect($presenter->state()['infoDialog'] === null, 'a view without a new table opens nothing');
	$view['houseRules'] = '$table-one';
	$presenter->update($view);
	$state = $presenter->state();
	$expect($state['infoDialog'] === 'game-settings' && $state['gameSettingsTitle'] === 'HOUSE RULES', 'the first view of a new table opens HOUSE RULES');
	$expect($state['gameSettings']['opening'] === 'Anything opens' && $state['gameSettings']['ante'] === '3 chips'
		&& $state['gameSettings']['wild'] === 'Deuces wild', 'the house rules show the table profile');
	$expect(count($presenter->paint(1280, 720)->instances()) > 100, 'the house rules paint over the live table');
	$presenter->input([['key'=>'enter']], null, 0);
	$expect($presenter->state()['infoDialog'] === null, 'Enter closes the house rules');
	$view['revision'] = ($view['revision'] ?? 0) + 1;
	$presenter->update($view);
	$expect($presenter->state()['infoDialog'] === null, 'later views of the same table do not reopen them');
	$presenter->input([['key'=>'alt+p']], null, 0);
	$presenter->input([['key'=>'r']], null, 0);
	$expect($presenter->state()['infoDialog'] === 'game-settings' && $presenter->state()['gameSettingsTitle'] === 'HOUSE RULES',
		'Poker > House Rules shows the same rules online');
	$presenter->input([['key'=>'escape']], null, 0);
	$view['houseRules'] = '$table-two';
	$presenter->update($view);
	$expect($presenter->state()['infoDialog'] === 'game-settings', 'the next table opens its own house rules');
	$offline = new Presenter($root);
	$offlineView = $fixture('opening-8');
	$offlineView['houseRules'] = '$ignored-offline-marker';
	$offlineView['screen'] = 'menu';
	$offline->update($offlineView);
	$expect($offline->state()['infoDialog'] === null, 'a marker on a non-table view opens nothing');
	$expect($offline->state()['gameSettingsTitle'] === 'HOUSE RULES', 'offline tables use the House Rules title too');
});

$test('online waits drum three reserved dots without changing the authorized message', static function(callable $expect) use ($fixture, $root): void {
	$presenter = new Presenter($root);
	$view = $fixture('opening-8');
	$view['mode'] = 'online';
	$view['legalActions'] = [];
	$view['waitingForConfirmation'] = true;
	$view['message'] = 'Waiting for both players to confirm the action.';
	$presenter->update($view);
	$expect($presenter->state()['networkWait'] === ['active' => true, 'dots' => 0], 'an online confirmation wait begins with three reserved hidden dots');
	$hidden = $presenter->paint(1280, 720)->instances();
	foreach([1,2,3,0] as $dots)
	{
		$presenter->advance(.3);
		$expect($presenter->state()['networkWait']['dots'] === $dots, "the wait cadence advances to $dots visible dots");
	}
	$expect($presenter->state()['hint'] === $view['message'], 'the cadence does not alter the authorized message');
	$presenter->advance(.9);
	$visible = $presenter->paint(1280, 720)->instances();
	$geometry = static fn(array $instances): array => array_values(array_map(static fn(array $instance): array => array_slice($instance, 0, 4)
		, array_filter($instances, static fn(array $instance): bool => ($instance[10] ?? 0) !== 1.0)));
	$expect($geometry($hidden) === $geometry($visible), 'showing the dots does not move or resize any plate');
	$expect(count($visible) > count($hidden), 'the cadence reveals dot glyphs instead of changing the message box');

	$offline = new Presenter($root);
	$view['mode'] = 'offline';
	$offline->update($view);
	$offline->advance(1);
	$expect($offline->state()['networkWait'] === ['active' => false, 'dots' => 0], 'an offline message with the same words does not imply network work');

	$shuffle = new Presenter($root);
	$view['mode'] = 'online';
	$view['phase'] = 'ready';
	$view['waitingForConfirmation'] = false;
	$view['message'] = 'Cryptographically shuffling deck.';
	$shuffle->update($view);
	$expect($shuffle->state()['networkWait']['active'], 'the verified online shuffle gets the same cadence');
});

$test('online loading status replaces the first-hand welcome card', static function(callable $expect) use ($fixture, $root): void {
	$view = $fixture('opening-8');
	$view['mode'] = 'online';
	$view['phase'] = 'ready';
	$view['handNumber'] = 1;
	$view['legalActions'] = [];
	$view['message'] = 'Cryptographically shuffling deck.';
	$first = new Presenter($root);
	$first->update($view);
	$firstFrame = $first->paint(390, 844)->instances();
	$view['handNumber'] = 2;
	$later = new Presenter($root);
	$later->update($view);
	$expect($firstFrame === $later->paint(390, 844)->instances(), 'the first-hand welcome does not cover a live online status');

	$view['message'] = '';
	$view['handNumber'] = 1;
	$quietFirst = new Presenter($root);
	$quietFirst->update($view);
	$view['handNumber'] = 2;
	$quietLater = new Presenter($root);
	$quietLater->update($view);
	$expect(count($quietFirst->paint(390, 844)->instances()) > count($quietLater->paint(390, 844)->instances()), 'a quiet ready table retains its one-time welcome');
});

$test('online plates show only the seat shuffling, dealing or revealing, and no idle YOU plate', static function(callable $expect) use ($fixture, $root): void {
	$view = $fixture('draw-2');
	$view['mode'] = 'online';
	$view['phase'] = 'ready';
	$view['turn'] = -1;
	$view['legalActions'] = [];
	$view['players'][0]['lastAction'] = $view['players'][1]['lastAction'] = '';
	$presenter = new Presenter($root);
	$presenter->update($view);
	$presenter->paint(1280, 720);
	$painter = static function(array $view, ?array $atlas = null) use ($presenter): Painter {
		[$text, $layout] = (fn(): array => [$this->text, $this->layout()])->call($presenter);
		return new Painter(new UiCanvas($text, $layout->uiScale()), $layout, $view, $presenter->state(), $atlas);
	};
	$plate = static fn(Painter $painter, array $view, int $seat): string => (fn(): string => $this->plateText(
		$view['players'][$seat], $seat, false
	))->call($painter);
	foreach(['shuffle' => 'Shuffling', 'deal' => 'Dealing', 'reveal' => 'Revealing'] as $stage => $word)
	{
		$view['activity'] = ['seat' => 1, 'stage' => $stage];
		$shown = $painter($view);
		$expect($plate($shown, $view, 1) === $word, "the working opponent's plate reads $word");
		$expect($plate($shown, $view, 0) === '', "the other seat has no plate while the opponent is $word");
		$view['activity'] = ['seat' => 0, 'stage' => $stage];
		$expect($plate($painter($view), $view, 0) === $word, "the human's plate reads $word, not that it is their turn");
	}
	$view['players'][1]['folded'] = true;
	$expect($plate($painter($view), $view, 1) === 'Fold', 'a folded seat keeps its Fold plate while the others reveal');
	$view['players'][1]['folded'] = false;
	$view['activity'] = null;
	$view['players'][0]['portrait'] = 'matrix-local';
	$expect($plate($painter($view), $view, 0) === '', 'online, the idle YOU plate is gone without a picture');
	$pictured = $painter($view, ['icons' => ['matrix-local' => [0, 0, 32, 32]], 'width' => 64, 'height' => 64]);
	$expect($plate($pictured, $view, 0) === '', 'online, the idle YOU plate is gone with a profile picture');
	$offline = ['mode' => 'offline'] + $view;
	$expect($plate($painter($offline), $offline, 0) === 'You', 'offline keeps the idle YOU plate');
	$view['players'][0]['lastAction'] = 'Calls 2';
	$expect($plate($pictured, $view, 0) === 'Calls 2', 'with a profile picture the plate still reports actions');
});

$test('the menu, setup and settings flow send only session intents the session knows', static function(callable $expect) use ($root): void {
	$presenter = new Presenter($root);
	$presenter->update(['screen' => 'menu', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => true]);
	$expect($presenter->input([['key' => 'enter']], null, 1 / 60) === [] && $presenter->state()['local'] === 'setup', 'Play Offline opens the local setup card');
	$presenter->input([['key' => 'arrowright'], ['key' => 'arrowright'], ['key' => 'v']], null, 1 / 60);
	$presenter->input([['key' => 'w']], null, 1 / 60);
	$expect($presenter->state()['local'] === 'wildcards', 'W opens the wild-card preset window');
	$presenter->input([['key' => '1'], ['key' => '0'], ['key' => '1'], ['key' => '2'], ['key' => 'enter']], null, 1 / 60);
	$expect($presenter->state()['local'] === 'setup' && $presenter->state()['setup']['wild'] === ['joker','deuces'], 'independent wild-card checkboxes return to setup');
	$presenter->input([['key' => 'l']], null, 1 / 60);
	$expect($presenter->state()['local'] === 'limits', 'L opens one combined betting and raising dialog');
	$click = static function(Presenter $presenter, array $rect): void {
		[$x,$y,$width,$height]=$presenter->layout()->rect(...$rect);
		$point=['x'=>$x+$width/2,'y'=>$y+$height/2];
		$presenter->input([], ['x'=>$point['x'],'y'=>$point['y'],'down'=>false,'clicks'=>[$point]], 0);
	};
	$limits=$presenter->layout()->limits();
	$click($presenter,$limits['fields']['ante']['more']);
	$click($presenter,$limits['fields']['minimum']['less']);
	$click($presenter,$limits['fields']['finalMinimum']['more']);
	$click($presenter,$limits['fields']['raiseLimit']['more']);
	$click($presenter,$limits['fields']['playerRaiseLimit']['less']);
	$presenter->input([['key' => 'enter']], null, 1 / 60);
	$expect($presenter->state()['local'] === 'setup' && $presenter->state()['setup']['ante'] === 2, 'the combined limits dialog applies its draft to setup');
	$start = $presenter->input([['key' => 'enter']], null, 1 / 60);
	$expect($start === [['type' => 'start', 'players' => 6, 'variant' => 'anything',
		'ante'=>2,'minimum'=>1,'maximum'=>20,'finalMinimum'=>3,'finalMaximum'=>20,'raiseLimit'=>4,'playerRaiseLimit'=>2,
		'wild'=>['joker','deuces'], 'customWild'=>[]]], 'Deal starts with the chosen seats, variant, wild cards and limits');
	$presenter->input([['key' => 'escape']], null, 1 / 60);
	$expect($presenter->input([['key' => 'c']], null, 1 / 60) === [['type' => 'resume']], 'C continues a saved game');
	$presenter->update(['screen' => 'settings', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => false, 'pace' => 'normal']);
	$before = $presenter->state()['mode'];
	$presenter->input([['key' => 'm']], null, 1 / 60);
	$expect($presenter->state()['mode'] !== $before, 'M toggles the visual mode');
	$expect($presenter->input([['key' => 'escape']], null, 1 / 60) === [['type' => 'menu']], 'Escape leaves settings for the menu');
});

$test('the title menu stays vertical inside one ruled window and help scrolls wrapped copy', static function(callable $expect) use ($root): void {
	foreach([[1280,720,1],[390,844,1],[844,326,1]] as [$width,$height,$dpr])
	{
		$layout = Layout::fit($width, $height, $dpr);
		$box = $layout->menuBox(true);
		$items = $layout->menu(true);
		$expect(array_column($items, 'key') === ['O','C','P','H','M'], "menu buttons carry their own shortcuts at {$width}x{$height}");
		foreach($items as $index => $item)
		{
			$expect(abs($item['rect'][0] - $items[0]['rect'][0]) < 1e-9, "menu remains one vertical column at {$width}x{$height}");
			$expect($item['rect'][0] >= $box[0] && $item['rect'][0] + $item['rect'][2] <= $box[0] + $box[2]
				&& $item['rect'][1] >= $box[1] && $item['rect'][1] + $item['rect'][3] <= $box[1] + $box[3], "menu item $index stays inside its window at {$width}x{$height}");
			if($index) $expect($item['rect'][1] > $items[$index - 1]['rect'][1] + $items[$index - 1]['rect'][3], "menu item $index follows vertically at {$width}x{$height}");
		}
	}
	$presenter = new Presenter($root);
	$view = ['screen' => 'help', 'phase' => 'ready', 'players' => [], 'legalActions' => [], 'revision' => 0, 'generation' => 0, 'error' => '', 'canContinue' => false];
	$presenter->resize(320, 500, 1);
	$presenter->update($view);
	$expect($presenter->state()['helpScroll'] === 0, 'help opens at its first paragraph');
	$expect($presenter->state()['helpScrollMax'] > 0, 'small help window reports measured overflow');
	$point = static function(Presenter $presenter, array $rect): array {
		[$x,$y,$width,$height] = $presenter->layout()->rect(...$rect);
		return ['x' => $x + $width / 2, 'y' => $y + $height / 2];
	};
	$copy = $point($presenter, [$presenter->layout()->information()['box'][0] + 30, $presenter->layout()->information()['box'][1] + 45, 2, 2]);
	$expect($presenter->input([], ['x' => $copy['x'], 'y' => $copy['y'], 'down' => false, 'clicks' => [$copy]], 0) === [], 'clicking help copy does not dismiss its dialog');
	$up = $point($presenter, $presenter->layout()->information()['up']);
	$expect($presenter->input([], ['x' => $up['x'], 'y' => $up['y'], 'down' => false, 'clicks' => [$up]], 0) === [], 'disabled help scroll controls do not dismiss its dialog');
	$before = $presenter->paint(320, 500)->instances();
	$presenter->input([['key' => 'arrowdown']], null, 0);
	$expect($presenter->state()['helpScroll'] === 1, 'Down scrolls help by one wrapped paragraph');
	$after = $presenter->paint(320, 500)->instances();
	$expect($before !== $after, 'scrolling changes the visible help copy on a small viewport');
	$information = $presenter->layout()->information();
	$controlTop = $presenter->layout()->rect(...$information['back'])[1];
	foreach($after as $instance) if(abs($instance[10] - 1) < 1e-9 && $instance[1] < $controlTop)
	$expect($instance[1] + $instance[3] <= $controlTop + 1e-6, 'wrapped help glyphs stop above the fixed scroll controls');

	$wide = new Presenter($root);
	$wide->resize(1280, 720, 1);
	$wide->update($view);
	$expect($wide->state()['helpScrollMax'] === 0, 'help disables scrolling when all wrapped copy fits');
	$view['canContinue'] = true;
	$wide->update($view);
	$back = $point($wide, $wide->layout()->information()['back']);
	$expect($wide->input([], ['x' => $back['x'], 'y' => $back['y'], 'down' => false, 'clicks' => [$back]], 0) === [['type' => 'menu']], 'Help Back returns to the title even when a game can continue');
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
	$setup->input([['key'=>'l']], null, 1 / 60, [], 'generic');
	$release($setup);
	$expect(($setup->state()['controllerFocus']['id'] ?? null) === 'limit:ante:less', 'the combined limits dialog exposes its steppers to controller focus');
	$press($setup, 'confirm');
	$expect($setup->state()['setup']['limitDraft']['ante'] === 0, 'A adjusts a focused betting limit');
	$press($setup, 'back');
	$expect($setup->state()['local'] === 'setup', 'B cancels the limits draft back to setup');
	$press($setup, 'back');
	$expect($setup->state()['local'] === 'menu', 'B backs out of table setup');

	$draw = new Presenter($root);
	$drawView = $fixture('draw-2');
	$draw->update($drawView);
	$release($draw);
	$expect(($draw->state()['controllerFocus']['id'] ?? null) === 'action:draw', 'a draw begins on its affirmative Stand pat action');
	$press($draw, 'up');
	$cardFocus=$draw->state()['controllerFocus']['id']??'';
	$expect(str_starts_with($cardFocus,'card:'), 'Up leaves the affirmative draw action for the card row');
	$cardIndex=(int)substr($cardFocus,5);
	$press($draw, 'confirm');
	$expect($draw->state()['discards'] === [$drawView['players'][0]['cards'][$cardIndex]], 'A toggles the focused discard card');
	$press($draw, 'down');
	if(($draw->state()['controllerFocus']['id']??null)==='action:clear') $press($draw,'right');
	$expect(($draw->state()['controllerFocus']['id'] ?? null) === 'action:draw', 'Down returns from the cards to the affirmative draw action');
	$intent = $press($draw, 'confirm');
	$expect(($intent[0]['type'] ?? null) === 'draw' && ($intent[0]['cards'] ?? []) === [$drawView['players'][0]['cards'][$cardIndex]], 'A draws the selected cards from the focused Draw button');

	$turn = new Presenter($root);
	$turnView = $fixture('facing-raise-5');
	$turn->update($turnView); $release($turn);
	$expect(($turn->state()['controllerFocus']['id']??null)==='action:call', 'a betting turn begins on Call instead of Fold');
	$expect(($press($turn,'confirm')[0]['type']??null)==='call', 'A takes the affirmative focused action');
	$neutral=$turn->state()['controllerFocus']??[];
	$expect(($neutral['id']??null)==='table:waiting' && !isset($neutral['rect']), 'an outstanding action moves focus to an invisible neutral table target');
	$expect($press($turn,'confirm')===[], 'the neutral target cannot repeat the previous action');
	$press($turn,'up');
	$expect(str_starts_with($turn->state()['controllerFocus']['id']??'','top:'), 'the table menu remains reachable from neutral focus');
	$turnView['revision']++;
	$turn->update($turnView); $release($turn);
	$expect(($turn->state()['controllerFocus']['id']??null)==='action:call', 'the next turn leaves the menu heading and restores the affirmative action');

	$opening = new Presenter($root); $opening->update($fixture('opening-8')); $release($opening);
	$expect(($opening->state()['controllerFocus']['id']??null)==='action:pass', 'an opening turn prefers Pass to Fold or Bet');
	$showdown = new Presenter($root); $showdown->update($fixture('showdown-5')); $release($showdown);
	$expect(($showdown->state()['controllerFocus']['id']??null)==='action:next', 'a finished hand focuses the next Deal action');
	$readyView=$fixture('showdown-5'); $readyView['phase']='ready'; $readyView['legalActions']=['next'=>true];
	$ready = new Presenter($root); $ready->update($readyView); $release($ready);
	$expect(($ready->state()['controllerFocus']['id']??null)==='action:deal', 'a ready table focuses Deal');
	$checkView=$fixture('facing-raise-5');
	$checkView['legalActions']=['fold'=>true,'check'=>true,'call'=>false,'pass'=>false,'bet'=>true,'minBet'=>2,'maxBet'=>20,'betLabel'=>'Bet'];
	$check = new Presenter($root); $check->update($checkView); $release($check);
	$expect(($check->state()['controllerFocus']['id']??null)==='action:check', 'a free final-betting action prefers Check to Fold or Bet');

	$stale = new Presenter($root); $staleView=$fixture('facing-raise-5'); $stale->update($staleView); $release($stale);
	$press($stale,'up');
	$expect(($stale->state()['controllerFocus']['id']??null)==='action:fold', 'the player can still choose Fold deliberately');
	$waitingView=$staleView; $waitingView['revision']++; $waitingView['turn']=1; $waitingView['legalActions']=[];
	$stale->update($waitingView); $release($stale);
	$expect(($stale->state()['controllerFocus']['id']??null)==='table:waiting', 'Fold is retired when the turn ends');
	$staleView['revision']+=2; $stale->update($staleView); $release($stale);
	$expect(($stale->state()['controllerFocus']['id']??null)==='action:call', 'a later turn does not reselect the stale Fold action');

	$table = new Presenter($root);
	$table->update($fixture('facing-raise-5'));
	$release($table);
	$before = $table->state()['amount'];
	$press($table, 'pageDown');
	$expect($table->state()['amount'] > $before, 'right shoulder increases the raise amount');
	$expect($press($table, 'pause') === [['type'=>'menu']] && $table->state()['confirmDialog']===null, 'Start leaves a saved offline table at once');
	$onlineTable = new Presenter($root); $onlineRaise = $fixture('facing-raise-5'); $onlineRaise['mode'] = 'online';
	$onlineTable->update($onlineRaise); $release($onlineTable);
	$expect($press($onlineTable, 'pause') === [] && $onlineTable->state()['confirmDialog']==='forfeit', 'Start asks before forfeiting an online match');
	$expect($press($onlineTable, 'back') === [] && $onlineTable->state()['confirmDialog']===null, 'Back cancels the forfeit confirmation');

	$slow = new Presenter($root); $slow->update($fixture('menu')); $release($slow);
	$slow->input([],null,1/60,['down'=>1],'generic');
	$expect(($slow->state()['controllerFocus']['id']??null)==='menu:continue','the first held direction moves one target');
	$slow->input([],null,5,['down'=>1],'generic');
	$expect(($slow->state()['controllerFocus']['id']??null)==='menu:continue','one slow frame cannot burst through controller targets');
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

$test('no opponent sits behind the action column at any seat count', static function(callable $expect) use ($overlaps, $fixture): void {
	$view = $fixture('facing-raise-5');
	foreach([2,3,4,5,6,7,8] as $seats)
	{
		$layout = Layout::fit(1280, 720, 1, $seats);
		$controls = array_column($layout->actions($view, ['discards' => [], 'amount' => 8]), 'rect');
		$human = $layout->seat(0, $seats);
		$handRight = $human['cards'][4][0] + $human['cards'][4][2];
		$expect(abs($controls[0][0] - ($handRight + Layout::ACTION_GUTTER * $layout->stretch())) < 1e-9, "$seats-player action column sits beside the local hand");
		// The dealer button stands in that gutter: clear of the plate and of every control.
		[$markerX, $markerY, $markerRadius] = $layout->dealerMarker(0, $seats);
		$disc = [$markerX - $markerRadius * $layout->stretch(), $markerY - $markerRadius, 2 * $markerRadius * $layout->stretch(), 2 * $markerRadius];
		$expect($disc[0] >= $human['plate'][0] + $human['plate'][2], "$seats-player dealer button stands outside the local plate");
		foreach($controls as $index => $control) $expect(!$overlaps($disc, $control), "$seats-player dealer button is not covered by control $index");
		$stepper = $layout->stepper($view);
		foreach(['less','amount','more'] as $part) $controls[] = $stepper[$part];
		$buttonRight = $controls[0][0] + $controls[0][2];
		$expect(abs($stepper['more'][0] + $stepper['more'][2] - $buttonRight) < 1e-9, "$seats-player stepper shares the action column's right edge");
		for($seat = 1; $seat < $seats; $seat++)
		{
			$geometry = $layout->seat($seat, $seats);
			$fan = $geometry['cards'][4];
			$block = [$geometry['plate'][0], $geometry['plate'][1], $geometry['plate'][2], $fan[1] + $fan[3] + 40 - $geometry['plate'][1]];
			foreach($controls as $control) $expect(!$overlaps($block, $control), "seat $seat of $seats clears the action column");
		}
	}
	$expect(abs(Layout::fit(1280, 720)->seatAngle(1, 5) - (M_PI / 2 + 2 * M_PI / 5)) < 1e-9, 'five seats keep even spacing');
	$expect(Layout::fit(1280, 720)->seatAngle(7, 8) < 2 * M_PI + Layout::ACTION_CLEARANCE + 1e-9, 'the eighth seat stops short of the action column');
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
	// The oval is kept on laptop-height canvases while its seats fit; columns take over only when they cannot.
	foreach([[900, 650], [1280, 627], [1366, 675], [1440, 697]] as [$laptopWidth, $laptopHeight]) foreach([2, 5, 8] as $laptopSeats)
	$expect(Layout::fit($laptopWidth, $laptopHeight, 1, $laptopSeats)->profile === 'wide', "a $laptopWidth x $laptopHeight canvas keeps the oval at $laptopSeats seats");
	$expect(Layout::fit(1000, 435, 1)->profile === 'landscape', 'a canvas too short for the oval and its controls uses the landscape columns');
	$expect(Layout::fit(700, 650, 1)->profile === 'tall', 'a nearly square narrow canvas keeps its vertical composition');
	$expect(Layout::fit(1536, 1850, 2)->profile === 'tall', 'a deeply portrait desktop uses columns before rigid card faces collide');
	$expect(Layout::fit(1518, 1850, 2)->profile === 'tall', 'the desktop remains in columns below the 760 CSS pixel floor');
	$expect(Layout::fit(1280, 1223, 1, 2)->profile === 'wide', 'a nearly square desktop canvas retains the wide composition');
	$expect(Layout::fit(1280, 720, 1)->profile === 'wide', 'the canonical wide canvas keeps its oval composition');
	$expect(Layout::fit(1500, 900, 2)->profile === 'landscape', 'a short landscape canvas uses its own profile');
	$transition = Layout::fit(770, 700, 1);
	$expect($transition->profile === 'wide', 'the final slice before the narrow breakpoint still uses the wide composition');
	foreach($transition->actions($fixture('facing-raise-5'), ['discards' => [], 'amount' => 8]) as $button)
		$expect($button['key'] === '' || $transition->keyHintFits($button['rect'][2] * $transition->horizontalScale), "{$button['id']} retains its key hint through the wide transition slice");
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
	$landscape = Layout::fit(1000, 435, 1, 8);
	$landscapeButtons = $landscape->actions($view, ['discards' => [], 'amount' => 8]);
	$expect($landscape->profile === 'landscape' && $landscape->verticalActionBar(), 'the short many-player desktop uses a vertical action column');
	$expect($landscape->tableRight() > $landscape->width() * .7, 'the vertical action column returns most of the short field to the table');
	$expect($landscape->metric('card')[0] >= 28 && $landscape->metric('card')[1] >= 40, 'the recovered short-field room is spent on larger CPU cards');
	foreach($landscapeButtons as $index => $button)
	{
		$expect($button['rect'][0] >= $landscape->tableRight(), "{$button['id']} stays inside the landscape control lane");
		$expect(abs($button['rect'][0] - $landscapeButtons[0]['rect'][0]) < 1e-9 && abs($button['rect'][2] - $landscapeButtons[0]['rect'][2]) < 1e-9, "{$button['id']} shares the landscape action column");
		if($index) $expect($button['rect'][1] > $landscapeButtons[$index - 1]['rect'][1] + $landscapeButtons[$index - 1]['rect'][3], "{$button['id']} follows the prior landscape action vertically");
	}
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
		$limits = $layout->limits();
		$wild = $layout->wildcardMenu();
		$customTargets = [];
		foreach(range(0,4) as $page)
		{
			$custom = $layout->customWildcards($page);
			$customTargets=array_merge($customTargets,array_values($custom['cards']));
			if($custom['paged']) $customTargets=array_merge($customTargets,[$custom['previous'],$custom['next']]);
		}
		$custom = $layout->customWildcards();
		$expect($layout->profile !== 'wide' || count($custom['cards']) === 54, "wide custom dialog exposes the complete deck at $width x $height / $dpr");
		$targets = array_merge(array_column($layout->menu(true), 'rect'), array_column($draw, 'rect'),
			[$setup['less'],$setup['more'],$setup['variant'],$setup['wild'],$setup['limits'],$setup['start'],$setup['back']],
			array_merge(...array_map(static fn(array $field): array=>[$field['less'],$field['more']],array_values($limits['fields']))),
			[$limits['back'],$limits['apply']],
			array_values($wild['items']),[$wild['back'],$wild['apply']],$customTargets,[$custom['back'],$custom['done']]);
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
			$shortName = Layout::identityNameX($g, 32);
			$expect(abs($shortName - $g['caption'][0]) < 1e-9, "a short name stays centered below the icon for seat $seat of $seats in {$layout->profile}");
			$edgeName = Layout::identityNameX($g, 40);
			$expect(abs($edgeName - $g['caption'][0]) < 1e-9, "a name touching but not crossing the cards stays centered for seat $seat of $seats in {$layout->profile}");
			$wideName = Layout::identityNameX($g, 64);
			$innerEdge = $right ? $wideName - 32 : $wideName + 32;
			$cardEdge = $right ? $fan[0] + $fan[2] : $fan[0];
			$expect(abs(abs($innerEdge - $cardEdge) - 4) < 1e-9, "only a wide name moves outward with clearance from the cards for seat $seat of $seats in {$layout->profile}");
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
		if($layout->profile === 'tall')
		{
			// On a tall screen the lone opponent's bank hugs the right corner of its fan, under the icon, and no real
			// bank reaches the pot plate.
			$fanRight = $headsUp['cards'][4][0] + $headsUp['cards'][4][2];
			$bank = $layout->bankBounds(1, 2, Chips::bank(500));
			$expect(abs($bank[0] + $bank[2] - $fanRight) < 1e-6, "the heads-up bank is flush with the right corner of its hand in {$layout->width()}x{$layout->height()}");
			$pot = $layout->centre()['pot'];
			$clear = true;
			for($value = 0; $value <= 1000; $value += 7) if(($bounds = $layout->bankBounds(1, 2, Chips::bank($value))) && $overlaps($bounds, $pot)) $clear = false;
			$expect($clear, "no heads-up bank reaches the pot plate in {$layout->width()}x{$layout->height()}");
		}
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

$test('tall table objects grow modestly into responsive field space', static function(callable $expect): void {
	$base = Layout::fit(360, 640, 1);
	$portrait = Layout::fit(390, 844, 1);
	$wide = Layout::fit(644, 600, 1);
	foreach(['card','handCard'] as $metric)
	{
		$expect($portrait->metric($metric)[0] >= $base->metric($metric)[0] * 1.1, "$metric uses the extra portrait space");
		$expect($wide->metric($metric)[0] >= $base->metric($metric)[0] * 1.1, "$metric uses the extra horizontal space");
	}
	$expect($portrait->metric('emblemSize') === 32 && $wide->metric('emblemSize') === 32, 'responsive growth preserves 32px identity emblems');
	$expect($portrait->centre()['deck'][2] > $base->centre()['deck'][2], 'the centre cards grow with the portrait field');
	$expect($wide->centre()['pot'][2] > $base->centre()['pot'][2], 'the centre plates grow with the wider field');
	$expect($portrait->centre()['pot'][1] > $portrait->height() * .3, 'the portrait centre cluster uses the vertical void');
});

$test('sparser tall tables spend their free space on larger table objects', static function(callable $expect) use ($overlaps): void {
	$previous = INF;
	$fullCentreWidth = Layout::fit(390, 782, 1, 8)->centre()['pot'][2];
	foreach(range(2, 8) as $seats)
	{
		$layout = Layout::fit(390, 782, 1, $seats);
		$width = $layout->metric('card')[0];
		$expect($width < $previous, "$seats-player cards follow the density curve");
		$previous = $width;
		if($seats < 8) $expect($layout->centre()['pot'][2] > $fullCentreWidth, "$seats-player centre plate grows beyond the full-table plate");
		$blocks = [];
		for($seat = 0; $seat < $seats; $seat++)
		{
			$g = $layout->seat($seat, $seats);
			$bank = $layout->bankBounds($seat, $seats, Chips::bank(500));
			$size = $layout->metric('emblemSize');
			$left = min($g['plate'][0], $g['emblem'][0] - $size / 2, $bank[0]);
			$right = max($g['plate'][0] + $g['plate'][2], $g['emblem'][0] + $size / 2, $bank[0] + $bank[2]);
			$bottom = max($g['cards'][0][1] + $g['cards'][0][3], $bank[1] + $bank[3], $g['caption'][1] + ($seat === 0 ? 8 : 22));
			$blocks[] = [$left, $g['plate'][1], $right - $left, $bottom - $g['plate'][1]];
		}
		foreach($blocks as $i => $a) foreach($blocks as $j => $b) if($i < $j)
			$expect(!$overlaps($a, $b), "$seats-player responsive seat blocks remain separate");
		$centre = $layout->centre();
		$chip = $layout->metric('chipRadius');
		$cluster = [min($centre['pot'][0], $centre['deck'][0], $centre['pile'][0] - $centre['pile'][2] - $chip), $centre['pot'][1], 0, 0];
		$cluster[2] = max($centre['pot'][0] + $centre['pot'][2], $centre['discard'][0] + $centre['discard'][2], $centre['pile'][0] + $centre['pile'][2] + $chip) - $cluster[0];
		$cluster[3] = max($centre['bet'][1] + $centre['bet'][3], $centre['deck'][1] + $centre['deck'][3]) + 8 + 56 - $cluster[1];
		foreach($blocks as $seat => $block) $expect(!$overlaps($block, $cluster), "$seats-player centre cluster clears seat $seat");
	}
	$headsUp = Layout::fit(973, 1222, 1, 2);
	$remote = $headsUp->seat(1, 2);
	$human = $headsUp->seat(0, 2);
	$centre = $headsUp->centre();
	$remoteBottom = $remote['cards'][0][1] + $remote['cards'][0][3];
	$statusBottom = max($centre['bet'][1] + $centre['bet'][3], $centre['deck'][1] + $centre['deck'][3]) + 8 + 56;
	$expect($centre['pot'][1] >= $remoteBottom + 14, 'the tall heads-up pot clears the remote cards');
	$expect(abs($human['plate'][1] - 14 - $statusBottom) < 1e-9, 'the tall heads-up centre cluster uses the lane down to the local plate');
});

$test('wide card fans anchor every player layout and CPU cards use the available vertical room', static function(callable $expect): void {
	foreach([[1280,720,1],[1617,1222,1],[1280,1208,1],[1536,1500,2]] as [$width,$height,$dpr])
	foreach(range(2, 8) as $seats)
	{
		$layout = Layout::fit($width, $height, $dpr, $seats);
		$expect($layout->profile === 'wide', "$width x $height remains a wide card-fan layout");
		$expect(abs($layout->metric('handCard')[1] - 1.2 * $layout->metric('card')[1]) < 1e-9, "$seats-player local cards are larger than CPU cards at $width x $height");
		for($seat = 0; $seat < $seats; $seat++)
		{
			$geometry = $layout->seat($seat, $seats);
			$first = $geometry['cards'][0];
			$last = $geometry['cards'][4];
			$fanLeft = $first[0];
			$fanRight = $last[0] + $last[2];
			$fanCentre = ($fanLeft + $fanRight) / 2;
			$expect(abs($geometry['plate'][0] - $fanLeft) < 1e-9 && abs($geometry['plate'][0] + $geometry['plate'][2] - $fanRight) < 1e-9, "seat $seat plate derives from its card fan at $seats players, $width x $height");
			if($seat === 0 || $seats === 2)
				$expect(abs($fanCentre - $layout->width() / 2) < 1e-9, "heads-up/human seat $seat is centered at $seats players, $width x $height");
		}
	}
	$canonical = Layout::fit(1280, 720, 1, 8);
	$expect($canonical->metric('card')[1] > 95 && abs($canonical->metric('card')[1] - 126) < 1e-9, 'a full table grows CPU cards from the old 95-unit face to 126 units');
	$expect(abs($canonical->metric('handCard')[1] - 151.2) < 1e-9, 'the centered local hand remains visibly larger at 151.2 units');
});

$test('wide tables keep vertical sizing independent from horizontal compression', static function(callable $expect) use ($fixture, $overlaps): void {
	$base = Layout::fit(1280, 720, 1, 2);
	$tall = Layout::fit(1280, 1223, 1, 2);
	$expect($tall->profile === 'wide' && $tall->height() === 720, 'the wide field retains its canonical vertical coordinate system');
	$expect(abs($tall->rect(0, 0, $tall->width(), $tall->height())[3] - 1223) < 1e-9, 'the wide field fills the canvas vertically');
	$baseTop = $base->seat(1, 2)['plate'][1] * $base->scale / $base->uiScale();
	$tallTop = $tall->seat(1, 2)['plate'][1] * $tall->scale / $tall->uiScale();
	$expect(abs($tallTop - $baseTop) < 1e-9, 'the top opponent remains anchored relative to vertically scaled chrome');
	$baseHuman = $base->seat(0, 2)['cards'][0];
	$tallHuman = $tall->seat(0, 2)['cards'][0];
	$expect(abs(($base->height() - $baseHuman[1] - $baseHuman[3]) - ($tall->height() - $tallHuman[1] - $tallHuman[3])) < 1e-9, 'the human hand remains anchored to the bottom');
	$expect(abs($tall->metric('actions')[1] - $base->metric('actions')[1]) < 1e-9, 'the action controls retain their bottom anchor');
	foreach([$base, $tall] as $layout)
	{
		foreach(range(0, 1) as $seat)
		{
			$cards = $layout->seat($seat, 2)['cards'];
			$fanCentre = ($cards[0][0] + $cards[4][0] + $cards[4][2]) / 2;
			$expect(abs($fanCentre - $layout->width() / 2) < 1e-9, "heads-up seat $seat anchors its fan on the table center line");
		}
	}
	$expect($tall->metric('handCard')[1] * $tall->scale > $base->metric('handCard')[1] * $base->scale, 'vertical room enlarges rendered cards');
	$expect($tall->metric('handChipRadius') * $tall->scale > $base->metric('handChipRadius') * $base->scale, 'vertical room enlarges rendered chips');
	$transition = Layout::fit(1223, 1223, 1, 2);
	$expect($transition->profile === 'wide' && Layout::fit(1222, 1223, 1, 2)->profile === 'wide', 'the oval continues through portrait desktop proportions');
	$expect(Layout::fit(1000, 1223, 1, 2)->profile === 'tall', 'columns begin when the oval can no longer fit rigid card faces');
	foreach(range(2, 4) as $seats)
	{
		$full = Layout::fit(1378, 1223, 1, $seats);
		$lastWide = Layout::fit(1223, 1223, 1, $seats);
		$expect(abs($full->metric('handCard')[1] * $full->scale - $lastWide->metric('handCard')[1] * $lastWide->scale) < 1e-9, "$seats-player cards retain one rendered height until geometry constrains them");
		$expect(abs($full->metric('handChipRadius') * $full->scale - $lastWide->metric('handChipRadius') * $lastWide->scale) < 1e-9, "$seats-player chips retain one rendered size until geometry constrains them");
	}
	foreach(range(2, 3) as $seats)
	{
		$portrait = Layout::fit(760, 1254, 1, $seats);
		$expect($portrait->profile === 'tall', "$seats-player portrait switches composition before fixed-size oval cards collide");
		$bank = $portrait->bankBounds(0, $seats, Chips::bank(500));
		$expect($bank !== null && $bank[1] + $bank[3] <= $portrait->height() - 4 + 1e-9, "$seats-player chips tuck into the hand corner without clipping");
	}
	$canonical = Layout::fit(1280, 720, 1, 2);
	$expect($canonical->metric('handCard')[1] >= 151.2 && $canonical->metric('handChipRadius') >= 17, 'the sparse canonical composition uses its free room without shrinking the local hand');
	$compact = Layout::fit(770, 700, 1, 2);
	$expect($compact->metric('handCard')[1] * $compact->scale < $tall->metric('handCard')[1] * $tall->scale, 'actual centre and seat constraints can reduce the rendered size');
	$view = $fixture('facing-raise-5');
	$horizontalChrome = [];
	foreach([[1920,1080,1],[1280,720,1],[1920,1208,1],[1378,1208,1],[1280,1208,1],[1536,1500,2]] as [$width,$height,$dpr])
	{
		$layout = Layout::fit($width, $height, $dpr, 8);
		$buttons = $layout->actions($view, ['discards' => [], 'amount' => 8]);
		$verticalScale = max(1, $height / $dpr / 720);
		$expect($layout->verticalActionBar(), "$width x $height uses the vertical action bar");
		$expect(abs($layout->metric('emblemSize') * $layout->scale / $dpr - 32 * $verticalScale) < 1e-9, "$width x $height sizes identity icons from vertical room");
		$expect(abs($layout->plateHeight() * $layout->scale / $dpr - Layout::PLATE_HEIGHT * $verticalScale) < 1e-9, "$width x $height sizes status plates from vertical room");
		$expect(abs($layout->menuHeight() * $layout->scale / $dpr - Layout::MENU_BAR * $verticalScale) < 1e-9, "$width x $height sizes the menu bar from vertical room");
		$expect(abs($layout->uiScale() / $dpr - $verticalScale) < 1e-9, "$width x $height sizes type from vertical room");
		$expect(abs($buttons[0]['rect'][3] * $layout->scale / $dpr - max(48, 32 * $verticalScale)) < 1e-9, "$width x $height sizes buttons from vertical room");
		if($height === 1208 && $dpr === 1) $horizontalChrome[] = [
			$layout->metric('emblemSize') * $layout->scale,
			$layout->plateHeight() * $layout->scale,
			$layout->uiScale(),
			$buttons[0]['rect'][3] * $layout->scale,
		];
		foreach($buttons as $index => $button)
		{
			$expect(abs($button['rect'][0] - $buttons[0]['rect'][0]) < 1e-9 && abs($button['rect'][2] - $buttons[0]['rect'][2]) < 1e-9, "{$button['id']} shares the action column at $width x $height");
			if($index) $expect($button['rect'][1] > $buttons[$index - 1]['rect'][1] + $buttons[$index - 1]['rect'][3], "{$button['id']} follows the prior action vertically at $width x $height");
		}
		$stepper = $layout->stepper($view);
		$expect($stepper !== null && $buttons[array_key_last($buttons)]['rect'][1] + $buttons[array_key_last($buttons)]['rect'][3] < $stepper['less'][1], "the raise stepper follows the vertical actions at $width x $height");
		$human = $layout->seat(0, 8);
		$handRight = $human['cards'][4][0] + $human['cards'][4][2];
		$expect(abs($buttons[0]['rect'][0] - ($handRight + Layout::ACTION_GUTTER * $layout->stretch())) < 1e-9, "the action column follows the local hand at $width x $height");
		$expect(abs($stepper['more'][0] + $stepper['more'][2] - ($buttons[0]['rect'][0] + $buttons[0]['rect'][2])) < 1e-9, "the stepper aligns with the action column at $width x $height");
		$controls = array_column($buttons, 'rect');
		foreach(['less','amount','more'] as $part) $controls[] = $stepper[$part];
		foreach(range(0, 7) as $seat)
		{
			$geometry = $layout->seat($seat, 8);
			$bank = $layout->bankBounds($seat, 8, Chips::bank(500));
			$size = $layout->metric('emblemSize');
			$left = min($geometry['plate'][0], $geometry['emblem'][0] - $size / 2, $bank[0]);
			$right = max($geometry['plate'][0] + $geometry['plate'][2], $geometry['emblem'][0] + $size / 2, $bank[0] + $bank[2]);
			$bottom = max($geometry['cards'][0][1] + $geometry['cards'][0][3], $bank[1] + $bank[3], $geometry['caption'][1] + ($seat === 0 ? 8 : 22));
			$block = [$left, min($geometry['plate'][1], $bank[1]), $right - $left, $bottom - min($geometry['plate'][1], $bank[1])];
			foreach($controls as $control) $expect(!$overlaps($block, $control), "seat $seat clears the vertical controls at $width x $height");
		}
	}
	foreach(range(0, 3) as $metric)
	{
		$values = array_column($horizontalChrome, $metric);
		$expect(max($values) - min($values) < 1e-9, "wide chrome metric $metric stays fixed through a purely horizontal resize");
	}
	$expect(Layout::fit(1280, 720, 1, 8)->verticalActionBar(), 'the canonical wide table uses the stable vertical action column');
	$fullWidth = Layout::fit(1280, 720, 1, 8)->metric('handCard')[0];
	foreach(range(2, 4) as $seats)
	$expect(Layout::fit(1280, 720, 1, $seats)->metric('handCard')[0] >= $fullWidth, "$seats-player sparse table never uses smaller cards than a full table");
});

$test('three-to-eight-player boards compress only their horizontal axis', static function(callable $expect) use ($fixture, $overlaps): void {
	$height = 1222;
	$referenceWidth = (int)ceil(1280 * $height / 720);
	$view = $fixture('facing-raise-5');
	foreach(range(3, 8) as $seats)
	{
		$reference = Layout::fit($referenceWidth, $height, 1, $seats);
		$referenceSeat = $reference->seat(0, $seats);
		$referenceCard = $reference->rect(...$referenceSeat['cards'][0]);
		$referenceCpu = $reference->rect(...$reference->seat(1, $seats)['cards'][0]);
		$referenceButton = $reference->actions($view, ['discards' => [], 'amount' => 8])[0]['rect'];
		$referenceButtonHeight = $reference->rect(...$referenceButton)[3];
		$previousHorizontal = INF;
		foreach([$referenceWidth, 1920, 1617, 1280] as $width)
		{
			$layout = Layout::fit($width, $height, 1, $seats);
			$humanGeometry = $layout->seat(0, $seats);
			$human = $layout->rect(...$humanGeometry['cards'][0]);
			$humanLast = $layout->rect(...$humanGeometry['cards'][4]);
			$cpu = $layout->rect(...$layout->seat(1, $seats)['cards'][0]);
			$button = $layout->actions($view, ['discards' => [], 'amount' => 8])[0]['rect'];
			$expect($layout->profile === 'wide', "$seats-player $width x $height board remains wide");
			$expect(abs($layout->scale - $reference->scale) < 1e-9, "$seats-player vertical scale stays fixed at width $width");
			$expect($layout->horizontalScale <= $previousHorizontal + 1e-9, "$seats-player x scale tightens monotonically at width $width");
			$expect(abs(($human[0] + $humanLast[0] + $humanLast[2]) / 2 - $width / 2) < 1e-6, "$seats-player local fan stays centered while its spacing compresses at width $width");
			// Cards and chips keep their size while the seats still fit. A full table in a narrow window would put
			// fans on top of each other, so there, and only there, they are given the largest size that fits; their
			// aspect ratio never changes and the board never has two seats overlapping.
			$objects = $human[3] / $referenceCard[3];
			$expect($objects <= 1 + 1e-9 && ($seats < 8 || $width >= $referenceWidth ? abs($objects - 1) < 1e-9 : $objects >= .6), "$seats-player cards keep their size at width $width unless a full table would collide");
			$expect(abs($human[1] + $human[3] - $referenceCard[1] - $referenceCard[3]) < 1e-6 && abs($human[2] / $human[3] - $referenceCard[2] / $referenceCard[3]) < 1e-9, "$seats-player local cards keep their baseline and aspect at width $width");
			$expect(abs($cpu[3] / $referenceCpu[3] - $objects) < 1e-9 && abs($cpu[2] / $cpu[3] - $referenceCpu[2] / $referenceCpu[3]) < 1e-9, "$seats-player CPU cards keep their aspect and share the local scale at width $width");
			$expect(abs($layout->rect(...$button)[3] - $referenceButtonHeight) < 1e-9, "$seats-player controls keep their height at width $width");
			$expect(abs($layout->metric('handChipRadius') * $layout->scale - $objects * $reference->metric('handChipRadius') * $reference->scale) < 1e-9, "$seats-player chips share the card scale at width $width");
			$boardBlocks = [];
			for($boardSeat = 0; $boardSeat < $seats; $boardSeat++)
			{
				$boardGeometry = $layout->seat($boardSeat, $seats);
				$boardLast = $boardGeometry['cards'][4];
				$boardBlocks[$boardSeat] = [$boardGeometry['plate'], [$boardGeometry['cards'][0][0], $boardGeometry['cards'][0][1], $boardLast[0] + $boardLast[2] - $boardGeometry['cards'][0][0], $boardLast[3]]];
			}
			foreach($boardBlocks as $first => $firstBlocks) foreach($boardBlocks as $second => $secondBlocks) if($first < $second)
			foreach($firstBlocks as $one) foreach($secondBlocks as $other) $expect(!$overlaps($one, $other), "$seats-player seats $first and $second stand apart at width $width");
			$expect(abs($layout->metric('emblemSize') * $layout->scale - $reference->metric('emblemSize') * $reference->scale) < 1e-9, "$seats-player icons keep their size at width $width");
			$previousHorizontal = $layout->horizontalScale;
		}
		foreach([1000, 760] as $width)
			$expect(Layout::fit($width, $height, 1, $seats)->profile === 'tall', "$seats-player $width x $height board changes composition before rigid cards collide");
	}
});

$test('column profiles seat everyone without overlap and fill the canvas', static function(callable $expect) use ($overlaps, $fixture): void {
	foreach([[390,844,1],[360,640,1],[1170,2340,3],[1518,1850,2],[844,326,1],[667,375,1],[2532,984,3]] as [$width,$height,$dpr])
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
			$cluster[3] = max($centre['bet'][1] + $centre['bet'][3], $centre['deck'][1] + $centre['deck'][3]) + 8 + 56 - $cluster[1];
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
			// The pot's plates keep clear air beside each column and use the lane above the human hand.
			$centre = $layout->centre();
			$leftPlate = $layout->seat(1, 8)['plate'];
			$rightPlate = $layout->seat(7, 8)['plate'];
			$humanPlate = $layout->seat(0, 8)['plate'];
			$statusBottom = max($centre['bet'][1] + $centre['bet'][3], $centre['deck'][1] + $centre['deck'][3]) + 8 + 56;
			$expect($centre['pot'][0] - ($leftPlate[0] + $leftPlate[2]) >= 16 && $rightPlate[0] - ($centre['pot'][0] + $centre['pot'][2]) >= 16, "the pot plates stand apart from both columns at $width x $height");
			$expect($centre['pot'][1] > $layout->height() * .25, "the pot uses the middle of the phone field at $width x $height");
			$expect($statusBottom <= $humanPlate[1] - 14 + 1e-9, "the complete centre cluster clears the human hand at $width x $height");
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

$test('the oval keeps its seats, centre plates and controls apart on laptop, desktop and narrow canvases', static function(callable $expect) use ($overlaps, $fixture): void {
	$view = $fixture('facing-raise-5');
	foreach([[1280, 627], [1366, 675], [1440, 697], [1365, 807], [1536, 771], [1920, 987], [2560, 987], [1100, 700], [1000, 807], [900, 807], [800, 807]] as [$width, $height]) foreach(range(2, 8) as $seats)
	{
		$layout = Layout::fit($width, $height, 1, $seats);
		$at = "at $seats seats, $width x $height";
		$expect($layout->profile === 'wide', "the oval is kept $at");
		$across = $layout->stretch();
		$size = $layout->metric('emblemSize');
		$parts = [];
		for($seat = 0; $seat < $seats; $seat++)
		{
			$geometry = $layout->seat($seat, $seats);
			$last = $geometry['cards'][4];
			$parts[$seat] = [
				'plate' => $geometry['plate']
				, 'fan' => [$geometry['cards'][0][0], $geometry['cards'][0][1], $last[0] + $last[2] - $geometry['cards'][0][0], $last[3]]
				, 'emblem' => [$geometry['emblem'][0] - $size * $across / 2, $geometry['emblem'][1] - $size / 2, $size * $across, $size]
				, 'bank' => $layout->bankBounds($seat, $seats, Chips::bank(500))
			];
			$expect($parts[$seat]['emblem'][0] >= -1.5 && $parts[$seat]['emblem'][0] + $parts[$seat]['emblem'][2] <= $layout->width() + 1.5, "seat $seat's emblem stays on the field $at");
		}
		$inset = static fn(array $rect): array => [$rect[0] + 1.5, $rect[1] + 1.5, $rect[2] - 3, $rect[3] - 3];
		foreach($parts as $first => $one) foreach($parts as $second => $other) if($first < $second)
		foreach($one as $firstName => $a) foreach($other as $secondName => $b)
		$expect(!$overlaps($inset($a), $b), "seat $first's $firstName clears seat $second's $secondName $at");
		$centre = $layout->centre();
		foreach(['pot', 'deck', 'bet'] as $name) foreach($parts as $seat => $blocks) foreach($blocks as $part => $block)
		$expect(!$overlaps($inset($centre[$name]), $block), "the $name clears seat $seat's $part $at");
		$expect($centre['pot'][1] + $centre['pot'][3] <= $centre['deck'][1] && $centre['deck'][1] + $centre['deck'][3] <= $centre['bet'][1], "the centre cluster keeps its order $at");
		$expect($centre['deck'][3] >= 40 && abs($centre['deck'][2] / $centre['deck'][3] - $centre['discard'][2] / $centre['discard'][3]) < 1e-9, "the stock stays a readable card $at");
		$status = $layout->statusLane();
		$expect($parts[0]['plate'][1] - ($centre['bet'][1] + $centre['bet'][3]) >= $layout->plateHeight() + 16 * $status['lines'] - .01, "the status message fits between the bet plate and the local plate $at");
		// The message is centred on the hand; beside the action lane it is never wide enough to run under a button.
		$messageRight = $centre['bet'][0] + $centre['bet'][2] / 2 + $status['width'] / 2;
		$messageTop = $parts[0]['plate'][1] - 8 - $layout->plateHeight() - 16 * ($status['lines'] - 1);
		$statusBox = [$messageRight - $status['width'], $messageTop, $status['width'], $parts[0]['plate'][1] - 8 - $messageTop];
		$expect($status['width'] >= 240 || $status['lines'] === 2, "the status message has a useful width $at");
		$controls = array_column($layout->actions($view, ['discards' => [], 'amount' => 8]), 'rect');
		foreach(['less', 'amount', 'more'] as $part) $controls[] = $layout->stepper($view)[$part];
		foreach($controls as $index => $control)
		{
			$expect($control[0] >= 0 && $control[0] + $control[2] <= $layout->width() + .01 && $control[1] + $control[3] <= $layout->height(), "control $index stays on the field $at");
			foreach($parts as $seat => $blocks) if($seat !== 0) foreach($blocks as $part => $block)
			$expect(!$overlaps($inset($control), $block), "control $index clears seat $seat's $part $at");
			$expect(!$overlaps($inset($statusBox), $control), "the status message stays out from under control $index $at");
		}
		for($seat = 0; $seat < $seats; $seat++)
		{
			[$markerX, $markerY, $markerRadius] = $layout->dealerMarker($seat, $seats);
			$disc = [$markerX - $markerRadius * $across, $markerY - $markerRadius, 2 * $markerRadius * $across, 2 * $markerRadius];
			foreach($controls as $index => $control) $expect(!$overlaps($disc, $control), "seat $seat's dealer button is not under control $index $at");
			foreach($parts as $other => $blocks) foreach($blocks as $part => $block) if($other !== $seat)
			$expect(!$overlaps($inset($disc), $block), "seat $seat's dealer button clears seat $other's $part $at");
			$expect($disc[0] >= -.01 && $disc[0] + $disc[2] <= $layout->width() + .01, "seat $seat's dealer button stays on the field $at");
		}
		// Shrinking is a last resort and bounded: cards and chips never fall below 60% of their preferred size.
		$preferred = Layout::fit((int)ceil(1280 * $height / 720), $height, 1, $seats <= 4 ? $seats : 5);
		$expect($layout->seat(0, $seats)['cards'][0][3] >= .6 * $preferred->seat(0, $seats <= 4 ? $seats : 5)['cards'][0][3] - 1e-6, "the local cards stay at least 60% of their preferred size $at");
	}
	// Standard desktop sizes keep every object at its preferred size.
	foreach([[1440, 807], [1920, 987], [2560, 987]] as [$width, $height]) foreach(range(5, 8) as $seats)
	$expect(abs(Layout::fit($width, $height, 1, $seats)->seat(0, $seats)['cards'][0][3] - Layout::fit($width, $height, 1, 5)->seat(0, 5)['cards'][0][3]) < 1e-9, "a $seats-seat table keeps its preferred card size at $width x $height");
});

$test('heads-up on a phone keeps the pot plate off the opponent', static function(callable $expect) use ($overlaps): void {
	foreach([[390, 782, 1], [1170, 2346, 3], [768, 916, 1], [973, 1177, 1], [360, 640, 1]] as [$width, $height, $dpr])
	{
		$layout = Layout::fit($width, $height, $dpr, 2);
		$expect($layout->profile === 'tall', "$width x $height is a tall table");
		$centre = $layout->centre();
		$opponent = $layout->seat(1, 2);
		$bank = $layout->bankBounds(1, 2, Chips::bank(500));
		$expect($centre['pot'][1] >= max($opponent['cards'][0][1] + $opponent['cards'][0][3], $bank[1] + $bank[3]), "the pot plate starts below the opponent's cards and chips at $width x $height");
		$expect($centre['bet'][1] + $centre['bet'][3] <= $layout->seat(0, 2)['plate'][1], "the bet plate ends above the local plate at $width x $height");
	}
});

$test('dialogs keep their CSS size and whole labels on a compressed desktop', static function(callable $expect) use ($overlaps): void {
	$full = Layout::fit(1440, 807, 1, 8);
	foreach([[1000, 807], [800, 807], [900, 807]] as [$width, $height])
	{
		$narrow = Layout::fit($width, $height, 1, 8);
		$reference = Layout::fit((int)ceil(1280 * $height / 720), $height, 1, 8);
		$expect($narrow->profile === 'wide' && $narrow->stretch() > 1.1, "$width x $height is a compressed oval");
		$pairs = [
			'setup variant' => [$narrow->setup(4, 'jackpots')['variant'], $reference->setup(4, 'jackpots')['variant']]
			, 'setup deal' => [$narrow->setup(4, 'jackpots')['start'], $reference->setup(4, 'jackpots')['start']]
			, 'wild cards cancel' => [$narrow->wildcardMenu()['back'], $reference->wildcardMenu()['back']]
			, 'wild cards row' => [$narrow->wildcardMenu()['items']['one-eyed-jacks'], $reference->wildcardMenu()['items']['one-eyed-jacks']]
			, 'help back' => [$narrow->information()['back'], $reference->information()['back']]
			, 'history ok' => [$narrow->history()['back'], $reference->history()['back']]
			, 'menu item' => [$narrow->menu(false)[0]['rect'], $reference->menu(false)[0]['rect']]
		];
		foreach($pairs as $name => [$squeezed, $natural])
		{
			$a = $narrow->rect(...$squeezed);
			$b = $reference->rect(...$natural);
			$expect($a[2] >= min($b[2], $width - 72) - 1 && abs($a[3] - $b[3]) < 1e-6, "the $name control keeps its CSS size at $width x $height");
			$expect($a[0] >= 0 && $a[0] + $a[2] <= $width, "the $name control stays on a $width x $height canvas");
		}
		foreach([$narrow->menuBox(true), $narrow->setup(4, 'jackpots')['box'], $narrow->limits()['box'], $narrow->wildcardMenu()['box'], $narrow->customWildcards()['box'], $narrow->information()['box'], $narrow->history()['box'], $narrow->discardDialog()['box'], $narrow->panel(440, 250, 120)] as $index => $box)
		{
			[$left, , $boxWidth] = $narrow->rect(...$box);
			$expect($left >= 0 && $left + $boxWidth <= $width, "dialog $index stays on a $width x $height canvas");
		}
		$draw = $narrow->actions(['screen' => 'table', 'phase' => 'draw', 'legalActions' => ['draw' => true]], ['discards' => [], 'amount' => 0]);
		foreach($draw as $button) $expect($narrow->rect(...$button['rect'])[2] >= 119, "{$button['id']} keeps its CSS width in the discard dialog at $width x $height");
	}
	// The card matrix is offered only where a cell holds a checkbox and a label; otherwise the dialog pages by suit.
	$matrix = $full->customWildcards();
	$expect(!$matrix['paged'] && count($matrix['cards']) === 54, 'a full desktop shows the whole matrix');
	$expect($full->rect(...$matrix['done'])[2] >= 200, 'the matrix Done button is wide enough for Use selection');
	$expect($matrix['done'][0] + $matrix['done'][2] <= $matrix['box'][0] + $matrix['box'][2] - 23 && $matrix['back'][0] + $matrix['back'][2] < $matrix['done'][0], 'the matrix footer buttons stay apart inside the dialog');
	foreach([[800, 807, 1, 8], [390, 782, 1, 8], [320, 500, 1, 8], [844, 328, 1, 8]] as [$width, $height, $dpr, $seats])
	{
		$layout = Layout::fit($width, $height, $dpr, $seats);
		$paged = $layout->customWildcards(1);
		$expect($paged['paged'], "a $width x $height canvas pages the custom wild cards by suit");
		$expect(array_key_first($paged['cards']) === 25 && count($paged['cards']) === 13, "a suit page starts with its ace at $width x $height");
		$back = $layout->rect(...$paged['back']);
		$done = $layout->rect(...$paged['done']);
		$box = $layout->rect(...$paged['box']);
		$expect($back[0] + $back[2] < $done[0] && $done[0] + $done[2] <= $box[0] + $box[2] && $back[0] >= $box[0], "the paged footer buttons stay apart inside the dialog at $width x $height");
		$expect($back[2] / $dpr >= 48 - 1e-6 && $done[2] / $dpr >= min(200, ($box[2] / $dpr - 48) * .5), "the paged footer buttons keep useful widths at $width x $height");
	}
});

$test('the table bar opens command menus and both odds dialogs', static function(callable $expect) use ($fixture, $root): void {
	$click=static function(Presenter $presenter,array $rect): array {
		[$x,$y,$width,$height]=$presenter->layout()->rect(...$rect);
		$point=['x'=>$x+$width/2,'y'=>$y+$height/2];
		return $presenter->input([],['x'=>$point['x'],'y'=>$point['y'],'down'=>false,'clicks'=>[$point]],0);
	};
	$view=$fixture('opening-8');
	$presenter=new Presenter($root); $presenter->update($view);
	$presenter->input([['key'=>'alt+g']],null,0);
	$expect($presenter->modal() && $presenter->state()['topMenu']==='game','Game opens as a modal table-bar menu');
	$expect($presenter->input([['key'=>'l']],null,0)===[['type'=>'load-game']] && !$presenter->modal(),'Load Game opens a file chooser without requiring a browser slot');
	$click($presenter,$presenter->layout()->topMenus()['game']);
	$save=$presenter->input([['key'=>'s']],null,0);
	$expect($save===[['type'=>'save-game']] && !$presenter->modal(),'Save Game emits one local command and closes the menu');
	$presenter->input([['key'=>'alt+g']],null,0);
	// Offline tables are saved after every action and Continue restores them, so leaving needs no confirmation.
	$expect($presenter->input([['key'=>'q']],null,0)===[['type'=>'menu']] && !$presenter->modal(),'offline Quit returns to the title at once');
	$escape=new Presenter($root); $escape->update($view);
	$expect($escape->input([['key'=>'escape']],null,0)===[['type'=>'menu']] && !$escape->modal(),'table Escape leaves an offline table at once');

	$new=new Presenter($root); $new->update($view);
	$click($new,$new->layout()->topMenus()['game']);
	$expect($new->input([['key'=>'n']],null,0)===[['type'=>'menu']],'New Game leaves the current table first');
	$menu=['screen'=>'menu','phase'=>'ready','players'=>[],'legalActions'=>[],'revision'=>0,'generation'=>1,'error'=>'','canContinue'=>true];
	$new->update($menu);
	$expect($new->state()['local']==='setup','New Game opens setup after reaching the title state');

	$online=new Presenter($root); $onlineView=$view; $onlineView['mode']='online'; $online->update($onlineView);
	$online->input([['key'=>'alt+g']],null,0);
	foreach(['n','s','l'] as $key)
	$expect($online->input([['key'=>$key]],null,0)===[] && $online->state()['topMenu']==='game',"online $key remains ghosted");
	$expect($online->input([['key'=>'q']],null,0)===[] && $online->state()['confirmDialog']==='forfeit','online Quit asks before forfeiting the match');
	$expect($online->input([['key'=>'n']],null,0)===[] && !$online->modal(),'N keeps the player at the online table');
	$expect($online->input([['key'=>'escape']],null,0)===[] && $online->state()['confirmDialog']==='forfeit','online Escape asks the same question');
	$expect(($online->status()['ui']['controllerFocus']['id']??'confirm:cancel')!=='confirm:leave','the forfeit confirmation never starts on Forfeit');
	$expect($online->input([['key'=>'enter']],null,0)===[] && !$online->modal(),'Enter chooses Stay, the safe default');
	$online->input([['key'=>'escape']],null,0);
	$expect($online->input([['key'=>'q']],null,0)===[] && $online->modal(),'a second Q cannot forfeit');
	$expect($online->input([['key'=>'y']],null,0)===[['type'=>'menu']],'online Quit leaves only after an explicit Y');

	$poker=new Presenter($root); $poker->update($view);
	$poker->input([['key'=>'alt+p']],null,0);
	$expect($poker->state()['topMenu']==='poker','Poker opens its information menu');
	$rules=array_values(array_filter($poker->layout()->topDropdown('poker')['items'],static fn(array $item): bool=>$item['id']==='game-settings'))[0];
	$expect($rules['label']==='House Rules' && $rules['key']==='R','the read-only rules menu is named House Rules and uses R');
	$expect($poker->input([['key'=>'h']],null,0)===[] && $poker->state()['history'] && $poker->modal(),'History opens from the Poker menu as a modal dialog');
	$history=$poker->layout()->history(count($view['players']));
	$expect($history['summary'] && count($history['emblems'])===8 && count($history['rows'])===8,
		'the wide History dialog has one portrait per player and eight matrix rows');
	$expect($history['header'][1]+$history['header'][3]<=$history['rows'][0][1]
		&& $history['rows'][7][1]+$history['rows'][7][3]<=$history['back'][1],'the action summary, transcript, and OK control do not overlap');
	$shortHistory=$poker->layout()->history(count($view['players']),1);
	$expect(count($shortHistory['rows'])===1 && $shortHistory['box'][3]<$history['box'][3],
		'a short transcript keeps the compact height of the reference dialog instead of reserving seven blank rows');
	$poker->update($view);
	$expect($poker->state()['history'],'the History dialog remains open when the table view refreshes');
	$expect(count($poker->paint(1280,720)->instances())>100,'the portrait history and complete transcript paint over the live table');
	$poker->input([['key'=>'enter']],null,0);
	$poker->input([['key'=>'alt+p']],null,0);
	$expect($poker->input([['key'=>'r']],null,0)===[] && $poker->state()['infoDialog']==='game-settings','House Rules opens the active table profile');
	$settings=$poker->state()['gameSettings'];
	$expect($settings===['table'=>'8 players · Offline','opening'=>'Jacks or better','wild'=>'No wild cards','ante'=>'1 chip',
		'before'=>'2–20 chips','after'=>'2–20 chips','raises'=>'3','playerRaises'=>'3'],'House Rules reports every active setup choice');
	$expect($poker->state()['oddsTables']===null && count($poker->paint(1280,720)->instances())>100,'House Rules paints without calculating odds');
	$click($poker,$poker->layout()->gameSettingsDialog()['ok']);
	$manyWild=$view;
	$manyWild['profile']['wild']=['joker','deuces','one-eyed-jacks','mustached-kings','deuces-treys','kings','custom'];
	$manyWild['profile']['customWild']=[12,25,38,51,52,53];
	$many=new Presenter($root); $many->update($manyWild); $many->input([['key'=>'alt+p'],['key'=>'r']],null,0);
	$expect($many->state()['gameSettingsWildLines']===3 && str_contains($many->state()['gameSettings']['wild'],'Joker 2 wild'),
		'a combined wildcard profile expands to show its complete description');
	$poker->input([['key'=>'alt+p']],null,0);
	$expect($poker->input([['key'=>'o']],null,0)===[] && $poker->state()['infoDialog']==='odds','Odds opens the exact dealt-hand table');
	$odds=$poker->state()['oddsTables'];
	$expect($odds['rules']==='No wild cards' && count($odds['odds'])===10,'the standard table includes every possible exact category');
	$wildView=$view; $wildView['profile']['wild']=['custom']; $wildView['profile']['customWild']=[0,1,2,13,14,15,26,27,28,39,40,41];
	$heavy=new Presenter($root); $heavy->update($wildView);
	$heavy->input([['key'=>'alt+p']],null,0); $heavy->input([['key'=>'i']],null,0);
	$heavyOdds=$heavy->state()['oddsTables'];
	$expect($heavyOdds['rules']==='Twos, Threes and Fours wild','the odds heading names the wild cards: '.$heavyOdds['rules']);
	$improving=array_column($heavyOdds['improving'],'odds');
	$expect(count($improving)===11 && in_array('1/4.21',$improving,true),'a favourite reads as 1/4.21 (34,236 to 144,129 against) rather than 0.24/1: '.json_encode($improving));
	$expect(array_filter([...$improving,...array_column($heavyOdds['odds'],'odds')],static fn(string $odds): bool => str_starts_with($odds,'0'))===[],'no odds start with 0.');
	$expect(count($poker->paint(1280,720)->instances())>100,'the first odds table paints over a live table');
	$click($poker,$poker->layout()->oddsDialog('odds',count($odds['odds']))['more']);
	$expect($poker->state()['infoDialog']==='improving','More advances to odds against improving');
	$click($poker,$poker->layout()->oddsDialog('improving',count($odds['improving']))['ok']);
	$expect(!$poker->modal(),'OK closes the information table');
	$wild=$view; $wild['profile']['wild']=['deuces']; $wild['profile']['customWild']=[];
	$wildOdds=new Presenter($root); $wildOdds->update($wild); $wildOdds->input([['key'=>'alt+p'],['key'=>'o']],null,0);
	$wildTable=$wildOdds->state()['oddsTables'];
	$expect($wildTable['rules']==='Deuces wild' && end($wildTable['odds'])['hand']==='Five of a kind','odds adapt to the active wild-card deck');

	foreach([[320,500,1],[390,844,1],[844,328,1],[1280,720,1],[800,807,1]] as [$width,$height,$dpr])
	{
		$layout=Layout::fit($width,$height,$dpr,8);
		foreach([$layout->topDropdown('game'),$layout->topDropdown('poker')] as $dropdown)
		{
			[$left,$top,$boxWidth,$boxHeight]=$layout->rect(...$dropdown['box']);
			$expect($left>=-.01 && $top>=-.01 && $left+$boxWidth<=$width+.01 && $top+$boxHeight<=$height+.01,"a table menu stays on $width x $height");
			foreach($dropdown['items'] as $item) $expect($layout->rect(...$item['rect'])[3]/$dpr>=48-.01,"a table menu item is touch sized on $width x $height");
		}
		foreach(['odds','improving'] as $name)
		{
			$dialog=$layout->oddsDialog($name); [$left,$top,$boxWidth,$boxHeight]=$layout->rect(...$dialog['box']);
			$expect($left>=-.01 && $top>=-.01 && $left+$boxWidth<=$width+.01 && $top+$boxHeight<=$height+.01,"the $name table stays on $width x $height");
			$menuBottom=$layout->rect(0,0,1,$layout->menuHeight())[3];
			$expect($top>=$menuBottom-.01,"the $name table clears the top menu bar on $width x $height");
			$expect($layout->rect(...$dialog['ok'])[3]/$dpr>=48-.01,"the $name OK button is touch sized on $width x $height");
		}
		$settings=$layout->gameSettingsDialog(3); [$left,$top,$boxWidth,$boxHeight]=$layout->rect(...$settings['box']);
		$expect($left>=-.01 && $top>=-.01 && $left+$boxWidth<=$width+.01 && $top+$boxHeight<=$height+.01,"the settings table stays on $width x $height");
		$expect($layout->rect(...$settings['ok'])[3]/$dpr>=48-.01,"the settings OK button is touch sized on $width x $height");
		$wildExtra=2*$layout->controlText()*1.25;
		$expect(abs($settings['rows']['wild'][3]-$settings['rows']['opening'][3]-$wildExtra)<.01,
			"three wildcard lines reserve text leading rather than three full rows on $width x $height");
		$confirmation=$layout->confirmationDialog(); [$left,$top,$boxWidth,$boxHeight]=$layout->rect(...$confirmation['box']);
		$expect($left>=-.01 && $top>=-.01 && $left+$boxWidth<=$width+.01 && $top+$boxHeight<=$height+.01,"the quit confirmation stays on $width x $height");
	}
});

echo "$passed PHP Poker presenter checks passed.\n";
if($failures) { fwrite(STDERR, "$failures presenter checks failed.\n"); exit(1); }

<?php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';
require __DIR__ . '/../../../node_modules/@seanmorris/phonics/autoload.php';

use PhpPoker\Session;
use PhpPoker\Ui\Chips;
use PhpPoker\Ui\Layout;
use PhpPoker\Ui\Motion;
use PhpPoker\Ui\Presenter;

$checks = 0;
$expect = static function(bool $ok, string $message) use (&$checks): void {
	if(!$ok) throw new RuntimeException($message);
	$checks++;
};
$value = static fn(array $banks): int => array_sum(array_map(Chips::value(...), $banks));
$ordered = static function(array $bank) use ($expect): void {
	ksort($bank);
	$rear = PHP_INT_MAX;
	foreach($bank as $count) if($count > 0)
	{
		$expect($count < $rear, 'larger denominations in front are strictly shorter than occupied rear stacks');
		$rear = $count;
	}
};
foreach([0,1,2,4,5,24,25,99,100,499,500,1000,4000,2147483647] as $amount)
{
	$expect(Chips::value(Chips::bank($amount)) === $amount, "exact denomination total for $amount");
	$ordered(Chips::bank($amount));
	if($amount <= 4000) $expect(max(Chips::bank($amount) ?: [0]) <= Chips::STACK_LIMIT, 'normal table banks fit the stack-height limit');
}
$expect(Chips::bank(0) === [], 'an empty stack contains no decorative chips');
$menu = ['generation'=>0,'mode'=>'offline','players'=>[],'pot'=>0];
$online = ['generation'=>0,'mode'=>'online','players'=>[
	['stack'=>499,'debt'=>0,'totalBet'=>1],['stack'=>500,'debt'=>0,'totalBet'=>0]
],'pot'=>1];
$entered = Chips::transition($menu,$online,Chips::fromView($menu));
$expect(Chips::value($entered['after'][0]) === 499 && Chips::value($entered['after'][1]) === 500
	&& Chips::value($entered['after']['pot']) === 1, 'entering an online table initializes every seat bank at an unchanged hand generation');
$stage = Chips::transfer([0 => [100 => 5], 'pot' => []], 0, 'pot', 2);
$expect(Chips::value($stage['before'][0]) === 500 && Chips::value($stage['after'][0]) === 498, 'making change preserves the starting bank and exact payment');
$expect(array_column($stage['moves'], 'value') === [1,1], 'a two-unit bet moves two physical one-unit chips');
$expect($stage['moves'][0]['sourceIndex'] === $stage['before'][0][1] - 1 && $stage['moves'][1]['sourceIndex'] === $stage['before'][0][1] - 2, 'chips leave from the actual top of the changed stack');
$expect($stage['after']['pot'] === [1 => 2], 'the pot keeps the chips that arrived');

// Check every departure/arrival prefix, including broke recipients and payments that would expose a rear stack.
foreach([1,2,5,6,24,25,26,99,100,499,500,1000,4000] as $amount)
foreach(array_unique([1,min(2,$amount),min(20,$amount),max(1,$amount-1),$amount]) as $payment)
foreach([0,1,5,100,500] as $recipient)
foreach([[0,'pot'],['pot',0],[0,1]] as [$from,$to])
{
	$stage = Chips::transfer([$from => Chips::bank($amount), $to => Chips::bank($recipient)], $from, $to, $payment);
	$visible = $stage['before'];
	foreach($visible as $owner => $bank) if($owner !== 'pot') $ordered($bank);
	$flying = 0;
	foreach($stage['events'] as $event)
	{
		$move = $stage['moves'][$event['move']];
		$depart = $event['kind'] === 'depart';
		$expect(Chips::value($event['before']) === Chips::value($visible[$event['owner']]), 'pre-flight change preserves the bank value');
		$expect(($depart ? $move['sourceIndex'] : $move['destinationIndex']) === ($event['before'][$move['value']] ?? 0) - ($depart ? 1 : 0), 'flight endpoints use the actual top chips before any exchange');
		$visible[$event['owner']] = $event['after'];
		$flying += $depart ? $move['value'] : -$move['value'];
		foreach($visible as $owner => $bank)
		{
			if($owner !== 'pot') $ordered($bank);
			$expect(max($bank ?: [0]) <= Chips::STACK_LIMIT, 'departures and pot wins automatically color up tall stacks');
		}
		$expect($value($visible) + $flying === $amount + $recipient, 'making change and moving chips preserve value including overlapping flights');
	}
	$expect($visible === $stage['after'], 'the landed inventory is the inventory used after animation');
}

$stage = Chips::transfer(['pot' => [1 => 1], 0 => [1 => 20]], 'pot', 0, 1);
$expect($stage['before'][0] === [1 => 20] && $stage['after'][0] === [1 => 6, 5 => 3], 'the twenty-first small chip automatically exchanges into larger denominations');
$stage = Chips::transfer([0 => Chips::bank(4000), 'pot' => []], 0, 'pot', 3999);
$expect(count($stage['moves']) < 80, 'a large payment does not explode into thousands of small chips to preserve height order');

foreach([.1,.25,.5,.75,.9] as $progress)
{
	$position = Motion::slide([10,20,5], [210,420,5], $progress);
	$expect(abs($position[0] - (10 + 200 * $progress)) < 1e-9 && abs($position[1] - (20 + 400 * $progress)) < 1e-9, 'constant-speed straight movement has no easing');
}

$before = json_decode(file_get_contents(__DIR__ . '/fixtures/views/facing-raise-5.json'), true, flags: JSON_THROW_ON_ERROR);
$after = $before;
$after['players'][0]['stack'] -= 5;
$after['players'][0]['bet'] = 5;
$after['players'][0]['totalBet'] += 5;
$after['pot'] += 5;
$change = Chips::transition($before, $after, Chips::fromView($before));
$expect(array_sum(array_column($change['stages'][0]['moves'], 'value')) === 5,
	'a raise to five from zero puts the call and raise, not only the two-chip raise increment, into the pot');
foreach([[1280,720,1],[780,1688,2],[1688,780,2]] as [$w,$h,$dpr])
{
	$layout = Layout::fit($w,$h,$dpr);
	$motion = new Motion($before,$after,$change,[]);
	$move = $change['stages'][0]['moves'][0];
	$source = $layout->chip(0,5,$move['value'],$move['sourceIndex']);
	$destination = $layout->chip('pot',5,$move['value'],$move['destinationIndex']);
	$opening = $motion->frame($layout);
	$expect($opening['chips'][0]['position'] === array_map(floatval(...),$source), 'flight starts on the actual source stack at every layout size');
	$expect($opening['totals'][0] === $before['players'][0]['stack'], 'the bankroll count starts at the previous total');
	$motion->advance(Motion::CHIP_TRAVEL / 2);
	$middle = $motion->frame($layout);
	$expect($middle['chips'][0]['position'] === Motion::slide($source,$destination,.5), 'half the travel time covers exactly half the path');
	$expect($middle['totals'][0] === 497, 'the fixed bankroll numerals count through the five-chip raise at an intermediate whole value');
	$motion->advance(10);
	$frame = $motion->frame($layout);
	$expect($frame['chips'] === [] && $frame['banks'] === $change['after'], 'arriving chips become the destination inventory without a duplicate sprite');
	$expect($frame['totals'][0] === $after['players'][0]['stack'], 'the bankroll count lands on the authorized total');
}

// A short online call returns the unmatched part of the raise. Both directions must animate and reconcile to
// the authorized balances, even though payment and refund arrive in the same confirmed view.
$refundBefore = [
	'handNumber'=>1,'generation'=>1,'mode'=>'online','phase'=>'betting','pot'=>21,'players'=>[
		['stack'=>480,'debt'=>0,'totalBet'=>20],['stack'=>500,'debt'=>0,'totalBet'=>0]
	]
];
$refundAfter = $refundBefore;
$refundAfter['phase'] = 'draw';
$refundAfter['pot'] = 11;
$refundAfter['players'][0]['stack'] = 495;
$refundAfter['players'][0]['totalBet'] = 5;
$refundAfter['players'][1]['stack'] = 495;
$refundAfter['players'][1]['totalBet'] = 5;
$refund = Chips::transition($refundBefore, $refundAfter, Chips::fromView($refundBefore));
$expect(count($refund['stages']) === 2, 'a short call animates its payment and the unmatched raise refund');
$expect(array_sum(array_column($refund['stages'][0]['moves'], 'value')) === 5
	&& array_sum(array_column($refund['stages'][1]['moves'], 'value')) === 15, 'the payment and refund move their exact values');
$expect(Chips::value($refund['after']['pot']) === 11 && Chips::value($refund['after'][0]) === 495
	&& Chips::value($refund['after'][1]) === 495, 'the visual pot and both stacks reconcile after the refund');

// A new online hand publishes its number and dealer ante before the joint shuffle has allocated any cards. The
// ante may move immediately, but card backs wait for the first view containing the verified five-card hands.
$onlineShowdown = [
	'handNumber'=>1,'generation'=>1,'mode'=>'online','phase'=>'showdown','dealer'=>0,'pot'=>0,'players'=>[
		['stack'=>501,'debt'=>0,'totalBet'=>0,'cards'=>[1,2,3,4,5]],
		['stack'=>499,'debt'=>0,'totalBet'=>0,'cards'=>[null,null,null,null,null]],
	]
];
$onlineShuffle = $onlineShowdown;
$onlineShuffle['handNumber'] = 2;
$onlineShuffle['phase'] = 'ready';
$onlineShuffle['dealer'] = 1;
$onlineShuffle['pot'] = 1;
$onlineShuffle['players'][1]['stack']--;
$onlineShuffle['players'][1]['totalBet'] = 1;
$onlineShuffle['players'][0]['cards'] = [];
$onlineShuffle['players'][1]['cards'] = [];
$shuffleChange = Chips::transition($onlineShowdown, $onlineShuffle, Chips::fromView($onlineShowdown));
$shuffleMotion = new Motion($onlineShowdown, $onlineShuffle, $shuffleChange, []);
$expect($shuffleMotion->status()['kind'] === 'chips' && $shuffleMotion->frame(Layout::fit(1280,720))['cards'] === [],
	'the pre-allocation online shuffle animates its ante without dealing provisional cards');
$onlineDeal = $onlineShuffle;
$onlineDeal['generation'] = 2;
$onlineDeal['phase'] = 'betting';
$onlineDeal['players'][0]['cards'] = [6,7,8,9,10];
$onlineDeal['players'][1]['cards'] = [null,null,null,null,null];
$dealChange = Chips::transition($onlineShuffle, $onlineDeal, $shuffleChange['after']);
$verifiedDeal = new Motion($onlineShuffle, $onlineDeal, $dealChange, []);
$expect($verifiedDeal->status()['kind'] === 'deal' && $verifiedDeal->active(),
	'the deal animation begins when the verified online hands become presentable');

// Exercise both sides of every overlapping departure/arrival, including exchanges during a pot award.
$award = Chips::transfer(['pot' => [1 => 500], 0 => [1 => 20]], 'pot', 0, 500);
$timeline = ['stages' => [$award], 'after' => $award['after']];
$layout = Layout::fit(1280,720);
$countBefore = $before;
$countBefore['players'][0]['stack'] = 20;
$countAfter = $countBefore;
$countAfter['players'][0]['stack'] = 520;
$count = new Motion($countBefore, $countAfter, $timeline, []);
$count->advance($count->status()['duration'] / 2);
$expect($count->frame($layout)['totals'][0] === 270, 'a pot award counts upward in place instead of replacing the total at once');
$count->advance(100);
$expect($count->frame($layout)['totals'][0] === 520, 'an upward bankroll count finishes exactly');
foreach($award['events'] as $event) foreach([-1e-9,0,1e-9] as $offset)
{
	$motion = new Motion($before, $before, $timeline, []);
	$motion->advance(max(0, $event['at'] + $offset));
	$frame = $motion->frame($layout);
	$expect($value($frame['banks']) + array_sum(array_column($frame['chips'], 'value')) === 520, 'no chip disappears or duplicates at an exchange boundary');
	$ordered($frame['banks'][0]);
	$expect(max($frame['banks'][0] ?: [0]) <= Chips::STACK_LIMIT, 'the rendered award bank stays below the height limit');
}

$seen = [];
foreach([2,4,8] as $seats) foreach(['jackpots','anything'] as $variant)
{
	$session = new Session;
	$presenter = new Presenter(dirname(__DIR__));
	$presenter->update($session->view());
	$session->command(['type' => 'start', 'players' => $seats, 'variant' => $variant], "motion-$seats-$variant");
	$session->command(['type' => 'pace', 'value' => 'fast']);
	for($step = 0; $step < 1800; $step++)
	{
		$view = $session->view();
		$presenter->update($view);
		$state = $presenter->state();
		foreach($view['players'] as $seat => $player)
		{
			$expect(Chips::value($state['chips'][$seat]) === $player['stack'], 'visible bank inventory matches the authorized balance');
			$ordered($state['chips'][$seat]);
		}
		$expect(Chips::value($state['chips']['pot']) === $view['pot'], 'pot inventory matches the authorized pot');
		$animation = $presenter->status()['ui']['animation'];
		if($animation)
		{
			$seen[$animation['kind']] = true;
			$expect($presenter->input([['key' => 'd'],['key' => 'c'],['key' => 'b'],['key' => 'f'],['key' => 'enter']], null, 0) === [], 'game input cannot act during a card or chip transfer');
			foreach([0,.1,.15,.35,.6,.9] as $dt)
			{
				$presenter->advance($dt);
				$frame = $presenter->state()['motion'];
				if(!$frame) break;
				$moving = array_sum(array_column($frame['chips'], 'value'));
				$expect($value($frame['banks']) + $moving === $value($state['chips']), 'every chip is in a stack or in flight, never duplicated or lost');
				foreach($frame['banks'] as $bank) foreach($bank as $count) $expect($count >= 0, 'no stack has a negative count');
				foreach($frame['banks'] as $owner => $bank) if($owner !== 'pot') $ordered($bank);
			}
			$presenter->advance(100);
			$expect(!$presenter->animating(), 'every movement has a finite idle endpoint');
		}
		if($view['handNumber'] >= 4 && $view['turn'] === -1) break;
		if($view['turn'] > 0) { $session->tick(.1); continue; }
		$legal = $view['legalActions'];
		if(!empty($legal['next'])) $intent = ['type' => 'next'];
		elseif(!empty($legal['draw'])) $intent = ['type' => 'draw', 'cards' => array_slice($view['players'][0]['cards'], 0, 3)];
		else $intent = ['type' => $legal['call'] ? 'call' : ($legal['bet'] ? 'bet' : ($legal['check'] ? 'check' : 'pass')), 'amount' => $legal['minBet']];
		$session->command($intent + ['generation' => $view['generation'], 'revision' => $view['revision']]);
	}
	$expect($view['handNumber'] >= 4, 'real sessions complete deals, bets, replacements, awards and carry pots');
}
$expect(isset($seen['deal'], $seen['draw'], $seen['chips']), 'all requested movements occur in real sessions');

$layout = Layout::fit(1688,780,2);
$human = $layout->seat(0,8);
$handRight = $human['cards'][4][0] + $human['cards'][4][2];
foreach(Chips::bank(500) as $denomination => $count) for($i = 0; $i < $count; $i++)
{
	[$x,$y,$r] = $layout->chip(0,8,$denomination,$i);
	$expect($x + $r < $layout->metric('actions')[0], 'short-screen human chips clear the action panel');
	$expect($y - $r * .42 > $human['caption'][1] + 8, 'short-screen chip stacks clear the human bankroll caption');
}
$expect(abs($human['chips'][0] - ($handRight + 4)) < 1e-9, 'short-screen human chips hug the hand corner');

// A finite winner flash changes only the winning seat's label; it never locks the next deal.
$presenter = new Presenter(dirname(__DIR__));
$before = json_decode(file_get_contents(__DIR__ . '/fixtures/views/showdown-5.json'), true, flags: JSON_THROW_ON_ERROR);
$before['phase'] = 'final-betting';
$before['awards'] = [];
$presenter->update($before);
$after = $before;
$after['phase'] = 'showdown';
$presenter->update($after);
$expect($presenter->state()['hint'] === '', 'no winning hand is announced at the top of the table');
$presenter->advance(.299);
$expect($presenter->state()['winnerFlash']['visible'], 'winner label starts visible for 0.3 seconds');
$presenter->advance(.002);
$expect(!$presenter->state()['winnerFlash']['visible'], 'winner label blinks off after 0.3 seconds');
$presenter->advance(.3);
$expect($presenter->state()['winnerFlash']['visible'], 'winner label blinks on after another 0.3 seconds');
$presenter->advance(3.399);
$expect($presenter->state()['winnerFlash'] === ['active' => false, 'visible' => true] && !$presenter->locked(), 'four seconds ends the flash with a visible, unlocked label');

// The page republishes its status only when a frame is drawn. A slow frame that jumps from a lit phase straight
// past four seconds changes nothing visible, so the end of the flash itself must ask for that final frame.
foreach([[3.7, .35], [3.8, .5], [3.65, 1.0]] as [$lit, $frame])
{
	$slow = new Presenter(dirname(__DIR__));
	$slow->update($before); $slow->update($after);
	$slow->advance($lit);
	(new ReflectionProperty(Presenter::class, 'motion'))->setValue($slow, null);
	$expect($slow->state()['winnerFlash'] === ['active' => true, 'visible' => true], "the flash is lit {$lit} seconds in");
	(new ReflectionProperty(Presenter::class, 'dirty'))->setValue($slow, false);
	$slow->advance($frame);
	$expect($slow->state()['winnerFlash'] === ['active' => false, 'visible' => true] && $slow->dirty(),
		"a {$frame}-second frame from {$lit} seconds ends the flash and asks for the final redraw");
}

echo "$checks PHP Poker motion and chip checks passed.\n";

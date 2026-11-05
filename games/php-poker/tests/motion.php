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
$after['players'][0]['stack'] -= 2;
$after['players'][0]['totalBet'] += 2;
$after['pot'] += 2;
$change = Chips::transition($before, $after, Chips::fromView($before));
foreach([[1280,720,1],[780,1688,2],[1688,780,2]] as [$w,$h,$dpr])
{
	$layout = Layout::fit($w,$h,$dpr);
	$motion = new Motion($before,$after,$change,[]);
	$move = $change['stages'][0]['moves'][0];
	$source = $layout->chip(0,5,$move['value'],$move['sourceIndex']);
	$destination = $layout->chip('pot',5,$move['value'],$move['destinationIndex']);
	$expect($motion->frame($layout)['chips'][0]['position'] === array_map(floatval(...),$source), 'flight starts on the actual source stack at every layout size');
	$motion->advance(Motion::CHIP_TRAVEL / 2);
	$expect($motion->frame($layout)['chips'][0]['position'] === Motion::slide($source,$destination,.5), 'half the travel time covers exactly half the path');
	$motion->advance(10);
	$frame = $motion->frame($layout);
	$expect($frame['chips'] === [] && $frame['banks'] === $change['after'], 'arriving chips become the destination inventory without a duplicate sprite');
}

// Exercise both sides of every overlapping departure/arrival, including exchanges during a pot award.
$award = Chips::transfer(['pot' => [1 => 500], 0 => [1 => 20]], 'pot', 0, 500);
$timeline = ['stages' => [$award], 'after' => $award['after']];
$layout = Layout::fit(1280,720);
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
	$expect($x - $r > $handRight && $x + $r < $layout->metric('actions')[0], 'short-screen human chips clear the hand and action panel');
	$expect($y + $r * .42 < $human['caption'][1] - 6, 'short-screen chip stacks clear the human bankroll caption');
}

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

echo "$checks PHP Poker motion and chip checks passed.\n";

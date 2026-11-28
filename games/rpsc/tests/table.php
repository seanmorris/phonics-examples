<?php
declare(strict_types=1);
// The table is plain PHP: no engine, no graphics device.
require dirname(__DIR__) . '/autoload.php';

use Rpsc\Board;
use Rpsc\Random;
use Rpsc\Session;
use Rpsc\Ui\Table;

$checks = 0;

function check(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

function sq(string $name): int
{
	return Board::square(ord($name[0]) - 97, (int)$name[1] - 1);
}

/** The drawn pieces in a comparable form. */
function drawn(Table $table): array
{
	$pieces = [];

	foreach($table->pieces as $key => $piece)
	{
		unset($piece['reveal']);
		ksort($piece);
		$pieces[$key] = $piece;
	}

	ksort($pieces);

	return $pieces;
}

/** Run the table until it has nothing left to show. */
function settle(Table $table): float
{
	$time = 0.0;

	while($table->busy())
	{
		$table->advance(1 / 60);
		$time += 1 / 60;
		check($time < 30, 'an animation ends');
	}

	return $time;
}

// Placement: the player's own pieces are drawn face up from the arrangement, the bot's face down.
$session = new Session(4, 'normal', Board::BLACK);
$table = new Table;
$table->show($session->view());
check(count($table->pieces) === 24, 'both sides are drawn during placement');
$own = array_filter($table->pieces, static fn(array $piece): bool => $piece['side'] === Board::BLACK);
check(array_column(array_values($own), 'kind') === $session->arrangement, 'the arrangement is drawn in home order');
foreach($table->pieces as $piece) check(($piece['side'] === Board::BLACK) === ($piece['face'] === 1.0), 'only the player\'s pieces are face up');
check($table->status() === 'Tap two of your pieces to swap them', 'placement prompt');
check($table->live(sq('b1')) && !$table->live(sq('b8')) && !$table->live(sq('c4')), 'only home squares are live during placement');
check($table->press(sq('b8')) === null && $table->selected === null, 'the opponent\'s pieces cannot be picked');
check($table->press(sq('b1')) === null && $table->selected === sq('b1') && $table->status() === 'Tap another piece to swap', 'first tap selects');
check($table->label(sq('b1')) === 'b1 your ' . strtolower(Board::KINDS[$session->arrangement[0]]) . ' selected', 'selected label');
check($table->press(sq('b1')) === null && $table->selected === null, 'tapping it again lets go');
$table->press(sq('b1'));
$intent = $table->press(sq('h3'));
check($intent === ['type' => 'swap', 'a' => 0, 'b' => 11] && $table->selected === null, 'second tap swaps');
$before = $session->arrangement;
$session->command($intent);
$table->show($session->view());
check($table->pieces[0]['kind'] === $before[11] && $table->pieces[11]['kind'] === $before[0], 'the swap is drawn');
check($table->label(sq('b8')) === 'b8 Red piece face down' && $table->label(sq('c4')) === 'c4 empty', 'labels say only what the player may know');
settle($table);
$table->cues = [];

// A swap is shown as the two pieces trading places: each starts on the other's square and slides home, lifted.
$session = new Session(4, 'normal', Board::BLACK);
$table = new Table;
$table->show($session->view());
$first = 0;
$second = (int)array_search(true, array_map(static fn(int $kind): bool => $kind !== $session->arrangement[0], $session->arrangement), true);
$home = $session->view()['home'];
$kinds = $session->arrangement;
check(!$table->busy(), 'a fresh placement stands still');
$table->press($home[$first]);
$intent = $table->press($home[$second]);
check($intent === ['type' => 'swap', 'a' => $first, 'b' => $second], 'two different kinds are picked');
$session->command($intent);
$table->show($session->view());
check($table->busy() && !$table->live($home[2]), 'the swap is shown before anything else can be pressed');
check($table->pieces[$first]['kind'] === $kinds[$second] && $table->pieces[$second]['kind'] === $kinds[$first], 'each piece already carries its new kind');
check($table->pieces[$first]['square'] === $home[$first] && $table->label($home[$first]) === Board::name($home[$first]) . ' your ' . strtolower(Board::KINDS[$kinds[$second]]), 'and is labeled on its new square');
check($table->pieces[$first]['x'] === (float)Board::x($home[$second]) && $table->pieces[$first]['y'] === (float)Board::y($home[$second]), 'it starts where its kind came from');
check($table->pieces[$second]['x'] === (float)Board::x($home[$first]) && $table->pieces[$second]['y'] === (float)Board::y($home[$first]), 'and so does the other');
$table->advance(Table::SWAP / 2);
$between = static fn(float $value, float $a, float $b): bool => $value > min($a, $b) - .001 && $value < max($a, $b) + .001 && ($a === $b || ($value !== $a && $value !== $b));
check($between($table->pieces[$first]['x'], Board::x($home[$first]), Board::x($home[$second])), 'halfway, the piece is between the two squares');
check($between($table->pieces[$first]['y'], Board::y($home[$first]), Board::y($home[$second])), 'on both axes');
check($table->pieces[$first]['scale'] > 1.1 && $table->pieces[$second]['scale'] > 1.1, 'both pieces are lifted for the trip');
check($table->pieces[2]['scale'] === 1.0 && $table->pieces[2]['x'] === (float)Board::x($home[2]), 'no other piece moves');
$took = settle($table);
check($took > 0 && $took <= Table::SWAP, 'the swap takes its set time');
$fresh = new Table;
$fresh->show($session->view());
check(drawn($table) === drawn($fresh) && $table->cues === ['move'], 'the pieces are set down exactly, with one sound');
check($table->live($home[2]) && $table->status() === 'Tap two of your pieces to swap them', 'and the player can go on arranging');

// Swapping two pieces of the same kind changes nothing, so nothing slides.
$same = (int)array_search($session->arrangement[0], array_slice($session->arrangement, 1, null, true), true);
$session->command(['type' => 'swap', 'a' => 0, 'b' => $same]);
$table->show($session->view());
check(!$table->busy(), 'equal kinds do not slide');

// A shuffle slides every piece whose kind changed, and ends on the new arrangement.
$table->cues = [];
$kinds = $session->arrangement;
$session->command(['type' => 'shuffle']);
$table->show($session->view());
$changed = count(array_filter($session->arrangement, static fn(int $kind, int $index): bool => $kind !== $kinds[$index], ARRAY_FILTER_USE_BOTH));
check($changed > 0 && $table->busy(), 'a shuffle is shown too');
$table->advance(Table::SWAP / 2);
$moving = count(array_filter($table->pieces, static fn(array $piece): bool => $piece['side'] === Board::BLACK && $piece['scale'] > 1.001));
check($moving === $changed, 'exactly the pieces whose kind changed are in the air');
settle($table);
$fresh = new Table;
$fresh->show($session->view());
check(drawn($table) === drawn($fresh) && $table->cues === ['move'], 'the shuffle ends on the new arrangement');

// A different game is dealt, not slid into place: the table is reset between games.
$table->reset();
$table->show((new Session(9, 'easy', Board::BLACK))->view());
check(!$table->busy() && count($table->pieces) === 24, 'a new game stands still at once');
$table->show((new Session(10, 'easy', Board::RED))->view());
check(!$table->busy(), 'and so does a game seen from the other side');
$session = new Session(4, 'normal', Board::BLACK);
$table = new Table;
$table->show($session->view());
$session->command(['type' => 'swap', 'a' => 0, 'b' => 11]);
$table->show($session->view());
settle($table);

// Play: selection, targets, and the cursor.
$session->command(['type' => 'ready']);
$table->push($session->drain());
$table->show($session->view());
check(!$table->busy() && $table->status() === 'Your move', 'play begins on the player\'s move');
check(!$table->live(sq('b1')) && $table->live(sq('b3')) && !$table->live(sq('c4')), 'only pieces with a step are live');
check($table->press(sq('b1')) === null && $table->selected === null, 'a blocked piece cannot be picked up');
check($table->press(sq('b3')) === null && $table->selected === sq('b3'), 'a free piece can');
check($table->targets() === [sq('a4'), sq('c4')] && $table->victims() === [] && $table->status() === 'Choose where to move', 'its steps are offered');
check($table->live(sq('c4')) && $table->label(sq('c4')) === 'c4 empty move here', 'a target is live and says so');
check($table->press(sq('d3')) === null && $table->selected === sq('d3'), 'tapping another piece switches to it');
check($table->press(sq('a6')) === null && $table->selected === null, 'tapping elsewhere lets go');
check($table->cancel() === false, 'nothing to cancel');
$table->steer(1, 0);
check($table->cursor === $session->view()['home'][9], 'the cursor appears on the player\'s front rank');
$table->steer(-1, 0);
$table->steer(0, 1);
check($table->cursor === sq('c4'), 'steering follows the screen for Black');
$table->press(sq('b3'));
$intent = $table->press(sq('c4'));
check($intent === ['type' => 'move', 'from' => sq('b3'), 'to' => sq('c4')], 'tapping a target moves');

// The move is shown after the rules have applied it: the piece slides, then stands on its square.
$key = $table->view()['pieces'][array_search(sq('b3'), array_column($table->view()['pieces'], 'square'), true)]['key'];
$session->command($intent);
$table->push($session->drain());
$table->show($session->view());
check($table->busy() && $table->pieces[$key]['square'] === sq('b3'), 'the table still shows the piece where it was');
check($table->press(sq('d3')) === null && $table->selected === null && !$table->live(sq('d3')), 'nothing can be pressed during an animation');
$table->advance(Table::SLIDE / 2);
check($table->pieces[$key]['x'] > 1 && $table->pieces[$key]['x'] < 2 && $table->pieces[$key]['y'] > 2 && $table->pieces[$key]['y'] < 3, 'the piece is between squares');
settle($table);
check($table->pieces[$key]['square'] === sq('c4') && $table->pieces[$key]['x'] === 2.0 && $table->pieces[$key]['scale'] === 1.0, 'the piece arrives');
check($table->trail === [sq('b3'), sq('c4')] && $table->cues === ['move'], 'the move leaves a trail and a sound');
check($table->status() === 'Red is thinking', 'the opponent\'s turn is announced');

// Red sees the board turned round.
$red = new Table;
$other = new Session(4, 'normal', Board::RED);
$other->command(['type' => 'ready']);
$red->show($other->view());
$red->steer(1, 0);
$start = $red->cursor;
$red->steer(1, 0);
check(Board::x($red->cursor) === Board::x($start) - 1, 'right on screen is toward file a for Red');
$red->steer(0, 1);
check(Board::y($red->cursor) === Board::y($start) - 1, 'up on screen is down the ranks for Red');

// Whole games: after every event has played, the table shows exactly what a fresh look at the game would.
$events = ['move' => 0, 'challenge' => 0, 'over' => 0, 'crown' => 0];
$cues = [];

for($seed = 1; $seed <= 12; $seed++)
{
	$random = new Random($seed);
	$human = $seed % 2;
	$session = new Session($seed, Rpsc\Bot::LEVELS[$seed % 3], $human);
	$session->command(['type' => 'ready']);
	$session->drain();
	$table = new Table;
	$table->show($session->view());

	for($steps = 0; $steps < 4000; $steps++)
	{
		$view = $session->view();
		$over = $view['phase'] === 'over';

		if($view['phase'] === 'play' && $view['turn'] !== $human) $session->tick(Session::THINK);
		elseif($view['phase'] === 'play')
		{
			// Play through the table, as a player would: pick a piece, then a challenge or a step.
			$wantChallenge = $view['challenges'] !== [] && $random->int(2) === 0;
			[$from, $to] = $wantChallenge ? $view['challenges'][$random->int(count($view['challenges']))] : $view['moves'][$random->int(count($view['moves']))];
			check($table->live($from) && $table->press($from) === null && $table->selected === $from, 'the piece can be picked up');
			check($table->live($to), 'the destination is live');
			$intent = $table->press($to);
			check($intent === ['type' => $wantChallenge ? 'challenge' : 'move', 'from' => $from, 'to' => $to], 'the table asks for what was tapped');
			check($session->command($intent), 'and the session accepts it');
		}

		$new = $session->drain();
		// Show the events without the new view, so the pieces come from the animation alone. The ending is the one
		// event that needs the view: it turns every survivor over to the kind the finished game now shows.
		$table->push($new);

		if($session->game->phase === 'over' && !$over) $table->show($session->view());

		foreach($new as $event)
		{
			$events[$event['type']] = ($events[$event['type']] ?? 0) + 1;

			if($event['type'] === 'challenge')
			{
				$table->advance(Table::FLIP + Table::HOLD / 2);
				$attacker = $table->pieces[$event['side'] * 12 + $event['attackerId']];
				$defender = $table->pieces[(1 - $event['side']) * 12 + $event['defenderId']];
				check($attacker['kind'] === $event['attacker'] && $defender['kind'] === $event['defender'], 'both challenged pieces are face up while it is held');
				check($attacker['scale'] > 1.2 && $table->banner !== '', 'and they are lifted, with a line saying what happened');
			}

			if($event['type'] === 'move' && $event['crowned']) $events['crown']++;
		}

		settle($table);
		$fresh = new Table;
		$fresh->show($session->view());
		check(drawn($table) === drawn($fresh), 'the animated table matches a fresh one');
		$table->show($session->view());
		$cues = array_merge($cues, $table->cues);
		$table->cues = [];

		if($over) break;
	}

	check($session->game->phase === 'over' && $table->status() !== '' && !$table->busy(), 'the game ended and the table says how');

	foreach($table->pieces as $piece) check($piece['kind'] !== null && $piece['face'] === 1.0, 'every survivor is face up at the end');
}

$counted = array_count_values($cues);
check($events['challenge'] > 20 && $events['over'] === 12 && $events['crown'] > 0, 'the games covered challenges, crownings, and endings');
check(($counted['move'] ?? 0) === $events['move'] && ($counted['flip'] ?? 0) === $events['challenge'] && ($counted['crown'] ?? 0) === $events['crown'], 'each event made its sound once');
check(($counted['take'] ?? 0) + ($counted['tie'] ?? 0) >= $events['challenge'], 'each challenge resolved with a sound');

printf("RPSC table: %d checks passed over %d moves and %d challenges.\n", $checks, $events['move'], $events['challenge']);

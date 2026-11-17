<?php
declare(strict_types=1);
// The bot and the session need no engine classes either.
require dirname(__DIR__) . '/autoload.php';

use Rpsc\Board;
use Rpsc\Bot;
use Rpsc\Game;
use Rpsc\Random;
use Rpsc\Session;

$checks = 0;

function check(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

const SPREAD = [0, 1, 2, 0, 1, 2, 0, 1, 2, 0, 1, 2];

/**
 * Play one game between two players and return it.
 *
 * A player is a bot level, or "random" for a player that picks any legal move and challenges one time in three.
 */
function play(int $seed, string $black, string $red): Game
{
	$random = new Random($seed);
	$game = new Game;
	$bots = [];

	foreach([Board::BLACK => $black, Board::RED => $red] as $side => $who)
	{
		$bots[$side] = $who === 'random' ? null : new Bot($side, $who, new Random($seed * 31 + $side));
		$game->place($side, $bots[$side] ? $bots[$side]->placement() : $random->shuffle(SPREAD));
	}

	$game->drain();

	for($actions = 0; $game->phase === 'play'; $actions++)
	{
		check($actions < 3000, 'a game between bots ends');

		$side = $game->turn;
		$view = $game->view($side);

		if($bots[$side] !== null)
		{
			$challenge = $bots[$side]->challenge($view);

			if($challenge !== null) $game->challenge(...$challenge);
			else $game->move(...$bots[$side]->move($view));
		}
		elseif($view['challenges'] !== [] && $random->int(3) === 0) $game->challenge(...$view['challenges'][$random->int(count($view['challenges']))]);
		else $game->move(...$view['moves'][$random->int(count($view['moves']))]);

		foreach($game->drain() as $event) foreach($bots as $bot) $bot?->observe($event, $game->ply);
	}

	return $game;
}

/** Share of the games a player wins from both sides, counting a draw as half. */
function share(string $player, string $against, int $games): array
{
	$score = 0.0;
	$turns = 0;
	$reasons = [];

	for($seed = 1; $seed <= $games; $seed++)
	{
		$side = $seed % 2;
		$game = $side === Board::BLACK ? play($seed, $player, $against) : play($seed, $against, $player);
		$score += $game->winner === null ? .5 : ($game->winner === $side ? 1 : 0);
		$turns += $game->ply;
		$reasons[$game->reason] = ($reasons[$game->reason] ?? 0) + 1;
	}

	ksort($reasons);

	return [$score / $games, intdiv($turns, $games), $reasons];
}

// Placement is always legal.
foreach(Bot::LEVELS as $level) for($seed = 1; $seed <= 20; $seed++) Game::checkPlacement((new Bot(Board::RED, $level, new Random($seed)))->placement());
check(true, 'bot placements are legal');

// The bot cannot peek: two games that differ only in the opponent's hidden kinds get the same first decisions.
foreach(Bot::LEVELS as $level)
{
	$choices = [];

	foreach([SPREAD, array_reverse(SPREAD), [2, 2, 2, 2, 1, 1, 1, 1, 0, 0, 0, 0]] as $hidden)
	{
		$game = new Game;
		$game->place(Board::BLACK, $hidden);
		$game->place(Board::RED, SPREAD);
		// Bring the armies into contact with moves that do not depend on kinds.
		foreach([['b3', 'c4'], ['c6', 'd5'], ['d3', 'e4'], ['e6', 'f5'], ['c4', 'd5'], ['a6', 'b5']] as [$from, $to])
		{
			$from = Board::square(ord($from[0]) - 97, (int)$from[1] - 1);
			$to = Board::square(ord($to[0]) - 97, (int)$to[1] - 1);

			if($game->piece($from) !== null && $game->piece($to) === null && in_array([$from, $to], $game->moves(), true)) $game->move($from, $to);
			else $game->move(...$game->moves()[0]);
		}

		check($game->turn === Board::BLACK, 'six moves were made');
		$game->move(...$game->moves()[0]);
		$bot = new Bot(Board::RED, $level, new Random(7));
		$view = $game->view(Board::RED);
		$choices[] = [$bot->challenge($view), $bot->move($view)];
	}

	check($choices[0] === $choices[1] && $choices[1] === $choices[2], "the $level bot decides without the hidden kinds");
}

// Same seed, same game.
$first = play(11, 'hard', 'normal')->export();
check($first === play(11, 'hard', 'normal')->export(), 'bot games are reproducible');

// Strength: each level beats a random player, and a stronger level beats a weaker one.
$report = [];
[$easy, $turns, $reasons] = share('easy', 'random', 60);
$report[] = sprintf('easy v random %.2f (%d turns)', $easy, $turns);
[$normal] = share('normal', 'random', 60);
$report[] = sprintf('normal v random %.2f', $normal);
[$hard, $turns, $reasons] = share('hard', 'random', 60);
$report[] = sprintf('hard v random %.2f (%d turns)', $hard, $turns);
[$ladder, $turns, $ladderReasons] = share('hard', 'easy', 80);
$report[] = sprintf('hard v easy %.2f (%d turns)', $ladder, $turns);
[$mirror, $turns, $mirrorReasons] = share('hard', 'hard', 80);
$report[] = sprintf('hard v hard %.2f (%d turns: %s)', $mirror, $turns, json_encode($mirrorReasons));
check($easy > .55, 'the easy bot beats a random player');
check($normal > .7, 'the normal bot beats a random player');
check($hard > .75, 'the hard bot beats a random player');
check($ladder > .55, 'the hard bot beats the easy bot');
check(($mirrorReasons['draw'] ?? 0) < 20, 'bots mostly finish their games');

// A session: the player places, both sides act, and the events arrive in order.
$session = new Session(5, 'normal', Board::BLACK);
check($session->view()['phase'] === 'placement' && $session->view()['arrangement'] === $session->arrangement, 'a new session waits for the placement');
Game::checkPlacement($session->arrangement);
check(!$session->command(['type' => 'move', 'from' => 17, 'to' => 24]), 'no move during placement');
$before = $session->arrangement;
check($session->command(['type' => 'swap', 'a' => 0, 'b' => 11]), 'swap two home squares');
check($session->arrangement[0] === $before[11] && $session->arrangement[11] === $before[0], 'the swap applies');
check(!$session->command(['type' => 'swap', 'a' => 0, 'b' => 12]) && !$session->command(['type' => 'swap', 'a' => 3, 'b' => 3]), 'bad swaps are ignored');
check($session->command(['type' => 'shuffle']), 'shuffle');
Game::checkPlacement($session->arrangement);
$session->consumeDirty();
check($session->command(['type' => 'ready']) && $session->consumeDirty() && !$session->consumeDirty(), 'ready places the pieces');
check($session->drain() === [['type' => 'start']] && $session->view()['arrangement'] === null, 'play starts');
check(!$session->command(['type' => 'ready']) && !$session->command(['type' => 'shuffle']), 'placement is over');
$session->tick(5);
check($session->drain() === [], 'the bot waits for the player');
check(!$session->command(['type' => 'move', 'from' => 0, 'to' => 9]), 'an illegal move is ignored');
[$from, $to] = $session->view()['moves'][0];
check($session->command(['type' => 'move', 'from' => $from, 'to' => $to]), 'the player moves');
$session->tick(.1);
check(count($session->drain()) === 1, 'the bot thinks before it acts');
$session->tick(Session::THINK, true);
check($session->drain() === [], 'the bot waits for the table');
$session->tick(Session::THINK);
$events = $session->drain();
check(count($events) === 1 && $events[0]['side'] === Board::RED, 'then it acts');

// Play a whole game through the session with a random player, saving and restoring on the way.
$random = new Random(99);
$session = new Session(21, 'hard', Board::RED);
$session->command(['type' => 'ready']);
$restored = 0;

for($steps = 0; $session->game->phase === 'play'; $steps++)
{
	check($steps < 6000, 'a session game ends');
	$view = $session->view();

	if($session->game->turn !== $session->human) $session->tick(Session::THINK);
	elseif($view['challenges'] !== [] && $random->int(3) === 0) $session->command(['type' => 'challenge', 'from' => $view['challenges'][0][0], 'to' => $view['challenges'][0][1]]);
	else
	{
		$move = $view['moves'][$random->int(count($view['moves']))];
		$session->command(['type' => 'move', 'from' => $move[0], 'to' => $move[1]]);
	}

	if($steps % 25 === 24)
	{
		$json = $session->save();
		$copy = Session::restore($json);
		check($copy !== null && $copy->save() === $json && $copy->view() === $session->view(), 'a session round-trips');
		// The restored session must carry on exactly as the original would.
		$session = $copy;
		$restored++;
	}
}

check($restored > 2 && $session->game->phase === 'over', 'the game finished across restores');
$twin = new Session(21, 'hard', Board::RED);
check($twin->save() !== '' && Session::restore('') === null && Session::restore('{}') === null && Session::restore('[1,2') === null, 'bad saves are refused');
$data = json_decode($twin->save(), true);
$data['level'] = 'impossible';
check(Session::restore(json_encode($data)) === null, 'an unknown level is refused');
$data = json_decode($twin->save(), true);
$data['bot']['side'] = Board::RED;
check(Session::restore(json_encode($data)) === null, 'a bot on the player\'s side is refused');
$data = json_decode($twin->save(), true);
$data['arrangement'][0] = ($data['arrangement'][0] + 1) % 3;
check(Session::restore(json_encode($data)) === null, 'an illegal arrangement is refused');
$session = new Session(3, 'easy', Board::BLACK);
check($session->command(['type' => 'resign']) && $session->game->winner === Board::RED && !$session->command(['type' => 'resign']), 'the player can resign');

printf("RPSC bot and session: %d checks passed. %s.\n", $checks, implode('; ', $report));

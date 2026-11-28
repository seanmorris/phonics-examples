<?php
declare(strict_types=1);
// The rules need no engine classes, so the suite runs on a bare PHP with nothing installed.
require dirname(__DIR__) . '/autoload.php';

use Rpsc\Board;
use Rpsc\Game;
use Rpsc\Random;

$checks = 0;

function check(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

function throws(callable $action, string $message): void
{
	try { $action(); }
	catch(InvalidArgumentException|LogicException) { check(true, $message); return; }

	check(false, $message);
}

/** Build a game in play from a list of [square name, side, kind, king] pieces. */
function position(array $pieces, int $turn = Board::BLACK, array $lost = [[], []]): Game
{
	$rows = [];
	$next = [0, 0];
	$kinds = [[0, 0, 0], [0, 0, 0]];

	foreach($pieces as [$name, $side, $kind, $king])
	{
		$rows[] = [sq($name), $side, $kind, $king ? 1 : 0, $next[$side]++];
		$kinds[$side][$kind]++;
	}

	// Whatever the position leaves out was lost, so every side still adds up to four of each kind.
	foreach([0, 1] as $side)
	{
		foreach($lost[$side] as $kind) $kinds[$side][$kind]++;

		foreach([0, 1, 2] as $kind) for($count = $kinds[$side][$kind]; $count < Board::PER_KIND; $count++) $lost[$side][] = $kind;
	}

	sort($rows);

	return Game::import([
		'version' => Game::VERSION, 'phase' => 'play', 'turn' => $turn, 'challenged' => false, 'quiet' => 0, 'ply' => 0
		, 'winner' => null, 'reason' => '', 'placed' => [true, true], 'lost' => $lost, 'pieces' => $rows
	]);
}

function sq(string $name): int
{
	return Board::square(ord($name[0]) - 97, (int)$name[1] - 1);
}

const R = Board::ROCK;
const P = Board::PAPER;
const S = Board::SCISSORS;
const B = Board::BLACK;
const D = Board::RED;
const SPREAD = [R, P, S, R, P, S, R, P, S, R, P, S];

// Geometry: every square belongs to exactly one side, and each side's home and goal squares are its own.
check(Board::owner(sq('a1')) === D, 'a1 is a black square, so Red plays on it');
check(Board::owner(sq('b1')) === B, 'b1 is a red square, so Black plays on it');
check(array_map(Board::name(...), Board::home(B)) === ['b1', 'd1', 'f1', 'h1', 'a2', 'c2', 'e2', 'g2', 'b3', 'd3', 'f3', 'h3'], 'Black home squares');
check(array_map(Board::name(...), Board::home(D)) === ['b8', 'd8', 'f8', 'h8', 'a7', 'c7', 'e7', 'g7', 'b6', 'd6', 'f6', 'h6'], 'Red home squares');
check(array_map(Board::name(...), Board::goal(B)) === ['a8', 'c8', 'e8', 'g8'], 'Black goal squares');
check(array_map(Board::name(...), Board::goal(D)) === ['a1', 'c1', 'e1', 'g1'], 'Red goal squares');

foreach([B, D] as $side)
{
	foreach(Board::home($side) as $square) check(Board::owner($square) === $side, 'home square color');
	foreach(Board::goal($side) as $square) check(Board::owner($square) === $side, 'goal square color');

	check(array_intersect(Board::goal($side), Board::home(Board::other($side))) === [], 'goal squares are never the opponent\'s');
}

for($square = 0; $square < Board::SQUARES; $square++)
{
	$side = Board::owner($square);

	// Diagonal steps stay on the mover's color; edge neighbors are always the other side's squares.
	foreach(Board::steps($square, $side, true) as $target) check(Board::owner($target) === $side, 'a step keeps its color');
	foreach(Board::neighbors($square) as $target) check(Board::owner($target) !== $side, 'an edge neighbor is the other color');

	check(Board::square(Board::x($square), Board::y($square)) === $square, 'square round trip');
}

check(Board::steps(sq('b1'), B, false) === [sq('a2'), sq('c2')], 'a man steps forward only');
check(Board::steps(sq('a2'), B, false) === [sq('b3')], 'the edge leaves one step');
check(Board::steps(sq('d6'), D, false) === [sq('c5'), sq('e5')], 'Red men step down the board');
check(count(Board::steps(sq('d4'), D, true)) === 4, 'a king steps four ways');
check(Board::steps(sq('a8'), B, false) === [], 'a man on the far rank has no step');
check(count(Board::neighbors(sq('a1'))) === 2 && count(Board::neighbors(sq('d4'))) === 4, 'edge neighbors');
check(Board::advance(B, sq('c3')) === 2 && Board::advance(D, sq('c3')) === 5, 'advance counts from each side\'s back rank');

// Rock-paper-scissors: each kind beats exactly one other and never itself.
check(Board::beats(R, S) && Board::beats(S, P) && Board::beats(P, R), 'rock beats scissors, scissors beats paper, paper beats rock');
check(!Board::beats(S, R) && !Board::beats(P, S) && !Board::beats(R, P), 'and never the other way');
foreach([R, P, S] as $kind) check(!Board::beats($kind, $kind), 'a kind does not beat itself');

// Placement.
$game = new Game;
check($game->phase === 'placement' && $game->moves() === [] && $game->challenges() === [], 'nothing moves before placement');
throws(static fn() => $game->place(B, [R, P, S]), 'a short placement is refused');
throws(static fn() => $game->place(B, [R, R, R, R, R, P, P, P, S, S, S, S]), 'five rocks are refused');
throws(static fn() => $game->place(B, [R, R, R, R, P, P, P, P, S, S, S, 3]), 'an unknown kind is refused');
throws(static fn() => $game->place(2, SPREAD), 'an unknown side is refused');
throws(static fn() => $game->move(sq('b3'), sq('a4')), 'no move before play');
$game->place(B, SPREAD);
check($game->placed(B) && !$game->placed(D) && $game->phase === 'placement', 'one side placed');
throws(static fn() => $game->place(B, SPREAD), 'a side places once');
$game->place(D, array_reverse(SPREAD));
check($game->phase === 'play' && $game->turn === B, 'Black moves first once both have placed');
check($game->drain() === [['type' => 'start']] && $game->drain() === [], 'events drain once');
check($game->piece(sq('b1')) === ['side' => B, 'kind' => R, 'king' => false, 'id' => 0], 'placement follows home order');
check($game->piece(sq('h3'))['kind'] === S && $game->piece(sq('b8'))['kind'] === S && $game->piece(sq('h6'))['kind'] === R, 'both placements land');
check($game->count(B) === 12 && $game->count(D) === 12, 'twelve each');
check(count($game->moves()) === 7 && $game->challenges() === [], 'seven opening moves and no contact');
throws(static fn() => $game->place(D, SPREAD), 'no placement during play');

// The view hides the opponent's kinds and nothing else.
$view = $game->view(B);
$hidden = array_filter($view['pieces'], static fn(array $piece): bool => $piece['kind'] === null);
check(count($view['pieces']) === 24 && count($hidden) === 12, 'twelve kinds are hidden from Black');
foreach($hidden as $piece) check($piece['side'] === D, 'only Red kinds are hidden from Black');
check(count($view['moves']) === 7 && $game->view(D)['moves'] === [], 'only the side to act is offered moves');
check(count(array_unique(array_column($view['pieces'], 'key'))) === 24, 'piece keys are distinct');
check($view['goals'] === [Board::goal(B), Board::goal(D)] && $view['filled'] === [0, 0] && $view['counts'] === [12, 12], 'view totals');

// Moves: forward only, onto empty squares, one per turn.
throws(static fn() => $game->move(sq('b6'), sq('a5')), 'the other side cannot move out of turn');
throws(static fn() => $game->move(sq('b1'), sq('a2')), 'an occupied square is refused');
throws(static fn() => $game->move(sq('b3'), sq('b4')), 'a straight step is refused');
throws(static fn() => $game->move(sq('b3'), sq('d5')), 'a jump is refused');
throws(static fn() => $game->move(sq('c4'), sq('d5')), 'an empty square has nothing to move');
$event = $game->move(sq('b3'), sq('c4'));
check($event['type'] === 'move' && $event['id'] === 8 && !$event['crowned'] && $game->turn === D && $game->ply === 1, 'Black moves and the turn passes');
$game->move(sq('d6'), sq('c5'));
throws(static fn() => $game->move(sq('c4'), sq('b3')), 'a man cannot step back');

// c4 and c5 now share an edge: the armies have met without blocking each other.
check($game->challenges() === [[sq('c4'), sq('c5')]], 'contact offers a challenge');
check($game->view(B)['challenges'] === [[sq('c4'), sq('c5')]] && $game->view(D)['challenges'] === [], 'only the side to act may challenge');
throws(static fn() => $game->challenge(sq('c4'), sq('d5')), 'a challenge needs an opposing piece');
throws(static fn() => $game->challenge(sq('c5'), sq('c4')), 'a challenge starts from the mover\'s own piece');
throws(static fn() => $game->challenge(sq('d3'), sq('c5')), 'a challenge needs a shared edge');

// b3 was home square 8 of Black's spread and d6 home square 9 of Red's reversed spread: both scissors.
$attacker = $game->piece(sq('c4'))['kind'];
$defender = $game->piece(sq('c5'))['kind'];
check($attacker === S && $defender === S, 'both pieces are scissors');
$event = $game->challenge(sq('c4'), sq('c5'));
check($event['outcome'] === 'tie' && $event['removed'] === null && $event['attacker'] === S && $event['defender'] === S, 'a tie removes nothing');
check($game->count(B) === 12 && $game->count(D) === 12 && $game->turn === B && $game->challenged, 'a tie leaves both and the turn goes on');
check($game->challenges() === [] && count($game->moves()) > 0, 'one challenge a turn, then a move');
throws(static fn() => $game->challenge(sq('c4'), sq('c5')), 'a second challenge is refused');
check($game->view(B)['pieces'][array_search(sq('c5'), array_column($game->view(B)['pieces'], 'square'), true)]['kind'] === null, 'the shown piece is hidden again');
$game->move(sq('c4'), sq('d5'));
check(!$game->challenged && $game->turn === D, 'the challenge resets with the turn');

// Every pairing of kinds, from both sides of the challenge.
foreach([R, P, S] as $mine) foreach([R, P, S] as $theirs)
{
	$game = position([['c4', B, $mine, false], ['c5', D, $theirs, false], ['h1', B, R, false], ['h8', D, R, false]]);
	$event = $game->challenge(sq('c4'), sq('c5'));
	$expected = $mine === $theirs ? 'tie' : (Board::beats($mine, $theirs) ? 'win' : 'loss');
	check($event['outcome'] === $expected, "challenge $mine against $theirs is a $expected");
	check($event['removed'] === ['tie' => null, 'win' => sq('c5'), 'loss' => sq('c4')][$expected], 'the loser\'s square is emptied');
	check(($game->piece(sq('c4')) === null) === ($expected === 'loss') && ($game->piece(sq('c5')) === null) === ($expected === 'win'), 'only the loser leaves');
	check($game->phase === 'play' && $game->turn === B && count($game->moves()) > 0, 'the challenger still has to move');
}

// A removal is public: both sides see which kind left the board.
$game = position([['c4', B, R, false], ['c5', D, S, false], ['h1', B, P, false], ['h8', D, P, false]]);
$before = $game->view(B)['lost'];
$game->challenge(sq('c4'), sq('c5'));
$after = $game->view(B)['lost'];
check(count($after[D]) === count($before[D]) + 1 && end($after[D]) === S && $after[B] === $before[B], 'the lost kind is listed for both sides');
check($game->view(D)['lost'] === $after, 'both sides see the same lost kinds');

// Crowning: a man that reaches the far rank becomes a king and may step back.
$game = position([['b7', B, R, false], ['h1', B, P, false], ['h8', D, P, false], ['a7', D, S, false]]);
$event = $game->move(sq('b7'), sq('c8'));
check($event['crowned'] && $game->piece(sq('c8'))['king'], 'a man is crowned on the far rank');
$game->move(sq('h8'), sq('g7'));
check(in_array([sq('c8'), sq('b7')], $game->moves(), true) && in_array([sq('c8'), sq('d7')], $game->moves(), true), 'a king steps back');
$game->move(sq('c8'), sq('d7'));
$game->move(sq('g7'), sq('f6'));
$event = $game->move(sq('d7'), sq('e8'));
check(!$event['crowned'] && $game->piece(sq('e8'))['king'], 'a king is not crowned twice');

// Home-row win: all four far-rank squares at once.
$game = position([
	['a8', B, R, true], ['c8', B, P, true], ['e8', B, S, true], ['f7', B, R, false], ['h1', B, P, false]
	, ['b8', D, R, false], ['d8', D, P, false], ['f8', D, S, false], ['h8', D, R, false]
]);
check($game->view(B)['filled'] === [3, 0], 'three of four goal squares');
$game->move(sq('h1'), sq('g2'));
check($game->phase === 'play', 'three squares do not win');
$game->move(sq('h8'), sq('g7'));
$game->move(sq('f7'), sq('g8'));
check($game->phase === 'over' && $game->winner === B && $game->reason === 'home-row', 'filling the fourth square wins at once');
check($game->moves() === [] && $game->challenges() === [], 'nothing is legal after the game');
throws(static fn() => $game->move(sq('g2'), sq('h3')), 'no move after the game');
$events = $game->drain();
check(end($events) === ['type' => 'over', 'winner' => B, 'reason' => 'home-row'], 'the result is announced');
$revealed = array_filter($game->view(B)['pieces'], static fn(array $piece): bool => $piece['kind'] === null);
check($revealed === [], 'every survivor is shown once the game is over');

// A king that leaves the far rank gives the square up again.
$game = position([
	['a8', B, R, true], ['c8', B, P, true], ['e8', B, S, true], ['h7', B, R, false], ['h1', B, P, false]
	, ['b8', D, R, false], ['d8', D, P, false], ['f8', D, S, false], ['h8', D, R, false]
]);
$game->move(sq('e8'), sq('f7'));
$game->move(sq('h8'), sq('g7'));
$game->move(sq('h7'), sq('g8'));
check($game->phase === 'play' && $game->view(B)['filled'][B] === 3, 'the row must be full at the same time');

// Elimination, in both directions.
$game = position([['c4', B, R, false], ['c5', D, S, false]]);
$game->challenge(sq('c4'), sq('c5'));
check($game->phase === 'over' && $game->winner === B && $game->reason === 'elimination', 'taking the last piece wins before the move');
$game = position([['c4', B, S, false], ['c5', D, R, false]]);
$game->challenge(sq('c4'), sq('c5'));
check($game->phase === 'over' && $game->winner === D && $game->reason === 'elimination', 'losing the last piece loses');

// A side can always move. Only its own pieces stand in its way, and a group with no free step needs a piece on every
// one of its far-rank squares, which has already won. So a running game with a full far rank is not a real position.
$full = position([['a8', B, R, true], ['c8', B, P, true], ['e8', B, S, true], ['f7', B, R, false], ['h8', D, R, false]])->export();
$full['pieces'] = array_map(static fn(array $piece): array => $piece[0] === sq('f7') ? [sq('g8'), B, R, 1, $piece[4]] : $piece, $full['pieces']);
sort($full['pieces']);
throws(static fn() => Game::import($full), 'a running game with a full far rank is refused');
$packed = position([['a1', D, R, true], ['b2', D, P, false], ['c1', D, S, true], ['d2', D, R, false], ['e1', D, R, true], ['f2', D, P, false], ['h2', D, S, false], ['b1', B, R, false]], D);
check($packed->moves() === [[sq('f2'), sq('g1')], [sq('h2'), sq('g1')]], 'the only free steps are the ones that fill the rank');
$packed->move(sq('h2'), sq('g1'));
check($packed->phase === 'over' && $packed->winner === D && $packed->reason === 'home-row', 'and taking one wins');

// Draw: eighty quiet turns. Two kings shuffle; a man's step or a removal starts the count again.
$game = position([['e4', B, R, true], ['d4', D, R, true], ['h1', B, P, false], ['b8', D, P, false]]);
$steps = [[sq('e4'), sq('f5')], [sq('d4'), sq('c5')], [sq('f5'), sq('e4')], [sq('c5'), sq('d4')]];

for($turn = 0; $turn < Game::QUIET_LIMIT - 1; $turn++) $game->move(...$steps[$turn % 4]);

check($game->phase === 'play' && $game->quiet === Game::QUIET_LIMIT - 1, 'seventy-nine quiet turns play on');
$game->move(...$steps[(Game::QUIET_LIMIT - 1) % 4]);
check($game->phase === 'over' && $game->winner === null && $game->reason === 'draw', 'the eightieth quiet turn draws');

$game = position([['e4', B, R, true], ['d4', D, R, true], ['h1', B, P, false], ['b8', D, P, false]]);
for($turn = 0; $turn < 40; $turn++) $game->move(...$steps[$turn % 4]);
check($game->quiet === 40, 'king steps count');
$game->move(sq('h1'), sq('g2'));
check($game->quiet === 0, 'a man\'s step starts the count again');
$game->move(sq('d4'), sq('c5'));
$game->move(sq('e4'), sq('d5'));
check($game->quiet === 2, 'and king steps count again');
// c5 (Red rock) touches d5 (Black rock): a tie changes nothing.
$game->challenge(sq('c5'), sq('d5'));
check($game->quiet === 2, 'a tie does not start the count again');

// Resignation.
$game = new Game;
$game->place(B, SPREAD);
$game->resign(B);
check($game->phase === 'over' && $game->winner === D && $game->reason === 'resign', 'resigning during placement');
$game->resign(D);
check($game->winner === D, 'a finished game cannot be resigned again');
check(Game::import($game->export())->export() === $game->export(), 'a resigned placement round-trips');

// Saves round-trip exactly and refuse anything this class could not have written.
$game = new Game;
$game->place(B, SPREAD);
check(Game::import($game->export())->export() === $game->export(), 'a half-placed game round-trips');
$game->place(D, SPREAD);
$game->move(sq('b3'), sq('c4'));
$game->move(sq('d6'), sq('c5'));
$game->challenge(sq('c4'), sq('c5'));
$saved = $game->export();
$json = json_encode($saved, JSON_THROW_ON_ERROR);
$copy = Game::import(json_decode($json, true, 8, JSON_THROW_ON_ERROR));
check($copy->export() === $saved && $copy->view(B) === $game->view(B) && $copy->view(D) === $game->view(D), 'a game in play round-trips through JSON');
check($copy->challenged === $game->challenged && $copy->moves() === $game->moves(), 'the restored game continues the same turn');

$broken = static function(callable $edit) use ($saved): void {
	$data = $saved;
	$edit($data);
	throws(static fn() => Game::import($data), 'a damaged save is refused');
};
$broken(static function(array &$data): void { $data['version'] = 2; });
$broken(static function(array &$data): void { $data['phase'] = 'paused'; });
$broken(static function(array &$data): void { $data['turn'] = 2; });
$broken(static function(array &$data): void { $data['quiet'] = -1; });
$broken(static function(array &$data): void { $data['winner'] = B; });
$broken(static function(array &$data): void { $data['reason'] = 'draw'; });
$broken(static function(array &$data): void { $data['placed'] = [true, false]; });
$broken(static function(array &$data): void { $data['pieces'][0][0] = 0; });       // Black piece on a black square
$broken(static function(array &$data): void { $data['pieces'][1][0] = $data['pieces'][0][0]; }); // two pieces on one square
$broken(static function(array &$data): void { $data['pieces'][0][2] = 3; });       // unknown kind
$broken(static function(array &$data): void { $data['pieces'][0][2] = ($data['pieces'][0][2] + 1) % 3; }); // five of a kind
$broken(static function(array &$data): void { $data['pieces'][1][4] = $data['pieces'][0][4]; }); // repeated id
$broken(static function(array &$data): void { array_pop($data['pieces']); });      // a piece neither on the board nor lost
$broken(static function(array &$data): void { $data['lost'][0][] = R; });
$broken(static function(array &$data): void { $data['pieces'] = 'none'; });
throws(static fn() => Game::import(null), 'a missing save is refused');
throws(static fn() => Game::import(['version' => Game::VERSION]), 'an empty save is refused');
$man = position([['a8', B, R, true], ['g1', D, R, true]])->export();
$man['pieces'][1][3] = 0;
throws(static fn() => Game::import($man), 'a man on the far rank is refused');

// Random playouts: every game ends, and the invariants hold after every action.
$lengths = [];
$results = ['elimination' => 0, 'home-row' => 0, 'draw' => 0];
$wins = [0, 0];

for($seed = 1; $seed <= 300; $seed++)
{
	$random = new Random($seed);
	$game = new Game;
	$game->place(B, $random->shuffle(SPREAD));
	$game->place(D, $random->shuffle(SPREAD));

	for($actions = 0; $game->phase === 'play'; $actions++)
	{
		check($actions < 4000, 'a random game ends');

		$challenges = $game->challenges();
		$moves = $game->moves();
		check($moves !== [], 'the side to act always has a step');

		if($challenges !== [] && $random->int(3) === 0) $game->challenge(...$challenges[$random->int(count($challenges))]);
		else $game->move(...$moves[$random->int(count($moves))]);

		$kinds = [[0, 0, 0], [0, 0, 0]];
		$view = $game->view(B);

		foreach($view['pieces'] as $piece)
		{
			check(Board::owner($piece['square']) === $piece['side'], 'a piece stays on its side\'s color');
			check($piece['king'] || Board::advance($piece['side'], $piece['square']) < 7, 'a man on the far rank is a king');

			$kinds[$piece['side']][$game->piece($piece['square'])['kind']]++;
		}

		foreach([B, D] as $side)
		{
			foreach($view['lost'][$side] as $kind) $kinds[$side][$kind]++;

			check($kinds[$side] === [4, 4, 4], 'pieces on the board and pieces lost make four of each kind');
		}
	}

	check(Game::import($game->export())->export() === $game->export(), 'a finished game round-trips');
	$lengths[] = $game->ply;
	$results[$game->reason]++;

	if($game->winner !== null) $wins[$game->winner]++;
}

sort($lengths);
printf(
	"RPSC rules: %d checks passed. 300 random games: median %d turns (%d to %d); %s; Black %d, Red %d.\n"
	, $checks, $lengths[150], $lengths[0], $lengths[299]
	, implode(', ', array_map(static fn(string $reason, int $count): string => "$reason $count", array_keys($results), $results))
	, $wins[B], $wins[D]
);

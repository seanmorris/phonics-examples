import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {Rules} from '../games/rpsc/browser/online-match.mjs';

const root = fileURLToPath(new URL('../', import.meta.url));

/**
 * PHP plays whole games at random and writes down every action, the event it produced, the position after it with
 * the kinds left out, and what was legal next. The browser's rules must reproduce all of it.
 */
const transcript = () => JSON.parse(execFileSync('php', ['-r'
	, `declare(strict_types=1);
require $argv[1] . '/games/rpsc/autoload.php';
use Rpsc\\Board; use Rpsc\\Game; use Rpsc\\Random;
$games = [];
for($seed = 1; $seed <= 30; $seed++)
{
	$random = new Random($seed * 7919);
	$game = new Game;
	$spread = [0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2];
	$game->place(Board::BLACK, $random->shuffle($spread));
	$game->place(Board::RED, $random->shuffle($spread));
	$game->drain();
	$steps = [];
	while($game->phase === 'play')
	{
		$challenges = $game->challenges();
		$moves = $game->moves();
		if($challenges !== [] && $random->int(3) === 0) { $pair = $challenges[$random->int(count($challenges))]; $event = $game->challenge(...$pair); }
		else { $pair = $moves[$random->int(count($moves))]; $event = $game->move(...$pair); }
		$state = $game->export();
		$state['pieces'] = array_map(static fn(array $piece): array => [$piece[0], $piece[1], $piece[3], $piece[4]], $state['pieces']);
		unset($state['version']);
		$steps[] = ['event' => $event, 'state' => $state, 'moves' => $game->moves(), 'challenges' => $game->challenges()
			, 'filled' => [$game->filled(Board::BLACK), $game->filled(Board::RED)], 'counts' => [$game->count(Board::BLACK), $game->count(Board::RED)]];
	}
	$games[] = $steps;
}
echo json_encode(['games' => $games, 'home' => [Board::home(0), Board::home(1)], 'goal' => [Board::goal(0), Board::goal(1)], 'limit' => Game::QUIET_LIMIT]);
`
	, root
], {encoding: 'utf8', maxBuffer: 256 * 1024 * 1024}));

/** One piece as PHP exports it without its kind: square, side, king, id. */
const row = ([at, piece]) => [Number(at), piece.side, piece.king ? 1 : 0, piece.id];

/** The browser's game in the shape PHP exports. */
const exported = game => {
	const {phase, turn, challenged, quiet, ply, winner, reason, placed, lost} = game;
	const pieces = Object.entries(game.pieces).map(row).sort((a, b) => a[0] - b[0]);

	return {phase, turn, challenged, quiet, ply, winner, reason, placed, lost, pieces};
};

test('the browser rules reproduce every action of thirty PHP games', () => {
	const {games, home, goal, limit} = transcript();
	assert.deepEqual([Rules.home(0), Rules.home(1)], home);
	assert.deepEqual([Rules.goal(0), Rules.goal(1)], goal);
	assert.equal(Rules.QUIET_LIMIT, limit);
	const seen = {move: 0, challenge: 0, crowned: 0, win: 0, loss: 0, tie: 0};
	const reasons = {};

	for(const [number, steps] of games.entries())
	{
		const game = Rules.create();
		Rules.place(game, 0);
		assert.equal(game.phase, 'placement');
		Rules.place(game, 1);
		assert.equal(game.phase, 'play');
		assert.equal(Rules.moves(game).length, 7);

		for(const [index, step] of steps.entries())
		{
			const {event} = step, where = `game ${number + 1} step ${index + 1}`;
			const mine = event.type === 'move'
				? Rules.move(game, event.from, event.to)
				: Rules.challenge(game, event.from, event.to, event.attacker, event.defender);

			assert.deepEqual(mine, event, where);
			assert.deepEqual(exported(game), step.state, where);
			assert.deepEqual(Rules.moves(game), step.moves, where);
			assert.deepEqual(Rules.challenges(game), step.challenges, where);
			assert.deepEqual([Rules.filled(game, 0), Rules.filled(game, 1)], step.filled, where);
			assert.deepEqual([Rules.count(game, 0), Rules.count(game, 1)], step.counts, where);
			seen[event.type]++;

			if(event.crowned) seen.crowned++;

			if(event.type === 'challenge') seen[event.outcome]++;
		}

		assert.equal(game.phase, 'over');
		reasons[game.reason] = (reasons[game.reason] ?? 0) + 1;
		// A finished game refuses everything, as PHP's does.
		assert.throws(() => Rules.move(game, 0, 9), /not in play/);
		assert.deepEqual(Rules.moves(game), []);
	}

	// The games together must have exercised every rule the two copies share.
	assert.ok(seen.move > 2000 && seen.challenge > 300 && seen.crowned > 20, JSON.stringify(seen));
	assert.ok(seen.win > 50 && seen.loss > 50 && seen.tie > 50, JSON.stringify(seen));
	assert.ok(reasons.elimination && reasons['home-row'] && reasons.draw, JSON.stringify(reasons));
});

test('the browser rules refuse what the PHP rules refuse', () => {
	// Nothing moves before both sides have placed, and a side places once.
	const game = Rules.create();
	assert.throws(() => Rules.move(game, 17, 24), /not in play/);
	Rules.place(game, 0);
	assert.throws(() => Rules.place(game, 0), /cannot place now/);
	Rules.place(game, 1);

	// Black to move: not Red's piece, not an occupied square, not straight ahead, not a jump.
	assert.throws(() => Rules.move(game, 41, 32), /No piece of the side to act/);
	assert.throws(() => Rules.move(game, 1, 8), /cannot step there/);
	assert.throws(() => Rules.move(game, 17, 25), /cannot step there/);
	assert.throws(() => Rules.move(game, 17, 35), /cannot step there/);
	assert.throws(() => Rules.checkChallenge(game, 17, 25), /shares an edge/);

	// c4 and c5 touch: a man cannot retreat, and a challenge needs the mover's own piece and a shared edge.
	Rules.move(game, 17, 26);
	Rules.move(game, 43, 34);
	assert.throws(() => Rules.move(game, 26, 17), /cannot step there/);
	assert.throws(() => Rules.checkChallenge(game, 34, 26), /No piece of the side to act/);
	assert.throws(() => Rules.checkChallenge(game, 19, 34), /shares an edge/);

	// One challenge a turn.
	Rules.challenge(game, 26, 34, 0, 0);
	assert.throws(() => Rules.checkChallenge(game, 26, 34), /already had its challenge/);
	assert.equal(Rules.beats(0, 2) && Rules.beats(2, 1) && Rules.beats(1, 0), true);
	assert.equal(Rules.beats(2, 0) || Rules.beats(1, 2) || Rules.beats(0, 1) || Rules.beats(1, 1), false);
});

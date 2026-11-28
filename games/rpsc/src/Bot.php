<?php
declare(strict_types=1);
namespace Rpsc;

/**
 * The computer opponent.
 *
 * The bot is only ever handed {@see Game::view()} for its own side, so it cannot see a kind the rules have not shown
 * it. What it knows about the other side comes from two places: the challenges it has watched, which it remembers
 * for a while, and the kinds that have left the board, which tell it what the hidden pieces can still be.
 *
 * It scores every challenge and every move with a few plain weights and takes the best, with some noise so that
 * games differ. The three levels change how long it remembers, whether it counts the kinds that are left, and how
 * much noise there is.
 */
final class Bot
{
	/** Level names, weakest first. */
	public const LEVELS = ['easy', 'normal', 'hard'];
	/** Per level: turns a seen kind is remembered, whether lost kinds are counted, noise added to each score. */
	private const TUNING = [
		'easy' => ['memory' => 6, 'counts' => false, 'noise' => 1.2]
		, 'normal' => ['memory' => 30, 'counts' => true, 'noise' => .3]
		, 'hard' => ['memory' => 100000, 'counts' => true, 'noise' => .05]
	];

	/** @var array<int,array{int,int}> Opposing piece key to the kind it showed and the turn it showed it. */
	private array $seen = [];
	/** @var array<int,bool> Own piece keys whose kind the opponent has been shown. */
	private array $shown = [];

	/**
	 * Create a bot for one side.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @param string $level One of Bot::LEVELS.
	 * @param Random $random Source of the bot's placement and noise.
	 * @return void The bot starts knowing nothing about the other side.
	 */
	public function __construct(private int $side, private string $level, private Random $random)
	{
		if(!isset(self::TUNING[$level])) throw new \InvalidArgumentException('Unknown bot level');
	}

	/**
	 * Choose a starting placement.
	 *
	 * @return list<int> Twelve kinds in Board::home() order, four of each, in random order.
	 */
	public function placement(): array
	{
		return $this->random->shuffle([0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2]);
	}

	/**
	 * Watch a challenge, as both players do.
	 *
	 * @param array<string,mixed> $event A challenge event from Game::challenge().
	 * @param int $turn Turn number the challenge happened on.
	 * @return void The surviving pieces' kinds are remembered; removed pieces are forgotten.
	 */
	public function observe(array $event, int $turn): void
	{
		if(($event['type'] ?? '') !== 'challenge') return;

		$mine = $event['side'] === $this->side;
		$ownKey = $this->side * Board::PIECES + ($mine ? $event['attackerId'] : $event['defenderId']);
		$theirKey = (1 - $this->side) * Board::PIECES + ($mine ? $event['defenderId'] : $event['attackerId']);
		$theirKind = $mine ? $event['defender'] : $event['attacker'];
		$lostOwn = $event['outcome'] === ($mine ? 'loss' : 'win');
		$lostTheirs = $event['outcome'] === ($mine ? 'win' : 'loss');

		if($lostOwn) unset($this->shown[$ownKey]);
		else $this->shown[$ownKey] = true;

		if($lostTheirs) unset($this->seen[$theirKey]);
		else $this->seen[$theirKey] = [$theirKind, $turn];
	}

	/**
	 * Decide whether to challenge before moving.
	 *
	 * @param array<string,mixed> $view Game::view() for the bot's side, on its turn.
	 * @return array{int,int}|null [from, to] of the challenge to make, or null to move without one.
	 */
	public function challenge(array $view): ?array
	{
		$tuning = self::TUNING[$this->level];
		$model = $this->model($view);
		$best = null;
		// A blind challenge is an even trade, so it has to promise something before the bot shows a piece for it.
		$bar = .05;

		foreach($view['challenges'] as [$from, $to])
		{
			$mine = $model['at'][$from];
			$theirs = $model['at'][$to];
			$odds = $this->odds($mine['kind'], $model['beliefs'][$theirs['key']]);
			$score = $odds['win'] * $this->worth($theirs, $view) - $odds['loss'] * $this->worth($mine, $view);
			// Showing a piece the opponent has not seen costs a little; learning one is worth a little.
			$score -= isset($this->shown[$mine['key']]) ? 0 : .15;
			$score += $model['certain'][$theirs['key']] ? 0 : .1;
			$score += ($this->random->unit() - .5) * $tuning['noise'];

			if($score > $bar) [$bar, $best] = [$score, [$from, $to]];
		}

		return $best;
	}

	/**
	 * Choose this turn's move.
	 *
	 * @param array<string,mixed> $view Game::view() for the bot's side, on its turn.
	 * @return array{int,int} [from, to] of a legal move.
	 */
	public function move(array $view): array
	{
		$tuning = self::TUNING[$this->level];
		$model = $this->model($view);
		$enemy = 1 - $this->side;
		$goals = array_flip($view['goals'][$this->side]);
		$filled = $view['filled'][$this->side];
		$pressure = $view['filled'][$enemy] + $this->intruders($model, $enemy);
		$best = null;
		$top = -INF;

		foreach($view['moves'] as [$from, $to])
		{
			$piece = $model['at'][$from];
			$score = ($this->random->unit() - .5) * $tuning['noise'];

			if(isset($goals[$to]))
			{
				// The move that fills the row wins outright; each square before it is worth more than the last.
				$score += $filled === Board::GOALS - 1 ? 1000 : 1.2 + .6 * $filled;
			}
			elseif(isset($goals[$from])) $score -= 1.5 + .6 * $filled;
			elseif(!$piece['king']) $score += .3 + .08 * Board::advance($this->side, $to);
			// A king away from the far rank heads back toward it.
			else $score += .15 * (Board::advance($this->side, $to) - Board::advance($this->side, $from));

			// Back-rank men stand beside the squares the opponent needs. The closer the opponent is, the more a
			// guard is worth where it stands.
			if(!$piece['king'] && Board::advance($this->side, $from) === 0) $score -= .25 + .2 * $pressure;

			$score += $this->danger($from, $piece, $model, $view) - $this->danger($to, $piece, $model, $view);

			if($score > $top) [$top, $best] = [$score, [$from, $to]];
		}

		if($best === null) throw new \LogicException('The bot was asked to move without a legal move');

		return $best;
	}

	/**
	 * Write what the bot remembers, for a saved game.
	 *
	 * @return array<string,mixed> Plain data that import() restores.
	 */
	public function export(): array
	{
		$seen = [];

		foreach($this->seen as $key => [$kind, $turn]) $seen[] = [$key, $kind, $turn];

		return ['side' => $this->side, 'level' => $this->level, 'seen' => $seen, 'shown' => array_keys($this->shown)];
	}

	/**
	 * Restore a bot written by export().
	 *
	 * @param mixed $data Decoded save.
	 * @param Random $random Source of the restored bot's noise.
	 * @return self The restored bot.
	 * @throws \InvalidArgumentException When the data is not a bot this class could have written.
	 */
	public static function import(mixed $data, Random $random): self
	{
		if(!is_array($data) || !in_array($data['side'] ?? null, [Board::BLACK, Board::RED], true) || !is_string($data['level'] ?? null)
			|| !is_array($data['seen'] ?? null) || !is_array($data['shown'] ?? null))
		{
			throw new \InvalidArgumentException('Saved bot is invalid');
		}

		$bot = new self($data['side'], $data['level'], $random);
		$limit = 2 * Board::PIECES;

		foreach($data['seen'] as $entry)
		{
			if(!is_array($entry) || count($entry) !== 3 || !is_int($entry[0]) || !is_int($entry[1]) || !is_int($entry[2])
				|| $entry[0] < 0 || $entry[0] >= $limit || $entry[1] < 0 || $entry[1] > 2 || $entry[2] < 0)
			{
				throw new \InvalidArgumentException('Saved bot is invalid');
			}

			$bot->seen[$entry[0]] = [$entry[1], $entry[2]];
		}

		foreach($data['shown'] as $key)
		{
			if(!is_int($key) || $key < 0 || $key >= $limit) throw new \InvalidArgumentException('Saved bot is invalid');

			$bot->shown[$key] = true;
		}

		return $bot;
	}

	/**
	 * Work out what the bot believes about every piece in a view.
	 *
	 * @param array<string,mixed> $view Game::view() for the bot's side.
	 * @return array<string,array<int,mixed>> `at`: pieces by square. `beliefs`: for each opposing piece key, the chance
	 * that it is rock, paper, or scissors. `certain`: for each opposing piece key, whether the bot has seen it.
	 */
	private function model(array $view): array
	{
		$tuning = self::TUNING[$this->level];
		$enemy = 1 - $this->side;
		$at = [];
		$left = [Board::PER_KIND, Board::PER_KIND, Board::PER_KIND];
		$alive = [];

		foreach($view['pieces'] as $piece)
		{
			$at[$piece['square']] = $piece;

			if($piece['side'] === $enemy) $alive[$piece['key']] = true;
		}

		foreach($view['lost'][$enemy] as $kind) $left[$kind]--;

		// Drop memories of pieces that are gone and, below the top level, memories that have grown old.
		foreach($this->seen as $key => [$kind, $turn])
		{
			if(!isset($alive[$key]) || $view['ply'] - $turn > $tuning['memory']) unset($this->seen[$key]);
			else $left[$kind]--;
		}

		// A forgotten piece can leave the count short of what is really on the board; never let it go negative.
		foreach($left as $kind => $count) $left[$kind] = max(0, $count);

		$total = array_sum($left);
		$spread = !$tuning['counts'] || $total === 0
			? [1 / 3, 1 / 3, 1 / 3]
			: [$left[0] / $total, $left[1] / $total, $left[2] / $total];
		$beliefs = [];
		$certain = [];

		foreach(array_keys($alive) as $key)
		{
			$certain[$key] = isset($this->seen[$key]);
			$beliefs[$key] = $spread;

			if($certain[$key])
			{
				$beliefs[$key] = [0.0, 0.0, 0.0];
				$beliefs[$key][$this->seen[$key][0]] = 1.0;
			}
		}

		return ['at' => $at, 'beliefs' => $beliefs, 'certain' => $certain];
	}

	/**
	 * Chances that a known kind wins, loses, or ties against a believed spread of kinds.
	 *
	 * @param int $kind The bot's own piece.
	 * @param array{float,float,float} $belief Chance that the opposing piece is rock, paper, or scissors.
	 * @return array{win:float,loss:float,tie:float} The three chances, which sum to one.
	 */
	private function odds(int $kind, array $belief): array
	{
		return ['win' => $belief[($kind + 2) % 3], 'loss' => $belief[($kind + 1) % 3], 'tie' => $belief[$kind]];
	}

	/**
	 * Weigh how much a piece matters where it stands.
	 *
	 * @param array<string,mixed> $piece A piece from the view.
	 * @param array<string,mixed> $view The view it came from.
	 * @return float One for a man at home, more as it nears the far rank, much more when it is about to win the row.
	 */
	private function worth(array $piece, array $view): float
	{
		$side = $piece['side'];
		$worth = 1 + .15 * Board::advance($side, $piece['square']) + ($piece['king'] ? .3 : 0);
		$goals = $view['goals'][$side];
		$filled = $view['filled'][$side];

		if(in_array($piece['square'], $goals, true)) return $worth + 1.5 + $filled;

		// With three squares held, a piece one step from the fourth is the whole game.
		if($filled === Board::GOALS - 1)
		{
			foreach(Board::steps($piece['square'], $side, $piece['king']) as $target)
			{
				if(in_array($target, $goals, true)) return $worth + 10;
			}
		}

		return $worth;
	}

	/**
	 * Weigh what standing on a square exposes a piece to, against what it threatens from there.
	 *
	 * The opponent moves next and may challenge first, so a neighbor that beats this piece counts in full when the
	 * opponent has seen it and in half when it has not. A neighbor this piece beats counts for less, because it may
	 * simply step away.
	 *
	 * @param int $square Square to weigh.
	 * @param array<string,mixed> $piece The bot's piece that would stand there.
	 * @param array<string,mixed> $model Beliefs from model().
	 * @param array<string,mixed> $view The view the model was built from.
	 * @return float Positive when the square is worse for the piece than open board.
	 */
	private function danger(int $square, array $piece, array $model, array $view): float
	{
		$danger = 0.0;
		$exposed = isset($this->shown[$piece['key']]) ? 1.0 : .5;

		foreach(Board::neighbors($square) as $next)
		{
			$other = $model['at'][$next] ?? null;

			if($other === null || $other['side'] === $this->side) continue;

			$odds = $this->odds($piece['kind'], $model['beliefs'][$other['key']]);
			$danger += $odds['loss'] * $this->worth($piece, $view) * $exposed - $odds['win'] * $this->worth($other, $view) * .6;
		}

		return $danger;
	}

	/**
	 * Count the opposing pieces that have crossed into the bot's half.
	 *
	 * @param array<string,mixed> $model Beliefs from model().
	 * @param int $enemy The opposing side.
	 * @return float One half for each such piece.
	 */
	private function intruders(array $model, int $enemy): float
	{
		$count = 0;

		foreach($model['at'] as $piece) if($piece['side'] === $enemy && Board::advance($enemy, $piece['square']) >= 4) $count++;

		return $count / 2;
	}
}

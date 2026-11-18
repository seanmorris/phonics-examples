<?php
declare(strict_types=1);
namespace Rpsc;

/**
 * One game of Rock Paper Scissors Checkers: placement, challenges, moves, and the result.
 *
 * A turn is an optional challenge followed by one move. A challenge names one of the mover's pieces and an opposing
 * piece that shares an edge with it. Both are shown, rock-paper-scissors decides, and the loser leaves the board; a
 * tie removes nothing. Both pieces are hidden again afterwards, so what was shown has to be remembered. Men step one
 * square diagonally forward, a man that reaches the far rank is crowned, and a king may also step backward.
 *
 * A side wins by removing every opposing piece, or by standing on all four of its squares on the far rank. Eighty
 * turns in a row without a removal or a man's advance draw the game. A side can always move: only its own pieces can
 * stand in its way, and a group with no free step would have to fill its far rank first, which already wins.
 *
 * The class holds the whole truth, including both sides' hidden kinds. {@see Game::view()} is the only method that
 * decides what one side may know.
 */
final class Game
{
	/** Turns in a row, counting both sides, with no removal and no man's advance before the game is drawn. */
	public const QUIET_LIMIT = 80;
	/** Save format written by export() and accepted by import(). */
	public const VERSION = 1;

	/** One of placement, play, or over. */
	public string $phase = 'placement';
	/** Side to act: Board::BLACK or Board::RED. */
	public int $turn = Board::BLACK;
	/** Whether the side to act has already used this turn's challenge. */
	public bool $challenged = false;
	/** Turns in a row without a removal or a man's advance. */
	public int $quiet = 0;
	/** Turns completed since play began. */
	public int $ply = 0;
	/** Winning side once the game is over, or null while it is running and after a draw. */
	public ?int $winner = null;
	/** Why the game ended: elimination, home-row, resign, draw, or empty while it is running. */
	public string $reason = '';

	/** @var array<int,array{side:int,kind:int,king:bool,id:int}> Pieces on the board, keyed by square. */
	private array $pieces = [];
	/** @var array{bool,bool} Whether each side has placed its twelve. */
	private array $placed = [false, false];
	/** @var array{list<int>,list<int>} Kinds each side has lost, in the order they were removed. */
	private array $lost = [[], []];
	/** @var list<array<string,mixed>> Events since the last drain(), oldest first. */
	private array $events = [];

	/**
	 * Place one side's twelve checkers face down.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @param list<int> $kinds One kind for each square of Board::home($side), in that order: four of each kind.
	 * @return void The pieces stand on the board; play begins once both sides have placed.
	 */
	public function place(int $side, array $kinds): void
	{
		if($this->phase !== 'placement') throw new \LogicException('Placement is over');
		if($side !== Board::BLACK && $side !== Board::RED) throw new \InvalidArgumentException('Unknown side');
		if($this->placed[$side]) throw new \LogicException(Board::SIDES[$side] . ' has already placed');

		self::checkPlacement($kinds);

		foreach(Board::home($side) as $index => $square)
		{
			$this->pieces[$square] = ['side' => $side, 'kind' => $kinds[$index], 'king' => false, 'id' => $index];
		}

		$this->placed[$side] = true;

		if($this->placed[Board::BLACK] && $this->placed[Board::RED])
		{
			$this->phase = 'play';
			$this->turn = Board::BLACK;
			$this->events[] = ['type' => 'start'];
		}
	}

	/**
	 * Check that a placement names four of each kind for twelve squares.
	 *
	 * @param mixed $kinds Candidate placement.
	 * @return void Nothing when the placement is legal.
	 * @throws \InvalidArgumentException When the list is not twelve kinds with four of each.
	 */
	public static function checkPlacement(mixed $kinds): void
	{
		if(!is_array($kinds) || !array_is_list($kinds) || count($kinds) !== Board::PIECES)
		{
			throw new \InvalidArgumentException('A placement names twelve kinds');
		}

		$counts = [0, 0, 0];

		foreach($kinds as $kind)
		{
			if(!is_int($kind) || $kind < 0 || $kind > 2) throw new \InvalidArgumentException('Unknown kind in placement');

			$counts[$kind]++;
		}

		if($counts !== [Board::PER_KIND, Board::PER_KIND, Board::PER_KIND])
		{
			throw new \InvalidArgumentException('A placement has four rocks, four papers, and four scissors');
		}
	}

	/**
	 * Report whether a side has placed its checkers.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return bool True once place() has accepted that side's twelve.
	 */
	public function placed(int $side): bool
	{
		return $this->placed[$side];
	}

	/**
	 * Read the piece on a square, hidden kind included.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return array{side:int,kind:int,king:bool,id:int}|null The piece, or null for an empty square.
	 */
	public function piece(int $square): ?array
	{
		return $this->pieces[$square] ?? null;
	}

	/**
	 * Count a side's pieces on the board.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return int Pieces that side still has.
	 */
	public function count(int $side): int
	{
		$count = 0;

		foreach($this->pieces as $piece) if($piece['side'] === $side) $count++;

		return $count;
	}

	/**
	 * Count how many of its four far-rank squares a side stands on.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return int Occupied goal squares, from 0 to 4.
	 */
	public function filled(int $side): int
	{
		$count = 0;

		foreach(Board::goal($side) as $square) if(($this->pieces[$square]['side'] ?? -1) === $side) $count++;

		return $count;
	}

	/**
	 * List the moves open to a side.
	 *
	 * @param int|null $side Side to list for; the side to act when null.
	 * @return list<array{int,int}> [from, to] square pairs, in board order of the moving piece.
	 */
	public function moves(?int $side = null): array
	{
		if($this->phase !== 'play') return [];

		$side ??= $this->turn;
		$moves = [];

		for($square = 0; $square < Board::SQUARES; $square++)
		{
			$piece = $this->pieces[$square] ?? null;

			if($piece === null || $piece['side'] !== $side) continue;

			foreach(Board::steps($square, $side, $piece['king']) as $target)
			{
				if(!isset($this->pieces[$target])) $moves[] = [$square, $target];
			}
		}

		return $moves;
	}

	/**
	 * List the challenges open to the side to act.
	 *
	 * @return list<array{int,int}> [from, to] pairs: the challenger's square and the opposing piece's square. Empty
	 * once this turn's challenge has been used.
	 */
	public function challenges(): array
	{
		if($this->phase !== 'play' || $this->challenged) return [];

		return $this->contacts($this->turn);
	}

	/**
	 * List every place where a side's piece shares an edge with an opposing piece.
	 *
	 * @param int $side Board::BLACK or Board::RED.
	 * @return list<array{int,int}> [own square, opposing square] pairs, in board order of the side's piece.
	 */
	public function contacts(int $side): array
	{
		$pairs = [];

		for($square = 0; $square < Board::SQUARES; $square++)
		{
			if(($this->pieces[$square]['side'] ?? -1) !== $side) continue;

			foreach(Board::neighbours($square) as $target)
			{
				if(isset($this->pieces[$target])) $pairs[] = [$square, $target];
			}
		}

		return $pairs;
	}

	/**
	 * Challenge an opposing piece before moving.
	 *
	 * @param int $from Square of the challenger, a piece of the side to act.
	 * @param int $to Square of the opposing piece; it must share an edge with the challenger.
	 * @return array<string,mixed> The challenge event: both kinds, the outcome for the challenger (win, loss, or tie),
	 * and the square that was emptied, or null after a tie.
	 */
	public function challenge(int $from, int $to): array
	{
		if($this->phase !== 'play') throw new \LogicException('The game is not in play');
		if($this->challenged) throw new \LogicException('This turn has already had its challenge');

		$attacker = $this->pieces[$from] ?? null;
		$defender = $this->pieces[$to] ?? null;

		if($attacker === null || $attacker['side'] !== $this->turn) throw new \InvalidArgumentException('No piece of the side to act stands there');
		if($defender === null || !in_array($to, Board::neighbours($from), true)) throw new \InvalidArgumentException('No opposing piece shares an edge with the challenger');

		$outcome = 'tie';
		$removed = null;

		if(Board::beats($attacker['kind'], $defender['kind'])) [$outcome, $removed] = ['win', $to];
		elseif(Board::beats($defender['kind'], $attacker['kind'])) [$outcome, $removed] = ['loss', $from];

		if($removed !== null)
		{
			$this->lost[$this->pieces[$removed]['side']][] = $this->pieces[$removed]['kind'];
			unset($this->pieces[$removed]);
			$this->quiet = 0;
		}

		$this->challenged = true;
		$event = [
			'type' => 'challenge'
			, 'side' => $this->turn
			, 'from' => $from
			, 'to' => $to
			, 'attacker' => $attacker['kind']
			, 'defender' => $defender['kind']
			, 'attackerId' => $attacker['id']
			, 'defenderId' => $defender['id']
			, 'outcome' => $outcome
			, 'removed' => $removed
		];
		$this->events[] = $event;
		$this->settle();

		return $event;
	}

	/**
	 * Move one piece and end the turn.
	 *
	 * @param int $from Square of a piece of the side to act.
	 * @param int $to Empty square one diagonal step away: forward for a man, either way for a king.
	 * @return array<string,mixed> The move event, including whether the piece was crowned.
	 */
	public function move(int $from, int $to): array
	{
		if($this->phase !== 'play') throw new \LogicException('The game is not in play');

		$piece = $this->pieces[$from] ?? null;

		if($piece === null || $piece['side'] !== $this->turn) throw new \InvalidArgumentException('No piece of the side to act stands there');
		if(isset($this->pieces[$to]) || !in_array($to, Board::steps($from, $piece['side'], $piece['king']), true))
		{
			throw new \InvalidArgumentException('That piece cannot step there');
		}

		$side = $this->turn;
		$crowned = !$piece['king'] && Board::advance($side, $to) === Board::SIZE - 1;
		$this->quiet = $piece['king'] ? $this->quiet + 1 : 0;

		if($crowned) $piece['king'] = true;

		unset($this->pieces[$from]);
		$this->pieces[$to] = $piece;
		$event = ['type' => 'move', 'side' => $side, 'from' => $from, 'to' => $to, 'id' => $piece['id'], 'crowned' => $crowned];
		$this->events[] = $event;
		$this->ply++;

		if($this->filled($side) === Board::GOALS)
		{
			$this->finish($side, 'home-row');

			return $event;
		}

		$this->turn = Board::other($side);
		$this->challenged = false;
		$this->settle();

		return $event;
	}

	/**
	 * Give up the game.
	 *
	 * @param int $side Side that resigns.
	 * @return void The other side wins, unless the game was already over.
	 */
	public function resign(int $side): void
	{
		if($this->phase === 'over') return;

		$this->finish(Board::other($side), 'resign');
	}

	/**
	 * Take the events recorded since the last call.
	 *
	 * @return list<array<string,mixed>> start, challenge, move, and over events, oldest first.
	 */
	public function drain(): array
	{
		$events = $this->events;
		$this->events = [];

		return $events;
	}

	/**
	 * Describe the game as one side may see it.
	 *
	 * Opposing kinds are null until the game is over, when every surviving piece is shown. Removed pieces are public:
	 * both sides saw them when they lost their challenge.
	 *
	 * @param int $viewer Side whose knowledge the view is limited to.
	 * @return array<string,mixed> Pieces, counts, lost kinds, goal squares, and the viewer's legal moves and challenges.
	 */
	public function view(int $viewer): array
	{
		$pieces = [];

		for($square = 0; $square < Board::SQUARES; $square++)
		{
			$piece = $this->pieces[$square] ?? null;

			if($piece === null) continue;

			$pieces[] = [
				'key' => $piece['side'] * Board::PIECES + $piece['id']
				, 'side' => $piece['side']
				, 'square' => $square
				, 'king' => $piece['king']
				, 'kind' => $piece['side'] === $viewer || $this->phase === 'over' ? $piece['kind'] : null
			];
		}

		$acting = $this->phase === 'play' && $this->turn === $viewer;

		return [
			'phase' => $this->phase
			, 'turn' => $this->turn
			, 'viewer' => $viewer
			, 'challenged' => $this->challenged
			, 'ply' => $this->ply
			, 'quiet' => $this->quiet
			, 'quietLimit' => self::QUIET_LIMIT
			, 'winner' => $this->winner
			, 'reason' => $this->reason
			, 'placed' => $this->placed
			, 'pieces' => $pieces
			, 'counts' => [$this->count(Board::BLACK), $this->count(Board::RED)]
			, 'lost' => $this->lost
			, 'goals' => [Board::goal(Board::BLACK), Board::goal(Board::RED)]
			, 'filled' => [$this->filled(Board::BLACK), $this->filled(Board::RED)]
			, 'moves' => $acting ? $this->moves() : []
			, 'challenges' => $acting ? $this->challenges() : []
		];
	}

	/**
	 * Write the whole game, hidden kinds included, for a save.
	 *
	 * @return array<string,mixed> Plain data that import() restores exactly.
	 */
	public function export(): array
	{
		$pieces = [];

		foreach($this->pieces as $square => $piece)
		{
			$pieces[] = [$square, $piece['side'], $piece['kind'], $piece['king'] ? 1 : 0, $piece['id']];
		}

		sort($pieces);

		return [
			'version' => self::VERSION
			, 'phase' => $this->phase
			, 'turn' => $this->turn
			, 'challenged' => $this->challenged
			, 'quiet' => $this->quiet
			, 'ply' => $this->ply
			, 'winner' => $this->winner
			, 'reason' => $this->reason
			, 'placed' => $this->placed
			, 'lost' => $this->lost
			, 'pieces' => $pieces
		];
	}

	/**
	 * Restore a game written by export().
	 *
	 * Saves come back from browser storage, so every field is checked: a piece must stand on a square of its own
	 * side's colour, piece ids must be distinct, the pieces on the board plus the pieces lost must add up to a legal
	 * starting twelve, and a game still in play cannot already hold a full far rank.
	 *
	 * @param mixed $data Decoded save.
	 * @return self The restored game.
	 * @throws \InvalidArgumentException When the save is not a position this class could have written.
	 */
	public static function import(mixed $data): self
	{
		/**
		 * Build the refusal for one field of the save.
		 *
		 * @param string $what Name of the field that is wrong.
		 * @return \InvalidArgumentException The exception to throw.
		 */
		$fail = static fn(string $what): \InvalidArgumentException => new \InvalidArgumentException("Saved game is invalid: $what");

		if(!is_array($data) || ($data['version'] ?? null) !== self::VERSION) throw $fail('version');

		$game = new self;
		$phase = $data['phase'] ?? null;
		$turn = $data['turn'] ?? null;
		$winner = $data['winner'] ?? null;
		$reason = $data['reason'] ?? null;
		$placed = $data['placed'] ?? null;
		$lost = $data['lost'] ?? null;
		$pieces = $data['pieces'] ?? null;

		if(!in_array($phase, ['placement', 'play', 'over'], true)) throw $fail('phase');
		if($turn !== Board::BLACK && $turn !== Board::RED) throw $fail('turn');
		if(!is_bool($data['challenged'] ?? null)) throw $fail('challenged');
		if(!is_int($data['quiet'] ?? null) || $data['quiet'] < 0 || $data['quiet'] > self::QUIET_LIMIT) throw $fail('quiet');
		if(!is_int($data['ply'] ?? null) || $data['ply'] < 0) throw $fail('ply');
		if($winner !== null && $winner !== Board::BLACK && $winner !== Board::RED) throw $fail('winner');
		if(!in_array($reason, ['', 'elimination', 'home-row', 'resign', 'draw'], true)) throw $fail('reason');
		if(($phase === 'over') !== ($reason !== '') || ($winner !== null && ($phase !== 'over' || $reason === 'draw'))) throw $fail('result');
		if($phase === 'over' && $reason !== 'draw' && $winner === null) throw $fail('result');
		if(!is_array($placed) || !array_is_list($placed) || count($placed) !== 2 || !is_bool($placed[0]) || !is_bool($placed[1])) throw $fail('placed');
		if(($phase === 'placement') === ($placed[0] && $placed[1]) && $reason !== 'resign') throw $fail('placed');
		if(!is_array($lost) || !array_is_list($lost) || count($lost) !== 2 || !is_array($pieces) || !array_is_list($pieces)) throw $fail('pieces');

		$kinds = [[0, 0, 0], [0, 0, 0]];
		$ids = [[], []];

		foreach([Board::BLACK, Board::RED] as $side)
		{
			if(!is_array($lost[$side]) || !array_is_list($lost[$side])) throw $fail('lost');

			foreach($lost[$side] as $kind)
			{
				if(!is_int($kind) || $kind < 0 || $kind > 2) throw $fail('lost');

				$kinds[$side][$kind]++;
			}
		}

		foreach($pieces as $entry)
		{
			if(!is_array($entry) || !array_is_list($entry) || count($entry) !== 5) throw $fail('piece');

			[$square, $side, $kind, $king, $id] = $entry;

			if(!is_int($square) || $square < 0 || $square >= Board::SQUARES || isset($game->pieces[$square])) throw $fail('square');
			if(($side !== Board::BLACK && $side !== Board::RED) || Board::owner($square) !== $side) throw $fail('side');
			if(!is_int($kind) || $kind < 0 || $kind > 2 || ($king !== 0 && $king !== 1)) throw $fail('kind');
			if(!is_int($id) || $id < 0 || $id >= Board::PIECES || isset($ids[$side][$id])) throw $fail('id');
			// A man never stands on the far rank: it is crowned the moment it arrives.
			if($king === 0 && Board::advance($side, $square) === Board::SIZE - 1) throw $fail('crown');

			$ids[$side][$id] = true;
			$kinds[$side][$kind]++;
			$game->pieces[$square] = ['side' => $side, 'kind' => $kind, 'king' => $king === 1, 'id' => $id];
		}

		foreach([Board::BLACK, Board::RED] as $side)
		{
			$expected = $placed[$side] ? [Board::PER_KIND, Board::PER_KIND, Board::PER_KIND] : [0, 0, 0];

			if($kinds[$side] !== $expected) throw $fail('kinds');
		}

		ksort($game->pieces);

		// Filling the far rank ends the game on that move, so a running game never shows it.
		if($phase === 'play' && ($game->filled(Board::BLACK) === Board::GOALS || $game->filled(Board::RED) === Board::GOALS)) throw $fail('goal');

		$game->phase = $phase;
		$game->turn = $turn;
		$game->challenged = $data['challenged'];
		$game->quiet = $data['quiet'];
		$game->ply = $data['ply'];
		$game->winner = $winner;
		$game->reason = $reason;
		$game->placed = $placed;
		$game->lost = $lost;

		return $game;
	}

	/**
	 * End the game where the position already decides it.
	 *
	 * Called after every challenge and at the start of every turn: a side with no pieces has lost, and a long run of
	 * quiet turns is a draw.
	 *
	 * @return void The phase becomes over when a result applies.
	 */
	private function settle(): void
	{
		if($this->phase !== 'play') return;

		$side = $this->turn;
		$enemy = Board::other($side);

		if($this->count($enemy) === 0) $this->finish($side, 'elimination');
		elseif($this->count($side) === 0) $this->finish($enemy, 'elimination');
		elseif($this->quiet >= self::QUIET_LIMIT) $this->finish(null, 'draw');
	}

	/**
	 * Record the result.
	 *
	 * @param int|null $winner Winning side, or null for a draw.
	 * @param string $reason elimination, home-row, resign, or draw.
	 * @return void The game is over and an over event is queued.
	 */
	private function finish(?int $winner, string $reason): void
	{
		$this->phase = 'over';
		$this->winner = $winner;
		$this->reason = $reason;
		$this->events[] = ['type' => 'over', 'winner' => $winner, 'reason' => $reason];
	}
}

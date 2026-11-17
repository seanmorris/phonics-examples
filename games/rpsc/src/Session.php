<?php
declare(strict_types=1);
namespace Rpsc;

/**
 * One game against the computer: the rules, the bot, the player's placement, and the save.
 *
 * The session turns the player's intents into rule calls, lets the bot act on its turns, and hands every event to
 * the table in the order it happened. It never draws anything.
 */
final class Session
{
	/** Save format written by save() and accepted by restore(). */
	public const VERSION = 1;
	/** Seconds the bot waits before each of its actions, so the player can follow them. */
	public const THINK = .55;

	/** The rules and the true position. */
	public Game $game;
	/** Side the player controls. */
	public int $human;
	/** Bot level: one of Bot::LEVELS. */
	public string $level;
	/** @var list<int> The player's twelve kinds in Board::home() order, editable until the player is ready. */
	public array $arrangement;

	private Bot $bot;
	private Random $random;
	private float $wait = self::THINK;
	/** @var list<array<string,mixed>> Events since the last drain(), oldest first. */
	private array $events = [];
	private bool $dirty = true;

	/**
	 * Start a game.
	 *
	 * @param int $seed Seed for the bot's placement and choices and for the player's first arrangement.
	 * @param string $level One of Bot::LEVELS.
	 * @param int $human Side the player controls: Board::BLACK moves first.
	 * @return void The bot has placed; the player's arrangement is shuffled and waiting for ready.
	 */
	public function __construct(int $seed, string $level = 'normal', int $human = Board::BLACK)
	{
		if($human !== Board::BLACK && $human !== Board::RED) throw new \InvalidArgumentException('Unknown side');

		$this->random = new Random($seed);
		$this->human = $human;
		$this->level = $level;
		$this->game = new Game;
		$this->bot = new Bot(Board::other($human), $level, $this->random);
		$this->game->place(Board::other($human), $this->bot->placement());
		$this->arrangement = $this->random->shuffle([0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2]);
	}

	/**
	 * Apply one of the player's intents.
	 *
	 * Intents that are not legal right now are ignored: the table only offers legal ones, so a refusal here means
	 * the intent was stale, not that the player needs an error.
	 *
	 * @param array<string,mixed> $intent `type` of swap, shuffle, ready, challenge, move, or resign, with `a` and `b`
	 * home indexes for swap and `from` and `to` squares for challenge and move.
	 * @return bool Whether the intent changed anything.
	 */
	public function command(array $intent): bool
	{
		$type = $intent['type'] ?? '';
		$placing = $this->game->phase === 'placement';
		$acting = $this->game->phase === 'play' && $this->game->turn === $this->human;

		try
		{
			if($type === 'swap' && $placing)
			{
				$a = $intent['a'] ?? null;
				$b = $intent['b'] ?? null;

				if(!is_int($a) || !is_int($b) || !isset($this->arrangement[$a], $this->arrangement[$b]) || $a === $b) return false;

				[$this->arrangement[$a], $this->arrangement[$b]] = [$this->arrangement[$b], $this->arrangement[$a]];
			}
			elseif($type === 'shuffle' && $placing) $this->arrangement = $this->random->shuffle($this->arrangement);
			elseif($type === 'ready' && $placing) $this->game->place($this->human, $this->arrangement);
			elseif($type === 'challenge' && $acting) $this->game->challenge((int)($intent['from'] ?? -1), (int)($intent['to'] ?? -1));
			elseif($type === 'move' && $acting) $this->game->move((int)($intent['from'] ?? -1), (int)($intent['to'] ?? -1));
			elseif($type === 'resign' && $this->game->phase !== 'over') $this->game->resign($this->human);
			else return false;
		}
		catch(\InvalidArgumentException|\LogicException)
		{
			return false;
		}

		$this->collect();
		$this->wait = self::THINK;

		return true;
	}

	/**
	 * Let time pass, and let the bot act when it is its turn.
	 *
	 * @param float $seconds Time since the last call.
	 * @param bool $busy Whether the table is still showing earlier events; the bot waits for it.
	 * @return void At most one bot action happens per call.
	 */
	public function tick(float $seconds, bool $busy = false): void
	{
		if($busy || $this->game->phase !== 'play' || $this->game->turn === $this->human) return;

		$this->wait -= max(0.0, $seconds);

		if($this->wait > 0) return;

		$this->wait = self::THINK;
		$view = $this->game->view(Board::other($this->human));
		$challenge = $this->bot->challenge($view);

		if($challenge !== null) $this->game->challenge(...$challenge);
		else $this->game->move(...$this->bot->move($view));

		$this->collect();
	}

	/**
	 * Take the events recorded since the last call.
	 *
	 * @return list<array<string,mixed>> Game events, oldest first.
	 */
	public function drain(): array
	{
		$events = $this->events;
		$this->events = [];

		return $events;
	}

	/**
	 * Report whether anything changed since the last call.
	 *
	 * @return bool True once after each change; the caller saves and redraws.
	 */
	public function consumeDirty(): bool
	{
		$dirty = $this->dirty;
		$this->dirty = false;

		return $dirty;
	}

	/**
	 * Describe the game as the player may see it.
	 *
	 * @return array<string,mixed> Game::view() for the player's side, plus the level and, during placement, the
	 * arrangement and the home squares it fills.
	 */
	public function view(): array
	{
		$view = $this->game->view($this->human);
		$view['level'] = $this->level;
		$view['human'] = $this->human;
		$view['home'] = Board::home($this->human);
		$view['arrangement'] = $this->game->phase === 'placement' && !$this->game->placed($this->human) ? $this->arrangement : null;

		// The player's twelve are in hand until Ready, and they count.
		if($view['arrangement'] !== null) $view['counts'][$this->human] = Board::PIECES;

		return $view;
	}

	/**
	 * Write the session for browser storage.
	 *
	 * @return string JSON that restore() accepts.
	 */
	public function save(): string
	{
		return json_encode([
			'version' => self::VERSION
			, 'human' => $this->human
			, 'level' => $this->level
			, 'random' => $this->random->state()
			, 'arrangement' => $this->arrangement
			, 'game' => $this->game->export()
			, 'bot' => $this->bot->export()
		], JSON_THROW_ON_ERROR);
	}

	/**
	 * Restore a session written by save().
	 *
	 * @param string $json Saved session, or anything else found in storage.
	 * @return self|null The session, or null when the text is not a save this class could have written.
	 */
	public static function restore(string $json): ?self
	{
		try
		{
			$data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);

			if(!is_array($data) || ($data['version'] ?? null) !== self::VERSION) return null;
			if(!in_array($data['human'] ?? null, [Board::BLACK, Board::RED], true) || !in_array($data['level'] ?? null, Bot::LEVELS, true)) return null;
			if(!is_int($data['random'] ?? null) || $data['random'] < 1 || $data['random'] > 2147483646) return null;

			Game::checkPlacement($data['arrangement'] ?? null);
			$session = new self(1, $data['level'], $data['human']);
			// The constructor seeded a throwaway game; replace every part of it with the saved one.
			$session->random = new Random($data['random'] - 1);
			$session->arrangement = $data['arrangement'];
			$session->game = Game::import($data['game'] ?? null);
			$session->bot = Bot::import($data['bot'] ?? null, $session->random);

			if(($data['bot']['side'] ?? null) !== Board::other($data['human']) || ($data['bot']['level'] ?? null) !== $data['level']) return null;
			if(!$session->game->placed(Board::other($data['human']))) return null;

			$session->events = [];

			return $session;
		}
		catch(\JsonException|\InvalidArgumentException|\LogicException)
		{
			return null;
		}
	}

	/**
	 * Move the game's new events into the session's queue and show them to the bot.
	 *
	 * @return void The session is marked changed.
	 */
	private function collect(): void
	{
		foreach($this->game->drain() as $event)
		{
			$this->bot->observe($event, $this->game->ply);
			$this->events[] = $event;
		}

		$this->dirty = true;
	}
}

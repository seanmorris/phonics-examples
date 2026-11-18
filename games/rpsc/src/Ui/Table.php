<?php
declare(strict_types=1);
namespace Rpsc\Ui;

use Rpsc\Board;

/**
 * What the player sees and touches: the pieces as drawn, the animation of each event, and the selection.
 *
 * The rules move on the instant an action is taken, but the table shows it afterwards, one event at a time: a piece
 * slides, two pieces trade places during placement, or two pieces turn face up, wait, and settle. Until an event has played, the table keeps drawing the
 * position from before it. Nothing here touches the graphics device, so the whole class runs in a plain PHP test.
 */
final class Table
{
	/** Seconds a piece takes to slide one square. */
	public const SLIDE = .18;
	/** Seconds two checkers take to trade places while the player arranges them. */
	public const SWAP = .3;
	/** Seconds a checker takes to turn over. */
	public const FLIP = .24;
	/** Seconds both challenged pieces stay face up. */
	public const HOLD = 1.1;
	/** Seconds the loser takes to leave and the survivors take to turn back. */
	public const SETTLE = .32;

	/**
	 * @var array<int,array{key:int,side:int,square:int,king:bool,kind:?int,x:float,y:float,face:float,scale:float,alpha:float,reveal?:int}>
	 * Pieces as drawn, by piece key. `x` and `y` are in squares from a1; `face` runs from 0, face down, to 1, face up;
	 * `reveal` is the kind a hidden piece is turning over to show.
	 */
	public array $pieces = [];
	/** Square of the selected piece, or null. */
	public ?int $selected = null;
	/** Square under the keyboard or controller cursor, or -1 while the pointer is in use. */
	public int $cursor = -1;
	/** Line describing the event being shown, or empty. */
	public string $banner = '';
	/** Squares of the last move or challenge, for a highlight: [from, to], or empty. */
	public array $trail = [];
	/** Sounds the animation has reached, in order; the caller drains them. */
	public array $cues = [];

	/** @var array<string,mixed> Latest view from the session. */
	private array $view = [];
	/** @var array<string,mixed> View the drawn pieces were last rebuilt from. */
	private array $settled = [];
	/** @var list<array<string,mixed>> Events waiting to be shown. */
	private array $queue = [];
	/** @var array<string,mixed>|null Event being shown, with its clock. */
	private ?array $active = null;
	private bool $stale = true;

	/**
	 * Take the latest view of the game.
	 *
	 * While the player is arranging their pieces, a view whose arrangement differs from the last one is shown as
	 * the pieces sliding to their new squares: two for a swap, more for a shuffle. A different game does not slide;
	 * call reset() between games.
	 *
	 * @param array<string,mixed> $view Session::view().
	 * @return void The drawn pieces follow it at once when nothing is waiting to be shown.
	 */
	public function show(array $view): void
	{
		$before = $this->view['arrangement'] ?? null;
		$after = $view['arrangement'] ?? null;
		$same = ($this->view['home'] ?? null) === ($view['home'] ?? []);
		$this->view = $view;
		$this->stale = true;

		if($this->active !== null || $this->queue !== []) return;

		$moves = $same && $before !== null && $after !== null ? self::rearranged($before, $after) : [];

		if($moves === [])
		{
			$this->rebuild();

			return;
		}

		// Start at once, so the pieces already carry their new kinds and stand where those kinds came from.
		$this->queue[] = ['type' => 'arrange', 'moves' => $moves];
		$this->advance(0.0);
	}

	/**
	 * Queue events to be shown in order.
	 *
	 * @param list<array<string,mixed>> $events Events from Session::drain().
	 * @return void Shown one after another by advance().
	 */
	public function push(array $events): void
	{
		foreach($events as $event)
		{
			if(in_array($event['type'] ?? '', ['move', 'challenge', 'over'], true)) $this->queue[] = $event;
		}
	}

	/**
	 * Forget everything, as when the table changes from one game to another.
	 *
	 * @return void No pieces, no selection, nothing queued; the next show() draws afresh.
	 */
	public function reset(): void
	{
		$this->pieces = $this->trail = $this->cues = $this->view = $this->settled = $this->queue = [];
		$this->selected = $this->active = null;
		$this->cursor = -1;
		$this->banner = '';
		$this->stale = true;
	}

	/**
	 * Report whether an event is still being shown.
	 *
	 * @return bool True while the player should watch rather than act.
	 */
	public function busy(): bool
	{
		return $this->active !== null || $this->queue !== [];
	}

	/**
	 * Move the animation on.
	 *
	 * @param float $seconds Time since the last call.
	 * @return void Pieces move, turn, and leave; the banner follows the event being shown.
	 */
	public function advance(float $seconds): void
	{
		$seconds = max(0.0, min(.25, $seconds));

		while($seconds > 0 || ($this->active === null && $this->queue !== []))
		{
			if($this->active === null)
			{
				if($this->queue === []) break;

				$this->begin(array_shift($this->queue));
			}

			$left = $this->active['length'] - $this->active['clock'];
			$step = min($seconds, $left);
			$this->active['clock'] += $step;
			$seconds -= $step;
			$this->pose();

			if($this->active['clock'] < $this->active['length']) break;

			$this->finish();
		}

		if($this->active === null && $this->queue === [] && $this->stale) $this->rebuild();
	}

	/**
	 * List the squares the selected piece may step to.
	 *
	 * @return list<int> Empty when nothing is selected or the player is not to move.
	 */
	public function targets(): array
	{
		return $this->from($this->view['moves'] ?? []);
	}

	/**
	 * List the opposing squares the selected piece may challenge.
	 *
	 * @return list<int> Empty when nothing is selected or this turn's challenge is used.
	 */
	public function victims(): array
	{
		return $this->from($this->view['challenges'] ?? []);
	}

	/**
	 * Report whether pressing a square would do anything right now.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return bool True for a piece the player can pick up, a square it can go to, or a piece it can challenge.
	 */
	public function live(int $square): bool
	{
		if($this->busy()) return false;

		if(($this->view['arrangement'] ?? null) !== null) return !($this->view['locked'] ?? false) && in_array($square, $this->view['home'], true);

		if($this->selected !== null && (in_array($square, $this->targets(), true) || in_array($square, $this->victims(), true))) return true;

		return $this->movable($square);
	}

	/**
	 * Press a square, as a tap, a click, or the confirm button on the cursor.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return array<string,mixed>|null An intent for Session::command(), or null when the press only changed the selection.
	 */
	public function press(int $square): ?array
	{
		if($this->busy() || $square < 0 || $square >= Board::SQUARES) return null;

		if(($this->view['arrangement'] ?? null) !== null)
		{
			$index = array_search($square, $this->view['home'], true);

			// Online, a placement is locked from Ready until it has been published.
			if($index === false || $this->selected === $square || ($this->view['locked'] ?? false)) { $this->selected = null; return null; }
			if($this->selected === null) { $this->selected = $square; return null; }

			$other = array_search($this->selected, $this->view['home'], true);
			$this->selected = null;

			return ['type' => 'swap', 'a' => $other, 'b' => $index];
		}

		if($this->selected !== null)
		{
			$from = $this->selected;

			if(in_array($square, $this->targets(), true))
			{
				$this->selected = null;

				return ['type' => 'move', 'from' => $from, 'to' => $square];
			}

			if(in_array($square, $this->victims(), true))
			{
				$this->selected = null;

				return ['type' => 'challenge', 'from' => $from, 'to' => $square];
			}
		}

		$this->selected = $square !== $this->selected && $this->movable($square) ? $square : null;

		return null;
	}

	/**
	 * Drop the selection.
	 *
	 * @return bool Whether anything was selected.
	 */
	public function cancel(): bool
	{
		$had = $this->selected !== null;
		$this->selected = null;

		return $had;
	}

	/**
	 * Move the keyboard or controller cursor one square as the player sees the board.
	 *
	 * @param int $dx 1 for right on screen, -1 for left.
	 * @param int $dy 1 for up on screen, -1 for down.
	 * @return void The cursor appears on the player's side of the board on its first use.
	 */
	public function steer(int $dx, int $dy): void
	{
		$flip = ($this->view['human'] ?? Board::BLACK) === Board::RED ? -1 : 1;

		if($this->cursor < 0)
		{
			$this->cursor = $this->selected ?? ($this->view['home'][9] ?? 0);

			return;
		}

		$x = max(0, min(Board::SIZE - 1, Board::x($this->cursor) + $dx * $flip));
		$y = max(0, min(Board::SIZE - 1, Board::y($this->cursor) + $dy * $flip));
		$this->cursor = Board::square($x, $y);
	}

	/**
	 * Describe a square for a screen reader.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return string Its name, what stands on it as far as the player may know, and what pressing it would do.
	 */
	public function label(int $square): string
	{
		$name = Board::name($square);
		$human = $this->view['human'] ?? Board::BLACK;
		$piece = null;

		foreach($this->pieces as $candidate) if($candidate['square'] === $square && $candidate['alpha'] > 0) $piece = $candidate;

		if($piece === null) $text = "$name empty";
		elseif($piece['side'] === $human) $text = "$name your " . strtolower(Board::KINDS[$piece['kind']]) . ($piece['king'] ? ' king' : '');
		else
		{
			$text = "$name " . Board::SIDES[$piece['side']] . ($piece['king'] ? ' king' : ' piece')
				. ($piece['kind'] === null ? ' face down' : ' ' . strtolower(Board::KINDS[$piece['kind']]));
		}

		if($this->selected === $square) return "$text selected";
		if($this->selected !== null && in_array($square, $this->targets(), true)) return "$text move here";
		if($this->selected !== null && in_array($square, $this->victims(), true)) return "$text challenge";

		return $text;
	}

	/**
	 * Say what the player should do, or what is happening.
	 *
	 * @return string One short line for the status row.
	 */
	public function status(): string
	{
		if($this->banner !== '') return $this->banner;

		$view = $this->view;
		$human = $view['human'] ?? Board::BLACK;
		$other = Board::SIDES[Board::other($human)];
		// An online table says what it is waiting for: the other player, the room, or a proof.
		$notice = (string)($view['notice'] ?? '');

		if(($view['phase'] ?? '') === 'placement')
		{
			if($notice !== '') return $notice;

			return $this->selected === null ? 'Tap two of your pieces to swap them' : 'Tap another piece to swap';
		}

		if(($view['phase'] ?? '') === 'over')
		{
			if($notice !== '') return $notice;
			if($view['reason'] === 'draw') return 'Draw: 80 quiet turns';

			$won = $view['winner'] === $human;

			return match($view['reason'])
			{
				'home-row' => $won ? 'You win: home row filled' : "$other wins: home row filled",
				'elimination' => $won ? 'You win: every piece taken' : "$other wins: every piece taken",
				default => $won ? "You win: $other resigned" : 'You resigned'
			};
		}

		if($this->busy()) return '';
		if($notice !== '') return $notice;
		if(($view['turn'] ?? -1) !== $human) return "$other is thinking";
		if($this->selected !== null) return $this->victims() === [] ? 'Choose where to move' : 'Move or challenge a neighbour';
		if($view['challenged']) return 'Now move a piece';

		return $view['challenges'] === [] ? 'Your move' : 'Challenge or move';
	}

	/**
	 * Read the latest view.
	 *
	 * @return array<string,mixed> Session::view(), or an empty array before the first one.
	 */
	public function view(): array
	{
		return $this->view;
	}

	/**
	 * Read the totals that match what is drawn.
	 *
	 * The rules are ahead of the table while an event plays, so the counts, the lost pieces, and the far-rank
	 * progress wait for the piece to actually leave or arrive.
	 *
	 * @return array<string,mixed> The view the drawn pieces were last rebuilt from.
	 */
	public function totals(): array
	{
		return $this->settled === [] ? $this->view : $this->settled;
	}

	/**
	 * Start showing one event.
	 *
	 * @param array<string,mixed> $event A move, challenge, or over event.
	 * @return void The event becomes the active animation.
	 */
	private function begin(array $event): void
	{
		$this->selected = null;
		$length = match($event['type'])
		{
			'move' => self::SLIDE,
			'arrange' => self::SWAP,
			'challenge' => self::FLIP + self::HOLD + self::SETTLE,
			default => self::FLIP
		};
		$this->active = ['event' => $event, 'clock' => 0.0, 'length' => $length, 'stage' => 0];

		if($event['type'] === 'arrange')
		{
			// The pieces are redrawn in their new arrangement first; pose() then starts each from its old square.
			$this->rebuild();
			$this->cues[] = 'move';

			return;
		}

		if($event['type'] === 'move')
		{
			$this->trail = [$event['from'], $event['to']];
			$this->cues[] = 'move';
		}
		elseif($event['type'] === 'challenge')
		{
			$this->trail = [$event['from'], $event['to']];
			$attacker = Board::KINDS[$event['attacker']];
			$defender = Board::KINDS[$event['defender']];
			$this->banner = match($event['outcome'])
			{
				'win' => "$attacker beats " . strtolower($defender),
				'loss' => "$defender beats " . strtolower($attacker),
				default => "$attacker meets " . strtolower($defender) . ': both stay'
			};
			$this->cues[] = 'flip';

			foreach([[$event['side'], $event['attackerId'], $event['attacker']], [1 - $event['side'], $event['defenderId'], $event['defender']]] as [$side, $id, $kind])
			{
				$key = $side * Board::PIECES + $id;

				// A hidden piece keeps its back until it is halfway through its turn.
				if(isset($this->pieces[$key]) && $this->pieces[$key]['kind'] === null) $this->pieces[$key]['reveal'] = $kind;
			}
		}
		else
		{
			// The game is over: every survivor is turned face up.
			foreach($this->view['pieces'] ?? [] as $piece)
			{
				if(isset($this->pieces[$piece['key']]) && $this->pieces[$piece['key']]['kind'] === null) $this->pieces[$piece['key']]['reveal'] = $piece['kind'];
			}

			$this->cues[] = ($this->view['reason'] ?? '') === 'draw' ? 'tie' : (($this->view['winner'] ?? null) === ($this->view['human'] ?? -1) ? 'win' : 'lose');
		}
	}

	/**
	 * Set every piece the active event touches to its pose at the current time.
	 *
	 * @return void Positions, faces, sizes, and opacity follow the event's clock.
	 */
	private function pose(): void
	{
		$event = $this->active['event'];
		$clock = $this->active['clock'];

		if($event['type'] === 'arrange')
		{
			$home = $this->view['home'];
			$human = $this->view['human'];
			$t = self::ease($clock / self::SWAP);
			// Every moving piece is lifted for the trip, so two that trade places pass over the board, not through it.
			$lift = 1 + .2 * sin(M_PI * min(1.0, $clock / self::SWAP));

			foreach($event['moves'] as [$from, $to])
			{
				$key = $human * Board::PIECES + $to;
				$this->pieces[$key]['x'] = Board::x($home[$from]) + (Board::x($home[$to]) - Board::x($home[$from])) * $t;
				$this->pieces[$key]['y'] = Board::y($home[$from]) + (Board::y($home[$to]) - Board::y($home[$from])) * $t;
				$this->pieces[$key]['scale'] = $lift;
			}

			return;
		}

		if($event['type'] === 'move')
		{
			$key = $event['side'] * Board::PIECES + $event['id'];

			if(!isset($this->pieces[$key])) return;

			$t = self::ease($clock / self::SLIDE);
			$this->pieces[$key]['x'] = Board::x($event['from']) + (Board::x($event['to']) - Board::x($event['from'])) * $t;
			$this->pieces[$key]['y'] = Board::y($event['from']) + (Board::y($event['to']) - Board::y($event['from'])) * $t;
			$this->pieces[$key]['scale'] = 1 + .12 * sin(M_PI * min(1.0, $clock / self::SLIDE));

			return;
		}

		if($event['type'] === 'over')
		{
			foreach($this->pieces as $key => $piece) if(isset($piece['reveal'])) $this->turn($key, $clock / self::FLIP);

			return;
		}

		$keys = [$event['side'] * Board::PIECES + $event['attackerId'], (1 - $event['side']) * Board::PIECES + $event['defenderId']];
		$loser = $event['removed'] === null ? null : ($event['removed'] === $event['from'] ? $keys[0] : $keys[1]);
		$up = min(1.0, $clock / self::FLIP);
		$down = max(0.0, min(1.0, ($clock - self::FLIP - self::HOLD) / self::SETTLE));

		if($down > 0 && $this->active['stage'] === 0)
		{
			$this->active['stage'] = 1;
			$this->cues[] = $event['removed'] === null ? 'tie' : 'take';
		}

		foreach($keys as $key)
		{
			if(!isset($this->pieces[$key])) continue;

			$hidden = isset($this->pieces[$key]['reveal']);
			$this->pieces[$key]['scale'] = 1 + .3 * self::ease($up) * (1 - $down);

			if($key === $loser)
			{
				$this->pieces[$key]['alpha'] = 1 - $down;
				$this->pieces[$key]['scale'] *= 1 - .5 * $down;

				if($hidden) $this->turn($key, $up);
			}
			elseif($hidden) $this->turn($key, $down > 0 ? 1 - $down : $up);
		}
	}

	/**
	 * Turn a hidden piece part of the way over.
	 *
	 * @param int $key Piece key.
	 * @param float $amount 0 for face down through 1 for face up.
	 * @return void The piece shows its kind from the halfway point.
	 */
	private function turn(int $key, float $amount): void
	{
		$amount = max(0.0, min(1.0, $amount));
		$this->pieces[$key]['face'] = $amount;
		$this->pieces[$key]['kind'] = $amount >= .5 ? $this->pieces[$key]['reveal'] : null;
	}

	/**
	 * Leave the active event's result on the table.
	 *
	 * @return void The moved piece stands on its square, the loser is gone, and survivors are hidden again.
	 */
	private function finish(): void
	{
		$event = $this->active['event'];
		$this->active = null;
		$this->banner = '';

		if($event['type'] === 'arrange')
		{
			// Every piece is set down exactly on its square.
			$this->rebuild();

			return;
		}

		if($event['type'] === 'move')
		{
			$key = $event['side'] * Board::PIECES + $event['id'];

			if(isset($this->pieces[$key]))
			{
				$this->pieces[$key] = ['square' => $event['to'], 'x' => (float)Board::x($event['to']), 'y' => (float)Board::y($event['to']), 'scale' => 1.0]
					+ $this->pieces[$key];

				if($event['crowned'])
				{
					$this->pieces[$key]['king'] = true;
					$this->cues[] = 'crown';
				}
			}

			return;
		}

		if($event['type'] === 'challenge')
		{
			$keys = [$event['side'] * Board::PIECES + $event['attackerId'], (1 - $event['side']) * Board::PIECES + $event['defenderId']];

			foreach($keys as $index => $key)
			{
				if($event['removed'] === ($index === 0 ? $event['from'] : $event['to'])) unset($this->pieces[$key]);
				elseif(isset($this->pieces[$key]))
				{
					if(isset($this->pieces[$key]['reveal'])) $this->pieces[$key] = ['kind' => null, 'face' => 0.0] + $this->pieces[$key];

					unset($this->pieces[$key]['reveal']);
					$this->pieces[$key]['scale'] = 1.0;
				}
			}
		}
	}

	/**
	 * Redraw the pieces from the latest view.
	 *
	 * @return void Every piece stands still on its square; during placement the player's own arrangement is shown.
	 */
	private function rebuild(): void
	{
		$this->stale = false;
		$this->settled = $this->view;
		$this->pieces = [];
		$human = $this->view['human'] ?? Board::BLACK;

		foreach($this->view['pieces'] ?? [] as $piece)
		{
			$this->pieces[$piece['key']] = $piece + [
				'x' => (float)Board::x($piece['square'])
				, 'y' => (float)Board::y($piece['square'])
				, 'face' => $piece['kind'] === null ? 0.0 : 1.0
				, 'scale' => 1.0
				, 'alpha' => 1.0
			];
		}

		foreach($this->view['arrangement'] ?? [] as $index => $kind)
		{
			$square = $this->view['home'][$index];
			$this->pieces[$human * Board::PIECES + $index] = [
				'key' => $human * Board::PIECES + $index, 'side' => $human, 'square' => $square, 'king' => false, 'kind' => $kind
				, 'x' => (float)Board::x($square), 'y' => (float)Board::y($square), 'face' => 1.0, 'scale' => 1.0, 'alpha' => 1.0
			];
		}

		if($this->selected !== null && !$this->movable($this->selected) && ($this->view['arrangement'] ?? null) === null) $this->selected = null;
		if(($this->view['phase'] ?? '') !== 'play') $this->trail = [];
	}

	/**
	 * Work out which home squares trade pieces between two arrangements.
	 *
	 * For each kind, the squares that lose it are paired in order with the squares that gain it. A swap gives two
	 * pairs; a shuffle gives one for every square whose kind changed.
	 *
	 * @param list<int> $before Kinds by home index before the change.
	 * @param list<int> $after Kinds by home index after it.
	 * @return list<array{int,int}> [from, to] home indexes, or an empty list when nothing moved.
	 */
	private static function rearranged(array $before, array $after): array
	{
		$moves = [];

		if(count($before) !== count($after)) return [];

		foreach([Board::ROCK, Board::PAPER, Board::SCISSORS] as $kind)
		{
			$leaving = [];
			$arriving = [];

			foreach($before as $index => $old)
			{
				if($old === $kind && $after[$index] !== $kind) $leaving[] = $index;
				if($old !== $kind && $after[$index] === $kind) $arriving[] = $index;
			}

			if(count($leaving) !== count($arriving)) return [];

			foreach($arriving as $n => $to) $moves[] = [$leaving[$n], $to];
		}

		return $moves;
	}

	/**
	 * Report whether a square holds one of the player's pieces that can act this turn.
	 *
	 * @param int $square Square number from 0 to 63.
	 * @return bool True when the piece has a step or a challenge open to it.
	 */
	private function movable(int $square): bool
	{
		foreach($this->view['moves'] ?? [] as [$from]) if($from === $square) return true;
		foreach($this->view['challenges'] ?? [] as [$from]) if($from === $square) return true;

		return false;
	}

	/**
	 * Pick the second square of every pair that starts on the selected square.
	 *
	 * @param list<array{int,int}> $pairs [from, to] pairs from the view.
	 * @return list<int> Destination squares for the selection.
	 */
	private function from(array $pairs): array
	{
		$squares = [];

		if($this->selected !== null && !$this->busy()) foreach($pairs as [$from, $to]) if($from === $this->selected) $squares[] = $to;

		return $squares;
	}

	/**
	 * Ease a fraction so motion starts quickly and settles.
	 *
	 * @param float $t Linear progress from 0 to 1.
	 * @return float Eased progress from 0 to 1.
	 */
	private static function ease(float $t): float
	{
		$t = max(0.0, min(1.0, $t));

		return 1 - (1 - $t) * (1 - $t);
	}
}

<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/** A finite, linear presentation timeline. It only consumes authorized views and public chip balances. */
final class Motion
{
	public const CARD_TRAVEL = .22;
	public const CARD_GAP = .055;
	public const CHIP_TRAVEL = .25;
	public const CHIP_GAP = .025;
	public const TOTAL_TRAVEL = .25;
	private float $elapsed = 0;
	private float $duration = 0;
	private float $cardStart = 0;
	private array $stages = [];
	private ?int $drawSeat = null;
	private bool $deal = false;
	private array $removed = [];
	private array $kept = [];
	private array $added = [];
	private string $kind = 'chips';
	private float $totalDuration = 0;
	private array $totalFrom = [];
	private array $totalTo = [];
	/** @var list<array{at:float,cue:string}> Browser PC-speaker cues on this presentation timeline. */
	private array $sounds = [];
	/** @var list<array{seat:int|null,index:int}> Cards dealt this step in a street game, in dealing order; a null seat is the board. */
	private array $arrivals = [];

	public function __construct(private array $previous, private array $next, private array $chips, private array $selection)
	{
		$chipSoundIndex = 0;
		foreach($chips['stages'] as $stage)
		{
			$stage['start'] = $this->duration;
			$stage['duration'] = self::CHIP_TRAVEL + max(0, count($stage['moves']) - 1) * self::CHIP_GAP;
			foreach($stage['moves'] as $index => $_)
			{
				if($chipSoundIndex++ % 2 === 0)
				$this->sounds[] = ['at' => $stage['start'] + $index * self::CHIP_GAP, 'cue' => 'chips'];
			}
			$this->duration += $stage['duration'];
			$this->stages[] = $stage;
		}
		foreach($next['players'] ?? [] as $seat => $player)
		{
			$to = (int)($player['stack'] ?? 0);
			$from = isset($previous['players'][$seat]) ? (int)($previous['players'][$seat]['stack'] ?? $to) : $to;
			$this->totalFrom[$seat] = $from;
			$this->totalTo[$seat] = $to;
			if($from !== $to) $this->totalDuration = max($this->duration, self::TOTAL_TRAVEL);
		}
		$this->duration = max($this->duration, $this->totalDuration);
		$this->cardStart = $this->duration;
		$changedHand = ($previous['generation'] ?? 0) !== ($next['generation'] ?? 0)
			|| ($previous['handNumber'] ?? 0) !== ($next['handNumber'] ?? 0);
		if(($next['game'] ?? 'draw') !== 'draw')
		{
			$this->street($changedHand);
			return;
		}
		// Online play advances the public hand number before its joint shuffle. That ready view has no allocated
		// cards yet, so it may animate the dealer's ante but must not invent a deal that disappears during crypto.
		$hasDealtHands = ($next['phase'] ?? 'ready') !== 'ready' && !empty($next['players']);
		foreach($next['players'] ?? [] as $player)
			$hasDealtHands = $hasDealtHands && count($player['cards'] ?? []) === 5;
		$this->deal = $changedHand && $hasDealtHands;
		if($this->deal)
		{
			$this->kind = 'deal';
			for($index = 0, $cards = count($next['players']) * 5; $index < $cards; $index++)
			$this->sounds[] = ['at' => $this->cardStart + $index * self::CARD_GAP, 'cue' => 'card'];
			$this->duration += max(0, count($next['players']) * 5 - 1) * self::CARD_GAP + self::CARD_TRAVEL;
			return;
		}
		foreach($next['players'] ?? [] as $seat => $player)
		{
			$old = $previous['players'][$seat] ?? [];
			if(($old['drawCount'] ?? null) !== null || ($player['drawCount'] ?? null) === null || $player['drawCount'] === 0) continue;
			$this->drawSeat = $seat;
			$this->kind = 'draw';
			$oldCards = $old['cards'];
			$newCards = $player['cards'];
			if($seat === 0)
			{
				foreach($oldCards as $index => $id)
				{
					$destination = array_search($id, $newCards, true);
					if($destination === false) $this->removed[] = $index;
					else $this->kept[$index] = $destination;
				}
				foreach($newCards as $index => $id) if(!in_array($id, $oldCards, true)) $this->added[] = $index;
			}
			else
			{
				// Only the draw count is public. Back positions are illustrative and never query concealed identities.
				for($i = 0; $i < 5; $i++)
				if($i < $player['drawCount']) { $this->removed[] = $i; $this->added[] = $i; }
				else $this->kept[$i] = $i;
			}
			foreach($this->removed as $order => $_)
			$this->sounds[] = ['at' => $this->cardStart + $order * self::CARD_GAP, 'cue' => 'card'];
			$drawStart = $this->cardStart + self::CARD_TRAVEL + (count($this->removed) - 1) * self::CARD_GAP;
			foreach($this->added as $order => $_)
			$this->sounds[] = ['at' => $drawStart + $order * self::CARD_GAP, 'cue' => 'card'];
			$this->duration += 2 * (self::CARD_TRAVEL + (count($this->removed) - 1) * self::CARD_GAP);
			break;
		}
	}

	/**
	 * Plan the cards a street game dealt between two views: each seat's new cards round the table from the dealer's
	 * left, first card first, and then any new board cards. A new hand counts every card as new.
	 */
	private function street(bool $changedHand): void
	{
		if(($this->next['phase'] ?? 'ready') === 'ready' || empty($this->next['players'])) return;
		$seats = count($this->next['players']);
		$fresh = [];
		$rounds = 0;
		foreach($this->next['players'] as $seat => $player)
		{
			$before = $changedHand ? 0 : count($this->previous['players'][$seat]['cards'] ?? []);
			$fresh[$seat] = range($before, count($player['cards'] ?? []) - 1);
			if(count($player['cards'] ?? []) <= $before) $fresh[$seat] = [];
			$rounds = max($rounds, count($fresh[$seat]));
		}
		for($round = 0; $round < $rounds; $round++) for($offset = 1; $offset <= $seats; $offset++)
		{
			$seat = ((int)($this->next['dealer'] ?? 0) + $offset) % $seats;
			if(isset($fresh[$seat][$round])) $this->arrivals[] = ['seat' => $seat, 'index' => $fresh[$seat][$round]];
		}
		$board = $changedHand ? 0 : count($this->previous['board'] ?? []);
		for($index = $board; $index < count($this->next['board'] ?? []); $index++) $this->arrivals[] = ['seat' => null, 'index' => $index];
		if(!$this->arrivals) return;
		$this->kind = 'deal';
		foreach($this->arrivals as $order => $_) $this->sounds[] = ['at' => $this->cardStart + $order * self::CARD_GAP, 'cue' => 'card'];
		$this->duration += (count($this->arrivals) - 1) * self::CARD_GAP + self::CARD_TRAVEL;
	}

	public function active(): bool { return $this->elapsed < $this->duration; }
	public function advance(float $seconds): void { $this->elapsed = min($this->duration, $this->elapsed + max(0, $seconds)); }
	public function status(): array
	{
		return ['kind' => $this->kind, 'elapsed' => $this->elapsed, 'duration' => $this->duration, 'sounds' => $this->sounds];
	}

	/** Straight interpolation only: no easing, acceleration, rotation or bounce. */
	public static function slide(array $from, array $to, float $progress): array
	{
		$progress = max(0, min(1, $progress));
		return array_map(static fn(float $a, float $b): float => $a + ($b - $a) * $progress, $from, $to);
	}

	public function frame(Layout $layout): array
	{
		$progress = $this->totalDuration > 0 ? min(1, $this->elapsed / $this->totalDuration) : 1;
		$totals = [];
		foreach($this->totalTo as $seat => $to)
		$totals[$seat] = (int)round($this->totalFrom[$seat] + ($to - $this->totalFrom[$seat]) * $progress);
		$frame = ['kind' => $this->kind, 'banks' => $this->chips['after'], 'totals' => $totals, 'cards' => [], 'chips' => [], 'hiddenSeats' => [],
			'hiddenCards' => [], 'hiddenBoard' => [], 'discardCount' => (int)($this->next['discardCount'] ?? 0)];
		foreach($this->stages as $stage) if($this->elapsed < $stage['start'] + $stage['duration'])
		{
			$frame['banks'] = $stage['before'];
			$elapsed = $this->elapsed - $stage['start'];
			foreach($stage['events'] as $event)
			{
				if($elapsed < $event['at']) break;
				$frame['banks'][$event['owner']] = $event['after'];
			}
			foreach($stage['moves'] as $index => $move)
			{
				$departure = $index * self::CHIP_GAP;
				if($elapsed < $departure || $elapsed >= $departure + self::CHIP_TRAVEL) continue;
				$t = ($elapsed - $departure) / self::CHIP_TRAVEL;
				$from = $layout->chip($move['from'], count($this->next['players']), $move['value'], $move['sourceIndex']);
				$to = $layout->chip($move['to'], count($this->next['players']), $move['value'], $move['destinationIndex']);
				$frame['chips'][] = ['position' => self::slide($from, $to, $t), 'value' => $move['value']];
			}
			break;
		}
		$centre = $layout->centre();
		if($this->arrivals)
		{
			// Every card dealt this step stays off the table until it lands; until then it flies face down.
			$seats = count($this->next['players']);
			foreach($this->arrivals as $order => ['seat' => $seat, 'index' => $index])
			{
				if($seat === null) $frame['hiddenBoard'][] = $index;
				else $frame['hiddenCards'][$seat][] = $index;
				$t = ($this->elapsed - $this->cardStart - $order * self::CARD_GAP) / self::CARD_TRAVEL;
				if($t < 0) continue;
				$to = $seat === null ? $centre['board'][$index] ?? null : $layout->seat($seat, $seats)['cards'][$index] ?? null;
				if($to === null) continue;
				$from = [$centre['deck'][0] + ($centre['deck'][2] - $to[2]) / 2, $centre['deck'][1] + ($centre['deck'][3] - $to[3]) / 2, $to[2], $to[3]];
				$id = $seat === null ? $this->next['board'][$index] : $this->next['players'][$seat]['cards'][$index];
				// The last card's progress can fall a rounding error short of one when the timeline ends.
				$landed = $t >= 1 - 1e-9 || $this->elapsed >= $this->duration;
				$frame['cards'][] = ['rect' => $landed ? $to : self::slide($from, $to, $t), 'id' => $landed ? $id : null];
			}
		}
		elseif($this->deal)
		{
			$seats = count($this->next['players']);
			$frame['hiddenSeats'] = range(0, $seats - 1);
			for($round = 0; $round < 5; $round++) for($offset = 1; $offset <= $seats; $offset++)
			{
				$seat = ($this->next['dealer'] + $offset) % $seats;
				$t = ($this->elapsed - $this->cardStart - ($round * $seats + $offset - 1) * self::CARD_GAP) / self::CARD_TRAVEL;
				if($t < 0) continue;
				$to = $layout->seat($seat, $seats)['cards'][$round];
				$from = [$centre['deck'][0] + ($centre['deck'][2] - $to[2]) / 2, $centre['deck'][1] + ($centre['deck'][3] - $to[3]) / 2, $to[2], $to[3]];
				$frame['cards'][] = ['rect' => self::slide($from, $to, $t), 'id' => $t >= 1 ? $this->next['players'][$seat]['cards'][$round] : null];
			}
		}
		elseif($this->drawSeat !== null)
		{
			$seat = $this->drawSeat;
			$frame['hiddenSeats'] = [$seat];
			$oldCards = $this->previous['players'][$seat]['cards'];
			$newCards = $this->next['players'][$seat]['cards'];
			$fan = $layout->seat($seat, count($this->next['players']))['cards'];
			$from = $seat === 0 ? $layout->discardDialog($oldCards, $this->selection)['cards'] : $fan;
			$drawStart = $this->cardStart + self::CARD_TRAVEL + (count($this->removed) - 1) * self::CARD_GAP;
			foreach($this->kept as $oldIndex => $newIndex)
			$frame['cards'][] = ['rect' => self::slide($from[$oldIndex], $fan[$newIndex], ($this->elapsed - $drawStart) / self::CARD_TRAVEL), 'id' => $newCards[$newIndex]];
			foreach($this->removed as $order => $index)
			{
				$t = ($this->elapsed - $this->cardStart - $order * self::CARD_GAP) / self::CARD_TRAVEL;
				if($t >= 1) continue;
				$frame['cards'][] = ['rect' => self::slide($from[$index], $centre['discard'], $t), 'id' => $oldCards[$index]];
			}
			foreach($this->added as $order => $index)
			{
				$t = ($this->elapsed - $drawStart - $order * self::CARD_GAP) / self::CARD_TRAVEL;
				if($t < 0) continue;
				$to = $fan[$index];
				$start = [$centre['deck'][0] + ($centre['deck'][2] - $to[2]) / 2, $centre['deck'][1] + ($centre['deck'][3] - $to[3]) / 2, $to[2], $to[3]];
				$frame['cards'][] = ['rect' => self::slide($start, $to, $t), 'id' => $t >= 1 ? $newCards[$index] : null];
			}
			if($this->elapsed < $this->cardStart + self::CARD_TRAVEL) $frame['discardCount'] = (int)$this->previous['discardCount'];
		}
		return $frame;
	}
}

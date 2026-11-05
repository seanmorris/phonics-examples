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

	public function __construct(private array $previous, private array $next, private array $chips, private array $selection)
	{
		foreach($chips['stages'] as $stage)
		{
			$stage['start'] = $this->duration;
			$stage['duration'] = self::CHIP_TRAVEL + max(0, count($stage['moves']) - 1) * self::CHIP_GAP;
			$this->duration += $stage['duration'];
			$this->stages[] = $stage;
		}
		$this->cardStart = $this->duration;
		$this->deal = ($previous['generation'] ?? 0) !== ($next['generation'] ?? 0)
			|| ($previous['handNumber'] ?? 0) !== ($next['handNumber'] ?? 0);
		if($this->deal)
		{
			$this->kind = 'deal';
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
			$this->duration += 2 * (self::CARD_TRAVEL + (count($this->removed) - 1) * self::CARD_GAP);
			break;
		}
	}

	public function active(): bool { return $this->elapsed < $this->duration; }
	public function advance(float $seconds): void { $this->elapsed = min($this->duration, $this->elapsed + max(0, $seconds)); }
	public function status(): array { return ['kind' => $this->kind, 'elapsed' => $this->elapsed, 'duration' => $this->duration]; }

	/** Straight interpolation only: no easing, acceleration, rotation or bounce. */
	public static function slide(array $from, array $to, float $progress): array
	{
		$progress = max(0, min(1, $progress));
		return array_map(static fn(float $a, float $b): float => $a + ($b - $a) * $progress, $from, $to);
	}

	public function frame(Layout $layout): array
	{
		$frame = ['kind' => $this->kind, 'banks' => $this->chips['after'], 'cards' => [], 'chips' => [], 'hiddenSeats' => [], 'discardCount' => (int)($this->next['discardCount'] ?? 0)];
		foreach($this->stages as $stage) if($this->elapsed < $stage['start'] + $stage['duration'])
		{
			$frame['banks'] = $stage['before'];
			foreach($stage['moves'] as $index => $move)
			{
				$t = ($this->elapsed - $stage['start'] - $index * self::CHIP_GAP) / self::CHIP_TRAVEL;
				if($t < 0) continue;
				$frame['banks'][$move['from']][$move['value']]--;
				if($t >= 1) { $frame['banks'][$move['to']][$move['value']] = ($frame['banks'][$move['to']][$move['value']] ?? 0) + 1; continue; }
				$from = $layout->chip($move['from'], count($this->next['players']), $move['value'], $move['sourceIndex']);
				$to = $layout->chip($move['to'], count($this->next['players']), $move['value'], $move['destinationIndex']);
				$frame['chips'][] = ['position' => self::slide($from, $to, $t), 'value' => $move['value']];
			}
			break;
		}
		$centre = $layout->centre();
		if($this->deal)
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

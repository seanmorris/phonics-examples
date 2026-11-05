<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/** Exact presentation-only chip inventories; balances always come from the authorized rules view. */
final class Chips
{
	public const VALUES = [1,5,25,100,500,1000,5000,25000,100000,500000,1000000,5000000,25000000,100000000,500000000,1000000000];
	public const STACK_LIMIT = 20;

	/** @return array<int,int> Denomination => physical chip count, with no decorative filler chips. */
	public static function bank(int $amount): array
	{
		$total = $amount;
		$result = [];
		// Keep a small amount of useful change in a new/restored bank instead of a single oversized chip.
		$change = $amount >= 100 ? 100 : ($amount >= 25 ? 25 : ($amount >= 5 ? 5 : 0));
		$amount -= $change;
		foreach(array_reverse(self::VALUES) as $value)
		{
			$count = intdiv($amount, $value);
			if($count) $result[$value] = $count;
			$amount %= $value;
		}
		foreach([25,5,1] as $value)
		{
			$count = $value === 1 ? $change : max(0, intdiv($change - 1, $value));
			if($count) $result[$value] = ($result[$value] ?? 0) + $count;
			$change -= $count * $value;
		}
		ksort($result);
		while(($value = self::obscured($result)) !== null) self::change($result, $value);
		if(max($result ?: [0]) > self::STACK_LIMIT)
		{
			// Find an exact, ordered color-up. Very large balances can require more than twenty chips:
			// choose the smallest feasible height rather than losing value or hiding a rear denomination.
			$misses = [];
			for($limit = self::STACK_LIMIT; ; $limit++)
			if(($compact = self::bounded($total, 0, $limit, $misses)) !== null) return $compact;
		}
		return $result;
	}

	/** Exact denomination allocation with strictly decreasing occupied stack heights. */
	private static function bounded(int $amount, int $slot, int $limit, array &$misses): ?array
	{
		if($amount === 0) return [];
		if($slot === count(self::VALUES) || $limit === 0) return null;
		$key = "$amount:$slot:$limit";
		if(isset($misses[$key])) return null;
		$value = self::VALUES[$slot];
		for($count = min($limit, intdiv($amount, $value)); $count >= ($slot === 0 ? 1 : 0); $count--)
		{
			$remaining = $amount - $count * $value;
			if(isset(self::VALUES[$slot + 1]) && $remaining % self::VALUES[$slot + 1]) continue;
			$rest = self::bounded($remaining, $slot + 1, $count ? $count - 1 : $limit, $misses);
			if($rest !== null) return $count ? [$value => $count] + $rest : $rest;
		}
		$misses[$key] = true;
		return null;
	}

	/** Exchange only when height or visibility requires it; otherwise leave physical chips in place. */
	private static function exchange(array $bank, int|string $owner): array
	{
		return max($bank ?: [0]) > self::STACK_LIMIT || ($owner !== 'pot' && self::obscured($bank) !== null)
			? self::bank(self::value($bank)) : $bank;
	}

	/** First front stack that is not shorter than every occupied stack behind it. */
	private static function obscured(array $bank): ?int
	{
		ksort($bank);
		$previous = PHP_INT_MAX;
		foreach($bank as $value => $count) if($count > 0)
		{
			if($count >= $previous) return (int)$value;
			$previous = $count;
		}
		return null;
	}

	/** Exchange one chip for the same value in the next smaller denomination. */
	private static function change(array &$bank, int $value): void
	{
		$slot = array_search($value, self::VALUES, true);
		if($slot === false || $slot === 0 || empty($bank[$value])) throw new \LogicException('Cannot make chip change');
		$smaller = self::VALUES[$slot - 1];
		$bank[$value]--;
		$bank[$smaller] = ($bank[$smaller] ?? 0) + intdiv($value, $smaller);
		ksort($bank);
	}

	public static function value(array $chips): int
	{
		$total = 0;
		foreach($chips as $value => $count) $total += $value * $count;
		return $total;
	}

	public static function fromView(array $view): array
	{
		$result = ['pot' => self::bank((int)($view['pot'] ?? 0))];
		foreach($view['players'] ?? [] as $seat => $player) $result[$seat] = self::bank((int)$player['stack']);
		return $result;
	}

	/**
	 * Move exact chips, exchanging denominations at departures and arrivals when needed. Each event records
	 * the actual bank and top-chip index, so overlapping flights retain their real source and destination.
	 */
	public static function transfer(array $banks, int|string $from, int|string $to, int $amount): array
	{
		if($amount < 0 || $amount > self::value($banks[$from])) throw new \InvalidArgumentException('Insufficient chips');
		if($from === $to) throw new \InvalidArgumentException('Chip transfer needs different owners');
		foreach([$from,$to] as $owner) $banks[$owner] = self::exchange($banks[$owner], $owner);
		// A fresh source bank has small change available, including when a saved pot is all large chips.
		$banks[$from] = self::bank(self::value($banks[$from]));
		$before = $banks;
		$remaining = $amount;
		$source = $banks[$from];
		$target = $banks[$to];
		$moves = $events = [];
		while($remaining > 0)
		{
			$piece = null;
			foreach(array_reverse(self::VALUES) as $value)
			if($value <= $remaining && !empty($source[$value])) { $piece = $value; break; }
			if($piece === null) { $source = self::bank(self::value($source)); continue; }
			$index = count($moves);
			$departure = $index * Motion::CHIP_GAP;
			$arrival = $departure + Motion::CHIP_TRAVEL;
			$sourceBefore = $source;
			$targetBefore = $target;
			$sourceIndex = --$source[$piece];
			$destinationIndex = $target[$piece] ?? 0;
			$target[$piece] = $destinationIndex + 1;
			$source = self::exchange($source, $from);
			$target = self::exchange($target, $to);
			$moves[] = ['value' => $piece, 'from' => $from, 'to' => $to, 'sourceIndex' => $sourceIndex, 'destinationIndex' => $destinationIndex];
			$events[] = ['at' => $departure, 'move' => $index, 'kind' => 'depart', 'owner' => $from, 'before' => $sourceBefore, 'after' => $source];
			$events[] = ['at' => $arrival, 'move' => $index, 'kind' => 'arrive', 'owner' => $to, 'before' => $targetBefore, 'after' => $target];
			$remaining -= $piece;
		}
		usort($events, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
		$banks[$from] = $source;
		$banks[$to] = $target;
		return ['before' => $before, 'after' => $banks, 'moves' => $moves, 'events' => $events];
	}

	/** Infer public payments, loans and awards without adding anything to saved or deterministic state. */
	public static function transition(array $previous, array $next, array $banks): array
	{
		$previousPlayers = $previous['players'] ?? [];
		$nextPlayers = $next['players'] ?? [];
		$missingBank = !isset($banks['pot']);
		foreach($nextPlayers as $seat => $_player) if(!isset($banks[$seat])) $missingBank = true;
		$newTable = ($previous['generation'] ?? 0) !== ($next['generation'] ?? 0)
			|| ($previous['mode'] ?? null) !== ($next['mode'] ?? null)
			|| count($previousPlayers) !== count($nextPlayers) || !$banks || $missingBank;
		$newHand = $newTable || ($previous['handNumber'] ?? 0) !== ($next['handNumber'] ?? 0);
		$payments = [];
		foreach($next['players'] ?? [] as $seat => $player)
		$payments[$seat] = max(0, (int)$player['totalBet'] - ($newHand ? 0 : (int)($previous['players'][$seat]['totalBet'] ?? 0)));
		if($newTable)
		{
			$banks = self::fromView($next);
			foreach($payments as $seat => $amount) $banks[$seat] = self::bank((int)$next['players'][$seat]['stack'] + $amount);
			$banks['pot'] = self::bank(max(0, (int)($next['pot'] ?? 0) - array_sum($payments)));
		}
		else foreach($next['players'] ?? [] as $seat => $player)
		{
			$loan = max(0, (int)$player['debt'] - (int)($previous['players'][$seat]['debt'] ?? 0));
			foreach(self::bank($loan) as $value => $count) $banks[$seat][$value] = ($banks[$seat][$value] ?? 0) + $count;
			$banks[$seat] = self::exchange($banks[$seat], $seat);
		}
		$stages = [];
		foreach($payments as $seat => $amount) if($amount)
		{
			$stage = self::transfer($banks, $seat, 'pot', $amount);
			$stages[] = $stage;
			$banks = $stage['after'];
		}
		if(($next['phase'] ?? '') === 'showdown' && ($previous['phase'] ?? '') !== 'showdown')
		foreach($next['awards'] ?? [] as $award) if($award['amount'])
		{
			$stage = self::transfer($banks, 'pot', (int)$award['seat'], (int)$award['amount']);
			$stages[] = $stage;
			$banks = $stage['after'];
		}
		return ['stages' => $stages, 'after' => $banks];
	}
}

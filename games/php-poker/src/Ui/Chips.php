<?php
declare(strict_types=1);
namespace PhpPoker\Ui;

/** Exact presentation-only chip inventories; balances always come from the authorized rules view. */
final class Chips
{
	public const VALUES = [1,5,25,100,500,1000,5000,25000,100000,500000,1000000,5000000,25000000,100000000,500000000,1000000000];

	/** @return array<int,int> Denomination => physical chip count, with no decorative filler chips. */
	public static function bank(int $amount): array
	{
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
		return $result;
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
	 * Move exact chips, making change before departure when needed. Returned indices are the actual top chips
	 * removed from the source and appended to the destination; painting and motion use these same indices.
	 */
	public static function transfer(array $banks, int|string $from, int|string $to, int $amount): array
	{
		if($amount < 0 || $amount > self::value($banks[$from])) throw new \InvalidArgumentException('Insufficient chips');
		// Exchange the banks before a transfer, then keep every chip fixed until it leaves or arrives.
		foreach([$from,$to] as $owner) if($owner !== 'pot') $banks[$owner] = self::bank(self::value($banks[$owner]));
		while(true)
		{
			$stage = self::payment($banks, $from, $to, $amount);
			$visible = $stage['before'];
			$invalid = null;
			$check = static function() use (&$visible, &$invalid, $from, $to): void {
				foreach([$from,$to] as $owner) if($owner !== 'pot' && ($value = self::obscured($visible[$owner])) !== null)
				{ $invalid = [$owner,$value]; return; }
			};
			$check();
			foreach($stage['moves'] as $move)
			{
				if($invalid !== null) break;
				$visible[$from][$move['value']]--;
				$visible[$to][$move['value']] = ($visible[$to][$move['value']] ?? 0) + 1;
				$check();
			}
			if($invalid === null) return $stage;
			[$owner,$value] = $invalid;
			$banks = $stage['before'];
			// If the offending destination chip is still incoming, break it at the source before it flies.
			self::change($banks[!empty($banks[$owner][$value]) ? $owner : $from], $value);
		}
	}

	/** Plan a payment; `transfer()` makes any additional change needed to keep stacks visible. */
	private static function payment(array $banks, int|string $from, int|string $to, int $amount): array
	{
		$remaining = $amount;
		$source = $banks[$from];
		$pieces = [];
		while($remaining > 0)
		{
			foreach(array_reverse(self::VALUES) as $value)
			{
				$count = min($source[$value] ?? 0, intdiv($remaining, $value));
				if(!$count) continue;
				$source[$value] -= $count;
				$remaining -= $value * $count;
				for($i = 0; $i < $count; $i++) $pieces[] = $value;
			}
			if(!$remaining) break;
			foreach(self::VALUES as $index => $value) if($value > $remaining && !empty($source[$value]))
			{
				$source[$value]--;
				// Split one chip into the next smaller denomination (including the 25 -> 5 and 100 -> 25 steps).
				$smaller = self::VALUES[$index - 1];
				$source[$smaller] = ($source[$smaller] ?? 0) + intdiv($value, $smaller);
				break;
			}
		}
		sort($pieces, SORT_NUMERIC);
		$before = $banks;
		$before[$from] = $source;
		foreach($pieces as $value) $before[$from][$value] = ($before[$from][$value] ?? 0) + 1;
		$after = $before;
		$moves = [];
		foreach($pieces as $value)
		{
			$sourceIndex = --$after[$from][$value];
			$destinationIndex = $after[$to][$value] ?? 0;
			$after[$to][$value] = $destinationIndex + 1;
			$moves[] = ['value' => $value, 'from' => $from, 'to' => $to, 'sourceIndex' => $sourceIndex, 'destinationIndex' => $destinationIndex];
		}
		return ['before' => $before, 'after' => $after, 'moves' => $moves];
	}

	/** Infer public payments, loans and awards without adding anything to saved or deterministic state. */
	public static function transition(array $previous, array $next, array $banks): array
	{
		$newTable = ($previous['generation'] ?? 0) !== ($next['generation'] ?? 0) || !$banks;
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

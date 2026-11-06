<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * Exact five-card high-poker evaluation with optional fully wild physical cards.
 *
 * Card IDs 0..51 encode clubs, diamonds, hearts and spades, each in rank order
 * from two through ace. A fixed-width base-15 score compares every tie breaker.
 */
final class Hand
{
	public const LABELS = ['High card','One pair','Two pair','Three of a kind','Straight','Flush','Full house','Four of a kind','Straight flush','Five of a kind'];

	/**
	 * Rank exactly five distinct cards.
	 * @param array $cards Five distinct physical card IDs; 52 is the joker.
	 * @param array $wildCards Physical IDs that may represent any missing rank and suit.
	 * @return array Category, descending tiebreak ranks, sortable score and friendly name.
	 */
	public static function evaluate(array $cards, array $wildCards = []): array
	{
		if(count($cards) !== 5 || count(array_unique($cards, SORT_REGULAR)) !== 5)
			throw new \InvalidArgumentException('A poker hand requires five distinct cards');
		$wildCards = WildCards::custom($wildCards);
		$wildLookup = array_fill_keys($wildCards, true);
		$ranks = []; $suits = []; $wild = 0;
		foreach($cards as $card)
		{
			if(!is_int($card) || $card < 0 || $card > 52 || ($card === 52 && !isset($wildLookup[52])))
				throw new \InvalidArgumentException('Invalid card');
			if(isset($wildLookup[$card])) { $wild++; continue; }
			$ranks[] = $card % 13 + 2;
			$suits[] = intdiv($card, 13);
		}
		$flushPossible = count(array_unique($suits)) <= 1;
		$best = null;
		// Rank allocations are combinations with repetition rather than 52^N substitutions. Even an all-wild hand
		// has only 6,188 candidates, and normal preset hands usually have 13 or 91.
		$visit = function(array $assigned) use (&$best, $ranks, $flushPossible, $wild): void {
			$candidate = self::evaluateRanks([...$ranks, ...$assigned], $flushPossible, $wild);
			if($best === null || $candidate['score'] > $best['score']) $best = $candidate;
		};
		$allocate = function(int $remaining, int $minimum, array $assigned) use (&$allocate, $visit): void {
			if($remaining === 0) { $visit($assigned); return; }
			for($rank = $minimum; $rank <= 14; $rank++) $allocate($remaining - 1, $rank, [...$assigned, $rank]);
		};
		$allocate($wild, 2, []);
		return $best;
	}

	/** Rank one completed five-rank interpretation whose natural suits may or may not form a flush. */
	private static function evaluateRanks(array $ranks, bool $flushPossible, int $wild): array
	{
		rsort($ranks, SORT_NUMERIC);
		$counts = array_count_values($ranks);
		$groups = array_keys($counts);
		usort($groups, static fn(int $a, int $b): int => ($counts[$b] <=> $counts[$a]) ?: ($b <=> $a));
		$flush = $flushPossible && count($counts) === 5;
		$straight = 0;
		if(count($counts) === 5)
		{
			if($ranks[0] - $ranks[4] === 4) $straight = $ranks[0];
			elseif($ranks === [14,5,4,3,2]) $straight = 5;
		}
		$first = $counts[$groups[0]];
		$second = isset($groups[1]) ? $counts[$groups[1]] : 0;
		if($first === 5 && $wild > 0) { $category = 9; $kickers = [$groups[0]]; }
		elseif($straight && $flush) { $category = 8; $kickers = [$straight]; }
		elseif($first === 4) { $category = 7; $kickers = $groups; }
		elseif($first === 3 && $second === 2) { $category = 6; $kickers = $groups; }
		elseif($flush) { $category = 5; $kickers = $ranks; }
		elseif($straight) { $category = 4; $kickers = [$straight]; }
		elseif($first === 3) { $category = 3; $kickers = $groups; }
		elseif($first === 2 && $second === 2) { $category = 2; $kickers = $groups; }
		elseif($first === 2) { $category = 1; $kickers = $groups; }
		else { $category = 0; $kickers = $ranks; }
		$score = $category;
		foreach(array_pad($kickers, 5, 0) as $rank) $score = $score * 15 + $rank;
		$name = self::LABELS[$category];
		if($category === 8 && $straight === 14) $name = 'Royal flush';
		return ['category' => $category, 'ranks' => $kickers, 'score' => $score, 'name' => $name];
	}

	/**
	 * Test the pre-draw Jackpots opening condition.
	 * @param array $cards Exact current five-card hand.
	 * @return bool Whether this hand is a pair of jacks or better.
	 */
	public static function canOpen(array $cards, array $wildCards = []): bool
	{
		$hand = self::evaluate($cards, $wildCards);
		return $hand['category'] > 1 || ($hand['category'] === 1 && $hand['ranks'][0] >= 11);
	}

	/**
	 * Describe a face for accessible controls and history.
	 * @param int $card Card ID.
	 * @return string Rank and suit in plain language.
	 */
	public static function label(int $card): string
	{
		if($card === 52) return 'Joker';
		if($card < 0 || $card > 51) throw new \InvalidArgumentException('Invalid card');
		$rank = $card % 13 + 2;
		return ([11=>'Jack',12=>'Queen',13=>'King',14=>'Ace'][$rank] ?? (string)$rank)
			. ' of ' . ['clubs','diamonds','hearts','spades'][intdiv($card,13)];
	}
}

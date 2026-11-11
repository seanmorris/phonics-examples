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
	 * @param array $cards Five distinct physical card IDs; 52 and 53 are the jokers.
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
			if(!is_int($card) || $card < 0 || $card > 53 || ($card >= 52 && !isset($wildLookup[$card])))
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
	 * Rank the best five-card hand that can be made from five or more cards.
	 *
	 * Seven-card games score every five-card subset with the exact evaluator, so the result is directly comparable
	 * with a five-card hand. Results are memoized because a view asks for the same hand many times per street.
	 *
	 * @param array $cards Five to seven distinct physical card IDs.
	 * @param array $wildCards Physical IDs that may represent any missing rank and suit.
	 * @return array The winning subset's category, tiebreak ranks, score and name, plus its `cards`.
	 */
	public static function best(array $cards, array $wildCards = []): array
	{
		$count = count($cards);
		if($count < 5 || $count > 7 || count(array_unique($cards, SORT_REGULAR)) !== $count)
			throw new \InvalidArgumentException('A best hand requires five to seven distinct cards');
		$cards = array_values($cards);
		$sorted = $cards;
		sort($sorted, SORT_NUMERIC);
		static $memo = [];
		$key = implode(',', $sorted) . '|' . implode(',', WildCards::custom($wildCards));
		if(isset($memo[$key])) return $memo[$key];
		if(count($memo) > 4096) $memo = [];
		// A wild card can stand for any card it replaces, so the best hand always plays every wild card it can.
		// Only the natural cards beside them need choosing, which keeps a hand full of wild cards as cheap as one
		// five-card evaluation instead of twenty-one.
		$wildLookup = array_fill_keys(WildCards::custom($wildCards), true);
		$wilds = array_values(array_filter($sorted, static fn(int $card): bool => isset($wildLookup[$card])));
		$naturals = array_values(array_filter($sorted, static fn(int $card): bool => !isset($wildLookup[$card])));
		$wilds = array_slice($wilds, 0, 5);
		$need = 5 - count($wilds);
		$best = null;
		$choose = static function(int $from, array $picked) use (&$choose, &$best, $naturals, $wilds, $need, $wildCards): void {
			if(count($picked) === $need)
			{
				$subset = [...$picked, ...$wilds];
				sort($subset, SORT_NUMERIC);
				$candidate = self::evaluate($subset, $wildCards);
				if($best === null || $candidate['score'] > $best['score']) $best = $candidate + ['cards' => $subset];
				return;
			}
			for($index = $from; $index <= count($naturals) - ($need - count($picked)); $index++)
				$choose($index + 1, [...$picked, $naturals[$index]]);
		};
		$choose(0, []);
		return $memo[$key] = $best;
	}

	/**
	 * Rank the cards a stud player is showing, to decide who acts first on a street.
	 *
	 * Only matched ranks and high cards count: one to four exposed cards never make a straight or a flush for this
	 * purpose. Wild cards join the largest natural group, or stand as aces when nothing else shows.
	 *
	 * @param array $cards One to four distinct exposed physical card IDs.
	 * @param array $wildCards Physical IDs that are wild.
	 * @return array Category on the evaluator's scale, tiebreak ranks, and a score comparable between showings.
	 */
	public static function showing(array $cards, array $wildCards = []): array
	{
		$count = count($cards);
		if($count < 1 || $count > 4 || count(array_unique($cards, SORT_REGULAR)) !== $count)
			throw new \InvalidArgumentException('A showing hand has one to four distinct cards');
		$wildLookup = array_fill_keys(WildCards::custom($wildCards), true);
		$ranks = []; $wild = 0;
		foreach($cards as $card)
		{
			if(!is_int($card) || $card < 0 || $card > 53 || ($card >= 52 && !isset($wildLookup[$card])))
				throw new \InvalidArgumentException('Invalid card');
			if(isset($wildLookup[$card])) $wild++;
			else $ranks[] = $card % 13 + 2;
		}
		$counts = array_count_values($ranks);
		$groups = array_keys($counts);
		usort($groups, static fn(int $a, int $b): int => ($counts[$b] <=> $counts[$a]) ?: ($b <=> $a));
		if($wild > 0)
		{
			$target = $groups[0] ?? 14;
			$counts[$target] = ($counts[$target] ?? 0) + $wild;
			$groups = array_keys($counts);
			usort($groups, static fn(int $a, int $b): int => ($counts[$b] <=> $counts[$a]) ?: ($b <=> $a));
		}
		$first = $counts[$groups[0]];
		$second = isset($groups[1]) ? $counts[$groups[1]] : 0;
		$category = match(true) {
			$first === 4 => 7
			, $first === 3 => 3
			, $first === 2 && $second === 2 => 2
			, $first === 2 => 1
			, default => 0
		};
		$score = $category;
		foreach(array_pad($groups, 5, 0) as $rank) $score = $score * 15 + $rank;
		return ['category' => $category, 'ranks' => $groups, 'score' => $score, 'name' => self::LABELS[$category]];
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
		if($card === 52 || $card === 53) return 'Joker ' . ($card - 51);
		if($card < 0 || $card > 51) throw new \InvalidArgumentException('Invalid card');
		$rank = $card % 13 + 2;
		return ([11=>'Jack',12=>'Queen',13=>'King',14=>'Ace'][$rank] ?? (string)$rank)
			. ' of ' . ['clubs','diamonds','hearts','spades'][intdiv($card,13)];
	}
}

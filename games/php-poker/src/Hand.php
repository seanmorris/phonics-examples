<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * Exact five-card high-poker evaluation without wild cards.
 *
 * Card IDs 0..51 encode clubs, diamonds, hearts and spades, each in rank order
 * from two through ace. A fixed-width base-15 score compares every tie breaker.
 */
final class Hand
{
	public const LABELS = ['High card','One pair','Two pair','Three of a kind','Straight','Flush','Full house','Four of a kind','Straight flush'];

	/**
	 * Rank exactly five distinct cards.
	 * @param array $cards Five integer card IDs.
	 * @return array Category, descending tiebreak ranks, sortable score and friendly name.
	 */
	public static function evaluate(array $cards): array
	{
		if(count($cards) !== 5 || count(array_unique($cards, SORT_REGULAR)) !== 5)
			throw new \InvalidArgumentException('A poker hand requires five distinct cards');
		$ranks = []; $suits = [];
		foreach($cards as $card)
		{
			if(!is_int($card) || $card < 0 || $card > 51) throw new \InvalidArgumentException('Invalid card');
			$ranks[] = $card % 13 + 2;
			$suits[] = intdiv($card, 13);
		}
		rsort($ranks, SORT_NUMERIC);
		$counts = array_count_values($ranks);
		$groups = array_keys($counts);
		usort($groups, static fn(int $a, int $b): int => ($counts[$b] <=> $counts[$a]) ?: ($b <=> $a));
		$flush = count(array_unique($suits)) === 1;
		$straight = 0;
		if(count($counts) === 5)
		{
			if($ranks[0] - $ranks[4] === 4) $straight = $ranks[0];
			elseif($ranks === [14,5,4,3,2]) $straight = 5;
		}
		$first = $counts[$groups[0]];
		$second = $counts[$groups[1]];
		if($straight && $flush) { $category = 8; $kickers = [$straight]; }
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
	public static function canOpen(array $cards): bool
	{
		$hand = self::evaluate($cards);
		return $hand['category'] > 1 || ($hand['category'] === 1 && $hand['ranks'][0] >= 11);
	}

	/**
	 * Describe a face for accessible controls and history.
	 * @param int $card Card ID.
	 * @return string Rank and suit in plain language.
	 */
	public static function label(int $card): string
	{
		if($card < 0 || $card > 51) throw new \InvalidArgumentException('Invalid card');
		$rank = $card % 13 + 2;
		return ([11=>'Jack',12=>'Queen',13=>'King',14=>'Ace'][$rank] ?? (string)$rank)
			. ' of ' . ['clubs','diamonds','hearts','spades'][intdiv($card,13)];
	}
}

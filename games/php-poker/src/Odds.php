<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * Exact poker odds for the rules in play, counted with the game's own evaluator.
 *
 * A hand's category depends only on the ranks of its natural (non-wild) cards, whether those natural cards share
 * one suit, and how many wild cards it holds; which physical card is wild does not matter. Instead of evaluating
 * every hand, each such pattern is evaluated once on a representative hand and weighted by the number of real
 * hands that share it. A standard deck has about 6,200 patterns against 2,598,960 hands.
 */
final class Odds
{
	/** Dealt-hand rows, lowest rank first. The ace-high straight flush is reported as its own row. */
	public const DEALT_ROWS = [
		'High card','One pair','Two pair','Three of a kind','Straight','Flush','Full house','Four of a kind'
		,'Straight flush','Royal flush','Five of a kind'
	];

	/** @var array<string,array<string,mixed>> Results for the decks and wild sets already counted. */
	private static array $cache = [];

	/**
	 * Count every five-card hand that can be dealt from a deck, by category.
	 *
	 * @param list<int> $deck Physical card IDs in the deck; 52 and 53 are jokers and must be wild.
	 * @param list<int> $wild Physical IDs that are fully wild. IDs outside the deck are ignored.
	 * @return array{total:int,counts:array<string,int>} Hands per DEALT_ROWS label and the number of hands.
	 */
	public static function dealt(array $deck, array $wild = []): array
	{
		[$deck, $wild] = self::rules($deck, $wild);
		$key = 'dealt:' . implode(',', $deck) . '|' . implode(',', $wild);
		if(isset(self::$cache[$key])) return self::$cache[$key];
		$natural = array_values(array_diff($deck, $wild));
		$counts = self::outcomes([], [...$natural, ...$wild], $wild, 5);
		$rows = array_fill_keys(self::DEALT_ROWS, 0);
		foreach($counts as $label => $count) $rows[$label] += $count;
		return self::$cache[$key] = ['total' => self::choose(count($deck), 5), 'counts' => $rows];
	}

	/**
	 * Count the final hands of one draw: the held cards plus every equally likely draw from the unseen cards.
	 *
	 * @param list<int> $held Cards kept; they must not be wild.
	 * @param list<int> $unseen Cards that may be drawn, wild ones included.
	 * @param int $draw Number of cards drawn.
	 * @param list<int> $wild Physical IDs that are fully wild.
	 * @return array<string,int> Final hands per DEALT_ROWS label; the values sum to C(count(unseen), draw).
	 */
	public static function draw(array $held, array $unseen, int $draw, array $wild = []): array
	{
		$wild = WildCards::custom($wild);
		if(count($held) + $draw !== 5 || $draw < 0 || $draw > count($unseen) || array_intersect($held, $unseen) !== [])
			throw new \InvalidArgumentException('A draw needs five cards in all, drawn from unseen cards only');
		if(array_intersect($held, $wild) !== []) throw new \InvalidArgumentException('Held cards must be natural');
		$rows = array_fill_keys(self::DEALT_ROWS, 0);
		foreach(self::outcomes($held, $unseen, $wild, $draw) as $label => $count) $rows[$label] += $count;
		return $rows;
	}

	/**
	 * Exact odds against improving the hands the DOS reference table lists, for the rules in play.
	 *
	 * Each row starts from a canonical five-card hand of natural cards and draws the stated number from the 47
	 * cards the player has not seen. A row whose starting hand cannot be formed from natural cards is omitted.
	 *
	 * @param list<int> $deck Physical card IDs in the deck.
	 * @param list<int> $wild Physical IDs that are fully wild.
	 * @return list<array{hand:string,draw:int,target:string,hits:int,trials:int}>
	 */
	public static function drawing(array $deck, array $wild = []): array
	{
		[$deck, $wild] = self::rules($deck, $wild);
		$key = 'drawing:' . implode(',', $deck) . '|' . implode(',', $wild);
		if(isset(self::$cache[$key])) return self::$cache[$key];
		$natural = array_fill_keys(array_diff($deck, $wild), true);
		$rows = [];
		foreach(self::startingHands() as [$hand, $choices, $targets])
		{
			$start = null;
			// The first arrangement whose ten cards are all natural represents the hand; ranks only shift when the
			// preferred ones are wild.
			foreach($choices as [$held, $discards])
			{
				if(array_diff([...$held, ...$discards], array_keys($natural)) === []) { $start = [$held, $discards]; break; }
			}
			if($start === null) continue;
			[$held, $discards] = $start;
			$unseen = array_values(array_diff($deck, $held, $discards));
			$finals = self::finals($held, $unseen, count($discards), $wild);
			$trials = self::choose(count($unseen), count($discards));
			foreach($targets as $target => $test)
			{
				$hits = 0;
				foreach($finals as [$evaluation, $count]) if($test($evaluation)) $hits += $count;
				$rows[] = ['hand' => $hand, 'draw' => count($discards), 'target' => $target, 'hits' => $hits, 'trials' => $trials];
			}
		}
		return self::$cache[$key] = $rows;
	}

	/** Odds against an event as "n to 1", or null when it cannot happen. */
	public static function against(int $hits, int $trials): ?float
	{
		if($hits < 0 || $trials < $hits || $trials < 1) throw new \InvalidArgumentException('Invalid odds counts');
		return $hits === 0 ? null : ($trials - $hits) / $hits;
	}

	/** @return array{list<int>,list<int>} Validated deck and the wild cards it contains. */
	private static function rules(array $deck, array $wild): array
	{
		if(!array_is_list($deck) || count($deck) < 5 || count(array_unique($deck)) !== count($deck))
			throw new \InvalidArgumentException('A deck needs at least five distinct cards');
		foreach($deck as $card) if(!is_int($card) || $card < 0 || $card > 53) throw new \InvalidArgumentException('Invalid card');
		sort($deck, SORT_NUMERIC);
		$wild = array_values(array_intersect(WildCards::custom($wild), $deck));
		foreach($deck as $card) if($card >= 52 && !in_array($card, $wild, true))
			throw new \InvalidArgumentException('A joker in the deck must be wild');
		return [$deck, $wild];
	}

	/** @return array<string,int> Final hands per row label. */
	private static function outcomes(array $held, array $unseen, array $wild, int $draw): array
	{
		$rows = [];
		foreach(self::finals($held, $unseen, $draw, $wild) as [$evaluation, $count])
		{
			$label = $evaluation['category'] === 8 && $evaluation['name'] === 'Royal flush' ? 'Royal flush' : Hand::LABELS[$evaluation['category']];
			$rows[$label] = ($rows[$label] ?? 0) + $count;
		}
		return $rows;
	}

	/**
	 * Evaluate one representative per pattern of drawn cards and weight it by the number of draws that share it.
	 *
	 * @return list<array{array,int}> Hand evaluation and the number of draws that end in it.
	 */
	private static function finals(array $held, array $unseen, int $draw, array $wild): array
	{
		$wildLookup = array_fill_keys($wild, true);
		$unseenWild = array_values(array_filter($unseen, static fn(int $card): bool => isset($wildLookup[$card])));
		$pool = [];
		foreach($unseen as $card) if(!isset($wildLookup[$card])) $pool[$card % 13 + 2][] = intdiv($card, 13);
		// A flush needs every natural card in the final hand to share a suit. Held cards fix that suit when they share
		// one; with nothing held, any suit will do.
		$heldSuits = array_values(array_unique(array_map(static fn(int $card): int => intdiv($card, 13), $held)));
		$flushSuits = $held === [] ? [0,1,2,3] : (count($heldSuits) === 1 ? $heldSuits : []);
		$finals = [];
		for($wilds = 0; $wilds <= min($draw, count($unseenWild)); $wilds++)
		{
			$weight = self::choose(count($unseenWild), $wilds);
			$wildCards = array_slice($unseenWild, 0, $wilds);
			self::patterns($pool, $draw - $wilds, static function(array $ranks, int $ways, array $suited)
				use (&$finals, $held, $pool, $flushSuits, $weight, $wildCards, $wild, $draw, $wilds): void {
				$flush = 0;
				foreach($flushSuits as $suit) $flush += $suited[$suit];
				// With at most one natural card in the whole hand, every hand can be a flush.
				if(count($held) + $draw - $wilds <= 1) $flush = $ways;
				foreach([[true, $flush],[false, $ways - $flush]] as [$suitedHand, $count])
				{
					if($count === 0) continue;
					$cards = self::representative($ranks, $pool, $suitedHand, $flushSuits);
					$finals[] = [Hand::evaluate([...$held, ...$cards, ...$wildCards], $wild), $count * $weight];
				}
			});
		}
		return $finals;
	}

	/**
	 * Visit every multiset of $size ranks that the pool can supply, with the number of card choices that realise it
	 * and, per suit, how many of those choices lie entirely in that suit.
	 *
	 * @param array<int,list<int>> $pool Available suits for each rank from 2 to 14.
	 */
	private static function patterns(array $pool, int $size, callable $visit): void
	{
		$ranks = array_keys($pool);
		sort($ranks, SORT_NUMERIC);
		$walk = function(int $index, int $remaining, array $chosen, int $ways, array $suited) use (&$walk, $ranks, $pool, $visit): void {
			if($remaining === 0) { $visit($chosen, $ways, $suited); return; }
			if($index >= count($ranks)) return;
			$rank = $ranks[$index];
			$available = $pool[$rank];
			for($take = 0; $take <= min($remaining, count($available)); $take++)
			{
				$nextSuited = $suited;
				if($take === 1) foreach($nextSuited as $suit => $value) $nextSuited[$suit] = in_array($suit, $available, true) ? $value : 0;
				elseif($take > 1) $nextSuited = [0,0,0,0];
				$walk($index + 1, $remaining - $take, [...$chosen, ...array_fill(0, $take, $rank)], $ways * self::choose(count($available), $take), $nextSuited);
			}
		};
		$walk(0, $size, [], 1, [1,1,1,1]);
	}

	/**
	 * Pick real cards for a rank multiset: all in one flush suit when $suited, otherwise not all in one such suit.
	 *
	 * @param list<int> $ranks Ranks from 2 to 14, with repeats.
	 * @return list<int> Physical card IDs.
	 */
	private static function representative(array $ranks, array $pool, bool $suited, array $flushSuits): array
	{
		if($ranks === []) return [];
		$options = array_map(static fn(int $rank): array => $pool[$rank], $ranks);
		$search = function(int $index, array $cards, array $suits) use (&$search, $ranks, $options, $suited, $flushSuits): ?array {
			if($index === count($ranks))
			{
				$one = count(array_unique($suits)) === 1 && in_array($suits[0], $flushSuits, true);
				return $one === $suited ? $cards : null;
			}
			foreach($options[$index] as $suit)
			{
				$card = $suit * 13 + $ranks[$index] - 2;
				if(in_array($card, $cards, true)) continue;
				$found = $search($index + 1, [...$cards, $card], [...$suits, $suit]);
				if($found !== null) return $found;
			}
			return null;
		};
		$cards = $search(0, [], []);
		if($cards === null) throw new \LogicException('No representative hand for a counted pattern');
		return $cards;
	}

	/**
	 * Canonical starting hands for the DOS drawing table. Each lists held cards and discards in preference order,
	 * then the targets counted for it.
	 *
	 * @return list<array{string,list<array{list<int>,list<int>}>,array<string,callable>}>
	 */
	private static function startingHands(): array
	{
		$card = static fn(int $rank, int $suit): int => $suit * 13 + $rank - 2;
		$is = static fn(int $category): callable => static fn(array $hand): bool => $hand['category'] === $category;
		// Alternative ranks keep each shape when the preferred cards are wild: a pair of sevens becomes a pair of eights.
		$shift = static function(callable $build) use ($card): array {
			$choices = [];
			foreach([0,1,-1,2,-2,3,-3] as $offset) $choices[] = $build($offset, $card);
			return $choices;
		};
		return [
			['One ace', [[[$card(14,3)],[$card(13,0),$card(9,1),$card(6,2),$card(3,0)]], [[$card(14,3)],[$card(12,0),$card(10,1),$card(7,2),$card(4,0)]]],
				['Pair of aces or better' => static fn(array $hand): bool => $hand['category'] > 1 || ($hand['category'] === 1 && $hand['ranks'][0] === 14)]],
			['One pair', $shift(static function(int $o, callable $c): array {
				$discards = array_slice(array_values(array_diff([13,11,4,12,3,14], [7+$o])), 0, 3);
				return [[$c(7+$o,0),$c(7+$o,1)],[$c($discards[0],2),$c($discards[1],3),$c($discards[2],0)]];
			}),
				['Two pair' => $is(2),'Three of a kind' => $is(3),'Full house' => $is(6),'Four of a kind' => $is(7)]],
			['Two pairs', $shift(static fn(int $o, callable $c): array => [[$c(9+$o,0),$c(9+$o,1),$c(5,2),$c(5,3)],[$c(13,0)]]),
				['Full house' => $is(6)]],
			['Three of a kind', $shift(static fn(int $o, callable $c): array => [[$c(8+$o,0),$c(8+$o,1),$c(8+$o,2)],[$c(13,3),$c(4,0)]]),
				['Full house' => $is(6),'Four of a kind' => $is(7)]],
			['Four flush', $shift(static fn(int $o, callable $c): array => [[$c(4,2),$c(7,2),$c(10+$o%2,2),$c(13,2)],[$c(6,0)]]),
				['Flush' => $is(5)]],
			['Outside straight', $shift(static fn(int $o, callable $c): array => [[$c(5+$o,0),$c(6+$o,1),$c(7+$o,2),$c(8+$o,3)],[$c(13,0)]]),
				['Straight' => $is(4)]],
			['Inside straight', $shift(static fn(int $o, callable $c): array => [[$c(5+$o,0),$c(6+$o,1),$c(8+$o,2),$c(9+$o,3)],[$c(13,0)]]),
				['Straight' => $is(4)]],
		];
	}

	/** Binomial coefficient for small arguments. */
	private static function choose(int $n, int $k): int
	{
		if($k < 0 || $k > $n) return 0;
		$k = min($k, $n - $k);
		$result = 1;
		for($index = 1; $index <= $k; $index++) $result = intdiv($result * ($n - $k + $index), $index);
		return $result;
	}
}

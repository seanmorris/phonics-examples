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
		$pool = [];
		foreach(array_diff($deck, $wild) as $card) $pool[$card % 13 + 2][] = intdiv($card, 13);
		$rows = array_fill_keys(self::DEALT_ROWS, 0);
		// Many patterns share one shape and therefore one row, so the evaluator runs once per shape: a few dozen
		// times instead of once per pattern. Wild hands made each evaluation costly.
		$labels = [];
		for($wilds = 0; $wilds <= min(5, count($wild)); $wilds++)
		{
			$weight = self::choose(count($wild), $wilds);
			$wildCards = array_slice($wild, 0, $wilds);
			self::patterns($pool, 5 - $wilds, static function(array $ranks, int $ways, array $suited)
				use (&$rows, &$labels, $pool, $weight, $wildCards, $wild, $wilds): void {
				// With at most one natural card, every hand can be a flush.
				$flush = count($ranks) <= 1 ? $ways : array_sum($suited);
				foreach([[true, $flush],[false, $ways - $flush]] as [$suitedHand, $count])
				{
					if($count === 0) continue;
					$shape = self::shape($ranks, $suitedHand, $wilds);
					$labels[$shape] ??= self::label(Hand::evaluate(
						[...self::representative($ranks, $pool, $suitedHand, [0,1,2,3]), ...$wildCards], $wild
					));
					$rows[$labels[$shape]] += $count * $weight;
				}
			});
		}
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
		foreach(self::startingHands() as [$hand, $build, $targets])
		{
			$start = $build(new StartingHand($natural));
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

	/** The DEALT_ROWS label for one evaluation. */
	private static function label(array $evaluation): string
	{
		return $evaluation['category'] === 8 && $evaluation['name'] === 'Royal flush' ? 'Royal flush' : Hand::LABELS[$evaluation['category']];
	}

	/**
	 * Everything a hand's category depends on: how many natural cards share each rank, whether the naturals share a
	 * suit, how many wild cards fill the hand, and whether the distinct natural ranks fit one five-rank straight
	 * window, an ace-high one included. Hands with equal shapes always fall in the same DEALT_ROWS row.
	 *
	 * @param list<int> $ranks Natural ranks from 2 to 14, with repeats.
	 */
	private static function shape(array $ranks, bool $suited, int $wilds): string
	{
		$counts = array_count_values($ranks);
		$profile = array_values($counts);
		rsort($profile, SORT_NUMERIC);
		$distinct = count($counts) === count($ranks);
		$fits = false;
		if($distinct)
		{
			// The ace may sit below the two in a wheel window.
			for($low = 1; $low <= 10 && !$fits; $low++)
			{
				$fits = true;
				foreach($ranks as $rank) if(!(($rank >= $low && $rank <= $low + 4) || ($rank === 14 && $low === 1))) { $fits = false; break; }
			}
		}
		$royal = $distinct && min([...$ranks, 14]) >= 10;
		return implode('', $profile) . '|' . (int)$suited . '|' . $wilds . '|' . (int)$fits . '|' . (int)$royal;
	}

	/** @return array<string,int> Final hands per row label. */
	private static function outcomes(array $held, array $unseen, array $wild, int $draw): array
	{
		$rows = [];
		foreach(self::finals($held, $unseen, $draw, $wild) as [$evaluation, $count])
		{
			$label = self::label($evaluation);
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
		$heldRanks = array_map(static fn(int $card): int => $card % 13 + 2, $held);
		// One evaluation per final shape, keyed also by the rank of the largest group so callers can read a pair's
		// rank: with one wild and no natural pair the wild pairs the highest natural card.
		$evaluated = [];
		for($wilds = 0; $wilds <= min($draw, count($unseenWild)); $wilds++)
		{
			$weight = self::choose(count($unseenWild), $wilds);
			$wildCards = array_slice($unseenWild, 0, $wilds);
			self::patterns($pool, $draw - $wilds, static function(array $ranks, int $ways, array $suited)
				use (&$finals, &$evaluated, $held, $heldRanks, $pool, $flushSuits, $weight, $wildCards, $wild, $draw, $wilds): void {
				$flush = 0;
				foreach($flushSuits as $suit) $flush += $suited[$suit];
				// With at most one natural card in the whole hand, every hand can be a flush.
				if(count($held) + $draw - $wilds <= 1) $flush = $ways;
				foreach([[true, $flush],[false, $ways - $flush]] as [$suitedHand, $count])
				{
					if($count === 0) continue;
					$all = [...$heldRanks, ...$ranks];
					$counts = array_count_values($all);
					$top = $all === [] ? 0 : max(array_keys($counts, max($counts), true));
					$shape = self::shape($all, $suitedHand, $wilds) . '|' . $top;
					$evaluated[$shape] ??= Hand::evaluate([...$held, ...self::representative($ranks, $pool, $suitedHand, $flushSuits), ...$wildCards], $wild);
					$finals[] = [$evaluated[$shape], $count * $weight];
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
	 * Starting hands for the DOS drawing table. Each builder picks natural cards only, held and discarded alike,
	 * preferring the classic shapes: a lone ace, a pair of sevens, nines and fives, trip eights, a spread four flush
	 * and runs from five. Discards never pair the held cards or supply one of the draw's outs, so a standard deck
	 * gives the textbook counts. A builder returns null when the rules leave no natural hand of that shape.
	 *
	 * @return list<array{string,callable(StartingHand):?array{list<int>,list<int>},array<string,callable>}>
	 */
	private static function startingHands(): array
	{
		$is = static fn(int $category): callable => static fn(array $hand): bool => $hand['category'] === $category;
		$discardOrder = [13,11,9,6,4,12,3,10,8,5,14,7,2];
		return [
			['One ace', static function(StartingHand $pick) use ($discardOrder): ?array {
				$ace = $pick->card(14, [3,2,1,0]);
				// Discards off the ace's suit leave every card of that suit unseen, as the textbook figure assumes.
				return $ace === null ? null : $pick->finish([$ace], 4, [14], [13,9,6,3,12,10,7,4,11,8,5,2], intdiv($ace, 13));
			}, ['Pair of aces or better' => static fn(array $hand): bool => $hand['category'] > 1 || ($hand['category'] === 1 && $hand['ranks'][0] === 14)]],
			['One pair', static function(StartingHand $pick) use ($discardOrder): ?array {
				foreach([7,8,6,9,5,10,4,11,3,12,2,13,14] as $rank)
				{
					$pair = $pick->cards($rank, 2);
					if($pair !== null) return $pick->finish($pair, 3, [$rank], $discardOrder);
				}
				return null;
			}, ['Two pair' => $is(2),'Three of a kind' => $is(3),'Full house' => $is(6),'Four of a kind' => $is(7)]],
			['Two pairs', static function(StartingHand $pick) use ($discardOrder): ?array {
				foreach([9,10,8,11,7,12,6,13,14] as $high) foreach([5,4,6,3,7,2,8] as $low)
				{
					if($low >= $high) continue;
					$pick->reset();
					$first = $pick->cards($high, 2); $second = $first === null ? null : $pick->cards($low, 2);
					if($second !== null) return $pick->finish([...$first, ...$second], 1, [$high,$low], $discardOrder);
				}
				return null;
			}, ['Full house' => $is(6)]],
			['Three of a kind', static function(StartingHand $pick) use ($discardOrder): ?array {
				foreach([8,9,7,10,6,11,5,12,4,13,3,14,2] as $rank)
				{
					$pick->reset();
					$trips = $pick->cards($rank, 3);
					if($trips !== null) return $pick->finish($trips, 2, [$rank], $discardOrder);
				}
				return null;
			}, ['Full house' => $is(6),'Four of a kind' => $is(7)]],
			['Four flush', static function(StartingHand $pick) use ($discardOrder): ?array {
				// Ranks too spread for any five-rank window, so the draw cannot make a straight flush.
				foreach([2,0,1,3] as $suit) foreach([[4,7,10,13],[3,6,9,12],[5,8,11,14],[2,6,10,13],[3,7,11,14]] as $ranks)
				{
					$pick->reset();
					$flush = $pick->suited($ranks, $suit);
					if($flush !== null) return $pick->finish($flush, 1, $ranks, $discardOrder, $suit);
				}
				return null;
			}, ['Flush' => $is(5)]],
			['Outside straight', static function(StartingHand $pick) use ($discardOrder): ?array {
				foreach([5,6,4,7,3,8,9,2,10] as $low)
				{
					$pick->reset();
					$run = $pick->mixed([$low,$low+1,$low+2,$low+3]);
					// Both ends are open: an ace below a two-high run, or anything up to an ace above a ten-high one.
					$outs = [$low === 2 ? 14 : $low - 1, $low + 4];
					if($run !== null) return $pick->finish($run, 1, [$low,$low+1,$low+2,$low+3,...$outs], $discardOrder);
				}
				return null;
			}, ['Straight' => $is(4)]],
			['Inside straight', static function(StartingHand $pick) use ($discardOrder): ?array {
				foreach([5,6,4,7,3,8,9,2,10] as $low)
				{
					$pick->reset();
					$ranks = [$low,$low+1,$low+3,$low+4];
					$run = $pick->mixed($ranks);
					if($run !== null) return $pick->finish($run, 1, [...$ranks, $low+2], $discardOrder);
				}
				return null;
			}, ['Straight' => $is(4)]],
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

/** Picks distinct natural cards for one drawing-table starting hand. */
final class StartingHand
{
	/** @var array<int,true> Cards already placed in this hand, held or discarded. */
	private array $used = [];

	/** @param array<int,true> $natural Cards in the deck that are not wild. */
	public function __construct(private array $natural) {}

	/** Forget the cards picked for an abandoned arrangement. */
	public function reset(): void { $this->used = []; }

	/** One natural card of a rank, trying suits in order, or null. */
	public function card(int $rank, array $suits = [0,1,2,3]): ?int
	{
		foreach($suits as $suit)
		{
			$card = $suit * 13 + $rank - 2;
			if(isset($this->natural[$card]) && !isset($this->used[$card])) { $this->used[$card] = true; return $card; }
		}
		return null;
	}

	/** @return list<int>|null $count natural cards of one rank. */
	public function cards(int $rank, int $count): ?array
	{
		$cards = [];
		for($index = 0; $index < $count; $index++)
		{
			$card = $this->card($rank);
			if($card === null) return null;
			$cards[] = $card;
		}
		return $cards;
	}

	/** @return list<int>|null One natural card of each rank, all in one suit. */
	public function suited(array $ranks, int $suit): ?array
	{
		$cards = [];
		foreach($ranks as $rank) { $card = $this->card($rank, [$suit]); if($card === null) return null; $cards[] = $card; }
		return $cards;
	}

	/** @return list<int>|null One natural card of each rank, not all in one suit. */
	public function mixed(array $ranks): ?array
	{
		$cards = [];
		foreach($ranks as $index => $rank)
		{
			$card = $this->card($rank, array_map(static fn(int $step): int => ($index + $step) % 4, [0,1,2,3]));
			if($card === null) return null;
			$cards[] = $card;
		}
		return count(array_unique(array_map(static fn(int $card): int => intdiv($card, 13), $cards))) > 1 ? $cards : null;
	}

	/**
	 * Add $count natural discards of distinct ranks outside $avoid, off $avoidSuit when given.
	 *
	 * @return array{list<int>,list<int>}|null Held cards and discards.
	 */
	public function finish(array $held, int $count, array $avoid, array $order, ?int $avoidSuit = null): ?array
	{
		$discards = [];
		foreach($order as $rank)
		{
			if(count($discards) === $count) break;
			if(in_array($rank, $avoid, true)) continue;
			$suits = array_values(array_filter([3,0,1,2], static fn(int $suit): bool => $suit !== $avoidSuit));
			$card = $this->card($rank, array_map(static fn(int $step): int => $suits[(count($discards) + $step) % count($suits)], range(0, count($suits) - 1)));
			if($card !== null) $discards[] = $card;
		}
		return count($discards) === $count ? [$held, $discards] : null;
	}
}


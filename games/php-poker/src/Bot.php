<?php
declare(strict_types=1);
namespace PhpPoker;

use Phonics\Random\RandomStream;

/**
 * Bounded local opponents with distinct betting tendencies, for five-card draw, hold'em and stud.
 *
 * Decisions depend exclusively on an authorized seat view and an independent
 * random stream. There is no reference to the dealer, other hands or real stock.
 */
final class Bot
{
	/** Stable local opponent identities, artwork keys and bounded pressure offsets. */
	public const OPPONENTS = [
		['name'=>'Ada','icon'=>'ada','object'=>'Pineapple','pressure'=>-100,'style'=>'Patient'],
		['name'=>'Jules','icon'=>'jules','object'=>'Doughnut','pressure'=>100,'style'=>'Bold'],
		['name'=>'Mara','icon'=>'mara','object'=>'Apple','pressure'=>20,'style'=>'Balanced'],
		['name'=>'Otis','icon'=>'otis','object'=>'Mug','pressure'=>-50,'style'=>'Defensive'],
		['name'=>'Cleo','icon'=>'cleo','object'=>'Gem','pressure'=>150,'style'=>'Aggressive'],
		['name'=>'Felix','icon'=>'felix','object'=>'Ring','pressure'=>-20,'style'=>'Measured'],
		['name'=>'Nora','icon'=>'nora','object'=>'Shamrock','pressure'=>60,'style'=>'Opportunistic']
	];

	/**
	 * Choose one legal action from a redacted seat view.
	 * @param array $view State visible to the bot, with only its own concealed cards.
	 * @param int $seat Bot's seat ID.
	 * @param RandomStream $random Independent policy stream.
	 * @param int $assistance Built-in local easing, from zero to 600.
	 * @return array Validated intent shape for the rules reducer.
	 */
	public static function choose(array $view, int $seat, RandomStream $random, int $assistance = 0): array
	{
		if(($view['turn'] ?? null) !== $seat || $assistance < 0 || $assistance > 600)
			throw new \InvalidArgumentException('Invalid bot turn');
		if(($view['game'] ?? Variant::DEFAULT) !== Variant::DEFAULT) return self::street($view, $seat, $random, $assistance);
		$cards=$view['players'][$seat]['cards'];
		$wildCards=is_array($view['profile']['wildCards']??null) ? $view['profile']['wildCards']
			: WildCards::ids((array)($view['profile']['wild']??[]), (array)($view['profile']['customWild']??[]));
		$hand=Hand::evaluate($cards,$wildCards);
		$legal=$view['legalActions'];
		if($legal['draw'] ?? false) return ['type'=>'draw','cards'=>self::discards($cards,$wildCards)];
		$roll=$random->boundedInt(10000);
		$personality=$seat===0 ? 0 : self::OPPONENTS[$seat-1]['pressure'];
		$strength=[140,410,650,760,840,880,940,980,995,1000][$hand['category']]
			+ ($hand['ranks'][0]-8)*8;
		$active=count(array_filter($view['players'],static fn(array $p): bool => !$p['folded']));
		$confidence=$strength+$personality-intdiv($assistance,2)-max(0,$active-2)*15;
		$due=$legal['callAmount'] ?? 0;
		$odds=$due > 0 ? intdiv(1000*$due,max(1,$view['pot']+$due)) : 0;
		$raiseChance=max(0,min(8500,($confidence-300)*12));
		if(($legal['bet'] ?? false) && (($view['opener'] === null && $roll < 8000-intdiv($assistance*5,2))
			|| ($view['opener'] !== null && $roll < $raiseChance)))
		{
			$range=$legal['maxBet']-$legal['minBet'];
			$increment=$range ? $random->boundedInt($range+1) : 0;
			// Cautious personalities and local easing favor smaller legal pressure.
			if($personality < 0 || $assistance > 200) $increment=intdiv($increment,3);
			return ['type'=>'bet','amount'=>$legal['minBet']+$increment];
		}
		if($legal['call'] ?? false)
		{
			$threshold=max(700,min(9800,($confidence-$odds)*16+2500));
			return ['type'=>$roll < $threshold ? 'call' : 'fold'];
		}
		if($legal['check'] ?? false) return ['type'=>'check'];
		if($legal['pass'] ?? false) return ['type'=>'pass'];
		return ['type'=>'fold'];
	}

	/**
	 * Bet a street game from what the seat can see: its own cards, the board, and every exposed card.
	 *
	 * The estimate is on the same 0 to 1000 scale as draw's, so personalities, easing and pot odds work the same.
	 */
	private static function street(array $view, int $seat, RandomStream $random, int $assistance): array
	{
		$legal=$view['legalActions'];
		$roll=$random->boundedInt(10000);
		$personality=$seat===0 ? 0 : self::OPPONENTS[$seat-1]['pressure'];
		$strength=self::streetStrength($view, $seat);
		$active=count(array_filter($view['players'],static fn(array $p): bool => !$p['folded']));
		$confidence=$strength+$personality-intdiv($assistance,2)-max(0,$active-2)*15;
		$due=$legal['callAmount'] ?? 0;
		$odds=$due > 0 ? intdiv(1000*$due,max(1,$view['pot']+$due)) : 0;
		$raiseChance=max(0,min(8500,($confidence-380)*14));
		// A starting hand is mostly potential; once five cards are known a weak hand should usually let go.
		$known=count(array_filter($view['players'][$seat]['cards'],'is_int'))+count($view['board'] ?? []);
		$starting=$known < 5 || (($view['game'] ?? '')==='holdem' && ($view['board'] ?? [])===[]);
		if(($legal['bet'] ?? false) && $roll < $raiseChance)
		{
			$range=$legal['maxBet']-$legal['minBet'];
			$increment=$range ? $random->boundedInt($range+1) : 0;
			if($personality < 0 || $assistance > 200) $increment=intdiv($increment,3);
			return ['type'=>'bet','amount'=>$legal['minBet']+$increment];
		}
		if($legal['call'] ?? false)
		{
			// Unfinished hands are worth more than their category: a flatter curve than draw's keeps bots in for a card.
			$threshold=max(500,min(9800,($confidence-$odds)*12+($starting ? 4000 : 2000)));
			return ['type'=>$roll < $threshold ? 'call' : 'fold'];
		}
		if($legal['check'] ?? false) return ['type'=>'check'];
		return ['type'=>'fold'];
	}

	/**
	 * Estimate a street-game hand from 0 to 1000 using only cards the seat may see.
	 * @param array $view The seat's own view.
	 * @param int $seat The seat being estimated.
	 * @return int Higher is stronger; draw's made-hand table anchors the scale.
	 */
	public static function streetStrength(array $view, int $seat): int
	{
		$wild=WildCards::ids((array)($view['profile']['wild']??[]), (array)($view['profile']['customWild']??[]));
		$wildLookup=array_fill_keys($wild,true);
		$own=array_values(array_filter($view['players'][$seat]['cards'], 'is_int'));
		$board=array_values($view['board'] ?? []);
		$rules=Variant::of((string)$view['game']);
		$toCome=count($rules->streets())-1-(int)($view['streetIndex'] ?? 0);
		$table=[140,410,650,760,840,880,940,980,995,1000];
		$known=[...$own,...$board];
		if($board===[] && count($own)===2 && $view['game']==='holdem')
		{
			// Before the flop: pairs by rank, otherwise high cards with bonuses for suits and closeness.
			$ranks=array_map(static fn(int $c): int => isset($wildLookup[$c]) || $c >= 52 ? 14 : $c%13+2, $own);
			rsort($ranks);
			[$high,$low]=$ranks;
			if($high===$low) return 420+($high-2)*36;
			$suited=count($own)===2 && intdiv($own[0],13)===intdiv($own[1],13) && $own[0] < 52 && $own[1] < 52;
			$gap=$high-$low-1;
			return max(100,($high-2)*30+($low-2)*14+($suited ? 80 : 0)+([60,40,20][$gap] ?? 0));
		}
		if(count($known) >= 5)
		{
			$made=Hand::best($known,$wild);
			$strength=$table[$made['category']]+($made['ranks'][0]-8)*8;
		}
		else
		{
			// A stud start of two to four cards: an average start is a high card, so it scores well above a
			// finished high-card hand.
			$made=Hand::showing($known,$wild);
			$strength=[0=>300,1=>560,2=>720,3=>820,7=>980][$made['category']]+($made['ranks'][0]-8)*10;
		}
		if(count($board) >= 3)
		{
			// What the board makes on its own is everyone's: only the part of a hand above it counts.
			$shared=count($board) >= 5 ? Hand::best($board,$wild) : Hand::showing($board,$wild);
			// Matching the board's own hand at its top rank only plays kickers; a higher pair or set over it is real.
			if($made['category'] < $shared['category']
				|| ($made['category'] === $shared['category'] && $made['ranks'][0] === $shared['ranks'][0])) $strength=min($strength,320);
			elseif($made['category'] === $shared['category']) $strength=min($strength,$table[$made['category']]-60);
		}
		if($toCome > 0) $strength+=30*$toCome;
		if($toCome > 0 && $made['category'] < 4)
		{
			$natural=array_values(array_filter($known,static fn(int $c): bool => !isset($wildLookup[$c]) && $c < 52));
			$wilds=count($known)-count($natural);
			$suits=array_count_values(array_map(static fn(int $c): int => intdiv($c,13),$natural));
			if(($suits ? max($suits) : 0)+$wilds >= 4) $strength+=90;
			$ranks=array_unique(array_map(static fn(int $c): int => $c%13+2,$natural));
			if(in_array(14,$ranks,true)) $ranks[]=1;
			$best=0;
			for($top=5;$top<=14;$top++) $best=max($best,count(array_intersect($ranks,range($top-4,$top)))+$wilds);
			if($best >= 4) $strength+=60;
		}
		if(str_starts_with((string)$view['game'],'stud'))
		{
			// Weigh the strongest exposed hand still in against this one.
			$threat=null;
			foreach($view['players'] as $id=>$player)
			{
				if($id===$seat || $player['folded']) continue;
				$up=[];
				foreach($player['cards'] as $index=>$card) if(is_int($card) && ($player['faces'][$index] ?? 'down')==='up') $up[]=$card;
				if(!$up) continue;
				$shown=Hand::showing(array_slice($up,0,4),$wild);
				if($threat===null || $shown['score'] > $threat['score']) $threat=$shown;
			}
			// Both scores share the evaluator's encoding, so a showing can be compared with a hand directly.
			if($threat!==null && $threat['score'] > $made['score'])
				$strength-=100+150*max(0,$threat['category']-$made['category']);
		}
		return max(0,min(1000,$strength));
	}

	/**
	 * Select replacements without inspecting the actual remaining deck.
	 * @param array $cards Own five cards.
	 * @return array IDs to discard, preserving made hands and useful draws.
	 */
	public static function discards(array $cards, array $wildCards = []): array
	{
		$hand=Hand::evaluate($cards,$wildCards);
		$wild=array_fill_keys($wildCards,true);
		if(in_array($hand['category'],[4,5,6,8,9],true)) return [];
		$natural=array_values(array_filter($cards,static fn(int $card): bool=>!isset($wild[$card])));
		$wildHeld=array_values(array_filter($cards,static fn(int $card): bool=>isset($wild[$card])));
		if(in_array($hand['category'],[1,3,7],true))
		{
			$rank=$hand['ranks'][0];
			return array_values(array_filter($natural,static fn(int $card): bool=>$card%13+2!==$rank));
		}
		if($hand['category']===2)
		{
			$ranks=array_slice($hand['ranks'],0,2);
			return array_values(array_filter($natural,static fn(int $card): bool=>!in_array($card%13+2,$ranks,true)));
		}
		foreach(range(0,3) as $suit)
		{
			$keep=array_filter($natural,static fn(int $c): bool => intdiv($c,13)===$suit);
			if(count($keep)+count($wildHeld)>=4) return array_values(array_diff($natural,$keep));
		}
		for($high=14;$high>=5;$high--)
		{
			$ranks=$high===5 ? [14,2,3,4,5] : range($high-4,$high);
			$keep=array_filter($natural,static fn(int $c): bool => in_array($c%13+2,$ranks,true));
			if(count($keep)+count($wildHeld)>=4) return array_values(array_diff($natural,$keep));
		}
		$sorted=$natural;
		usort($sorted,static fn(int $a,int $b): int => ($b%13) <=> ($a%13));
		$keep=array_slice(array_filter($sorted,static fn(int $c): bool => $c%13+2>=11),0,2);
		return array_values(array_diff($cards,$keep));
	}
}

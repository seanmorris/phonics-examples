<?php
declare(strict_types=1);
namespace PhpPoker;

use Phonics\Random\RandomStream;

/**
 * Bounded five-card draw opponents with distinct betting tendencies.
 *
 * Decisions depend exclusively on an authorized seat view and an independent
 * random stream. There is no reference to the dealer, other hands or real stock.
 */
final class Bot
{
	/** Stable local opponent identities, artwork keys and bounded pressure offsets. */
	public const OPPONENTS = [
		['name'=>'Ada','icon'=>'ada','object'=>'Clock','pressure'=>-100,'style'=>'Patient'],
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

<?php
declare(strict_types=1);
namespace PhpPoker;

/** Canonical Bicycle-style wild-card presets and validated custom selections. */
final class WildCards
{
	public const OPTIONS = [
		'none' => 'None', 'joker' => 'Joker', 'deuces' => 'Deuces', 'one-eyed-jacks' => 'One-Eyed Jacks',
		'mustached-kings' => 'Mustached Kings', 'deuces-treys' => 'Deuces and Treys', 'kings' => 'Kings',
		'custom' => 'Custom'
	];

	/** @return list<string> Canonically ordered checked rules; an empty list means None. */
	public static function selection(string|array $presets): array
	{
		if(is_string($presets)) $presets = $presets === 'none' ? [] : [$presets];
		if(!array_is_list($presets)) throw new \InvalidArgumentException('Invalid wild-card options');
		foreach($presets as $preset)
			if(!is_string($preset) || !isset(self::OPTIONS[$preset])) throw new \InvalidArgumentException('Invalid wild-card option');
		if(in_array('none', $presets, true))
		{
			if(count($presets) !== 1) throw new \InvalidArgumentException('None cannot be combined with wild cards');
			return [];
		}
		$selected = array_fill_keys($presets, true);
		return array_values(array_filter(array_keys(self::OPTIONS), static fn(string $id): bool => $id !== 'none' && isset($selected[$id])));
	}

	/** @return list<int> Physical card IDs treated as wild; 52 and 53 are the two jokers. */
	public static function ids(string|array $presets, array $custom = []): array
	{
		$presets = self::selection($presets);
		if(!in_array('custom', $presets, true) && $custom !== [])
			throw new \InvalidArgumentException('Custom wild cards require the Custom option');
		$cards = in_array('custom', $presets, true) ? self::custom($custom) : [];
		foreach($presets as $preset) foreach(self::presetIds($preset) as $card) $cards[] = $card;
		$cards = array_values(array_unique($cards));
		sort($cards, SORT_NUMERIC);
		return $cards;
	}

	/** @return list<int> Cards contributed by one built-in checkbox. */
	private static function presetIds(string $preset): array
	{
		return match($preset) {
			'custom' => [],
			'none' => [],
			'joker' => [52,53],
			'deuces' => [0,13,26,39],
			'one-eyed-jacks' => [35,48], // J♥, J♠
			'mustached-kings' => [11,24,50], // K♣, K♦, K♠; K♥ is the only king without a mustache.
			'deuces-treys' => [0,1,13,14,26,27,39,40],
			'kings' => [11,24,37,50],
			default => throw new \InvalidArgumentException('Invalid wild-card option')
		};
	}

	/** @return list<int> Sorted, unique custom physical card IDs. */
	public static function custom(array $cards): array
	{
		if(!array_is_list($cards) || count($cards) > 54) throw new \InvalidArgumentException('Invalid custom wild cards');
		foreach($cards as $card)
			if(!is_int($card) || $card < 0 || $card > 53) throw new \InvalidArgumentException('Invalid custom wild card');
		$cards = array_values(array_unique($cards));
		sort($cards, SORT_NUMERIC);
		return $cards;
	}

	/** @return list<int> Complete physical deck for this rule. */
	public static function deck(string|array $presets, array $custom = []): array
	{
		$wild = self::ids($presets, $custom);
		$deck = range(0,51);
		foreach([52,53] as $joker) if(in_array($joker, $wild, true)) $deck[] = $joker;
		return $deck;
	}

	public static function label(string $preset): string
	{
		return self::OPTIONS[$preset] ?? throw new \InvalidArgumentException('Invalid wild-card option');
	}

	public static function selectionLabel(string|array $presets): string
	{
		$presets = self::selection($presets);
		if($presets === []) return 'None';
		if(count($presets) === 1) return self::label($presets[0]);
		return count($presets) . ' selections';
	}

	public static function cardLabel(int $card): string
	{
		if($card === 52 || $card === 53) return 'Joker ' . ($card - 51);
		if($card < 0 || $card > 51) throw new \InvalidArgumentException('Invalid card');
		return self::rankLabel($card) . ['♣','♦','♥','♠'][intdiv($card,13)];
	}

	/** Rank-only label for a card shown beneath an explicit suit heading. */
	public static function rankLabel(int $card): string
	{
		if($card === 52 || $card === 53) return 'J' . ($card - 51);
		if($card < 0 || $card > 51) throw new \InvalidArgumentException('Invalid card');
		return ['2','3','4','5','6','7','8','9','10','J','Q','K','A'][$card % 13];
	}
}

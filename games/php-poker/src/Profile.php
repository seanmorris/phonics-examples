<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * Immutable local table settings, including which game is dealt, and explicitly versioned adaptations.
 *
 * Documentary DOS rules are shared by both opening variants. Unverified DOS
 * defaults are named in the rules provenance; this profile is never an online
 * authorization or a claim to reproduce undocumented executable behavior.
 */
final class Profile
{
	public const VERSION = 'bicycle-inspired-local-v5';
	/** Last version without a game field; such saves are five-card draw. */
	public const DRAW_ONLY_VERSION = 'bicycle-inspired-local-v4';
	private const LEGACY_VERSION = 'bicycle-inspired-local-v1';
	private const SINGLE_WILD_VERSION = 'bicycle-inspired-local-v2';
	private const MULTI_WILD_VERSION = 'bicycle-inspired-local-v3';
	/** @var list<string> Checked wild-card rules; empty means none. */
	public readonly array $wild;

	/**
	 * Validate table settings.
	 * @param string $variant Opening rule for five-card draw: jackpots or anything.
	 * @param int $ante Dealer-only contribution per deal.
	 * @param int $minimum Smallest opening bet or raise increment.
	 * @param int $maximum Largest bet increment.
	 * @param int $raiseLimit Total number of raises after the first bet in each round.
	 * @param int|null $finalMinimum Smallest post-draw bet or raise increment; defaults to the first-round minimum.
	 * @param int|null $finalMaximum Largest post-draw bet increment; defaults to the first-round maximum.
	 * @param int|null $playerRaiseLimit Number of raises each player may make per round; defaults to the total cap.
	 * @param string $game Which game is dealt; one of Variant::ids().
	 */
	public function __construct(
		public readonly string $variant = 'jackpots', public readonly int $ante = 1,
		public readonly int $minimum = 2, public readonly int $maximum = 20, public readonly int $raiseLimit = 3,
		string|array $wild = [], public readonly array $customWild = [],
		?int $finalMinimum = null, ?int $finalMaximum = null, ?int $playerRaiseLimit = null,
		public readonly string $game = Variant::DEFAULT
	)
	{
		$this->finalMinimum = $finalMinimum ?? $minimum;
		$this->finalMaximum = $finalMaximum ?? $maximum;
		$this->playerRaiseLimit = $playerRaiseLimit ?? $raiseLimit;
		if(!in_array($game, Variant::ids(), true) || !in_array($variant, ['jackpots','anything'], true) || $ante < 0 || $ante > 100
			|| $minimum < 1 || $minimum > $maximum || $maximum > 100
			|| $this->finalMinimum < 1 || $this->finalMinimum > $this->finalMaximum || $this->finalMaximum > 100
			|| $raiseLimit < 0 || $raiseLimit > 10 || $this->playerRaiseLimit < 0 || $this->playerRaiseLimit > 10)
			throw new \InvalidArgumentException('Invalid poker settings');
		$this->wild = WildCards::selection($wild);
		WildCards::ids($this->wild, $customWild);
		if(in_array('custom', $this->wild, true) && $customWild !== WildCards::custom($customWild))
			throw new \InvalidArgumentException('Custom wild cards must be sorted and unique');
	}

	public readonly int $finalMinimum;
	public readonly int $finalMaximum;
	public readonly int $playerRaiseLimit;

	/** @return array Versioned local-only settings, without a rubber-banding switch. */
	public function snapshot(): array
	{
		return ['version'=>self::VERSION,'game'=>$this->game,'variant'=>$this->variant,'ante'=>$this->ante,
			'minimum'=>$this->minimum,'maximum'=>$this->maximum,
			'finalMinimum'=>$this->finalMinimum,'finalMaximum'=>$this->finalMaximum,
			'raiseLimit'=>$this->raiseLimit,'playerRaiseLimit'=>$this->playerRaiseLimit,
			'wild'=>$this->wild,'customWild'=>$this->customWild];
	}

	/** @return Variant The streets, forced bets and seat limit of this profile's game. */
	public function rules(): Variant { return Variant::of($this->game); }
	/** @return list<int> Physical cards acting as wild under this profile. */
	public function wildCards(): array { return WildCards::ids($this->wild, $this->customWild); }
	/** @return list<int> Physical cards in this profile's deck. */
	public function deck(): array { return WildCards::deck($this->wild, $this->customWild); }

	/**
	 * Restore a local profile, rejecting online or assistance capabilities.
	 * @param array $state Exact profile fields.
	 * @return self Validated settings.
	 */
	public static function restore(array $state): self
	{
		$version = $state['version'] ?? null;
		$legacy = $version === self::LEGACY_VERSION;
		$singleWild = $version === self::SINGLE_WILD_VERSION;
		$multiWild = $version === self::MULTI_WILD_VERSION;
		$drawOnly = $version === self::DRAW_ONLY_VERSION;
		$rounds = ['version','variant','ante','minimum','maximum','finalMinimum','finalMaximum','raiseLimit','playerRaiseLimit','wild','customWild'];
		$fields = $legacy ? ['version','variant','ante','minimum','maximum','raiseLimit']
			: (($singleWild || $multiWild) ? ['version','variant','ante','minimum','maximum','raiseLimit','wild','customWild']
				: ($drawOnly ? $rounds : ['version','game',...array_slice($rounds, 1)]));
		if(($version !== self::VERSION && !$drawOnly && !$legacy && !$singleWild && !$multiWild) || array_keys($state) !== $fields)
			throw new \InvalidArgumentException('Unsupported poker profile');
		$amounts = ['ante','minimum','maximum','raiseLimit'];
		if($version === self::VERSION || $drawOnly) array_push($amounts, 'finalMinimum', 'finalMaximum', 'playerRaiseLimit');
		if($version === self::VERSION && !is_string($state['game'] ?? null)) throw new \InvalidArgumentException('Invalid profile game');
		foreach($amounts as $key)
			if(!is_int($state[$key] ?? null)) throw new \InvalidArgumentException('Invalid profile amount');
		if(!is_string($state['variant'] ?? null)) throw new \InvalidArgumentException('Invalid profile variant');
		if(!$legacy && (!is_array($state['customWild'] ?? null)
			|| ($singleWild ? !is_string($state['wild'] ?? null) : !is_array($state['wild'] ?? null))))
			throw new \InvalidArgumentException('Invalid wild-card profile');
		if(!$legacy && !$singleWild && WildCards::selection($state['wild']) !== $state['wild'])
			throw new \InvalidArgumentException('Wild-card profile is not canonical');
		return new self($state['variant'],$state['ante'],$state['minimum'],$state['maximum'],$state['raiseLimit'],
			$legacy ? [] : $state['wild'], $legacy ? [] : $state['customWild'],
			$state['finalMinimum'] ?? $state['minimum'], $state['finalMaximum'] ?? $state['maximum'],
			$state['playerRaiseLimit'] ?? $state['raiseLimit'], $state['game'] ?? Variant::DEFAULT);
	}
}

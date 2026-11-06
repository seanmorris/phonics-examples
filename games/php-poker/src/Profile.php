<?php
declare(strict_types=1);
namespace PhpPoker;

/**
 * Immutable local five-card draw settings and explicitly versioned adaptations.
 *
 * Documentary DOS rules are shared by both opening variants. Unverified DOS
 * defaults are named in the rules provenance; this profile is never an online
 * authorization or a claim to reproduce undocumented executable behavior.
 */
final class Profile
{
	public const VERSION = 'bicycle-inspired-local-v3';
	private const LEGACY_VERSION = 'bicycle-inspired-local-v1';
	private const SINGLE_WILD_VERSION = 'bicycle-inspired-local-v2';
	/** @var list<string> Checked wild-card rules; empty means none. */
	public readonly array $wild;

	/**
	 * Validate table settings.
	 * @param string $variant Jackpots or anything.
	 * @param int $ante Dealer-only contribution per deal.
	 * @param int $minimum Smallest opening bet or raise increment.
	 * @param int $maximum Largest bet increment.
	 * @param int $raiseLimit Number of raises after the first bet in each round.
	 */
	public function __construct(
		public readonly string $variant = 'jackpots', public readonly int $ante = 1,
		public readonly int $minimum = 2, public readonly int $maximum = 20, public readonly int $raiseLimit = 3,
		string|array $wild = [], public readonly array $customWild = []
	)
	{
		if(!in_array($variant, ['jackpots','anything'], true) || $ante < 0 || $ante > 100
			|| $minimum < 1 || $minimum > $maximum || $maximum > 100 || $raiseLimit < 0 || $raiseLimit > 10)
			throw new \InvalidArgumentException('Invalid poker settings');
		$this->wild = WildCards::selection($wild);
		WildCards::ids($this->wild, $customWild);
		if(in_array('custom', $this->wild, true) && $customWild !== WildCards::custom($customWild))
			throw new \InvalidArgumentException('Custom wild cards must be sorted and unique');
	}

	/** @return array Versioned local-only settings, without a rubber-banding switch. */
	public function snapshot(): array
	{
		return ['version'=>self::VERSION,'variant'=>$this->variant,'ante'=>$this->ante,
			'minimum'=>$this->minimum,'maximum'=>$this->maximum,'raiseLimit'=>$this->raiseLimit,
			'wild'=>$this->wild,'customWild'=>$this->customWild];
	}

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
		$fields = $legacy ? ['version','variant','ante','minimum','maximum','raiseLimit']
			: ['version','variant','ante','minimum','maximum','raiseLimit','wild','customWild'];
		if(($version !== self::VERSION && !$legacy && !$singleWild) || array_keys($state) !== $fields)
			throw new \InvalidArgumentException('Unsupported poker profile');
		foreach(['ante','minimum','maximum','raiseLimit'] as $key)
			if(!is_int($state[$key] ?? null)) throw new \InvalidArgumentException('Invalid profile amount');
		if(!is_string($state['variant'] ?? null)) throw new \InvalidArgumentException('Invalid profile variant');
		if(!$legacy && (!is_array($state['customWild'] ?? null)
			|| ($singleWild ? !is_string($state['wild'] ?? null) : !is_array($state['wild'] ?? null))))
			throw new \InvalidArgumentException('Invalid wild-card profile');
		if(!$legacy && !$singleWild && WildCards::selection($state['wild']) !== $state['wild'])
			throw new \InvalidArgumentException('Wild-card profile is not canonical');
		return new self($state['variant'],$state['ante'],$state['minimum'],$state['maximum'],$state['raiseLimit'],
			$legacy ? [] : $state['wild'], $legacy ? [] : $state['customWild']);
	}
}

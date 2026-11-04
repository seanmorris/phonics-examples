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
	public const VERSION = 'bicycle-inspired-local-v1';

	/**
	 * Validate table settings.
	 * @param string $variant Jackpots or anything, both without wild cards.
	 * @param int $ante Dealer-only contribution per deal.
	 * @param int $minimum Smallest opening bet or raise increment.
	 * @param int $maximum Largest bet increment.
	 * @param int $raiseLimit Number of raises after the first bet in each round.
	 */
	public function __construct(
		public readonly string $variant = 'jackpots', public readonly int $ante = 1,
		public readonly int $minimum = 2, public readonly int $maximum = 20, public readonly int $raiseLimit = 3
	)
	{
		if(!in_array($variant, ['jackpots','anything'], true) || $ante < 0 || $ante > 100
			|| $minimum < 1 || $minimum > $maximum || $maximum > 100 || $raiseLimit < 0 || $raiseLimit > 10)
			throw new \InvalidArgumentException('Invalid poker settings');
	}

	/** @return array Versioned local-only settings, without a rubber-banding switch. */
	public function snapshot(): array
	{
		return ['version'=>self::VERSION,'variant'=>$this->variant,'ante'=>$this->ante,
			'minimum'=>$this->minimum,'maximum'=>$this->maximum,'raiseLimit'=>$this->raiseLimit];
	}

	/**
	 * Restore a local profile, rejecting online or assistance capabilities.
	 * @param array $state Exact profile fields.
	 * @return self Validated settings.
	 */
	public static function restore(array $state): self
	{
		if(($state['version'] ?? null) !== self::VERSION || array_diff(array_keys($state), ['version','variant','ante','minimum','maximum','raiseLimit']))
			throw new \InvalidArgumentException('Unsupported poker profile');
		foreach(['ante','minimum','maximum','raiseLimit'] as $key)
			if(!is_int($state[$key] ?? null)) throw new \InvalidArgumentException('Invalid profile amount');
		if(!is_string($state['variant'] ?? null)) throw new \InvalidArgumentException('Invalid profile variant');
		return new self($state['variant'],$state['ante'],$state['minimum'],$state['maximum'],$state['raiseLimit']);
	}
}

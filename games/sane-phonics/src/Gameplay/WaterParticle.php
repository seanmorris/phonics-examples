<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Effects\Lifetime;

/** Identifies one finite splash or body-bound skim animation in the ECS. */
final class WaterParticle extends Lifetime
{
	public const SPLASH = 'splash';
	public const SKIM = 'skim';

	/**
	 * Describe a particle.
	 *
	 * @param string $kind SPLASH or SKIM.
	 * @param float $lifetime Seconds before it retires, or zero to live until its owner removes it.
	 * @param int|null $body Entity the particle follows, when any.
	 * @return void Rejects an unknown kind, a negative or non-finite lifetime, or an invalid body id.
	 */
	public function __construct(
		public string $kind
		, public float $lifetime
		, public ?int $body = null
	){
		if(!in_array($kind, [self::SPLASH,self::SKIM], true)
			|| !is_finite($lifetime) || $lifetime < 0 || ($body !== null && $body < 1)
		){
			throw new \InvalidArgumentException('Invalid water particle');
		}

		parent::__construct($lifetime > 0 ? $lifetime : INF, clamp: false);
	}

	/**
	 * Age the particle by one update.
	 *
	 * @param float $dt Seconds elapsed.
	 * @return bool True once a finite lifetime has run out.
	 */
	public function step(float $dt): bool
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Water particle delta must be finite and nonnegative');
		}

		$this->advance($dt);

		return $this->expired();
	}
}

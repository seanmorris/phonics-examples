<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Identifies one finite splash or body-bound skim animation in the ECS. */
final class WaterParticle
{
	public const SPLASH = 'splash';
	public const SKIM = 'skim';
	public float $age = 0;

	/**
	 * Describe one water effect without coupling its lifecycle to the renderer.
	 *
	 * @param string $kind Splash or skim particle kind.
	 * @param float $lifetime Positive lifetime in seconds, or zero for a state-bound particle.
	 * @param int|null $body Optional source body entity retained for diagnostics.
	 * @return void Initializes a fresh effect at age zero.
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
	}

	/**
	 * Advance the particle lifetime.
	 *
	 * @param float $dt Finite nonnegative elapsed seconds.
	 * @return bool True when a finite particle has reached its lifetime.
	 */
	public function step(float $dt): bool
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Water particle delta must be finite and nonnegative');
		}

		$this->age += $dt;

		return $this->lifetime > 0 && $this->age >= $this->lifetime;
	}
}

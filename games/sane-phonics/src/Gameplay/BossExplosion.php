<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Short-lived visual emitted throughout a defeated boss's explosion sequence. */
final class BossExplosion
{
	public float $age = 0;

	/**
	 * Configure one boss explosion effect.
	 *
	 * @param float $lifetime Positive visible lifetime in seconds.
	 * @return void Initializes the effect at zero age.
	 */
	public function __construct(public float $lifetime = .512)
	{
		if(!is_finite($lifetime) || $lifetime <= 0)
		{
			throw new \InvalidArgumentException('Boss explosion lifetime must be finite and positive');
		}
	}
}

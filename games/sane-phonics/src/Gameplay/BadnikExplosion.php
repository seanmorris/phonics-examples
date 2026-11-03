<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Short-lived visual left on a badnik entity after its gameplay components are removed. */
final class BadnikExplosion
{
	public float $age = 0;

	/**
	 * Set the duration of a popped badnik's visual effect.
	 *
	 * @param float $lifetime Positive fade duration in seconds.
	 * @return void Initializes an explosion at age zero.
	 */
	public function __construct(public float $lifetime = .35)
	{
		if(!is_finite($lifetime) || $lifetime <= 0)
		{
			throw new \InvalidArgumentException('Explosion lifetime must be finite and positive');
		}
	}
}

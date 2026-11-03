<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Short-lived explosion rendered when an intact monitor breaks. */
final class MonitorExplosion
{
	public float $age = 0;

	/**
	 * Configure the pop effect's lifetime.
	 *
	 * @param float $lifetime Positive effect duration in seconds.
	 * @return void Initializes an explosion at age zero.
	 */
	public function __construct(public float $lifetime = .512)
	{
		if(!is_finite($lifetime) || $lifetime <= 0)
		{
			throw new \InvalidArgumentException('Monitor explosion lifetime must be finite and positive');
		}
	}
}

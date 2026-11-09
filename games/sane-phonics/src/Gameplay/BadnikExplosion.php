<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Effects\Lifetime;

/** Short-lived visual left on a badnik entity after its gameplay components are removed. */
final class BadnikExplosion extends Lifetime
{

	/**
	 * Start the explosion.
	 *
	 * @param float $duration Seconds the explosion lasts while it fades out.
	 * @return void Rejects a non-positive or non-finite duration.
	 */
	public function __construct(float $duration = .35)
	{
		if(!is_finite($duration) || $duration <= 0)
		{
			throw new \InvalidArgumentException('Explosion lifetime must be finite and positive');
		}

		parent::__construct($duration);
	}
}

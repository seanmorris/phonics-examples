<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Effects\Lifetime;

/** Short-lived visual emitted throughout a defeated boss's explosion sequence. */
final class BossExplosion extends Lifetime
{
	/**
	 * Start the explosion.
	 *
	 * @param float $duration Seconds the explosion lasts while it fades out.
	 * @return void Rejects a non-positive or non-finite duration.
	 */
	public function __construct(float $duration = .512)
	{
		if(!is_finite($duration) || $duration <= 0)
		{
			throw new \InvalidArgumentException('Boss explosion lifetime must be finite and positive');
		}

		parent::__construct($duration);
	}
}

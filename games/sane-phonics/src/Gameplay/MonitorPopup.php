<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Effects\Lifetime;

/** Timed screen icon released upward from a broken monitor. */
final class MonitorPopup extends Lifetime
{
	/**
	 * Start a popup at the monitor's screen.
	 *
	 * @param float $x Horizontal centre in world pixels.
	 * @param float $y Starting vertical centre in world pixels.
	 * @param float $rise Pixels the popup climbs over its rise.
	 * @param float $riseDuration Seconds the eased climb takes.
	 * @param float $fadeStart Seconds before the fade to transparent begins.
	 * @param float $duration Seconds the popup lasts in total.
	 * @return void Rejects non-finite values, a non-positive rise or climb, or a fade that starts at or after the end.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $rise = 32
		, public float $riseDuration = .25
		, float $fadeStart = .75
		, float $duration = 1
	){
		foreach([$x, $y, $rise, $riseDuration, $fadeStart, $duration] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Monitor popup state must be finite');
			}
		}

		if($rise <= 0 || $riseDuration <= 0 || $fadeStart < 0 || $duration <= $fadeStart)
		{
			throw new \InvalidArgumentException('Monitor popup timing and travel are invalid');
		}

		parent::__construct($duration, $fadeStart);
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Timed screen icon released upward from a broken monitor. */
final class MonitorPopup
{
	public float $age = 0;

	/**
	 * Configure the screen icon's rise, fade, and lifetime.
	 *
	 * @param float $x Initial world X coordinate in pixels.
	 * @param float $y Initial world Y coordinate in pixels.
	 * @param float $rise Positive upward travel in world pixels.
	 * @param float $riseDuration Positive duration of the initial rise in seconds.
	 * @param float $fadeStart Nonnegative age at which opacity begins decreasing.
	 * @param float $lifetime Positive retirement age greater than the fade start.
	 * @return void Initializes the popup at age zero.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $rise = 32
		, public float $riseDuration = .25
		, public float $fadeStart = .75
		, public float $lifetime = 1
	){
		foreach([$x, $y, $rise, $riseDuration, $fadeStart, $lifetime] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Monitor popup state must be finite');
			}
		}

		if($rise <= 0 || $riseDuration <= 0 || $fadeStart < 0 || $lifetime <= $fadeStart)
		{
			throw new \InvalidArgumentException('Monitor popup timing and travel are invalid');
		}
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Configurable surface-relative melee capability attached to a vehicle entity. */
final class VehicleMelee
{
	public float $active = 0, $remaining = 0;

	/**
	 * Configure a reusable timed melee capability.
	 *
	 * @param int $button Logical numbered button that begins the attack.
	 * @param float $duration Positive active duration in seconds.
	 * @param float $cooldown Positive interval between attack starts in seconds.
	 * @param float $reach Positive distance projected forward from the chassis edge.
	 * @param float $height Positive thickness normal to the supporting surface.
	 * @param float $normalOffset Nonnegative distance outward from the chassis support point.
	 * @return void Initializes inactive attack and cooldown timers.
	 */
	public function __construct(
		public int $button
		, public float $duration
		, public float $cooldown
		, public float $reach
		, public float $height
		, public float $normalOffset
	){
		foreach([$duration, $cooldown, $reach, $height, $normalOffset] as $value)
		if(!is_finite($value))
		throw new \InvalidArgumentException('Vehicle melee configuration must be finite');

		if($button < 0 || $duration <= 0 || $cooldown <= 0 || $reach <= 0 || $height <= 0
			|| $normalOffset < 0
		){
			throw new \InvalidArgumentException('Vehicle melee configuration is invalid');
		}
	}

	/**
	 * Report whether the hit volume is active during the current simulation endpoint.
	 *
	 * @return bool True while generic combat should test the configured volume.
	 */
	public function attacking(): bool { return $this->active > 1e-9; }
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Repeating surface-relative projectile emitter attached to a vehicle entity. */
final class VehicleEmitter
{
	public float $remaining = 0, $flash = 0;
	public int $shots = 0;

	/**
	 * Configure a reusable repeating projectile emitter.
	 *
	 * @param int $button Logical numbered button held to fire.
	 * @param float $interval Positive time between shots in seconds.
	 * @param float $speed Nonnegative launch speed in pixels per second.
	 * @param float $gravity Nonnegative projectile gravity in pixels per second squared.
	 * @param float $gravityDelay Nonnegative delay before projectile gravity begins.
	 * @param float $lifetime Positive projectile lifetime in seconds.
	 * @param float $radius Positive projectile collision radius.
	 * @param float $forwardOffset Signed muzzle offset along the facing tangent.
	 * @param float $normalOffset Signed muzzle offset outward from the support point.
	 * @param int|null $spriteGid Optional projectile artwork tile.
	 * @return void Initializes released timing, flash, and shot-count state.
	 */
	public function __construct(
		public int $button
		, public float $interval
		, public float $speed
		, public float $gravity
		, public float $gravityDelay
		, public float $lifetime
		, public float $radius
		, public float $forwardOffset
		, public float $normalOffset
		, public ?int $spriteGid = null
	){
		foreach([$interval, $speed, $gravity, $gravityDelay, $lifetime, $radius, $forwardOffset, $normalOffset]
			as $value
		) if(!is_finite($value)) throw new \InvalidArgumentException('Vehicle emitter configuration must be finite');

		if($button < 0 || $interval <= 0 || $speed < 0 || $gravity < 0 || $gravityDelay < 0
			|| $lifetime <= 0 || $radius <= 0 || ($spriteGid !== null && $spriteGid < 1)
		){
			throw new \InvalidArgumentException('Vehicle emitter configuration is invalid');
		}
	}

	/**
	 * Report whether recoil artwork should be visible during the current endpoint.
	 *
	 * @return bool True during the short generic firing flash.
	 */
	public function firing(): bool { return $this->flash > 1e-9; }
}

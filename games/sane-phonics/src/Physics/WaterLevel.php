<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;

/** Smoothly moves one water surface toward switch-selected world positions. */
final class WaterLevel
{
	public float $target;
	private float $bottom;

	/**
	 * Attach a level controller to one mutable region.
	 *
	 * @param WaterRegion $region Region whose fixed bottom and moving surface define the volume.
	 * @param float $fillSpeed Upward surface speed in pixels per second.
	 * @param float $drainSpeed Downward surface speed in pixels per second.
	 * @return void Retains the initial surface as the first target.
	 */
	public function __construct(
		public WaterRegion $region
		, public float $fillSpeed = 240
		, public float $drainSpeed = 480
	){
		$this->bottom = $region->y + $region->height;
		$this->target = $region->y;
		$this->validateSpeed($fillSpeed, 'fill');
		$this->validateSpeed($drainSpeed, 'drain');
	}

	/**
	 * Select a new surface and optional movement rates.
	 *
	 * @param float $target World-space surface Y above the region's fixed bottom.
	 * @param float|null $fillSpeed Optional upward speed override in pixels per second.
	 * @param float|null $drainSpeed Optional downward speed override in pixels per second.
	 * @return void Stores a target that subsequent update calls approach without overshoot.
	 */
	public function setTarget(
		float $target
		, ?float $fillSpeed = null
		, ?float $drainSpeed = null
	): void {
		if(!is_finite($target) || $target >= $this->bottom)
		{
			throw new \InvalidArgumentException('Water target must remain above its bottom');
		}

		if($fillSpeed !== null)
		{
			$this->validateSpeed($fillSpeed, 'fill');
			$this->fillSpeed = $fillSpeed;
		}

		if($drainSpeed !== null)
		{
			$this->validateSpeed($drainSpeed, 'drain');
			$this->drainSpeed = $drainSpeed;
		}

		$this->target = $target;
	}

	/**
	 * Advance the surface toward its target.
	 *
	 * @param float $dt Finite positive simulation delta in seconds.
	 * @return bool True when the surface moved during this update.
	 */
	public function update(float $dt): bool
	{
		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Water level delta must be positive');
		}

		$distance = $this->target - $this->region->y;

		if(abs($distance) < 1e-9)
		{
			return false;
		}

		$speed = $distance < 0 ? $this->fillSpeed : $this->drainSpeed;
		$step = min(abs($distance), $speed * $dt);
		$this->region->setSurface($this->region->y + ($distance <=> 0) * $step);

		return true;
	}

	/**
	 * Validate one configured surface speed.
	 *
	 * @param float $speed Candidate speed in pixels per second.
	 * @param string $name Property name included in validation errors.
	 * @return void Rejects nonfinite or nonpositive movement rates.
	 */
	private function validateSpeed(float $speed, string $name): void
	{
		if(!is_finite($speed) || $speed <= 0)
		{
			throw new \InvalidArgumentException("Water $name speed must be positive");
		}
	}
}

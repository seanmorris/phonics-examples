<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** Smooths upward first-person floor changes without delaying falls. */
final class CameraHeight
{
	private ?float $height = null;
	private ?float $target = null;

	public function __construct(
		public float $riseFraction = .25
		, public float $snapDistance = 4
	){
		if($riseFraction <= 0 || $riseFraction > 1)
		throw new \InvalidArgumentException('Camera rise fraction must be within (0, 1]');

		if($snapDistance < 0)
		throw new \InvalidArgumentException('Camera snap distance cannot be negative');
	}

	/** Follow a physical foot height, optionally snapping across a discontinuity or moving support. */
	public function follow(float $target, bool $smooth = true, bool $movingSupport = false): float
	{
		if($movingSupport) $smooth = false;

		if($this->height === null || !$smooth)
		{
			$this->target = $target;

			return $this->height = $target;
		}

		$descending = $this->target !== null && $target < $this->target;
		$this->target = $target;

		// A fall can begin before a previous stair rise has caught up. Hold the
		// rendered eye at that lower height until the physical eye passes it, then
		// follow downward directly; never continue an upward ease during the fall.
		if($descending) return $this->height = min($this->height, $target);

		$distance = $target - $this->height;

		if($distance > $this->snapDistance)
		$this->height += $distance * $this->riseFraction;
		else $this->height = $target;

		return $this->height;
	}
}

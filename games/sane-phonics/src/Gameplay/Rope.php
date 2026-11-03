<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Gravity-driven endpoint constrained to a maximum distance from an ECS anchor. */
final class Rope
{
	public float $vx = 0, $vy = 0;

	/**
	 * Configure one slack-capable rope in world pixels and seconds.
	 *
	 * @param int $anchor Entity containing the suspension point's RopeAnchor component.
	 * @param float $x World X coordinate at the hanging endpoint.
	 * @param float $y World Y coordinate at the hanging endpoint.
	 * @param float $length Positive maximum distance from the anchor to the endpoint.
	 * @param bool $noSwing Lock the endpoint horizontally beneath its anchor.
	 * @param float $gravity Nonnegative downward acceleration, defaulting to MB2's .6 px/frame².
	 * @param float $acceleration Nonnegative rider pumping acceleration, MB2's .25 px/frame².
	 * @return void Stores validated rope geometry and zero initial velocity.
	 */
	public function __construct(
		public int $anchor
		, public float $x
		, public float $y
		, public float $length = 64
		, public bool $noSwing = false
		, public float $gravity = 2160
		, public float $acceleration = 900
	){
		foreach([$x, $y, $length, $gravity, $acceleration] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Rope coordinates and tuning must be finite');
			}
		}

		if($anchor < 1 || $length <= 0 || $gravity < 0 || $acceleration < 0)
		{
			throw new \InvalidArgumentException('Invalid rope anchor, length, or acceleration');
		}
	}

	/**
	 * Propose a gravity and input step, removing outward motion when the rope becomes taut.
	 *
	 * The owner must sweep the proposed displacement against terrain before accepting it.
	 * Anchor-relative velocity preserves moving-anchor momentum, and inward motion permits slack.
	 *
	 * @param RopeAnchor $anchor Suspension pose interpolated to this substep.
	 * @param float $dt Positive finite substep duration in seconds.
	 * @param float $axis Rider input clamped to the range minus one through one.
	 * @return void Updates the proposed endpoint position and velocity without terrain collision.
	 */
	public function step(RopeAnchor $anchor, float $dt, float $axis = 0): void
	{
		if(!is_finite($dt) || $dt <= 0 || !is_finite($axis))
		{
			throw new \InvalidArgumentException('Rope steps require positive time and finite input');
		}

		if(!$this->noSwing)
		{
			$this->vx += max(-1, min(1, $axis)) * $this->acceleration * $dt
				* ($this->y >= $anchor->y ? 1 : -1);
		}

		$this->vy += $this->gravity * $dt;
		$this->x += $this->vx * $dt;
		$this->y += $this->vy * $dt;

		if($this->noSwing)
		{
			$this->x = $anchor->x;
			$this->vx = $anchor->vx;
		}

		$dx = $this->x - $anchor->x;
		$dy = $this->y - $anchor->y;
		$distance = hypot($dx, $dy);

		if($distance > $this->length)
		{
			$nx = $dx / $distance;
			$ny = $dy / $distance;
			$this->x = $anchor->x + $nx * $this->length;
			$this->y = $anchor->y + $ny * $this->length;
			$outward = ($this->vx - $anchor->vx) * $nx + ($this->vy - $anchor->vy) * $ny;

			if($outward > 0)
			{
				$this->vx -= $outward * $nx;
				$this->vy -= $outward * $ny;
			}
		}
	}
}

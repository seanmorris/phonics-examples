<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** A sampled, simply supported surface that bends vertically under one or more bodies. */
final class BendableBridge
{
	public ?int $shape = null;
	public int $entity = 0;
	/** @var list<float> Downward displacement at each surface sample. */
	public array $offsets;
	/** @var list<float> Vertical spring velocity at each surface sample. */
	public array $velocities;
	/** @var list<int> Body entity IDs contributing load during the latest update. */
	public array $riders = [];
	public int $segments;

	/**
	 * Define a horizontal flexible span whose endpoints remain fixed to neighboring terrain.
	 *
	 * @param float $x Left endpoint in world pixels.
	 * @param float $y Undeformed top surface in world pixels.
	 * @param float $width Positive span width in world pixels.
	 * @param float $height Positive visual depth below the collision surface.
	 * @param float $droop Peak displacement contributed by a centered body in pixels.
	 * @param float $segmentWidth Preferred maximum distance between collision samples.
	 * @param float $response Natural spring frequency in radians per second.
	 * @param int $maxLoads Maximum summed body loads before deformation is clamped.
	 * @param bool $grinding Whether contact puts a body into rail-grinding state.
	 * @param int $collisionLayer Shared plane zero or a positive collision plane.
	 * @param int $sourceId Optional Tiled object ID for scripting.
	 * @return void Creates a flat, unregistered bridge with fixed endpoints.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width
		, public float $height = 16
		, public float $droop = 32
		, float $segmentWidth = 16
		, public float $response = 14
		, public int $maxLoads = 4
		, public bool $grinding = false
		, public int $collisionLayer = 0
		, public int $sourceId = 0
	){
		foreach([$x, $y, $width, $height, $droop, $segmentWidth, $response] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Bridge values must be finite');
			}
		}

		if($width <= 0 || $height <= 0 || $droop < 0 || $segmentWidth <= 0 || $response <= 0
			|| $maxLoads < 1 || $collisionLayer < 0 || $sourceId < 0
		){
			throw new \InvalidArgumentException('Invalid bridge dimensions or settings');
		}

		$this->segments = max(4, (int)ceil($width / $segmentWidth));
		$this->offsets = $this->velocities = array_fill(0, $this->segments + 1, 0.0);
	}

	/**
	 * Return the horizontal interval between neighboring samples.
	 *
	 * @return float Exact sample spacing in world pixels.
	 */
	public function step(): float { return $this->width / $this->segments; }

	/**
	 * Build the current left-to-right collision polyline.
	 *
	 * @return list<array{float,float}> Sampled world-space surface with fixed endpoints.
	 */
	public function points(): array
	{
		$points = [];
		$step = $this->step();

		foreach($this->offsets as $index => $offset)
		{
			$points[] = [$this->x + $index * $step, $this->y + $offset];
		}

		return $points;
	}

	/**
	 * Interpolate the current surface height at a world X coordinate.
	 *
	 * @param float $x World X coordinate, clamped to the bridge span.
	 * @param list<float>|null $offsets Optional displacement snapshot to sample.
	 * @return float World Y coordinate on the sampled surface.
	 */
	public function surfaceY(float $x, ?array $offsets = null): float
	{
		$offsets ??= $this->offsets;
		$position = max(0, min($this->segments, ($x - $this->x) / $this->step()));
		$left = min($this->segments - 1, (int)floor($position));
		$amount = $position - $left;

		return $this->y + $offsets[$left] + ($offsets[$left + 1] - $offsets[$left]) * $amount;
	}

	/**
	 * Resolve the current tangent angle beneath a world X coordinate.
	 *
	 * @param float $x World X coordinate, clamped to a surface segment.
	 * @return float Clockwise world-space tangent angle in radians.
	 */
	public function angle(float $x): float
	{
		$position = max(0, min($this->segments - 1, ($x - $this->x) / $this->step()));
		$index = (int)floor($position);

		return atan2($this->offsets[$index + 1] - $this->offsets[$index], $this->step());
	}

	/**
	 * Calculate summed smooth deflection targets for body contact positions.
	 *
	 * Each load uses a zero-slope curve at both fixed endpoints. Contributions add so separate
	 * actors produce separate low points, then clamp at the configured load capacity.
	 *
	 * @param list<float> $positions Rider world X coordinates within the span.
	 * @return list<float> Downward target displacement for every surface sample.
	 */
	public function targets(array $positions): array
	{
		$targets = array_fill(0, $this->segments + 1, 0.0);

		foreach($positions as $x)
		{
			$rider = max(0, min(1, ($x - $this->x) / $this->width));
			$edge = 4 * $rider * (1 - $rider);

			for($i = 2; $i < $this->segments - 1; $i++)
			{
				$sample = $i / $this->segments;
				$ratio = $sample <= $rider
					? $sample / max(1e-9, $rider)
					: (1 - $sample) / max(1e-9, 1 - $rider);

				$ratio = max(0, min(1, $ratio));
				$smooth = $ratio * $ratio * (3 - 2 * $ratio);
				$targets[$i] += $this->droop * $edge * $smooth;
			}
		}

		$limit = $this->droop * $this->maxLoads;

		for($i = 2; $i < $this->segments - 1; $i++)
		{
			$targets[$i] = min($limit, $targets[$i]);
		}

		return $targets;
	}
}

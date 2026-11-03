<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;

/** A convex, nonsolid gate selecting a directional route through overlapping terrain. */
final class LayerSwitch
{

	/**
	 * Define a convex nonsolid region that selects a directional collision route.
	 *
	 * @param list<array{0:float|int,1:float|int}> $points Convex gate vertices in world pixels; at
	 * least three noncollinear points.
	 * @param int $fromLayer Positive source layer for forward travel through a normal gate.
	 * @param int $toLayer Distinct positive destination layer for forward travel through a normal
	 * gate.
	 * @param bool $back Reverse routing while grounded, as required at a loop crown.
	 * @param bool $roll Require rolling with a body height of at most 28 pixels.
	 * @return void Validates the region and normalizes its winding.
	 */
	public function __construct(
		public array $points
		, public int $fromLayer = 1
		, public int $toLayer = 2
		, public bool $back = false
		, public bool $roll = false
	){
		$count = count($points);

		if($count < 3 || $fromLayer < 1 || $toLayer < 1 || $fromLayer === $toLayer)
		{
			throw new \InvalidArgumentException(
				'A layer switch needs a convex region and two distinct positive layers'
			);
		}

		$area = 0;

		for($i = 0; $i < $count; $i++)
		{
			[$x,$y] = $points[$i];
			[$u,$v] = $points[($i + 1) % $count];
			$area += $x * $v - $u * $y;
		}

		if(abs($area) < 1e-8)
		{
			throw new \InvalidArgumentException('Empty layer switch');
		}

		if($area < 0)
		{
			$this->points = array_reverse($points);
		}

		for($i = 0; $i < $count; $i++)
		{
			[$x,$y] = $this->points[$i];
			[$u,$v] = $this->points[($i + 1) % $count];
			[$a,$b] = $this->points[($i + 2) % $count];

			if(($u - $x) * ($b - $v) - ($v - $y) * ($a - $u) < -1e-8)
			{
				throw new \InvalidArgumentException('Layer switches must be convex');
			}
		}
	}

	/**
	 * Choose an eligible destination using tangent speed, airborne velocity, or movement direction.
	 *
	 * @param Body $body Actor whose collision layer and movement state determine the route.
	 * @return int|null Destination layer, or null if direction, source layer, or rolling
	 * requirements prevent switching.
	 */
	public function target(Body $body): ?int
	{
		if($this->roll && (!$body->rolling || $body->halfHeight * 2 > 28))
		{
			return null;
		}

		$speed = ($body->grounded?$body->groundSpeed:0) ?: ($body->vx ?: $body->direction);

		if(!$speed)
		{
			return null;
		}

		$back = $this->back && $body->grounded;
		$forward = ($speed > 0) !== $back;
		$source = $forward?$this->fromLayer:$this->toLayer;
		$target = $forward?$this->toLayer:$this->fromLayer;

		return $body->collisionLayer === $source || $body->collisionLayer === 0?$target:null;
	}

	/**
	 * Clip the complete body-center displacement against the convex gate.
	 *
	 * @param float $x Starting world X coordinate in pixels.
	 * @param float $y Starting world Y coordinate in pixels.
	 * @param float $dx Full horizontal displacement in pixels.
	 * @param float $dy Full vertical displacement in pixels.
	 * @return float|null Entry fraction in [0,1], zero when already inside, or null when the
	 * segment misses.
	 */
	public function crossing(float $x, float $y, float $dx, float $dy): ?float
	{
		$near = 0;
		$far = 1;
		$count = count($this->points);

		for($i = 0; $i < $count; $i++)
		{
			[$ax,$ay] = $this->points[$i];
			[$bx,$by] = $this->points[($i + 1) % $count];
			$nx = $by - $ay;
			$ny = $ax - $bx;
			$distance = ($x - $ax) * $nx + ($y - $ay) * $ny;
			$velocity = $dx * $nx + $dy * $ny;

			if(abs($velocity) < 1e-10)
			{
				if($distance > 1e-8)
				{
					return null;
				}

				continue;
			}

			$t = -$distance / $velocity;

			if($velocity < 0)
			{
				$near = max($near, $t);
			}
			else
			{
				$far = min($far, $t);
			}

			if($near > $far + 1e-9)
			{
				return null;
			}
		}

		return $near;
	}
}

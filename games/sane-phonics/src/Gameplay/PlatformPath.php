<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Fixed-speed linked route with terminal nodes, cyclic tails, and optional rider activation. */
final class PlatformPath
{
	public float $x, $y;
	public int $target = 1;
	public bool $finished = false;

	/**
	 * Define an ordered route with an optional link from the last node to an earlier node.
	 *
	 * @param list<array{float,float}> $points Bottom-anchor coordinates in world pixels.
	 * @param float $speed Nonnegative speed in pixels per second.
	 * @param int|null $loop Index to visit after the last point, or null to stop at the end.
	 * @param bool $rideOnly Whether the route advances only while carrying a rider.
	 * @return void Places the route cursor at its first point and validates every link.
	 */
	public function __construct(
		public array $points
		, public float $speed = 480
		, public ?int $loop = null
		, public bool $rideOnly = false
	){
		if(!$points || array_keys($points) !== range(0, count($points) - 1)
			|| !is_finite($speed) || $speed < 0
			|| ($loop !== null && ($loop < 0 || $loop >= count($points)))
		){
			throw new \InvalidArgumentException('Invalid platform route');
		}

		foreach($points as $point)
		{
			if(!is_array($point) || count($point) !== 2 || !isset($point[0], $point[1])
				|| !is_numeric($point[0]) || !is_numeric($point[1])
				|| !is_finite((float)$point[0]) || !is_finite((float)$point[1])
			){
				throw new \InvalidArgumentException('Path points must contain two finite coordinates');
			}
		}

		[$this->x, $this->y] = $points[0];
	}

	/**
	 * Spend this step's distance through successive nodes without cutting across corners.
	 *
	 * @param float $dt Finite positive simulation delta in seconds.
	 * @param bool $ridden Whether a grounded body currently occupies the platform.
	 * @param callable(float,float):float $move Moves to a bottom anchor and returns the accepted fraction.
	 * @return void Advances the cursor by actual movement, stopping at an obstruction or terminal node.
	 */
	public function advance(float $dt, bool $ridden, callable $move): void
	{
		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Path delta must be positive');
		}

		if($this->finished || ($this->rideOnly && !$ridden))
		{
			return;
		}

		$distance = $this->speed * $dt;
		$zeroLength = [];

		while($distance > 1e-9)
		{
			if($this->target >= count($this->points))
			{
				if($this->loop === null)
				{
					$this->finished = true;

					return;
				}

				$this->target = $this->loop;
			}

			[$x, $y] = $this->points[$this->target];
			$length = hypot($x - $this->x, $y - $this->y);

			if($length <= 1e-9)
			{
				if(isset($zeroLength[$this->target]))
				{
					return;
				}

				$zeroLength[$this->target++] = true;
				continue;
			}

			$zeroLength = [];
			$step = min($distance, $length);
			$dx = ($x - $this->x) * $step / $length;
			$dy = ($y - $this->y) * $step / $length;
			$fraction = $move($this->x + $dx, $this->y + $dy);
			$this->x += $dx * $fraction;
			$this->y += $dy * $fraction;

			if($fraction < 1)
			{
				return;
			}

			$distance -= $step;

			if($step >= $length)
			{
				$this->target++;
				$this->finished = $this->target >= count($this->points) && $this->loop === null;
			}
		}
	}
}

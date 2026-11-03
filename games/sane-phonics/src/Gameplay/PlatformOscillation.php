<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Reference Block motion: squine on one axis, an elliptical sine/cosine pair on two. */
final class PlatformOscillation
{
	public float $time = 0;

	/**
	 * Configure periodic motion in seconds and world pixels.
	 *
	 * @param float $x Signed horizontal excursion from the platform's origin.
	 * @param float $y Signed vertical excursion from the platform's origin.
	 * @param float $period Positive single-axis cycle duration; the reference uses 90*pi frames.
	 * @param float $timeX Positive sine divisor in seconds for two-axis motion.
	 * @param float $timeY Positive cosine divisor in seconds for two-axis motion.
	 * @param float $phase Signed phase offset in seconds.
	 * @return void Initializes the oscillator at time zero.
	 */
	public function __construct(
		public float $x = 0
		, public float $y = 0
		, public float $period = M_PI * 1.5
		, public float $timeX = 1.5
		, public float $timeY = 1.5
		, public float $phase = 0
	){
		foreach([$x, $y, $period, $timeX, $timeY, $phase] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Oscillator values must be finite');
			}
		}

		if($period <= 0 || $timeX <= 0 || $timeY <= 0)
		{
			throw new \InvalidArgumentException('Oscillator times must be positive');
		}
	}

	/**
	 * Evaluate the current displacement without changing the clock.
	 *
	 * @return array{float,float} Horizontal and vertical offsets from the original bottom anchor.
	 */
	public function offset(): array
	{
		$time = $this->time + $this->phase;

		if($this->x != 0 && $this->y != 0)
		{
			return [-sin($time / $this->timeX) * $this->x, cos($time / $this->timeY) * $this->y];
		}

		// The flattened endpoints are the reference squine curve, including its pixel quantization.
		$value = cos(sin($time * M_PI / $this->period) ** 5) ** (5 * 3.333);

		return [-floor($value * $this->x + .5), -floor($value * $this->y + .5)];
	}
}

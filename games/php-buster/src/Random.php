<?php
declare(strict_types=1);
namespace PhpBuster;

/** Portable Park-Miller generator used to keep wave construction reproducible on wasm32. */
final class Random
{
	private int $state;

	/**
	 * Seed the portable Park-Miller sequence.
	 *
	 * @param int $seed Positive value below the generator modulus.
	 * @return void The generator stores the validated seed.
	 */
	public function __construct(int $seed)
	{
		if($seed < 1 || $seed >= 2147483647)
		{
			throw new \InvalidArgumentException('Seed must be between 1 and 2147483646');
		}

		$this->state = $seed;
	}

	/**
	 * Return the next positive value without overflowing a signed 32-bit intermediate.
	 *
	 * @return int The next reproducible Park-Miller value.
	 */
	public function next(): int
	{
		$high = intdiv($this->state, 127773);
		$low = $this->state % 127773;
		$value = 16807 * $low - 2836 * $high;
		$this->state = $value > 0 ? $value : $value + 2147483647;

		return $this->state;
	}

	/**
	 * Return an integer in the half-open interval from zero to the supplied bound.
	 *
	 * @param int $bound Exclusive positive upper bound.
	 * @return int A reproducible value from zero through bound minus one.
	 */
	public function int(int $bound): int
	{
		if($bound < 1) throw new \InvalidArgumentException('Random bound must be positive');

		return $this->next() % $bound;
	}
}

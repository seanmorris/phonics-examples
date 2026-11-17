<?php
declare(strict_types=1);
namespace Rpsc;

/** Portable Park-Miller generator, so a seed gives the same placements and bot choices on every runtime, including wasm32. */
final class Random
{
	private int $state;

	/**
	 * Seed the portable Park-Miller sequence.
	 *
	 * @param int $seed Any integer; it is folded into the generator's range of 1 to 2147483646.
	 * @return void The generator stores the folded seed.
	 */
	public function __construct(int $seed)
	{
		$this->state = self::fold($seed);
	}

	/**
	 * Fold any integer into a valid generator state.
	 *
	 * @param int $seed Any integer, including zero and negative values.
	 * @return int A value from 1 to 2147483646.
	 */
	public static function fold(int $seed): int
	{
		$seed %= 2147483646;

		return ($seed < 0 ? $seed + 2147483646 : $seed) + 1;
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

	/**
	 * Return a fraction in the half-open interval from zero to one.
	 *
	 * @return float A reproducible value from 0 up to, but not including, 1.
	 */
	public function unit(): float
	{
		return ($this->next() - 1) / 2147483646;
	}

	/**
	 * Shuffle a list with the Fisher-Yates walk.
	 *
	 * @param list<mixed> $values Values to reorder.
	 * @return list<mixed> The same values in a reproducible random order.
	 */
	public function shuffle(array $values): array
	{
		$values = array_values($values);

		for($index = count($values) - 1; $index > 0; $index--)
		{
			$other = $this->int($index + 1);
			[$values[$index], $values[$other]] = [$values[$other], $values[$index]];
		}

		return $values;
	}

	/**
	 * Read the generator state for a saved game.
	 *
	 * @return int The current state, a valid seed for a new generator.
	 */
	public function state(): int
	{
		return $this->state;
	}
}

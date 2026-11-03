<?php
declare(strict_types=1);
namespace PhpSlalom;

/** Java-compatible 32-bit generator used by the original course. */
final class Random
{
	private int $high = 0;
	private int $low = 0;

	/**
	 * Initialize the reproducible course sequence.
	 *
	 * @param int $seed Nonnegative 31-bit run seed.
	 * @return void Splits the seed into arithmetic that is safe on 32-bit PHP.
	 */
	public function __construct(int $seed) { $this->setSeed($seed); }

	/**
	 * Reset the wrapped state without a 64-bit integer or floating-point mask.
	 *
	 * @param int $seed Nonnegative 31-bit run seed.
	 * @return void Restores the exact two-limb generator state.
	 */
	public function setSeed(int $seed): void
	{
		if($seed < 0 || $seed > 2147483647)
		throw new \InvalidArgumentException('Slalom seed must be a nonnegative 31-bit integer');

		$this->low = $seed % 65536;
		$this->high = intdiv($seed, 65536);
	}

	/**
	 * Return the same unsigned upper sixteen bits as RandomGenerator.nextInt().
	 *
	 * @return int Value from zero through 65,535 after one wrapped recurrence.
	 */
	public function next(): int
	{
		// 1,593,227 is 0x00184f8b. Both intermediate values remain below
		// PHP_INT_MAX on 32-bit builds, including the browser runtime.
		$lower = $this->low * 20363 + 13;
		$carry = intdiv($lower, 65536);
		$this->high = ($this->low * 24 + $this->high * 20363 + $carry) % 65536;
		$this->low = $lower % 65536;

		return $this->high;
	}
}

<?php
declare(strict_types=1);
namespace PhpTris;

/** Park–Miller with Schrage reduction: identical on native and 32-bit WASM PHP. */
final class Random
{

	/**
	 * Initialize the component and its owned resources.
	 *
	 * @param int $state Initial deterministic generator state.
	 * @return void Updates owned state without returning a value.
	 */
	public function __construct(public int $state = 1)
	{
		$this->state = max(1, $state % 2147483647);
	}

	/**
	 * Advance the deterministic random stream within an exclusive upper bound.
	 *
	 * @param int $limit Exclusive upper bound.
	 * @return int Computed count, position, or deterministic value.
	 */
	public function next(int $limit): int
	{
		$next = 16807 * ($this->state % 127773) - 2836 * intdiv($this->state, 127773);
		$this->state = $next > 0 ? $next : $next + 2147483647;

		return $this->state % $limit;
	}

	/**
	 * Shuffle a copy using the deterministic Fisher-Yates algorithm.
	 *
	 * @param array $items Values to shuffle.
	 * @return array Computed values in iteration order.
	 */
	public function shuffle(array $items): array
	{
		for($i = count($items) - 1; $i > 0; $i--)
		{
			$j = $this->next($i + 1);
			[$items[$i], $items[$j]] = [$items[$j], $items[$i]];
		}

		return $items;
	}
}

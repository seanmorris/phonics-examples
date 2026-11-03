<?php
declare(strict_types=1);
namespace PhpNoid\Component;

/** Buffered pointer state; keyboard and controller input remain in core Controls. */
final class Pointer
{
	public ?float $x = null;
	public bool $held = false, $tap = false;
	public int $generation = -1;

	/**
	 * Discard pointer activity across focus, pause, loading, and stage changes.
	 * @return void Clears movement and fire state.
	 */
	public function reset(): void
	{
		$this->x = null;
		$this->held = $this->tap = false;
	}
}

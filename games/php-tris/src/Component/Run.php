<?php
declare(strict_types=1);
namespace PhpTris\Component;

/** Session and resolution state; board data and input are separate components. */
final class Run
{
	public string $phase = 'countdown', $spin = '', $notice = 'READY';
	public int $level = 1, $score = 0, $lines = 0, $goal = 0, $wave = 0, $combo = -1, $seed = 1, $placed = 0;
	public bool $backToBack = false, $avalanche = false, $cascade = false, $expired = false, $ghost = true;
	public float $elapsed = 0, $delay = .75;
	public array $rows = [], $events = [], $catalog = [], $motions = [];
	public int $lineTarget = 40, $duration = 120, $lastClear = 0;

	/**
	 * Initialize the component and its owned resources.
	 *
	 * @param string $mode Selected rule track identifier.
	 * @return void Updates owned state without returning a value.
	 */
	public function __construct(public string $mode = 'endless') {}

	/**
	 * Identify modes with a fifteen-rank objective track.
	 *
	 * @return bool Whether the operation or predicate succeeded.
	 */
	public function challenge(): bool { return !in_array($this->mode, ['endless','race','time'], true); }

	/**
	 * Calculate the current rank objective for this mode.
	 *
	 * @return int Computed count, position, or deterministic value.
	 */
	public function target(): int
	{
		return match($this->mode)
		{
			'squares' => 10 * $this->level, 'reactor' => 2 * $this->level,
			'bonds' => 1, default => 5 * $this->level
		};
	}
}

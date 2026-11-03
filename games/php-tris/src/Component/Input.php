<?php
declare(strict_types=1);
namespace PhpTris\Component;

/** Sampled actions and simulation-clock repeat timers, independent of OS keyboard repeat. */
final class Input
{
	public array $held = [], $previous = [], $timers = [], $blocked = [], $pending = [];

	/**
	 * Quarantine currently held inputs until release.
	 *
	 * @return void Updates owned state without returning a value.
	 */
	public function clear(): void
	{
		$this->blocked = array_filter($this->held);
		$this->previous = $this->timers = $this->pending = [];
	}

	/**
	 * Retain short rendered-frame taps until a simulation tick consumes them.
	 *
	 * @param array $held Logical buttons sampled from keyboard, controller, and touch.
	 * @return void Buffers new presses and updates held state.
	 */
	public function sample(array $held): void
	{
		foreach($held as $key => $down)
		if($down && empty($this->held[$key])) $this->pending[$key] = true;
		$this->held = $held;
	}

	/**
	 * Derive logical actions using edge detection and independent repeat clocks.
	 *
	 * @param float $dt Fixed simulation step in seconds.
	 * @return array Computed values in iteration order.
	 */
	public function presses(float $dt): array
	{
		$actions = [];
		$effective = $this->held;

		foreach($this->pending as $key => $down) $effective[$key] = $down;

		foreach(['left','right','soft','cw','ccw','drop','hold'] as $key)
		{
			$down = !empty($effective[$key]);

			if(!$down) unset($this->blocked[$key]);

			if(!$down || isset($this->blocked[$key]))
			{
				unset($this->timers[$key]);
				continue;
			}

			if(empty($this->previous[$key]))
			{
				$actions[$key] = true;
				$this->timers[$key] = .166666667;
			}
			elseif(in_array($key, ['left','right','cw','ccw'], true))
			{
				$this->timers[$key] -= $dt;

				if($this->timers[$key] <= 0.000001)
				{
					$actions[$key] = true;
					$this->timers[$key] += in_array($key, ['left','right'], true) ? 1 / 30 : .1;
				}
			}
		}

		$this->previous = $effective;
		$this->pending = [];

		return $actions;
	}
}

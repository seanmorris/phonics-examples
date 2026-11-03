<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Per-player ring target and reference-frame timers for the runner's light-dash. */
final class LightDash
{
	public ?int $target = null;
	public float $remaining = 0, $cooldown = 0;
	public bool $collected = false;
	public ?ComboReward $reward = null;

	/**
	 * Discard a route, its timers, and its retained combo reward.
	 *
	 * @return void Restores newly created component state without changing body momentum.
	 */
	public function reset(): void
	{
		$this->target = null;
		$this->remaining = $this->cooldown = 0;
		$this->collected = false;
		$this->reward = null;
	}
}

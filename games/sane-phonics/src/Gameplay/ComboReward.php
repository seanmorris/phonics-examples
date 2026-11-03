<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** One named score contribution retained while a movement combo remains active. */
final class ComboReward
{

	/**
	 * Describe one combo action and its contribution to the final score.
	 *
	 * @param string $label Nonempty user-facing action name.
	 * @param int $points Nonnegative base points, which may grow while the action continues.
	 * @param float $multiplier Finite positive multiplier added to the chain multiplier.
	 * @param string $color Optional application-defined display color name.
	 * @param bool $special Whether this reward should display even when it is the only chain item.
	 * @return void Stores validated mutable reward state.
	 */
	public function __construct(
		public string $label
		, public int $points
		, public float $multiplier = 1
		, public string $color = ''
		, public bool $special = false
	){
		if($label === '' || $points < 0 || !is_finite($multiplier) || $multiplier <= 0)
		{
			throw new \InvalidArgumentException('Combo rewards require a label, points, and a positive multiplier');
		}
	}
}

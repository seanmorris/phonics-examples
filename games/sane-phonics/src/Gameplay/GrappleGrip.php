<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Opt-in player component holding a grapple association, input, and per-handle regrab delays. */
final class GrappleGrip
{
	public ?int $point = null;
	public float $axis = 0;
	/** @var array<int,float> Remaining seconds before each released handle can be grabbed again. */
	public array $cooldowns = [];

	/**
	 * Clear attachment and regrab state when a player is reset or removed from a level.
	 *
	 * @return void Removes the association and buffered pump input without changing body momentum.
	 */
	public function reset(): void
	{
		$this->point = null;
		$this->axis = 0;
		$this->cooldowns = [];
	}
}

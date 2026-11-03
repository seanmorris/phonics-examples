<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Temporary first-person lighting contributed by weapon-state actions. */
final class ViewLighting
{
	private int $weaponSteps = 0;

	public function clearWeaponFlash(): void { $this->weaponSteps = 0; }

	public function useWeaponFlash(): void { $this->weaponSteps = 1; }

	public function useBrightWeaponFlash(): void { $this->weaponSteps = 2; }

	/** Return the classic map-light increase: each weapon-light step is 16 levels. */
	public function mapLightIncrease(): int { return $this->weaponSteps * 16; }
}

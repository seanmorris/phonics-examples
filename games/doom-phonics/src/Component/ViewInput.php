<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Browser mouse/controller look deltas accumulated between fixed simulation steps. */
final class ViewInput
{
	public bool $fire = false;
	public bool $altFire = false;
	private bool $use = false;
	private ?int $weaponSlot = null;
	private int $weaponSteps = 0;

	public function __construct(public float $yaw = 0, public float $pitch = 0) {}

	public function look(float $yaw, float $pitch): void
	{
		// Match the wire command's bound before previewing: consumption must never snap back.
		$this->yaw = max(-1.5, min(1.5, $this->yaw + $yaw));
		$this->pitch = max(-.75, min(.75, $this->pitch + $pitch));
	}

	public function pressUse(): void { $this->use = true; }

	/** Buffer a direct number-key weapon bank until the combat step consumes it. */
	public function requestWeaponSlot(int $slot): void
	{
		if($slot < 0 || $slot > 9) throw new \InvalidArgumentException('Weapon slots range from 0 to 9');

		$this->weaponSlot = $slot;
	}

	public function stepWeapon(int $steps): void { $this->weaponSteps += $steps; }

	public function setPitch(float $pitch): void { $this->pitch = max(-.75, min(.75, $pitch)); }

	public function consumeUse(): bool
	{
		$value = $this->use;
		$this->use = false;

		return $value;
	}

	public function consumeWeaponSlot(): ?int
	{
		$value = $this->weaponSlot;
		$this->weaponSlot = null;

		return $value;
	}

	public function consumeWeaponSteps(): int
	{
		$value = $this->weaponSteps;
		$this->weaponSteps = 0;

		return $value;
	}

	public function consumeYaw(): float
	{
		$value = $this->yaw;
		$this->yaw = 0;

		return $value;
	}

	/** Drop pending gameplay actions and look motion after control has been lost. */
	public function release(): void
	{
		$this->yaw = 0;
		$this->fire = $this->altFire = $this->use = false;
		$this->weaponSlot = null;
		$this->weaponSteps = 0;
	}

	public function aimSlope(): float { return tan($this->pitch); }
}

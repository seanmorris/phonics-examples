<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use Phonics\Input\Controls;
use DoomPhonics\Input\DoomActions;

/** One device-independent Doom command state consumed by a single player entity. */
final class PlayerCommand
{
	private bool $use = false;
	private ?int $weaponSlot = null;
	private int $weaponSteps = 0;
	private float $yaw = 0;

	public function __construct(
		public float $forward = 0
		, public float $strafe = 0
		, public float $turn = 0
		, public float $pitch = 0
		, public bool $fire = false
		, public bool $altFire = false
	){
	}

	/** Sample local SDL/browser state without exposing device button numbers on the wire. */
	public function sample(Controls $controls, ViewInput $view): void
	{
		$this->forward = $this->axis($controls->value(DoomActions::FORWARD));
		$this->strafe = $this->axis($controls->value(DoomActions::STRAFE));
		$this->turn = 0; // Local rates have already been integrated at display rate.
		$this->yaw = $view->consumeYaw();
		$this->pitch = $view->pitch;
		$this->fire = $controls->down(DoomActions::FIRE);
		$this->altFire = $controls->down(DoomActions::ALT_FIRE);
		$this->use = $controls->pressed(DoomActions::USE);
		$this->weaponSlot = null;

		foreach(DoomActions::SLOTS as $slot => $code)
		if($controls->pressed($code))
		{
			$this->weaponSlot = $slot;
			break;
		}

		$this->weaponSteps = max(
			-8
			, min(
				8
				, max(
					$controls->pressCount(DoomActions::NEXT_WEAPON)
					, (int)$controls->pressed(DoomActions::NEXT_WEAPON)
				) - max(
					$controls->pressCount(DoomActions::PREVIOUS_WEAPON)
					, (int)$controls->pressed(DoomActions::PREVIOUS_WEAPON)
				)
			)
		);
	}

	/** @param array<string,bool|int|float|string|null> $actions */
	public function apply(array $actions, ?ViewInput $view = null, bool $accumulate = false): void
	{
		$this->forward = $this->axis((float)($actions['forward'] ?? 0));
		$this->strafe = $this->axis((float)($actions['strafe'] ?? 0));
		$this->turn = $this->axis((float)($actions['turn'] ?? 0));
		$this->yaw = ($accumulate ? $this->yaw : 0) + max(-1.5, min(1.5, (float)($actions['yaw'] ?? 0)));
		$this->pitch = max(-.75, min(.75, (float)($actions['pitch'] ?? 0)));
		$this->fire = (bool)($actions['fire'] ?? false);
		$this->altFire = (bool)($actions['altFire'] ?? false);
		$this->use = ($accumulate && $this->use) || (bool)($actions['use'] ?? false);
		$slot = $actions['weaponSlot'] ?? null;
		$this->weaponSlot = is_int($slot) && $slot >= 0 && $slot <= 9
			? $slot
			: ($accumulate ? $this->weaponSlot : null);

		$this->weaponSteps = max(
			-8
			, min(8, ($accumulate ? $this->weaponSteps : 0) + (int)($actions['weaponSteps'] ?? 0))
		);

		if($view) $view->setPitch($this->pitch);
	}

	/** @return array<string,bool|int|float|null> */
	public function actions(): array
	{
		return [
			'forward' => $this->forward
			, 'strafe' => $this->strafe
			, 'turn' => $this->turn
			, 'yaw' => $this->yaw
			, 'pitch' => $this->pitch
			, 'fire' => $this->fire
			, 'altFire' => $this->altFire
			, 'use' => $this->use
			, 'weaponSlot' => $this->weaponSlot
			, 'weaponSteps' => $this->weaponSteps
		];
	}

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

	/** Clear every actionable value when the owning player cannot accept control. */
	public function release(): void
	{
		$this->forward = $this->strafe = $this->turn = $this->yaw = 0;
		$this->fire = $this->altFire = $this->use = false;
		$this->weaponSlot = null;
		$this->weaponSteps = 0;
	}

	private function axis(float $value): float { return max(-1, min(1, is_finite($value) ? $value : 0)); }
}

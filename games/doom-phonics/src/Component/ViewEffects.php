<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use DoomPhonics\Game\PlayerState;
use DoomPhonics\Graphics\ViewColorState;

/** Classic damage, pickup, radiation, and invulnerability view-color state. */
final class ViewEffects
{
	private const TICS_PER_SECOND = 35;
	private const BONUS_ADD = 6;
	private const MAX_COUNTER = 100;
	private const POWER_BLINK_START = 4 * 32;

	private int $damageTics = 0;
	private int $bonusTics = 0;
	private bool $dead = false;

	/** Add post-armor player damage to the red PLAYPAL counter. */
	public function recordDamage(int $damage): void
	{
		$this->damageTics = min(self::MAX_COUNTER, $this->damageTics + max(0, $damage));
	}

	/** Hold the strongest red PLAYPAL page throughout the death camera. */
	public function recordDeath(): void
	{
		$this->dead = true;
		$this->damageTics = self::MAX_COUNTER;
	}

	/** Add the standard pickup flash, allowing rapidly collected items to accumulate. */
	public function recordPickup(): void
	{
		$this->bonusTics = min(self::MAX_COUNTER, $this->bonusTics + self::BONUS_ADD);
	}

	/** Advance both finite palette counters by whole 35 Hz game tics. */
	public function advance(int $tics = 1): void
	{
		if($tics <= 0) return;

		if(!$this->dead) $this->damageTics = max(0, $this->damageTics - $tics);

		$this->bonusTics = max(0, $this->bonusTics - $tics);
	}

	public function damageTics(): int { return $this->damageTics; }

	public function bonusTics(): int { return $this->bonusTics; }

	/** Resolve effect precedence to the PLAYPAL page and fixed COLORMAP used this frame. */
	public function colors(PlayerState $player): ViewColorState
	{
		$palette = ViewColorState::NORMAL_PALETTE;

		if($this->damageTics > 0)
		{
			$step = min(ViewColorState::DAMAGE_PALETTE_COUNT, ($this->damageTics + 7) >> 3);
			$palette = ViewColorState::FIRST_DAMAGE_PALETTE + $step - 1;
		}
		elseif($this->bonusTics > 0)
		{
			$step = min(ViewColorState::BONUS_PALETTE_COUNT, ($this->bonusTics + 7) >> 3);
			$palette = ViewColorState::FIRST_BONUS_PALETTE + $step - 1;
		}
		elseif($this->powerVisible((float)($player->powers['IronFeet'] ?? 0)))
		$palette = ViewColorState::RADIATION_PALETTE;

		$fixed = $this->powerVisible((float)($player->powers['Invulnerable'] ?? 0))
			? ViewColorState::INVULNERABILITY_COLORMAP
			: null;

		return new ViewColorState($palette, $fixed);
	}

	/** Keep power effects steady, then blink during their final classic four seconds. */
	private function powerVisible(float $seconds): bool
	{
		if($seconds <= 0) return false;

		$tics = !is_finite($seconds) || $seconds >= PHP_INT_MAX / self::TICS_PER_SECOND
			? PHP_INT_MAX
			: max(0, (int)round($seconds * self::TICS_PER_SECOND));

		return $tics > self::POWER_BLINK_START || ($tics & 8) !== 0;
	}
}

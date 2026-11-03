<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use DoomPhonics\Game\FaceDirection;

/** Classic status-bar face expression, pain band, idle glance, and reaction timers. */
final class StatusFace
{
	private const TICS_PER_SECOND = 35;
	private const IDLE_TICS = 17;
	private const PAIN_TICS = self::TICS_PER_SECOND;
	private const GRIN_TICS = self::TICS_PER_SECOND * 2;
	private const RAMPAGE_DELAY = self::TICS_PER_SECOND * 2;
	private const MUCH_PAIN = 20;
	private const IDLE_SEQUENCE = [0,1,0,2];

	private string $expression = 'straight';
	private int $priority = 0;
	private int $remaining = 0;
	private int $lookFrame = 0;
	private int $lookCursor = 0;
	private int $lookRemaining = self::IDLE_TICS;
	private int $attackTics = 0;

	/** Record post-armor damage, preserving a higher-priority weapon grin already in progress. */
	public function recordDamage(int $damage, FaceDirection $direction): void
	{
		if($damage <= 0) return;

		$this->show($damage > self::MUCH_PAIN ? 'ouch' : $direction->expression(), 7, self::PAIN_TICS);
	}

	/** Smile when a genuinely new weapon enters the player's inventory. */
	public function recordWeaponPickup(): void { $this->show('grin', 8, self::GRIN_TICS); }

	/** Advance expression and idle-look timers by whole 35 Hz game tics. */
	public function advance(bool $attacking, int $tics = 1): void
	{
		for($tic = 0; $tic < max(0, $tics); $tic++)
		{
			if($this->remaining > 0 && --$this->remaining === 0)
			{
				$this->expression = 'straight';
				$this->priority = 0;
			}

			$this->attackTics = $attacking ? $this->attackTics + 1 : 0;

			if($this->attackTics >= self::RAMPAGE_DELAY && $this->priority <= 5)
			$this->show('rampage', 5, 2);

			if($this->priority !== 0) continue;

			if(--$this->lookRemaining <= 0)
			{
				$this->lookFrame = self::IDLE_SEQUENCE[$this->lookCursor];
				$this->lookCursor = ($this->lookCursor + 1) % count(self::IDLE_SEQUENCE);
				$this->lookRemaining = self::IDLE_TICS;
			}
		}
	}

	/** Resolve the exact WAD patch for current health and invulnerability state. */
	public function patch(int $health, bool $invulnerable = false): string
	{
		if($health <= 0) return 'STFDEAD0';

		if($invulnerable) return 'STFGOD0';

		$pain = min(4, intdiv((100 - min(100, max(0, $health))) * 5, 101));

		return match($this->expression)
		{
			'left' => 'STFTL' . $pain . '0',
			'right' => 'STFTR' . $pain . '0',
			'ouch' => 'STFOUCH' . $pain,
			'grin' => 'STFEVL' . $pain,
			'rampage' => 'STFKILL' . $pain,
			default => 'STFST' . $pain . $this->lookFrame,
		};
	}

	public function expression(): string { return $this->expression; }

	private function show(string $expression, int $priority, int $tics): void
	{
		if($priority < $this->priority) return;

		$this->expression = $expression;
		$this->priority = $priority;
		$this->remaining = max(1, $tics);
	}
}

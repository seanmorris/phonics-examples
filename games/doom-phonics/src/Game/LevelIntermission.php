<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Audio\SoundCue;

/** Deterministic 35 Hz tally sequence for one completed Doom level. */
final class LevelIntermission
{
	private const STEP = 1 / 35;
	private const INTRO_TICS = 35;
	private const BETWEEN_TICS = 12;
	private const ENTERING_TICS = 35;
	private float $accumulator = 0;
	private int $tics = 0;
	private int $wait = self::INTRO_TICS;
	private int $stage = 0;
	private bool $activationHeld = true;
	private bool $activationArmed = false;
	private bool $ready = false;
	public int $displayKills = 0;
	public int $displayItems = 0;
	public int $displaySecrets = 0;
	public int $displayTime = 0;
	public int $displayPar = 0;

	public function __construct(
		public readonly string $map
		, public readonly ?string $nextMap
		, public readonly bool $secretExit
		, public readonly int $kills
		, public readonly int $killTotal
		, public readonly int $items
		, public readonly int $itemTotal
		, public readonly int $secrets
		, public readonly int $secretTotal
		, public readonly int $time
		, public readonly ?int $par
		, public readonly ?string $mapTitle = null
		, public readonly ?string $nextMapTitle = null
	){
	}

	public function killPercent(): int { return self::percent($this->kills, $this->killTotal); }

	public function itemPercent(): int { return self::percent($this->items, $this->itemTotal); }

	public function secretPercent(): int { return self::percent($this->secrets, $this->secretTotal); }

	public function phase(): string
	{
		return match($this->stage)
		{
			0 => 'kills',1 => 'items',2 => 'secrets',3 => 'time',4 => 'done',default => 'entering'
		};
	}

	public function ready(): bool { return $this->ready; }

	/** Advance real display time while fixed simulation is paused and return at most one audible cue. */
	public function advance(float $elapsed, bool $activation): ?SoundCue
	{
		if(!is_finite($elapsed) || $elapsed < 0)
		throw new \InvalidArgumentException('Intermission elapsed time must be finite and nonnegative');

		$edge = $activation && !$this->activationHeld && $this->activationArmed;

		if(!$activation) $this->activationArmed = true;

		$this->activationHeld = $activation;

		if($edge)
		{
			if($this->stage < 4)
			{
				$this->completeTallies();

				return SoundCue::intermissionStage();
			}

			if($this->stage === 4)
			{
				if($this->nextMap === null) $this->ready = true;
				else
				{
					$this->stage = 5;
					$this->wait = self::ENTERING_TICS;
				}

				return SoundCue::intermissionDone();
			}
		}

		if($this->ready || $this->stage > 5) return null;

		$this->accumulator += min(.25, $elapsed);
		$cue = null;

		while($this->accumulator + 1e-9 >= self::STEP)
		{
			$this->accumulator -= self::STEP;
			$next = $this->tick();

			if($next && ($cue === null || $next->lump() !== SoundCue::intermissionTick()->lump())) $cue = $next;
			elseif($next && $cue === null) $cue = $next;
		}

		return $cue;
	}

	/** @return array<string,mixed> */
	public function status(): array
	{
		return [
			'map' => $this->map
			, 'mapTitle' => $this->mapTitle
			, 'nextMap' => $this->nextMap
			, 'nextMapTitle' => $this->nextMapTitle
			, 'secretExit' => $this->secretExit
			, 'phase' => $this->phase()
			, 'ready' => $this->ready
			, 'kills' => [$this->displayKills,$this->killPercent(),$this->kills,$this->killTotal]
			, 'items' => [$this->displayItems,$this->itemPercent(),$this->items,$this->itemTotal]
			, 'secrets' => [$this->displaySecrets,$this->secretPercent(),$this->secrets,$this->secretTotal]
			, 'time' => [$this->displayTime,$this->time]
			, 'par' => [$this->displayPar,$this->par]
		];
	}

	private function tick(): ?SoundCue
	{
		$this->tics++;

		if($this->wait > 0)
		{
			$this->wait--;

			if($this->stage === 5 && $this->wait === 0) $this->ready = true;

			return null;
		}

		if($this->stage === 5)
		{
			$this->ready = true;

			return null;
		}

		if($this->stage === 4) return null;

		$complete = match($this->stage)
		{
			0 => $this->increase($this->displayKills, $this->killPercent(), 2),
			1 => $this->increase($this->displayItems, $this->itemPercent(), 2),
			2 => $this->increase($this->displaySecrets, $this->secretPercent(), 2),
			3 => $this->increaseTimes(),
		};

		if($complete)
		{
			$this->stage++;
			$this->wait = self::BETWEEN_TICS;

			return SoundCue::intermissionStage();
		}

		return ($this->tics & 3) === 0 ? SoundCue::intermissionTick() : null;
	}

	private function increase(int &$value, int $target, int $amount): bool
	{
		$value = min($target, $value + $amount);

		return $value >= $target;
	}

	private function increaseTimes(): bool
	{
		$this->displayTime = min($this->time, $this->displayTime + 3);

		if($this->par !== null) $this->displayPar = min($this->par, $this->displayPar + 3);

		return $this->displayTime >= $this->time && ($this->par === null || $this->displayPar >= $this->par);
	}

	private function completeTallies(): void
	{
		$this->displayKills = $this->killPercent();
		$this->displayItems = $this->itemPercent();
		$this->displaySecrets = $this->secretPercent();
		$this->displayTime = $this->time;
		$this->displayPar = $this->par ?? 0;
		$this->stage = 4;
		$this->wait = 0;
	}

	private static function percent(int $value, int $total): int
	{
		return $total <= 0 ? 0 : max(0, (int)floor($value * 100 / $total));
	}
}

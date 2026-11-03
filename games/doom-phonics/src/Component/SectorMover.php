<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/**
 * One active Doom floor or ceiling thinker.
 *
 * Speeds are map units per second. Waits are counted in Doom's 35 Hz tics so
 * the behavior remains deterministic when a host supplies a slightly uneven dt.
 */
final class SectorMover
{
	private const FLOOR = 'floor';
	private const CEILING = 'ceiling';
	private const DOOR_NORMAL = 'door-normal';
	private const DOOR_DELAYED_OPEN = 'door-delayed-open';
	private const DOOR_OPEN = 'door-open';
	private const PLATFORM = 'floor';
	private const LIFT = 'lift';
	private const STAIRS = 'stairs';
	private const DONUT = 'donut';

	private bool $waiting = false;
	public int $waitRemaining = 0;

	private function __construct(
		public int $sector
		, private string $plane
		, private string $kind
		, public float $target
		, public float $speed
		, public ?float $returnTarget = null
		, public int $waitTics = 0
		, private bool $reverseWhenBlocked = false
		, public ?string $completionTexture = null
		, public ?int $completionSpecial = null
	){
		if(!in_array($plane, [self::FLOOR,self::CEILING], true))
		throw new \InvalidArgumentException("Unknown sector plane $plane");
	}

	public static function normalDoor(int $sector, float $openTarget, float $closedTarget): self
	{
		return new self($sector, self::CEILING, self::DOOR_NORMAL, $openTarget, 70, $closedTarget, 150, true);
	}

	public static function delayedOpenDoor(int $sector, float $closedTarget, float $openTarget): self
	{
		return new self($sector, self::CEILING, self::DOOR_DELAYED_OPEN, $closedTarget, 70, $openTarget, 1050, true);
	}

	public static function openDoor(int $sector, float $openTarget): self
	{
		return new self($sector, self::CEILING, self::DOOR_OPEN, $openTarget, 70);
	}

	public static function platform(int $sector, float $target, float $speed): self
	{
		return new self($sector, self::FLOOR, self::PLATFORM, $target, $speed);
	}

	/** Move a ceiling to an absolute height without a door's wait or return phase. */
	public static function ceiling(int $sector, float $target, float $speed): self
	{
		return new self($sector, self::CEILING, 'ceiling', $target, $speed);
	}

	public static function stairs(int $sector, float $target): self
	{
		return new self($sector, self::FLOOR, self::STAIRS, $target, 8.75);
	}

	public static function donut(
		int $sector
		, float $target
		, ?string $completionTexture = null
		, ?int $completionSpecial = null
	): self {
		return new self(
			$sector
			, self::FLOOR
			, self::DONUT
			, $target
			, 17.5
			, null
			, 0
			, false
			, $completionTexture
			, $completionSpecial
		);
	}

	public static function lift(int $sector, float $target, float $returnTarget): self
	{
		return new self($sector, self::FLOOR, self::LIFT, $target, 140, $returnTarget, 105);
	}

	public function isDoor(): bool
	{
		return in_array($this->kind, [self::DOOR_NORMAL,self::DOOR_DELAYED_OPEN,self::DOOR_OPEN], true);
	}

	public function isNormalDoor(): bool { return $this->kind === self::DOOR_NORMAL; }

	public function isLift(): bool { return $this->kind === self::LIFT; }

	public function movesFloor(): bool { return $this->plane === self::FLOOR; }

	public function movesCeiling(): bool { return $this->plane === self::CEILING; }

	public function reversesWhenBlocked(): bool { return $this->reverseWhenBlocked; }

	public function isWaiting(): bool { return $this->waiting; }

	public function beginWaiting(): void
	{
		$this->waiting = true;
		$this->waitRemaining = $this->waitTics;
	}

	/** Advance one classic tic and return true when the wait has elapsed. */
	public function advanceWait(): bool { return --$this->waitRemaining <= 0; }

	public function resume(): void { $this->waiting = false; }

	public function direction(float $current): int
	{
		return $this->target <=> $current;
	}
}

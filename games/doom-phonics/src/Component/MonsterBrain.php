<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Small mutable AI state; decisions are made by systems and DECORATE actions. */
final class MonsterBrain
{
	public ?int $target = null;
	public bool $awake = false;
	/** Doom retains a damage source for this many A_Chase decisions before considering another target. */
	public int $threshold = 0;
	/** Index of the next multiplayer slot considered by A_Look/P_LookForPlayers. */
	public int $lastLook = 0;
	public float $reaction = 0;
	public float $attackCooldown = 0;

	public function __construct(public bool $ambush = false) {}
}

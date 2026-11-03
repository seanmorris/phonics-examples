<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Moving-boxes-inspired stalking, bomb, recovery, and defeat timing for a floating mini boss. */
final class MiniBoss
{
	public float $age = 0,$phaseAge = 0,$bombAge = 0,$escapeAge = 0,$explosionAge = 0;
	public float $opacity = 1;
	public int $bombs = 0,$hits = 0,$explosions = 0;
	/** @var array<int,float> Last successful hit clock keyed by attacking entity ID. */
	public array $damagers = [];

	/**
	 * Configure the floating mini-boss encounter in seconds and pixels per second.
	 *
	 * @param float $stalkSpeed Positive maximum horizontal tracking speed.
	 * @param float $bombInterval Positive interval between falling spike bombs.
	 * @param float $hitCooldown Nonnegative per-attacker delay between successful hits.
	 * @param float $recovery Positive damaged or knocked phase duration.
	 * @param float $deathDelay Nonnegative delay between zero health and the finisher phase.
	 * @param float $explosionDuration Positive duration of the staged explosion sequence.
	 * @param float $explosionInterval Positive interval between visible explosion effects.
	 * @param float $escapeSpeed Positive upward escape speed after the explosion sequence.
	 * @param float $escapeDuration Positive time retained while escaping before cleanup.
	 * @param float $gravity Nonnegative downward acceleration after defeat.
	 * @param float $bombGravity Nonnegative spike-bomb downward acceleration.
	 * @param float $bombLifetime Positive maximum spike-bomb lifetime.
	 * @return void Initializes an inactive encounter with no emitted attacks or effects.
	 */
	public function __construct(
		public float $stalkSpeed = 270
		, public float $bombInterval = 1
		, public float $hitCooldown = .75
		, public float $recovery = 20 / 60
		, public float $deathDelay = 6 / 60
		, public float $explosionDuration = 1.5
		, public float $explosionInterval = 3 / 60
		, public float $escapeSpeed = 840
		, public float $escapeDuration = 1
		, public float $gravity = 720
		, public float $bombGravity = 900
		, public float $bombLifetime = 4
	){
		foreach([
			$stalkSpeed
			, $bombInterval
			, $hitCooldown
			, $recovery
			, $deathDelay
			, $explosionDuration
			, $explosionInterval
			, $escapeSpeed
			, $escapeDuration
			, $gravity
			, $bombGravity
			, $bombLifetime
		] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Mini-boss tuning must be finite');
			}
		}

		if($stalkSpeed <= 0 || $bombInterval <= 0 || $hitCooldown < 0 || $recovery <= 0
			|| $deathDelay < 0 || $explosionDuration <= 0 || $explosionInterval <= 0
			|| $escapeSpeed <= 0 || $escapeDuration <= 0 || $gravity < 0 || $bombGravity < 0
			|| $bombLifetime <= 0
		){
			throw new \InvalidArgumentException('Mini-boss speed and timing values are invalid');
		}
	}
}

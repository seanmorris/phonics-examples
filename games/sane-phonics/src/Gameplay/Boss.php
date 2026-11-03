<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Shared position, health, contact bounds, and phase state for a multi-hit boss. */
final class Boss
{
	public int $hitPoints;
	public string $phase = 'idle';
	public float $vx = 0,$vy = 0;

	/**
	 * Describe a boss independently of its encounter-specific behavior.
	 *
	 * @param float $x Bottom-center world X coordinate in pixels.
	 * @param float $y Bottom-center world Y coordinate in pixels.
	 * @param float $width Positive contact width in world pixels.
	 * @param float $height Positive contact height above the bottom anchor in world pixels.
	 * @param int $maxHitPoints Positive number of successful attacks needed for defeat.
	 * @param int $points Nonnegative score awarded when the boss is defeated.
	 * @param int $collisionLayer Nonnegative terrain plane used by falling and projectile casts.
	 * @return void Initializes full health and the idle encounter phase.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width = 48
		, public float $height = 48
		, public int $maxHitPoints = 8
		, public int $points = 1000
		, public int $collisionLayer = 1
	){
		foreach([$x, $y, $width, $height] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Boss position and bounds must be finite');
			}
		}

		if($width <= 0 || $height <= 0 || $maxHitPoints < 1 || $points < 0 || $collisionLayer < 0)
		{
			throw new \InvalidArgumentException('Boss bounds, health, rewards, and collision layer are invalid');
		}

		$this->hitPoints = $maxHitPoints;
	}

	/**
	 * Report whether this boss still keeps a referenced arena locked.
	 *
	 * @return bool True while at least one hit point remains.
	 */
	public function alive(): bool { return $this->hitPoints > 0; }
}

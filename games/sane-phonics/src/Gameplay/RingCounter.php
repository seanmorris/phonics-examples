<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Player-owned ring and score totals updated by the ring collection system. */
final class RingCounter
{

	/**
	 * Initialize collectible totals for a player or another ring collector.
	 *
	 * @param int $rings Nonnegative initial ring count.
	 * @param int $score Nonnegative initial score.
	 * @return void Stores totals that may persist independently of a level's ring entities.
	 */
	public function __construct(public int $rings = 0, public int $score = 0)
	{
		if($rings < 0 || $score < 0)
		{
			throw new \InvalidArgumentException('Ring and score totals cannot be negative');
		}
	}

	/**
	 * Apply one collected ring's count and score rewards.
	 *
	 * @param Ring $ring Ring removed by the collection system.
	 * @return void Adds the ring's configured rewards to this counter.
	 */
	public function collect(Ring $ring): void
	{
		$this->rings += $ring->value;
		$this->score += $ring->points;
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;

/** Movement reward chain with rail accrual, safe-landing settlement, and failed results. */
final class Combo
{
	/** @var list<ComboReward> Active rewards in the order they were earned. */
	public array $chain = [];
	public ?int $result = null;
	public bool $failed = false;
	public float $resultRemaining = 0;
	private ?ComboReward $grindReward = null;
	private float $groundedTime = 0;
	private float $grindPointRemainder = 0;

	/**
	 * Configure combo settlement and result display timing.
	 *
	 * @param float $settleDelay Grounded non-grinding time required to bank an active chain.
	 * @param float $resultDuration Time that a settled or failed result remains available to a HUD.
	 * @return void Initializes an empty chain with validated timing.
	 */
	public function __construct(public float $settleDelay = .05, public float $resultDuration = 6)
	{
		if(!is_finite($settleDelay) || $settleDelay < 0 || !is_finite($resultDuration) || $resultDuration < 0)
		{
			throw new \InvalidArgumentException('Combo timing must be finite and nonnegative');
		}
	}

	/**
	 * Append one caller-defined action to the current reward chain.
	 *
	 * @param ComboReward $reward Validated action reward retained by reference for live accrual.
	 * @return ComboReward The same reward, allowing a behavior to update its points while active.
	 */
	public function add(ComboReward $reward): ComboReward
	{
		$this->chain[] = $reward;
		$this->groundedTime = 0;

		return $reward;
	}

	/**
	 * Advance rail scoring and settle a chain after an ordinary stable landing.
	 *
	 * @param Body $body Player movement state after its physics update.
	 * @param float $dt Finite positive simulation delta in seconds.
	 * @return int|null Newly banked score, or null while no successful settlement occurred.
	 */
	public function update(Body $body, float $dt): ?int
	{
		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Combo updates require a positive finite delta');
		}

		$this->resultRemaining = max(0, $this->resultRemaining - $dt);

		if($this->resultRemaining <= 0)
		{
			$this->result = null;
			$this->failed = false;
		}

		if($body->grinding)
		{
			$this->groundedTime = 0;

			if($this->grindReward === null)
			{
				$this->grindReward = $this->add(new ComboReward('Rail Grind', 100, 1, '', true));
			}

			$rate = max(60, round(abs($body->groundSpeed) / 10));
			$this->grindPointRemainder += $rate * $dt;
			$points = (int)floor($this->grindPointRemainder);

			if($points > 0)
			{
				$this->grindReward->points += $points;
				$this->grindPointRemainder -= $points;
			}

			return null;
		}

		$this->grindReward = null;
		$this->grindPointRemainder = 0;

		if(!$this->chain || !$body->grounded)
		{
			$this->groundedTime = 0;

			return null;
		}

		$this->groundedTime += $dt;

		return $this->groundedTime >= $this->settleDelay ? $this->settle() : null;
	}

	/**
	 * Sum active base points before the combined multiplier is applied.
	 *
	 * @return int Nonnegative sum of every reward's current points.
	 */
	public function base(): int
	{
		return array_sum(array_map(
			/**
			 * Read one reward's base points.
			 *
			 * @param ComboReward $reward Reward in the active chain.
			 * @return int Current nonnegative point contribution.
			 */
			fn(ComboReward $reward): int => $reward->points
			, $this->chain
		));
	}

	/**
	 * Sum active reward multipliers using the moving-boxes-2 combo rule.
	 *
	 * @return float Positive combined multiplier, or zero for an empty chain.
	 */
	public function multiplier(): float
	{
		return array_sum(array_map(
			/**
			 * Read one reward's multiplier.
			 *
			 * @param ComboReward $reward Reward in the active chain.
			 * @return float Positive multiplier contribution.
			 */
			fn(ComboReward $reward): float => $reward->multiplier
			, $this->chain
		));
	}

	/**
	 * Bank the current chain and publish its successful result for the HUD.
	 *
	 * @return int Rounded-up base-points times summed-multiplier score, or zero when empty.
	 */
	public function settle(): int
	{
		return $this->finish(false);
	}

	/**
	 * Discard the current chain while publishing the score that was lost.
	 *
	 * @return int Rounded-up value of the failed chain, or zero when empty.
	 */
	public function fail(): int
	{
		return $this->finish(true);
	}

	/**
	 * Clear active rewards and all transient result presentation.
	 *
	 * @return void Restores the component to its newly constructed state.
	 */
	public function reset(): void
	{
		$this->chain = [];
		$this->result = null;
		$this->failed = false;
		$this->resultRemaining = 0;
		$this->grindReward = null;
		$this->groundedTime = 0;
		$this->grindPointRemainder = 0;
	}

	/**
	 * Calculate and clear a chain while retaining its temporary display result.
	 *
	 * @param bool $failed Whether the caller lost rather than banked the calculated score.
	 * @return int Calculated chain value, or zero when no rewards were active.
	 */
	private function finish(bool $failed): int
	{
		if(!$this->chain)
		{
			return 0;
		}

		$total = (int)ceil($this->base() * $this->multiplier());
		$this->chain = [];
		$this->result = $total ?: null;
		$this->failed = $failed && $total > 0;
		$this->resultRemaining = $total > 0 ? $this->resultDuration : 0;
		$this->grindReward = null;
		$this->groundedTime = 0;
		$this->grindPointRemainder = 0;

		return $total;
	}
}

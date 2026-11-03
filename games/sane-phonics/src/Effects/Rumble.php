<?php
declare(strict_types=1);
namespace SanePhonics\Effects;

/** Platformer haptic patterns layered on the generic pulse queue. */
final class Rumble extends \Phonics\Effects\Rumble
{

	/**
	 * Queue MB2's Monitor and CanPop two-stage impact pattern.
	 *
	 * @return void Completes the operation.
	 */
	public function pop(): void
	{
		$this->pulse(40, 0, 1);
		$this->pulse(110, .75, 1, 40);
	}

	/**
	 * Queue MB2's spring impact and low-frequency release tail.
	 *
	 * @return void Completes the operation.
	 */
	public function spring(): void
	{
		$this->pulse(120, 1, 1);
		$this->pulse(500, 0, .25, 100);
	}

	/**
	 * Queue MB2's one-shot breakable-block impact.
	 *
	 * @return void Completes the operation.
	 */
	public function breakBlock(): void { $this->pulse(140, 1, 1); }

	/**
	 * Queue MB2 RedEyeJet's successful-hit pattern.
	 *
	 * @return void Completes the operation.
	 */
	public function bossHit(): void
	{
		$this->pulse(120, 1, 1);
		$this->pulse(100, 0, .5, 100);
	}

	/**
	 * Queue MB2's ring-loss damage impact and release tail.
	 *
	 * @return void Completes the operation.
	 */
	public function playerDamage(): void
	{
		$this->pulse(350, 1, 1);
		$this->pulse(150, .5, 1, 350);
	}

	/**
	 * Queue MB2 BubbleSheild's water rebound impact.
	 *
	 * @return void Completes the operation.
	 */
	public function bubbleRebound(): void { $this->pulse(200, 1, 1); }

	/**
	 * Queue MB2 Platformer's floor-impact pulse from gravity-relative speed in pixels per second.
	 *
	 * MB2 stores velocity in pixels per sixty-hertz frame, so its original clamped `abs(ySpeed *
	 * 10) / 40` expression becomes `abs(speed) / 240` here.
	 *
	 * @param float $gravitySpeed Impact speed along gravity in world pixels per second.
	 * @return void Completes the operation.
	 */
	public function landing(float $gravitySpeed): void
	{
		if(!is_finite($gravitySpeed)) throw new \InvalidArgumentException('Invalid landing speed');

		$this->pulse(80, 0, max(.25, min(1, abs($gravitySpeed) / 240)));
	}

	/**
	 * Translate an MB2 viewport vertical shake into its corresponding haptic impact.
	 *
	 * Only a growing large shake restarts its haptic pulse, matching Viewport's threshold guards.
	 * Small shakes retain MB2's repeated high-frequency feedback. Magnitudes are normalized because
	 * this runtime validates SDL's nonnegative duration and [0,1] motor ranges.
	 *
	 * @param float $vertical Current vertical camera displacement in world pixels.
	 * @param float $previousVertical Previous displacement, used by the large-shake restart guards.
	 * @return bool True when the shake generated a haptic pulse.
	 */
	public function cameraShake(float $vertical, float $previousVertical = 0): bool
	{
		if(!is_finite($vertical) || !is_finite($previousVertical))
		{
			throw new \InvalidArgumentException('Invalid camera shake');
		}

		$magnitude = abs($vertical);
		$previous = abs($previousVertical);

		if($magnitude > 40 && $magnitude > $previous)
		{
			$this->pulse((int)round(4 * $magnitude), 1, 1);

			return true;
		}

		if($magnitude > 20 && $magnitude > $previous)
		{
			$this->pulse((int)round(8 * $magnitude), min(1, $magnitude / 20), 1);

			return true;
		}

		if($magnitude > 1)
		{
			$this->pulse((int)round(16 * $magnitude), .1, 1);

			return true;
		}

		return false;
	}
}

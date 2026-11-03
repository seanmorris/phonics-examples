<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

/**
 * Adaptive deadline calculator for expensive animated frames.
 *
 * Fast renderers retain the requested display cadence. When software WebGL or a constrained GPU takes longer than
 * that cadence, the pacer reserves an equal yield period after the frame so rendering cannot monopolize a core while
 * deterministic physics and input continue on the engine clock.
 */
final class FramePacer
{
	/** Earliest monotonic time at which another animated frame may begin. */
	private float $nextFrame = 0.0;
	/** Start time of the last frame submitted to WebGL, if one has rendered. */
	private ?float $lastStarted = null;
	/** Whether an observed browser-side frame cost has already scheduled its matching yield. */
	private bool $yielding = false;

	/**
	 * @param float $minimumInterval Shortest interval between frame starts, in seconds.
	 * @param float $maximumDutyCycle Largest fraction of wall time that adaptive rendering may occupy.
	 */
	public function __construct(
		private float $minimumInterval = 1 / 60, private float $maximumDutyCycle = .5
	) {
		if($minimumInterval <= 0 || $maximumDutyCycle <= 0 || $maximumDutyCycle > 1)
		throw new \InvalidArgumentException('Invalid render pacing limits');
	}

	/**
	 * Report whether a dirty scene may be rendered at the supplied monotonic time.
	 *
	 * @param float|null $now Monotonic timestamp, or null to read the runtime clock.
	 */
	public function ready(?float $now = null): bool
	{
		$now ??= microtime(true);
		if($now + 1e-9 < $this->nextFrame) return false;
		if($this->yielding)
		{
			$this->yielding = false;
			return true;
		}

		// WebGL may finish substantial raster work after PHP has returned from present(). When the browser invokes the
		// next animation frame much later than the PHP-side deadline, use that observed start-to-start gap as the real
		// render cost and reserve the corresponding idle share before accepting another frame.
		if($this->lastStarted !== null && $now - $this->nextFrame > $this->minimumInterval * 1.5)
		{
			$observedCost = min(.5, max(0, $now - $this->lastStarted));
			$yield = $observedCost * (1 / $this->maximumDutyCycle - 1);
			if($yield > $this->minimumInterval)
			{
				$this->nextFrame = $now + $yield;
				$this->yielding = true;
				return false;
			}
		}

		return true;
	}

	/**
	 * Record a completed render and schedule the next frame from its measured cost.
	 *
	 * @param float $started Monotonic timestamp immediately before rendering began.
	 * @param float|null $finished Monotonic timestamp after presentation, or null to read the runtime clock.
	 */
	public function complete(float $started, ?float $finished = null): void
	{
		$finished ??= microtime(true);
		$cost = max(0, $finished - $started);
		$this->lastStarted = $started;
		$this->yielding = false;
		$this->nextFrame = $started + max($this->minimumInterval, $cost / $this->maximumDutyCycle);
	}

	/** Forget continuous-animation timing while the scene is clean so the next input frame is immediate. */
	public function idle(): void
	{
		$this->nextFrame = 0.0;
		$this->lastStarted = null;
		$this->yielding = false;
	}

	/** Allow the next dirty frame immediately after context restoration or a reset. */
	public function reset(): void { $this->idle(); }
}

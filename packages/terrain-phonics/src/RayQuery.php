<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Cooperative exact first-hit query over one committed landscape revision.
 * Frame budgets are checked between block skips and individual cells. One bounded solve can overrun
 * the requested time slightly. Cancellation releases the generator without touching terrain.
 */
final class RayQuery
{
	/** Suspended exact traversal, released after completion or cancellation. */
	private ?\Generator $steps;
	/** Source revision and bounded progress counters. */
	private int $revision, $cells = 0;
	/** Query state is separate from a legitimate completed miss. */
	private bool $started = false, $complete = false, $cancelled = false;
	/** Final hit and accumulated computation time, excluding waits between frames. */
	private ?array $hit = null;
	private float $elapsedMs = 0, $lastAdvanceMs = 0;

	/**
	 * Capture the source revision and prepare a lazy exact traversal.
	 * @param Landscape $surface Caller-owned source surface.
	 * @param array $origin Finite world ray origin [x,y,z].
	 * @param array $direction Finite nonzero ray direction [x,y,z].
	 * @param float $maxDistance Maximum travel in world metres.
	 * @return void Retains the source and generator without generating terrain samples.
	 */
	public function __construct(private Landscape $surface, array $origin, array $direction, float $maxDistance = 10000)
	{
		$this->revision = $surface->revision();
		$this->steps = $surface->raycastSteps($origin, $direction, $maxDistance);
	}

	/**
	 * Advance until the cooperative time or cell budget expires, or the exact hit is known.
	 * @param float $budgetMs Positive computation budget in milliseconds, at most one second.
	 * @param int $maxCells Additional hard cap on traversal steps (block skips or single cells).
	 * @return bool Whether traversal completed, including a completed miss.
	 */
	public function advance(float $budgetMs = 2, int $maxCells = 64): bool
	{
		if(!is_finite($budgetMs) || $budgetMs <= 0 || $budgetMs > 1000 || $maxCells < 1 || $maxCells > 4096)
		throw new \InvalidArgumentException('Invalid terrain picking budget');

		if($this->cancelled) throw new \LogicException('Terrain ray query was cancelled');

		if($this->complete) return true;

		if($this->surface->revision() !== $this->revision)
		{
			$this->cancel();
			throw new \LogicException('Terrain changed during ray query');
		}

		$started = hrtime(true);
		$deadline = $started + $budgetMs * 1e6;
		$processed = 0;

		try
		{
			if(!$this->started)
			{
				$this->steps->rewind();
				$this->started = true;
			}

			while($this->steps->valid())
			{
				if($processed >= $maxCells || ($processed > 0 && hrtime(true) >= $deadline)) return false;

				$this->steps->next();
				$processed++;
				$this->cells++;
			}

			$this->hit = $this->steps->getReturn();
			$this->steps = null;
			$this->complete = true;

			return true;
		}
		catch(\Throwable $error)
		{
			$this->cancel();
			throw $error;
		}
		finally
		{
			$this->lastAdvanceMs = (hrtime(true) - $started) / 1e6;
			$this->elapsedMs += $this->lastAdvanceMs;
		}
	}

	/**
	 * Report whether no further traversal remains.
	 * @return bool True after completion or cancellation.
	 */
	public function done(): bool { return $this->complete || $this->cancelled; }

	/**
	 * Retrieve only a complete, revision-consistent result.
	 * @return array|null Exact nearest hit, or null for a completed miss.
	 */
	public function result(): ?array
	{
		if($this->cancelled || !$this->complete) throw new \LogicException('Terrain ray query has no completed result');

		if($this->surface->revision() !== $this->revision)
		throw new \LogicException('Terrain changed after ray query completion');

		return $this->hit;
	}

	/**
	 * Stop traversal and release its bounded work state without closing the source.
	 * @return void Marks the query cancelled; repeated cancellation is harmless.
	 */
	public function cancel(): void
	{
		$this->steps = null;
		$this->hit = null;
		$this->cancelled = true;
	}

	/**
	 * Report progress without resuming generation.
	 * @return array Traversal steps (legacy cells key), captured revision, state and computation-time counters.
	 */
	public function stats(): array
	{
		return [
			'cells' => $this->cells
			, 'revision' => $this->revision
			, 'done' => $this->done()
			, 'cancelled' => $this->cancelled
			, 'elapsedMs' => $this->elapsedMs
			, 'lastAdvanceMs' => $this->lastAdvanceMs
		];
	}
}

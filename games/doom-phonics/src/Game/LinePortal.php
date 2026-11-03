<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Live vertical opening between the two sectors joined by a linedef. */
final class LinePortal
{

	public function __construct(
		public int $rightSector
		, public int $leftSector
		, private SectorState $rightState
		, private SectorState $leftState
	){
	}

	/** Return the sector across this portal from the supplied sector. */
	public function opposite(int $sector): ?int
	{
		if($sector === $this->rightSector) return $this->leftSector;

		if($sector === $this->leftSector) return $this->rightSector;

		return null;
	}

	/** Return the current lower edge of the opening, including moving floors. */
	public function floor(?float $x = null, ?float $y = null): float
	{
		if($x !== null && $y !== null) return max($this->rightState->floorZ($x, $y), $this->leftState->floorZ($x, $y));

		return max($this->rightState->floor, $this->leftState->floor);
	}

	/** Return the current upper edge of the opening, including moving ceilings. */
	public function ceiling(?float $x = null, ?float $y = null): float
	{
		if($x !== null && $y !== null)
		return min($this->rightState->ceilingZ($x, $y), $this->leftState->ceilingZ($x, $y));

		return min($this->rightState->ceiling, $this->leftState->ceiling);
	}

	/** Whether both sides belong to the same unbounded outdoor sky. */
	public function sharesOpenSky(): bool
	{
		return $this->rightState->hasSkyCeiling() && $this->leftState->hasSkyCeiling();
	}

	/** Report whether a vertical body interval fits through the current opening. */
	public function admits(float $bottom, float $height, float $tolerance = .001): bool
	{
		return $bottom >= $this->floor() - $tolerance
			&& $bottom + $height <= $this->ceiling() + $tolerance;
	}

	/**
	 * Projectiles may cross above the lower ceiling where two outdoor sectors meet.
	 * Doom treats their differing sky heights as one continuous, unbounded opening.
	 */
	public function admitsProjectile(
		float $bottom
		, float $height
		, float $tolerance = .001
		, ?float $x = null
		, ?float $y = null
		, bool $sight = false
	): bool {
		return $bottom >= $this->floor($x, $y) - $tolerance
			&& ($this->sharesOpenSky() || $bottom + $height <= $this->ceiling($x, $y) + $tolerance)
			&& !$this->rightState->blocksVolume($x ?? 0, $y ?? 0, $bottom, $height, $sight)
			&& !$this->leftState->blocksVolume($x ?? 0, $y ?? 0, $bottom, $height, $sight);
	}
}

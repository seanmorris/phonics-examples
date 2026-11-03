<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/**
 * A control-sector volume projected into another sector by Sector_Set3dFloor.
 *
 * Heights remain references to the live model planes, so rendering, collision and
 * liquid queries all observe the same moving or sloping surface.
 */
final class SectorVolume
{
	/** Construct one volume; type bits and flags retain their Hexen meanings. */
	public function __construct(
		public SectorState $model,
		public int $line,
		public int $type,
		public int $flags,
		public int $alpha,
		public string $texture
	) {}

	/** Whether actors collide with this volume. */
	public function solid(): bool { return ($this->type & 3) <= 1 && ($this->type & 7) !== 4; }
	/** Whether the volume applies liquid movement. */
	public function swimmable(): bool { return ($this->type & 3) === 2; }
	/** Whether a ray is stopped, including the visibility/shootability inversion bits. */
	public function blocks(bool $sight = false): bool { return $this->solid() !== (bool)($this->type & ($sight ? 16 : 32)); }
	/** Select the model plane used for the upper surface. */
	public function topPlane(): int { return ($this->type & 3) === 0 && ($this->type & 7) !== 4 ? 0 : 1; }
	/** Select the model plane used for the lower surface. */
	public function bottomPlane(): int { return ($this->flags & 8) ? $this->topPlane() : 1 - $this->topPlane(); }
	/** Return the upper surface at the supplied map position. */
	public function top(float $x, float $y, float $alpha = 1): float
	{
		return $this->topPlane() ? $this->model->ceilingZ($x, $y, $alpha) : $this->model->floorZ($x, $y, $alpha);
	}
	/** Return the lower surface at the supplied map position. */
	public function bottom(float $x, float $y, float $alpha = 1): float
	{
		return $this->bottomPlane() ? $this->model->ceilingZ($x, $y, $alpha) : $this->model->floorZ($x, $y, $alpha);
	}
	/** Test overlap with a vertical interval, preserving contact at either surface. */
	public function overlaps(float $x, float $y, float $z, float $height): bool
	{
		return $this->top($x, $y) > $this->bottom($x, $y) + .001
			&& $z < $this->top($x, $y) - .001 && $z + $height > $this->bottom($x, $y) + .001;
	}
}

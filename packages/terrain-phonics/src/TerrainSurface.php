<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Revisioned world-space heightfield shared by rendering and simulation. */
interface TerrainSurface
{

	/**
	 * Return elevation in metres at an in-bounds world X/Z coordinate.
	 * @param float $x World horizontal X coordinate in metres.
	 * @param float $z World horizontal Z coordinate in metres.
	 * @return float Committed surface elevation in metres.
	 */
	public function height(float $x, float $z): float;

	/**
	 * Return height, world-space dx/dz, upward unit normal, and optional material.
	 * @param float $x World horizontal X coordinate in metres.
	 * @param float $z World horizontal Z coordinate in metres.
	 * @return array Committed height, gradient, normal and material data.
	 */
	public function sample(float $x, float $z): array;

	/**
	 * Return the committed geometry revision; queued edits are invisible.
	 * @return int Monotonically increasing surface revision.
	 */
	public function revision(): int;
}

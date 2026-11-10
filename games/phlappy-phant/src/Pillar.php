<?php
declare(strict_types=1);
namespace PhlappyPhant;

/** One pair of grass pillars: one hanging from the ceiling and one rising from the ground, with a gap between them. */
final class Pillar
{
	/** World x of the pillars' left edge on the previous step, for render interpolation. */
	public float $previousX;
	/** Whether the phant has already been credited for passing this pair. */
	public bool $scored = false;

	/**
	 * Place a pillar pair.
	 *
	 * @param float $x World x of the pillars' left edge; the phant sits at world x zero.
	 * @param float $gapTop Top of the gap in logical units from the ceiling, on the 8-unit tile grid.
	 * @param float $gap Height of the gap, on the 8-unit tile grid.
	 * @param int $index Position of the pair in the run's sequence, for scenery that must not draw on the seed.
	 * @return void The previous pose starts at the current one.
	 */
	public function __construct(public float $x, public float $gapTop, public float $gap, public int $index = 0)
	{
		$this->previousX = $x;
	}
}

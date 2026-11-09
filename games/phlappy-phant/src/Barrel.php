<?php
declare(strict_types=1);
namespace PhlappyPhant;

/** One pair of DB silo stacks: a column from the ceiling and one from the ground with a gap between them. */
final class Barrel
{
	/** World x of the stack's left edge on the previous step, for render interpolation. */
	public float $previousX;
	/** Whether the phant has already been credited for passing this pair. */
	public bool $scored = false;

	/**
	 * Place a barrel pair.
	 *
	 * @param float $x World x of the stack's left edge; the phant sits at world x zero.
	 * @param float $gapTop Top of the gap in logical units from the ceiling.
	 * @param float $gap Height of the gap.
	 * @return void The previous pose starts at the current one.
	 */
	public function __construct(public float $x, public float $gapTop, public float $gap)
	{
		$this->previousX = $x;
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Present on an intact block; replaced by particle components when it breaks. */
final class BreakableBlock
{

	/**
	 * Describe how an intact block's primary sprite will be divided.
	 *
	 * @param int $columns Number of horizontal pieces in the shatter grid.
	 * @param int $rows Number of vertical pieces in the shatter grid.
	 * @return void Stores positive grid dimensions; the manager checks them against the image.
	 */
	public function __construct(public int $columns, public int $rows)
	{
		if($columns < 1 || $rows < 1)
		{
			throw new \InvalidArgumentException('Breakable grids must have positive dimensions');
		}
	}
}

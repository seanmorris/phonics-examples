<?php
declare(strict_types=1);
namespace PhpTris;

/** Integer cell geometry; the fourth state is deliberately retained for mirrored pieces. */
final class Shapes
{
	public const CELLS = [
		'I' => [[0,1],[1,1],[2,1],[3,1]]
		, 'J' => [[0,0],[0,1],[1,1],[2,1]]
		, 'L' => [[2,0],[0,1],[1,1],[2,1]]
		, 'O' => [[1,0],[2,0],[1,1],[2,1]]
		, 'S' => [[1,0],[2,0],[0,1],[1,1]]
		, 'Z' => [[0,0],[1,0],[1,1],[2,1]]
		, 'T' => [[1,0],[0,1],[1,1],[2,1]]
		, 'A' => [[1,0]]
	];
	// Offsets use screen coordinates (positive y points down).
	private const KICKS = [
		'01' => [[0,0],[-1,0],[-1,-1],[0,2],[-1,2]]
		, '10' => [[0,0],[1,0],[1,1],[0,-2],[1,-2]]
		, '12' => [[0,0],[1,0],[1,1],[0,-2],[1,-2]]
		, '21' => [[0,0],[-1,0],[-1,-1],[0,2],[-1,2]]
		, '23' => [[0,0],[1,0],[1,-1],[0,2],[1,2]]
		, '32' => [[0,0],[-1,0],[-1,1],[0,-2],[-1,-2]]
		, '30' => [[0,0],[-1,0],[-1,1],[0,-2],[-1,-2]]
		, '03' => [[0,0],[1,0],[1,-1],[0,2],[1,2]]
	];
	private const I_KICKS = [
		'01' => [[0,0],[-2,0],[1,0],[-2,1],[1,-2]]
		, '10' => [[0,0],[2,0],[-1,0],[2,-1],[-1,2]]
		, '12' => [[0,0],[-1,0],[2,0],[-1,-2],[2,1]]
		, '21' => [[0,0],[1,0],[-2,0],[1,2],[-2,-1]]
		, '23' => [[0,0],[2,0],[-1,0],[2,-1],[-1,2]]
		, '32' => [[0,0],[-2,0],[1,0],[-2,1],[1,-2]]
		, '30' => [[0,0],[1,0],[-2,0],[1,2],[-2,-1]]
		, '03' => [[0,0],[-1,0],[2,0],[-1,-2],[2,1]]
	];

	/**
	 * Return local occupied cells for a shape and rotation.
	 *
	 * @param string $shape Shape identifier.
	 * @param int $rotation Clockwise quarter-turn index.
	 * @return array Computed values in iteration order.
	 */
	public static function cells(string $shape, int $rotation = 0): array
	{
		static $cache = [];
		$rotation = ($rotation % 4 + 4) % 4;
		$key = $shape . $rotation;

		if(isset($cache[$key])) return $cache[$key];

		$cells = self::CELLS[$shape];

		if($shape !== 'O' && $shape !== 'A')
		{
			$size = $shape === 'I' ? 3 : 2;

			for($i = 0; $i < $rotation; $i++)
			{
				foreach($cells as &$cell) $cell = [$size - $cell[1], $cell[0]];
				unset($cell);
			}
		}

		return $cache[$key] = $cells;
	}

	/**
	 * Return ordered wall and floor offsets for a rotation transition.
	 *
	 * @param string $shape Shape identifier.
	 * @param int $from Previous rotation index.
	 * @param int $to Requested rotation index.
	 * @return array Computed values in iteration order.
	 */
	public static function kicks(string $shape, int $from, int $to): array
	{
		return in_array($shape, ['O','A'], true) ? [[0,0]] : ($shape === 'I' ? self::I_KICKS : self::KICKS)["$from$to"];
	}
}

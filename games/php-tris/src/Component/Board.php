<?php
declare(strict_types=1);
namespace PhpTris\Component;
use PhpTris\Shapes;

/** Settled cells retain origin, color, and objective identity independently of their position. */
final class Board
{
	public array $cells = [];
	public int $revision = 0, $serial = 0;

	/**
	 * Test board bounds and settled-cell occupancy.
	 *
	 * @param int $x Horizontal cell or pixel coordinate.
	 * @param int $y Vertical cell or pixel coordinate.
	 * @return bool Whether the operation or predicate succeeded.
	 */
	public function occupied(int $x, int $y): bool
	{
		return $x < 0 || $x >= 10 || $y < 0 || $y >= 40 || isset($this->cells[$y * 10 + $x]);
	}

	/**
	 * Test every occupied cell of a transformed active piece.
	 *
	 * @param string $shape Shape identifier.
	 * @param int $rotation Clockwise quarter-turn index.
	 * @param int $x Horizontal cell or pixel coordinate.
	 * @param int $y Vertical cell or pixel coordinate.
	 * @return bool Whether the operation or predicate succeeded.
	 */
	public function fits(string $shape, int $rotation, int $x, int $y): bool
	{
		foreach(Shapes::cells($shape, $rotation) as [$dx, $dy])
		if($this->occupied($x + $dx, $y + $dy)) return false;

		return true;
	}

	/**
	 * Place a settled cell and invalidate cached rendering geometry.
	 *
	 * @param int $x Horizontal cell or pixel coordinate.
	 * @param int $y Vertical cell or pixel coordinate.
	 * @param int $color Palette index or RGBA channels.
	 * @param string $kind Cell role in board resolution.
	 * @param int $group Original piece identity, or zero to allocate one.
	 * @param string $shape Shape identifier.
	 * @return void Updates owned state without returning a value.
	 */
	public function put(
		int $x
		, int $y
		, int $color = 0
		, string $kind = 'normal'
		, int $group = 0
		, string $shape = ''
	): void {
		if($x < 0 || $x >= 10 || $y < 0 || $y >= 40) throw new \InvalidArgumentException('Cell outside board');

		$this->cells[$y * 10 + $x] = [
			'color' => $color
			, 'kind' => $kind
			, 'group' => $group ?: ++$this->serial
			, 'shape' => $shape
			, 'square' => 0
			, 'bonus' => 0
		];

		$this->revision++;
	}
}

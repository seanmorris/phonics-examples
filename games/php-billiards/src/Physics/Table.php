<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/** Standard nine-foot pool-table playfield and pocket geometry, in metres. */
final class Table
{
	public const WIDTH = 2.54;
	public const HEIGHT = 1.27;
	/** Midpoints of the WPA mouth ranges: 4 9/16 and 5 1/16 inches. */
	public const CORNER_MOUTH = 0.1158875;
	public const SIDE_MOUTH = 0.1285875;
	private const CORNER_JAW = 0.08194483710475602;

	/** @return list<array{float,float}> */
	public static function pockets(): array
	{
		$x = self::WIDTH / 2;
		$y = self::HEIGHT / 2;

		return [[-$x,-$y],[0,-$y],[$x,-$y],[-$x,$y],[0,$y],[$x,$y]];
	}

	/** Whether a rail face exists at the supplied along-rail coordinate. */
	public static function cushion(string $axis, float $coordinate): bool
	{
		if($axis === 'x') return abs($coordinate) < self::HEIGHT / 2 - self::CORNER_JAW;

		return abs($coordinate) < self::WIDTH / 2 - self::CORNER_JAW
			&& abs($coordinate) > self::SIDE_MOUTH / 2;
	}

	public static function pocketRadius(int $index): float
	{
		return $index === 1 || $index === 4 ? self::SIDE_MOUTH / 2 : self::CORNER_MOUTH / 2;
	}

	public static function pocketed(float $x, float $y): bool
	{
		foreach(self::pockets() as $index => [$px,$py])
		if(hypot($x - $px, $y - $py) <= self::pocketRadius($index)) return true;

		return false;
	}

	/**
	 * Resolve a ball that has reached a pocket bowl or crossed a rail through a pocket mouth.
	 *
	 * @return array{index:int,x:float,y:float}|null
	 */
	public static function capture(float $x, float $y): ?array
	{
		$pockets = self::pockets();
		$nearest = null;
		$nearestDistance = INF;

		foreach($pockets as $index => [$px,$py])
		{
			$distance = hypot($x - $px, $y - $py);

			if($distance < $nearestDistance)
			{
				$nearest = ['index' => $index,'x' => $px,'y' => $py];
				$nearestDistance = $distance;
			}
		}

		if($nearestDistance <= self::pocketRadius($nearest['index'])) return $nearest;

		$edgeX = self::WIDTH / 2 - Ball::RADIUS;
		$edgeY = self::HEIGHT / 2 - Ball::RADIUS;
		$throughMouth = abs($x) >= $edgeX && !self::cushion('x', $y)
			|| abs($y) >= $edgeY && !self::cushion('y', $x);

		return $throughMouth ? $nearest : null;
	}
}

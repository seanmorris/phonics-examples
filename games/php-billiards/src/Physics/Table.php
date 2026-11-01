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
	/** Rounded corner bowl diameter is midway between the corner and side mouth widths. */
	public const CORNER_BOWL_RADIUS = 0.06111875;
	private const CORNER_JAW = 0.08194483710475602;

	/** @return list<array{float,float}> */
	public static function pockets(): array
	{
		$x = self::WIDTH / 2;
		$y = self::HEIGHT / 2;
		$mouthHalf = self::CORNER_MOUTH / 2;
		$depth = $mouthHalf - sqrt(self::CORNER_BOWL_RADIUS ** 2 - $mouthHalf ** 2);
		$inset = $depth * M_SQRT1_2;
		$cornerX = $x - $inset;
		$cornerY = $y - $inset;

		return [[-$cornerX,-$cornerY],[0,-$y],[$cornerX,-$cornerY]
			,[-$cornerX,$cornerY],[0,$y],[$cornerX,$cornerY]];
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
		return $index === 1 || $index === 4 ? self::SIDE_MOUTH / 2 : self::CORNER_BOWL_RADIUS;
	}

	/** Half of the measured jaw-to-jaw mouth, independent of the liner radius. */
	public static function pocketMouthHalf(int $index): float
	{
		return ($index === 1 || $index === 4 ? self::SIDE_MOUTH : self::CORNER_MOUTH) / 2;
	}

	/** @return array{float,float} Direction from the playfield into a pocket. */
	public static function pocketOutward(int $index): array
	{
		[$x,$y] = self::pockets()[$index];

		if($index === 1 || $index === 4) return [0.0,(float)($y <=> 0.0)];

		return [($x <=> 0.0) * M_SQRT1_2,($y <=> 0.0) * M_SQRT1_2];
	}

	/** Cushion-nose endpoints whose ball-radius offsets form the playable pocket jaws. */
	public static function jaws(): array
	{
		$halfWidth = self::WIDTH / 2;
		$halfHeight = self::HEIGHT / 2;
		$side = self::SIDE_MOUTH / 2;
		$jaws = [];

		foreach([-1,1] as $vertical)
		foreach([-$halfWidth + self::CORNER_JAW,-$side,$side,$halfWidth - self::CORNER_JAW] as $x)
		$jaws[] = [$x,$vertical * $halfHeight];

		foreach([-1,1] as $horizontal)
		foreach([-$halfHeight + self::CORNER_JAW,$halfHeight - self::CORNER_JAW] as $y)
		$jaws[] = [$horizontal * $halfWidth,$y];

		return $jaws;
	}

	public static function pocketed(float $x, float $y): bool
	{
		foreach(self::pockets() as $index => [$px,$py])
		if(hypot($x - $px, $y - $py) <= self::pocketRadius($index)) return true;

		return false;
	}

	/** @return array{index:int,x:float,y:float}|null Pocket liner entered through a jaw or shelf. */
	public static function pocketEntry(float $x, float $y): ?array
	{
		$pockets = self::pockets();
		$nearest = null;
		$nearestDistance = INF;

		foreach($pockets as $index => [$px,$py])
		{
			$distance = hypot($x - $px, $y - $py);

			if($distance < $nearestDistance)
			{
				$nearestDistance = $distance;
				$nearest = ['index' => $index,'x' => $px,'y' => $py];
			}

			[$outwardX,$outwardY] = self::pocketOutward($index);

			$relativeX = $x - $px;
			$relativeY = $y - $py;
			$depth = $relativeX * $outwardX + $relativeY * $outwardY;
			$lateral = abs(-$outwardY * $relativeX + $outwardX * $relativeY);
			$clearance = self::pocketMouthHalf($index) - Ball::RADIUS;

			// The centre must pass the cushion nose and fit through the throat. Merely
			// overlapping the visible mouth leaves the ball on the shelf.
			if($depth >= -Ball::RADIUS * .45 && $lateral <= $clearance)
			return ['index' => $index,'x' => $px,'y' => $py];
		}

		$edgeX = self::WIDTH / 2 - Ball::RADIUS;
		$edgeY = self::HEIGHT / 2 - Ball::RADIUS;
		$throughMouth = abs($x) >= $edgeX && !self::cushion('x', $y)
			|| abs($y) >= $edgeY && !self::cushion('y', $x);

		// Crossing a mouth starts liner physics; it does not imply that the ball sank.
		return $throughMouth ? $nearest : null;
	}
}

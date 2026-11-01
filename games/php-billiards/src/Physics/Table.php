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
	public const CUSHION_WIDTH = 0.055;
	/** The reference uses one circular tabletop cut behind every dimensioned mouth. */
	public const THROAT_RADIUS = self::CORNER_MOUTH / 2;
	/** Analytic leather lip inset used by the renderer to hide full-table grid chords. */
	public const POCKET_LIP_INSET = 0.0025;
	public const LINER_RADIUS = self::THROAT_RADIUS - self::POCKET_LIP_INSET;
	private const CORNER_JAW = 0.08194483710475602;

	/** @return list<array{float,float}> */
	public static function pockets(): array
	{
		static $pockets = null;

		if($pockets !== null) return $pockets;

		$cornerX = self::WIDTH / 2 + self::CUSHION_WIDTH / 2;
		$cornerY = self::HEIGHT / 2 + self::CUSHION_WIDTH / 2;
		$sideY = self::HEIGHT / 2 + self::CUSHION_WIDTH;

		return $pockets = [[-$cornerX,-$cornerY],[0,-$sideY],[$cornerX,-$cornerY]
			,[-$cornerX,$cornerY],[0,$sideY],[$cornerX,$cornerY]];
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
		return self::LINER_RADIUS;
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

	/** @return array{float,float} Centre of the regulation jaw chord. */
	public static function pocketMouthCenter(int $index): array
	{
		[$outwardX,$outwardY] = self::pocketOutward($index);

		if($index === 1 || $index === 4)
		return [0.0,$outwardY * self::HEIGHT / 2];

		$cornerX = ($outwardX <=> 0.0) * self::WIDTH / 2;
		$cornerY = ($outwardY <=> 0.0) * self::HEIGHT / 2;

		return [$cornerX - $outwardX * self::CORNER_MOUTH / 2
			, $cornerY - $outwardY * self::CORNER_MOUTH / 2];
	}

	/** @return array{array{float,float},array{float,float}} */
	public static function pocketJaws(int $index): array
	{
		[$mouthX,$mouthY] = self::pocketMouthCenter($index);
		[$outwardX,$outwardY] = self::pocketOutward($index);
		$mouth = $index === 1 || $index === 4 ? self::SIDE_MOUTH : self::CORNER_MOUTH;
		$lateralX = -$outwardY;
		$lateralY = $outwardX;

		return [[$mouthX - $lateralX * $mouth / 2,$mouthY - $lateralY * $mouth / 2]
			, [$mouthX + $lateralX * $mouth / 2,$mouthY + $lateralY * $mouth / 2]];
	}

	/** Tangency from one jaw to the rendered round tabletop hole. @return array{float,float} */
	private static function facingTangent(int $index, array $jaw): array
	{
		[$centerX,$centerY] = self::pockets()[$index];
		[$mouthX,$mouthY] = self::pocketMouthCenter($index);
		[$outwardX,$outwardY] = self::pocketOutward($index);
		$dx = $jaw[0] - $centerX;
		$dy = $jaw[1] - $centerY;
		$base = atan2($dy, $dx);
		$offset = acos(self::THROAT_RADIUS / hypot($dx, $dy));
		$candidates = [];

		foreach([$base - $offset,$base + $offset] as $angle)
		{
			$x = $centerX + cos($angle) * self::THROAT_RADIUS;
			$y = $centerY + sin($angle) * self::THROAT_RADIUS;
			$candidates[] = [$x,$y,($x - $mouthX) * $outwardX + ($y - $mouthY) * $outwardY];
		}

		return $candidates[0][2] >= $candidates[1][2]
			? [$candidates[0][0],$candidates[0][1]] : [$candidates[1][0],$candidates[1][1]];
	}

	/** Render-matched rubber faces from every jaw to its round throat. @return list<array{float,float,float,float}> */
	public static function facings(): array
	{
		static $facings = null;

		if($facings !== null) return $facings;

		$facings = [];

		foreach(array_keys(self::pockets()) as $index)
		foreach(self::pocketJaws($index) as $jaw)
		{
			[$x,$y] = self::facingTangent($index, $jaw);
			$facings[] = [$jaw[0],$jaw[1],$x,$y];
		}

		return $facings;
	}

	/** Cushion-nose endpoints whose ball-radius offsets form the playable pocket jaws. */
	public static function jaws(): array
	{
		static $jaws = null;

		if($jaws !== null) return $jaws;

		$jaws = [];

		foreach(array_keys(self::pockets()) as $index)
		foreach(self::pocketJaws($index) as $jaw) $jaws[] = $jaw;

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

		foreach($pockets as $index => [$px,$py])
		{
			$distance = hypot($x - $px, $y - $py);

			// Crossing a jaw chord leaves the ball on the rendered cloth shelf. It
			// enters liner physics only when its centre fits inside the circular lip.
			if($distance <= self::pocketRadius($index) - Ball::RADIUS)
			return ['index' => $index,'x' => $px,'y' => $py];
		}

		return null;
	}
}

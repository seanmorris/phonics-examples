<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/**
 * Standard nine-foot pool-table playfield and pocket geometry, in metres.
 *
 * Regulation ball, mouth, jaw, shelf, and rail measurements live here so simulation and constructive render
 * geometry query one canonical physical model for all six pockets and every playable boundary.
 */
final class Table
{
	public const WIDTH = 2.54;
	public const HEIGHT = 1.27;
	/** Distance of the foot spot, where the rack's apex ball sits, from the table centre; the head spot mirrors it. */
	public const FOOT_SPOT = self::WIDTH * .23;
	/** Midpoints of the WPA mouth ranges: 4 9/16 and 5 1/16 inches. */
	public const CORNER_MOUTH = 0.1158875;
	public const SIDE_MOUTH = 0.1285875;
	public const CUSHION_WIDTH = 0.055;
	/** The reference uses one circular tabletop cut behind every dimensioned mouth. */
	public const THROAT_RADIUS = self::CORNER_MOUTH / 2;
	/** Analytic leather lip inset used by the renderer to hide full-table grid chords. */
	public const POCKET_LIP_INSET = 0.0025;
	public const LINER_RADIUS = self::THROAT_RADIUS - self::POCKET_LIP_INSET;
	/**
	 * Cloth shelf between a side pocket's mouth and its cut: the WPA maximum of 3/8 inch. The rail behind the notch
	 * must stay thicker than its two round-overs, which rules out a deeper side shelf. Corner pockets keep the 3.9 cm
	 * shelf that their throat placement already gives, inside the WPA 1-2 1/4 inch range.
	 */
	public const SIDE_SHELF = .009525;
	private const CORNER_JAW = 0.08194483710475602;

	/**
	 * Return the six pocket centres: one round cut through the slate and rail, where balls drop and liners hang.
	 *
	 * Corner centres follow the rounded table corners while side centres sit beyond the long rail, giving physics and
	 * rendering one ordered index for mouths, liners, shelves, drops, and sound events.
	 *
	 * @return list<array{float,float}>
	 */
	public static function pockets(): array
	{
		static $pockets = null;

		if($pockets !== null) return $pockets;

		$cornerX = self::WIDTH / 2 + self::CUSHION_WIDTH / 2;
		$cornerY = self::HEIGHT / 2 + self::CUSHION_WIDTH / 2;
		$sideY = self::HEIGHT / 2 + self::SIDE_SHELF + self::THROAT_RADIUS;

		return $pockets = [[-$cornerX,-$cornerY],[0,-$sideY],[$cornerX,-$cornerY]
			,[-$cornerX,$cornerY],[0,$sideY],[$cornerX,$cornerY]];
	}

	/**
	 * Whether a rail face exists at the supplied along-rail coordinate.
	 *
	 * @param string $axis Cushion axis, either X or Y.
	 * @param float $coordinate Coordinate along the cushion opening line.
	 */
	public static function cushion(string $axis, float $coordinate): bool
	{
		if($axis === 'x') return abs($coordinate) < self::HEIGHT / 2 - self::CORNER_JAW;

		return abs($coordinate) < self::WIDTH / 2 - self::CORNER_JAW
			&& abs($coordinate) > self::SIDE_MOUTH / 2;
	}

	/**
	 * @param int $index Pocket index in table order.
	 */
	public static function pocketRadius(int $index): float
	{
		return self::LINER_RADIUS;
	}

	/**
	 * Half of the measured jaw-to-jaw mouth, independent of the liner radius.
	 *
	 * @param int $index Pocket index in table order.
	 */
	public static function pocketMouthHalf(int $index): float
	{
		return ($index === 1 || $index === 4 ? self::SIDE_MOUTH : self::CORNER_MOUTH) / 2;
	}

	/**
	 * @param int $index Pocket index in table order.
	 * @return array{float,float} Direction from the playfield into a pocket.
	 */
	public static function pocketOutward(int $index): array
	{
		[$x,$y] = self::pockets()[$index];

		if($index === 1 || $index === 4) return [0.0,(float)($y <=> 0.0)];

		return [($x <=> 0.0) * M_SQRT1_2,($y <=> 0.0) * M_SQRT1_2];
	}

	/**
	 * @param int $index Pocket index in table order.
	 * @return array{float,float} Centre of the regulation jaw chord.
	 */
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

	/**
	 * Return the two tangent jaw endpoints for one regulation pocket mouth.
	 *
	 * Candidate tangencies are selected relative to the pocket's outward direction so corner and side mouths share
	 * one analytic construction and agree with the rendered rubber facings.
	 *
	 * @param int $index Pocket index in table order.
	 * @return array{array{float,float},array{float,float}}
	 */
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

	/** Tangency from one jaw to the round pocket cut. @return array{float,float} */
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

	/**
	 * Return render-matched rubber faces from every jaw to its round throat.
	 *
	 * Each segment is a physical collision surface as well as a mesh boundary, preventing balls from crossing a
	 * visually solid pocket taper or reflecting from geometry the renderer leaves open.
	 *
	 * @return list<array{float,float,float,float}>
	 */
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

	/**
	 * Whether a ball centre is over a slate cut, where the cloth no longer holds it up.
	 *
	 * @param float $x Ball-centre X coordinate.
	 * @param float $y Ball-centre Y coordinate.
	 */
	public static function pocketed(float $x, float $y): bool
	{
		foreach(self::pockets() as [$px,$py])
		if(hypot($x - $px, $y - $py) <= self::THROAT_RADIUS) return true;

		return false;
	}

	/**
	 * Find the slate cut a ball centre has crossed.
	 *
	 * A ball drops as soon as its centre passes the cloth edge, the same circle the renderer cuts from the bed, so
	 * nothing rests over a visible hole; it then tips over the rim into the liner rather than needing to fit it first.
	 *
	 * @param float $x Ball-centre X coordinate.
	 * @param float $y Ball-centre Y coordinate.
	 * @return array{index:int,x:float,y:float}|null
	 */
	public static function pocketEntry(float $x, float $y): ?array
	{
		foreach(self::pockets() as $index => [$px,$py])
		{
			// hypot() never falls below the longer leg by more than an ulp, so a clear box miss is a certain miss.
			if(abs($x - $px) > self::THROAT_RADIUS + 1e-9 || abs($y - $py) > self::THROAT_RADIUS + 1e-9) continue;
			if(hypot($x - $px, $y - $py) <= self::THROAT_RADIUS) return ['index' => $index,'x' => $px,'y' => $py];
		}

		return null;
	}
}

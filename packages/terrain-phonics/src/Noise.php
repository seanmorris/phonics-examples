<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Seeded improved gradient noise with continuous first and second derivatives. */
final class Noise
{
	/** Repeated permutation avoids changing gradients at adjacent cell boundaries. */
	private array $permutation;
	/** Unit gradients avoid axis-dependent amplitude scaling. */
	private const GRADIENTS = [
		[1,0],[-1,0],[0,1],[0,-1]
		, [.7071067811865476,.7071067811865476],[-.7071067811865476,.7071067811865476]
		, [.7071067811865476,-.7071067811865476]
		, [-.7071067811865476,-.7071067811865476]
	];

	/**
	 * Build a reproducible permutation without PHP's shared random generator.
	 * @param string|int $seed Seed used to construct the reproducible gradient permutation.
	 * @return void Builds the repeated permutation table.
	 */
	public function __construct(string|int $seed = 'first-light')
	{
		$values = range(0, 255);

		for($i = 255; $i > 0; $i--)
		{
			$bytes = hash('sha256', (string)$seed . ':' . $i, true);
			$j = (ord($bytes[0]) * 256 + ord($bytes[1])) % ($i + 1);
			[$values[$i], $values[$j]] = [$values[$j], $values[$i]];
		}

		$this->permutation = array_merge($values, $values);
	}

	/**
	 * Share the seed's gradient lookup with batch render backends.
	 * @return array The 256 permutation entries before periodic repetition.
	 */
	public function permutation(): array { return array_slice($this->permutation, 0, 256); }

	/**
	 * Evaluate value and coordinate derivatives as [value, dx, dz].
	 * @param float $x Coordinate in noise-lattice units.
	 * @param float $z Coordinate in noise-lattice units.
	 * @return array Noise value and derivatives [value,dx,dz].
	 */
	public function sample(float $x, float $z): array
	{
		if(!is_finite($x) || !is_finite($z)) throw new \InvalidArgumentException('Noise coordinates must be finite');

		$ix = (int)floor($x);
		$iz = (int)floor($z);
		$x -= $ix;
		$z -= $iz;
		$ix &= 255;
		$iz &= 255;
		$u = self::fade($x);
		$v = self::fade($z);
		$du = 30 * $x * $x * ($x - 1) * ($x - 1);
		$dv = 30 * $z * $z * ($z - 1) * ($z - 1);
		$rows = [];

		for($j = 0; $j < 2; $j++)
		{
			$a = self::GRADIENTS[$this->permutation[$this->permutation[$ix] + $iz + $j] & 7];
			$b = self::GRADIENTS[$this->permutation[$this->permutation[$ix + 1] + $iz + $j] & 7];
			$left = $a[0] * $x + $a[1] * ($z - $j);
			$right = $b[0] * ($x - 1) + $b[1] * ($z - $j);
			$rows[] = [
				$left + $u * ($right - $left)
				, $a[0] + $u * ($b[0] - $a[0]) + $du * ($right - $left)
				, $a[1] + $u * ($b[1] - $a[1])
			];
		}

		return [
			$rows[0][0] + $v * ($rows[1][0] - $rows[0][0])
			, $rows[0][1] + $v * ($rows[1][1] - $rows[0][1])
			, $rows[0][2] + $v * ($rows[1][2] - $rows[0][2]) + $dv * ($rows[1][0] - $rows[0][0])
		];
	}

	/**
	 * Return only the scalar noise value.
	 * @param float $x Coordinate in noise-lattice units.
	 * @param float $z Coordinate in noise-lattice units.
	 * @return float Scalar gradient-noise value.
	 */
	public function value(float $x, float $z): float
	{
		if(!is_finite($x) || !is_finite($z)) throw new \InvalidArgumentException('Noise coordinates must be finite');

		$ix = (int)floor($x);
		$iz = (int)floor($z);
		$x -= $ix;
		$z -= $iz;
		$ix &= 255;
		$iz &= 255;
		$u = self::fade($x);
		$v = self::fade($z);
		$rows = [];

		for($j = 0; $j < 2; $j++)
		{
			$a = self::GRADIENTS[$this->permutation[$this->permutation[$ix] + $iz + $j] & 7];
			$b = self::GRADIENTS[$this->permutation[$this->permutation[$ix + 1] + $iz + $j] & 7];
			$left = $a[0] * $x + $a[1] * ($z - $j);
			$right = $b[0] * ($x - 1) + $b[1] * ($z - $j);
			$rows[] = $left + $u * ($right - $left);
		}

		return $rows[0] + $v * ($rows[1] - $rows[0]);
	}

	/**
	 * Bound noise over a rectangle by Taylor expansion around its centre.
	 * @param float $x Centre X in noise coordinates.
	 * @param float $z Centre Z in noise coordinates.
	 * @param float $rx Horizontal half-width.
	 * @param float $rz Vertical half-width.
	 * @return array Minimum, maximum and absolute bounds on both partial derivatives.
	 */
	public function envelope(float $x, float $z, float $rx, float $rz): array
	{
		// Unit gradients, corner dot products bounded by sqrt(2), fade' <= 1.875
		// and |fade''| < 6 give |first partial| < 7 and |second partial| < 32,
		// including the mixed partial. Noise is C2 across cell boundaries.
		$rx += 32 * PHP_FLOAT_EPSILON * (1 + abs($x) + $rx);
		$rz += 32 * PHP_FLOAT_EPSILON * (1 + abs($z) + $rz);
		$r = $rx + $rz;
		if($r >= .5) return [-1.42,1.42,7.0,7.0];
		[$value,$dx,$dz] = $this->sample($x, $z);
		$radius = abs($dx) * $rx + abs($dz) * $rz + 16 * $r * $r + 1e-12;
		return [max(-1.42, $value - $radius), min(1.42, $value + $radius),
			min(7.0, abs($dx) + 32 * $r + 1e-12), min(7.0, abs($dz) + 32 * $r + 1e-12)];
	}

	/**
	 * Quintic interpolation has zero first and second derivatives at both ends.
	 * @param float $t Interpolation coordinate between zero and one.
	 * @return float Quintic blend with vanishing endpoint first and second derivatives.
	 */
	public static function fade(float $t): float { return $t * $t * $t * ($t * ($t * 6 - 15) + 10); }
}

<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Validated, ordered terrain edits with compact smooth influence fields. */
final class Edits
{

	/**
	 * Normalize one serializable edit and rebuild its bounded road acceleration data.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Validated operation with bounded derived acceleration data.
	 */
	public static function normalize(array $operation): array
	{
		$type = $operation['type'] ?? null;

		if(!in_array($type, ['raise','lower','flatten','smooth','crater','material','road'], true))
		throw new \InvalidArgumentException('Unknown terrain edit type');

		if($type === 'road') return self::road($operation);

		$result = [
			'type' => $type
			, 'x' => self::number($operation['x'] ?? null, 'x', -1e9, 1e9)
			, 'z' => self::number($operation['z'] ?? null, 'z', -1e9, 1e9)
			, 'radius' => self::number($operation['radius'] ?? null, 'radius', .001, 32768)
		];

		if(in_array($type, ['raise','lower','crater'], true))
		$result['amount'] = self::number($operation['amount'] ?? 1, 'amount', 0, 65536);

		if(in_array($type, ['flatten','smooth'], true))
		$result['amount'] = self::number($operation['amount'] ?? 1, 'amount', 0, 1);

		if($type === 'flatten')
		$result['height'] = self::number($operation['height'] ?? null, 'height', -1e6, 1e6);

		if($type === 'smooth')
		$result['sampleStep'] = self::number($operation['sampleStep'] ?? 1, 'sampleStep', .001, 32768);

		if($type === 'material' && !array_key_exists('material', $operation))
		throw new \InvalidArgumentException('Material edits require a material identifier or null');

		if(array_key_exists('material', $operation))
		$result['material'] = self::materialName($operation['material']);

		return $result;
	}

	/**
	 * Return the conservative horizontal support as minimum X/Z and maximum X/Z.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Influence rectangle [minX,minZ,maxX,maxZ].
	 */
	public static function bounds(array $operation): array
	{
		if($operation['type'] === 'road') return $operation['_road']['bounds'];

		$r = $operation['radius'];

		return [$operation['x'] - $r, $operation['z'] - $r, $operation['x'] + $r, $operation['z'] + $r];
	}

	/**
	 * Apply one edit to a node. The callback samples the surface before this edit. Smooth edits use a fixed lattice
	 * step so memoized histories share their work.
	 * @param float $x World X coordinate in metres.
	 * @param float $z World Z coordinate in metres.
	 * @param float $height Previous height in metres.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @param callable $before Height evaluator for the surface before this edit, used by smoothing.
	 * @return float Edited lattice height in metres.
	 */
	public static function apply(float $x, float $z, float $height, array $operation, callable $before): float
	{
		$type = $operation['type'];

		if($type === 'material') return $height;

		if($type === 'road')
		{
			[$weight,$target] = self::roadAt($x, $z, $operation);

			return $height + $weight * ($target - $height);
		}

		$q = (($x - $operation['x']) ** 2 + ($z - $operation['z']) ** 2) / $operation['radius'] ** 2;

		if($q >= 1) return $height;

		$weight = self::falloff($q);
		$amount = $operation['amount'] ?? 0;

		if($amount === 0.0 || $weight <= 0) return $height;

		if($type === 'raise') return $height + $amount * $weight;

		if($type === 'lower') return $height - $amount * $weight;

		if($type === 'flatten') return $height + $amount * $weight * ($operation['height'] - $height);

		if($type === 'crater')
		{
			// A smooth depressed centre and low annular rim, both zero at the boundary.
			return $height + $amount * $weight * (2 * $q - 1);
		}

		$step = $operation['sampleStep'];
		$target = ($height + $before($x - $step, $z) + $before($x + $step, $z)
			+ $before($x, $z - $step) + $before($x, $z + $step)
		) / 5;

		return $height + $amount * $weight * ($target - $height);
	}

	/**
	 * Return the most recent material covering the query, including explicit erasure.
	 * @param float $x World X coordinate in metres.
	 * @param float $z World Z coordinate in metres.
	 * @param array $operations Committed edits in application order.
	 * @return ?string Painted material identifier, or null for procedural classification.
	 */
	public static function material(float $x, float $z, array $operations): ?string
	{
		for($i = count($operations) - 1; $i >= 0; $i--)
		{
			$operation = $operations[$i];

			if(!array_key_exists('material', $operation)) continue;

			if($operation['type'] === 'road') $weight = self::roadAt($x, $z, $operation)[0];
			else
			{
				$q = (($x - $operation['x']) ** 2 + ($z - $operation['z']) ** 2) / $operation['radius'] ** 2;
				$weight = self::falloff($q);
			}

			if($weight >= .5) return $operation['material'];
		}

		return null;
	}

	/**
	 * Propagate conservative node-height limits through an operation.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @param float $minimum Conservative minimum of previous node heights.
	 * @param float $maximum Conservative maximum of previous node heights.
	 * @return array Conservative minimum and maximum edited node heights.
	 */
	public static function heightBounds(array $operation, float $minimum, float $maximum): array
	{
		return match($operation['type'])
		{
			'raise' => [$minimum, $maximum + $operation['amount']],
			'lower' => [$minimum - $operation['amount'], $maximum],
			'crater' => [$minimum - $operation['amount'], $maximum + $operation['amount']],
			'flatten' => [min($minimum, $operation['height']), max($maximum, $operation['height'])],
			'road' => [min($minimum, $operation['_road']['range'][0]), max($maximum, $operation['_road']['range'][1])],
			default => [$minimum, $maximum]
		};
	}

	/**
	 * Strip rebuildable acceleration data before persisting an edit.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Portable edit fields without derived caches.
	 */
	public static function serialize(array $operation): array
	{
		unset($operation['_road']);

		return $operation;
	}

	/**
	 * Quintic falloff in squared radius is twice continuously differentiable.
	 * @param float $q Normalized distance from the brush or road centre.
	 * @return float Smooth weight from one at the centre to zero at the boundary.
	 */
	private static function falloff(float $q): float
	{
		if($q <= 0) return 1;

		if($q >= 1) return 0;

		return 1 - $q * $q * $q * ($q * ($q * 6 - 15) + 10);
	}

	/**
	 * Validate a finite scalar without accepting numeric strings or booleans.
	 * @param mixed $value Input value to validate.
	 * @param string $name Field name used in validation errors.
	 * @param float $minimum Inclusive allowed minimum.
	 * @param float $maximum Inclusive allowed maximum.
	 * @return float Validated finite numeric value.
	 */
	private static function number(mixed $value, string $name, float $minimum, float $maximum): float
	{
		if((!is_int($value) && !is_float($value)) || !is_finite((float)$value)
			|| $value < $minimum || $value > $maximum
		)
		throw new \InvalidArgumentException("Invalid terrain edit $name");

		return (float)$value;
	}

	/**
	 * Material identifiers are portable names; null restores the recipe material.
	 * @param mixed $value Input value to validate.
	 * @return ?string Validated material name or null for erasure.
	 */
	private static function materialName(mixed $value): ?string
	{
		if($value === null) return null;

		if(!is_string($value) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D', $value))
		throw new \InvalidArgumentException('Invalid terrain material identifier');

		return $value;
	}

	/**
	 * Sample a Catmull-Rom centreline into overlapping smooth local road planes. Compact kernels avoid
	 * nearest-segment changes at bends, ends and junctions.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Normalized road and bounded spatially indexed spline samples.
	 */
	private static function road(array $operation): array
	{
		$points = $operation['points'] ?? null;

		if(!is_array($points) || !array_is_list($points) || count($points) < 2 || count($points) > 128)
		throw new \InvalidArgumentException('Roads require between 2 and 128 control points');

		foreach($points as $i => $point)
		{
			if(!is_array($point) || !array_is_list($point) || count($point) !== 3)
			throw new \InvalidArgumentException('Road points must be [x, y, z]');

			foreach($point as $axis => $coordinate)
			$points[$i][$axis] = self::number($coordinate, 'road point', -1e6, 1e6);

			if($i && hypot($points[$i][0] - $points[$i - 1][0], $points[$i][2] - $points[$i - 1][2]) < .001)
			throw new \InvalidArgumentException('Consecutive road points must differ horizontally');
		}

		$width = self::number($operation['width'] ?? 8, 'road width', .5, 1024);
		$shoulder = self::number($operation['shoulder'] ?? 2, 'road shoulder', .01, 1024);
		$crown = self::number($operation['crown'] ?? 0, 'road crown', -1024, 1024);
		$step = min(4, $width / 4);
		$half = $width / 2;
		$radius = $half + $shoulder;
		$samples = [];

		for($i = 0; $i < count($points) - 1; $i++)
		{
			$p1 = $points[$i];
			$p2 = $points[$i + 1];
			$p0 = $points[max(0, $i - 1)];
			$p3 = $points[min(count($points) - 1, $i + 2)];
			// Derivative bounds bound each segment's arc length, including spline overshoot.
			$coefficients = [];

			for($axis = 0; $axis < 3; $axis++)
			$coefficients[$axis] = [
				$p1[$axis]
				, .5 * (-$p0[$axis] + $p2[$axis])
				, .5 * (2 * $p0[$axis] - 5 * $p1[$axis] + 4 * $p2[$axis] - $p3[$axis])
				, .5 * (-$p0[$axis] + 3 * $p1[$axis] - 3 * $p2[$axis] + $p3[$axis])
			];

			$dx = abs($coefficients[0][1]) + 2 * abs($coefficients[0][2]) + 3 * abs($coefficients[0][3]);
			$dz = abs($coefficients[2][1]) + 2 * abs($coefficients[2][2]) + 3 * abs($coefficients[2][3]);
			$steps = max(1, (int)ceil(hypot($dx, $dz) / $step));

			if(count($samples) + $steps + 1 > 4096)
			throw new \InvalidArgumentException('Road exceeds 4096 samples; split it into shorter edits');

			for($j = ($i ? 1 : 0); $j <= $steps; $j++)
			{
				$t = $j / $steps;
				$position = $tangent = [];

				foreach($coefficients as [$a,$b,$c,$d])
				{
					$position[] = $a + $t * ($b + $t * ($c + $t * $d));
					$tangent[] = $b + $t * (2 * $c + $t * 3 * $d);
				}

				$length = hypot($tangent[0], $tangent[2]);

				if($length < 1e-8)
				{
					$tangent = [$p2[0] - $p1[0], $p2[1] - $p1[1], $p2[2] - $p1[2]];
					$length = hypot($tangent[0], $tangent[2]);
				}

				$samples[] = [...$position, $tangent[0] / $length, $tangent[2] / $length, $tangent[1] / $length];
			}
		}

		$grid = [];
		$bounds = [INF,INF,-INF,-INF];
		$range = [INF,-INF];

		foreach($samples as $index => [$x,$y,$z,$tx,$tz,$grade])
		{
			$bounds = [
				min($bounds[0], $x - $radius), min($bounds[1], $z - $radius)
				, max($bounds[2], $x + $radius), max($bounds[3], $z + $radius)
			];

			$extent = abs($grade) * $radius + abs($crown);
			$range = [min($range[0], $y - $extent), max($range[1], $y + $extent)];

			for($gx = (int)floor(($x - $radius) / $radius); $gx <= (int)floor(($x + $radius) / $radius); $gx++)
			for($gz = (int)floor(($z - $radius) / $radius); $gz <= (int)floor(($z + $radius) / $radius); $gz++)
			$grid["$gx,$gz"][] = $index;
		}

		return [
			'type' => 'road'
			, 'points' => $points
			, 'width' => $width
			, 'shoulder' => $shoulder
			, 'crown' => $crown
			, 'material' => self::materialName(
				array_key_exists('material', $operation) ? $operation['material'] : 'road'
			)
			, '_road' => compact('samples', 'grid', 'radius', 'half', 'bounds', 'range')
		];
	}

	/**
	 * Blend only nearby kernels; no hard minimum or winner selection affects geometry.
	 * @param float $x World X coordinate in metres.
	 * @param float $z World Z coordinate in metres.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Blended road height, influence weight and width information.
	 */
	private static function roadAt(float $x, float $z, array $operation): array
	{
		$road = $operation['_road'];
		$radius = $road['radius'];
		$key = (int)floor($x / $radius) . ',' . (int)floor($z / $radius);
		$sum = $target = 0.0;
		$outside = 1.0;
		$inner2 = $road['half'] ** 2;
		$span = $radius ** 2 - $inner2;

		foreach($road['grid'][$key] ?? [] as $index)
		{
			[$sx,$sy,$sz,$tx,$tz,$grade] = $road['samples'][$index];
			$dx = $x - $sx;
			$dz = $z - $sz;
			$weight = self::falloff(($dx * $dx + $dz * $dz - $inner2) / $span);

			if($weight <= 0) continue;

			$cross = -$tz * $dx + $tx * $dz;
			$crown = max(0, 1 - $cross * $cross / $inner2) ** 3;
			$target += $weight * ($sy + $grade * ($tx * $dx + $tz * $dz) + $operation['crown'] * $crown);
			$sum += $weight;
			$outside *= 1 - $weight;
		}

		return $sum > 0 ? [1 - $outside, $target / $sum] : [0.0, 0.0];
	}
}

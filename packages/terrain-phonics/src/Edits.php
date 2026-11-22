<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Validated, ordered terrain edits with compact smooth influence fields. */
final class Edits
{
	public const MAX_ROAD_SAMPLES = 65536;

	/**
	 * Normalize one serializable edit and rebuild its bounded road acceleration data.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Validated operation with bounded derived acceleration data.
	 */
	public static function normalize(array $operation): array
	{
		$work = self::normalizeSteps($operation);
		foreach($work as $_) {}
		return $work->getReturn();
	}

	/** The same operation, with scheduling points while a long spline and its footprint are prepared. */
	public static function normalizeSteps(array $operation): \Generator
	{
		$type = $operation['type'] ?? null;

		if(!in_array($type, ['raise','lower','flatten','smooth','crater','material','road'], true))
		throw new \InvalidArgumentException('Unknown terrain edit type');

		if(array_key_exists('replaceAt', $operation)) {
			if($type !== 'road' || !is_int($operation['replaceAt']) || $operation['replaceAt'] < 0)
				throw new \InvalidArgumentException('A road replacement needs an existing edit index.');
			return (yield from self::road($operation)) + ['replaceAt'=>$operation['replaceAt']];
		}
		if($type === 'road') return yield from self::road($operation);

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

	/** Index road strips along the spline so a diagonal road never paints its entire bounding rectangle. */
	public static function footprints(array $operation, float $minimumCell): array
	{
		if($operation['type'] !== 'road') return [self::bounds($operation)];
		$road = $operation['_road'];
		$stride = max(1,(int)ceil($minimumCell/$road['radius']));
		$rows = [];
		foreach(array_keys($road['grid']) as $key)
		{
			[$x,$z] = explode(',',$key);
			$rows[(int)floor((int)$z/$stride)][(int)floor((int)$x/$stride)] = true;
		}
		$cell = $road['radius']*$stride;
		$boxes = [];
		foreach($rows as $z => $columns)
		{
			$columns = array_keys($columns);
			sort($columns,SORT_NUMERIC);
			$first = $last = $columns[0];
			foreach(array_slice($columns,1) as $x)
			{
				if($x>$last+1)
				{
					$boxes[] = [$first*$cell,$z*$cell,($last+1)*$cell,($z+1)*$cell];
					$first = $x;
				}
				$last = $x;
			}
			$boxes[] = [$first*$cell,$z*$cell,($last+1)*$cell,($z+1)*$cell];
		}
		return $boxes;
	}

	/**
	 * Apply one edit to a node. The callback samples the surface before this edit. Smooth edits use a fixed lattice
	 * step so memoized histories share their work.
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @param float $height Previous height in meters.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @param callable $before Height evaluator for the surface before this edit, used by smoothing.
	 * @return float Edited lattice height in meters.
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
			// A smooth depressed center and low annular rim, both zero at the boundary.
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
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @param array $operations Committed edits in application order.
	 * @return ?string Painted material identifier, or null for procedural classification.
	 */
	public static function material(float $x, float $z, array $operations, ?array &$centerOffset = null, ?array &$mark = null): ?string
	{
		$withMark = func_num_args() >= 4;
		$mark = null;
		$centerOffset = null;
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

			if($weight >= .5) {
				if($withMark && $operation['type'] === 'road' && $operation['material'] === 'road')
					$centerOffset = self::centerOffset($x,$z,$operation['_road'],$mark);
				return $operation['material'];
			}
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
	 * @param float $q Normalized distance from the brush or road center.
	 * @return float Smooth weight from one at the center to zero at the boundary.
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

	/** Cubic handles relative to a control point. Automatic handles reproduce the uniform Catmull-Rom curve. */
	public static function roadHandles(array $points, array $handles, int $i): array
	{
		$prev = $points[max(0, $i-1)];
		$next = $points[min(count($points)-1, $i+1)];
		$out = array_map(static fn($a,$b) => ($b-$a)/6., $prev, $next);
		return ['in'=>$handles[$i]['in'] ?? array_map(static fn($x) => -$x, $out), 'out'=>$handles[$i]['out'] ?? $out];
	}

	/**
	 * Sample the cubic centerline into overlapping smooth local road planes. Compact kernels avoid
	 * nearest-segment changes at bends, ends and junctions.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Normalized road and bounded spatially indexed spline samples.
	 */
	private static function road(array $operation): \Generator
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
		$endBlend = self::number($operation['endBlend'] ?? 0, 'road end blend', 0, 1024);
		$banks = $operation['banks'] ?? null;
		if($banks !== null)
		{
			if(!is_array($banks) || !array_is_list($banks) || count($banks) !== count($points))
				throw new \InvalidArgumentException('A banked road requires one cross slope per control point');
			$banks = array_map(static fn($bank): float => self::number($bank, 'road bank', -.35, .35), $banks);
		}
		$handles = $operation['handles'] ?? [];
		if(!is_array($handles) || !array_is_list($handles) || ($handles && count($handles) !== count($points)))
			throw new \InvalidArgumentException('Road handles must correspond to the control points.');
		foreach($handles as $i => $pair) {
			if($pair === null) continue;
			if(!is_array($pair) || array_diff_key($pair, ['in'=>1,'out'=>1]))
				throw new \InvalidArgumentException('Expected incoming or outgoing road handles.');
			foreach($pair as $side => $vector) {
				if(!is_array($vector) || !array_is_list($vector) || count($vector) !== 3)
					throw new \InvalidArgumentException('Road handles must be [dx, dy, dz] in meters.');
				$handles[$i][$side] = array_map(static fn($v) => self::number($v,'handle offset',-1e6,1e6), $vector);
			}
		}
		$tangents = [];
		foreach(array_keys($points) as $i) $tangents[] = self::roadHandles($points, $handles, $i);
		$step = min(4, $width / 4);
		$half = $width / 2;
		$radius = $half + $shoulder;
		$samples = [];
		$totalChord = 0.;
		foreach($points as $i=>$p) if($i) $totalChord += hypot($p[0]-$points[$i-1][0],$p[2]-$points[$i-1][2]);
		$chunked = $totalChord/$step > 2048.;

		for($i = 0; $i < count($points) - 1; $i++)
		{
			$p1 = $points[$i];
			$p2 = $points[$i + 1];
			// Derivative bounds bound each segment's arc length, including spline overshoot.
			$coefficients = [];

			for($axis = 0; $axis < 3; $axis++) {
				$start = 3 * $tangents[$i]['out'][$axis];
				$end = -3 * $tangents[$i+1]['in'][$axis];
				$delta = $p2[$axis] - $p1[$axis];
				$coefficients[$axis] = [$p1[$axis], $start, 3*$delta-2*$start-$end, $start+$end-2*$delta];
			}

			$dx = abs($coefficients[0][1]) + 2 * abs($coefficients[0][2]) + 3 * abs($coefficients[0][3]);
			$dz = abs($coefficients[2][1]) + 2 * abs($coefficients[2][2]) + 3 * abs($coefficients[2][3]);
			$steps = max(1, (int)ceil(hypot($dx, $dz) / $step));

			if(count($samples)+$steps+1 > 4096) {
				// A cubic derivative lies inside the convex hull of its three Bézier derivative controls.
				$speeds = [];
				foreach([0,1,2] as $control) {
					$axes = [];
					foreach([0,2] as $axis) {
						[, $b,$c,$d] = $coefficients[$axis];
						$axes[] = match($control) { 0=>$b, 1=>$b+$c, 2=>$b+2*$c+3*$d };
					}
					$speeds[] = hypot(...$axes);
				}
				$steps = max(1,(int)ceil(max($speeds)/$step));
			}
			if(count($samples)+$steps+($i === 0 ? 1 : 0) > self::MAX_ROAD_SAMPLES)
				throw new \OverflowException('Road exceeds the landscape sample budget');

			for($j = ($i ? 1 : 0); $j <= $steps; $j++)
			{
				if(($chunked || count($samples)>=4096) && (count($samples)&255)===255) yield;
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

				$bank = $banks === null ? 0. : $banks[$i] + ($banks[$i+1]-$banks[$i]) * $t;
				$samples[] = [...$position, $tangent[0] / $length, $tangent[2] / $length, $tangent[1] / $length, $bank, 1.];
			}
		}

		// Feather each cap along the spline so overlapping sections hand over the road surface gradually.
		if($endBlend > 0)
		{
			$last = count($samples) - 1;
			foreach([1, -1] as $direction)
			{
				$i = $direction === 1 ? 0 : $last;
				$distance = 0.;
				while($distance < $endBlend)
				{
					$samples[$i][7] = min($samples[$i][7], 1 - self::falloff($distance / $endBlend));
					$next = $i + $direction;
					if($next < 0 || $next > $last) break;
					$distance += hypot($samples[$next][0] - $samples[$i][0], $samples[$next][2] - $samples[$i][2]);
					$i = $next;
				}
			}
		}

		$stations = [0.];
		$grid = [];
		$bounds = [INF,INF,-INF,-INF];
		$range = [INF,-INF];

		foreach($samples as $index => [$x,$y,$z,$tx,$tz,$grade,$bank])
		{
			if($index > 0) {
				if(($chunked || count($samples)>4096) && ($index & 255) === 0) yield;
				[$px,$py,$pz] = $samples[$index-1];
				$stations[] = $stations[$index-1]+hypot(hypot($x-$px,$z-$pz),$y-$py);
			}
			$bounds = [
				min($bounds[0], $x - $radius), min($bounds[1], $z - $radius)
				, max($bounds[2], $x + $radius), max($bounds[3], $z + $radius)
			];

			$extent = hypot($grade,$bank) * $radius + abs($crown);
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
			, '_road' => compact('samples', 'stations', 'grid', 'radius', 'half', 'bounds', 'range')
		] + ($banks === null ? [] : ['banks' => $banks]) + ($endBlend > 0 ? ['endBlend' => $endBlend] : [])
			+ (array_filter($handles) ? ['handles'=>$handles] : []);
	}

	/**
	 * Blend only nearby kernels; no hard minimum or winner selection affects geometry.
	 * @param float $x World X coordinate in meters.
	 * @param float $z World Z coordinate in meters.
	 * @param array $operation Validated edit record, including derived road data when applicable.
	 * @return array Blended road height, influence weight and width information.
	 */
	private static function roadAt(float $x, float $z, array $operation): array
	{
		$road = $operation['_road'];
		$radius = $road['radius'];
		$key = (int)floor($x / $radius) . ',' . (int)floor($z / $radius);
		$sum = $target = $coverage = 0.0;
		$outside = 1.0;
		$inner2 = $road['half'] ** 2;
		$span = $radius ** 2 - $inner2;

		foreach($road['grid'][$key] ?? [] as $index)
		{
			[$sx,$sy,$sz,$tx,$tz,$grade,$bank,$opacity] = $road['samples'][$index];
			$dx = $x - $sx;
			$dz = $z - $sz;
			$weight = self::falloff(($dx * $dx + $dz * $dz - $inner2) / $span);

			if($weight <= 0) continue;

			$cross = -$tz * $dx + $tx * $dz;
			$crown = max(0, 1 - $cross * $cross / $inner2) ** 3;
			$target += $weight * ($sy + $grade * ($tx * $dx + $tz * $dz) + $bank * $cross + $operation['crown'] * $crown);
			$sum += $weight;
			$coverage += $weight * $opacity;
			$outside *= 1 - $weight;
		}

		return $sum > 0 ? [(1 - $outside) * ($coverage / $sum), $target / $sum] : [0.0, 0.0];
	}

	private static function centerOffset(float $x, float $z, array $road, ?array &$mark): ?array
	{
		$r = $road['radius'];
		$key = (int)floor($x/$r) . ',' . (int)floor($z/$r);
		$nearest = INF; $offset = null; $last = count($road['samples'])-1;
		foreach($road['grid'][$key] ?? [] as $i) {
			[$ax,,$az] = $road['samples'][$i];
			$dx = $x-$ax; $dz = $z-$az;
			if($dx*$dx+$dz*$dz >= $r*$r) continue;
			[$bx,,$bz] = $road['samples'][$i+1] ?? $road['samples'][$i];
			$vx = $bx-$ax; $vz = $bz-$az;
			$t = max(0.,min(1.,($dx*$vx+$dz*$vz)/max(1e-12,$vx*$vx+$vz*$vz)));
			$dx -= $t*$vx; $dz -= $t*$vz;
			$d = $dx*$dx+$dz*$dz;
			if($d >= $nearest) continue;
			$nearest = $d; $offset = [$dx,$dz];
			$dot = $dx*$vx+$dz*$vz;
			if($i === $last || ($i === 0 && $t === 0. && $dot < 0.) || ($i+1 === $last && $t === 1. && $dot > 0.)) {
				$mark = null;
				continue;
			}
			$arc = $road['stations'][$i];
			$mark = [($vx*$dz-$vz*$dx < 0. ? -1. : 1.)*sqrt($d),
				$arc+$t*($road['stations'][$i+1]-$arc)];
		}
		return $offset;
	}
}

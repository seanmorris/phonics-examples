<?php
declare(strict_types=1);
namespace PhpBilliards\Geometry;

use PhpBilliards\Physics\Ball;

/**
 * Pure procedural billiards geometry used by the build-time mesh generator and geometry tests.
 *
 * Its constructive helpers produce rounded extrusions, revolved solids, spheres, and the complete table
 * from shared profiles, leaving runtime code to upload compact checked mesh data instead of rebuilding it.
 */
final class MeshBuilder
{
	/**
	 * Build the complete named mesh set consumed by the runtime renderer.
	 *
	 * Every table material comes from the canonical shared profiles, while reusable primitives and cue sections are
	 * generated with stable tessellation before each mesh is validated and packed.
	 *
	 * @return array<string,MeshData>
	 */
	public static function all(): array
	{
		$profiles = TableGeometry::profiles();

		return [
			'apron' => self::extrude($profiles['apron'], $profiles['bounds'], 112, 64, -.25, TableGeometry::APRON_TOP)
			, 'bed' => self::extrude($profiles['bed'], $profiles['bounds'], 128, 72, -.047, TableGeometry::TABLETOP_TOP)
			// The rail reaches down to the apron so no slot under it exposes the open pocket shafts; the join is flush.
			, 'wood' => self::extrudeRounded(
				$profiles['wood'], $profiles['bounds'], TableGeometry::COLUMNS, TableGeometry::ROWS
				, TableGeometry::APRON_TOP, .105, TableGeometry::WOOD_EDGE_RADIUS, 3, 0.0
			)
			, 'cushion' => self::cushions($profiles['cushionRails'], $profiles['bounds'])
			, 'liners' => self::liners($profiles['pockets'], Ball::RADIUS)
			, 'cube' => self::cube()
			, 'cueWood' => self::cylinder(.5, .0125 / .0285 / 2)
			, 'cueTrim' => self::cylinder()
			, 'sphere' => self::sphere()
			, 'disc' => self::disc()
		];
	}

	private static function point(callable $field, float $x, float $z, float $offset = 0): array
	{
		return ['x' => $x,'z' => $z,'distance' => $field($x, $z) + $offset];
	}

	private static function interpolate(array $a, array $b): array
	{
		$amount = $a['distance'] / ($a['distance'] - $b['distance']);

		return [
			'x' => $a['x'] + ($b['x'] - $a['x']) * $amount
			, 'z' => $a['z'] + ($b['z'] - $a['z']) * $amount
			, 'distance' => 0.0
		];
	}

	private static function unique(array $points): array
	{
		$result = [];
		foreach($points as $point)
		{
			$duplicate = false;
			foreach($result as $candidate)
			if(hypot($point['x'] - $candidate['x'], $point['z'] - $candidate['z']) < 1e-9)
			{
				$duplicate = true;
				break;
			}
			if(!$duplicate) $result[] = $point;
		}

		return $result;
	}

	/** @return array{array,array}|array{} */
	private static function endpoints(array $points): array
	{
		$pair = [];
		$distance = -1.0;

		for($a = 0; $a < count($points); $a++) for($b = $a + 1; $b < count($points); $b++)
		{
			$candidate = ($points[$a]['x'] - $points[$b]['x']) ** 2 + ($points[$a]['z'] - $points[$b]['z']) ** 2;
			if($candidate > $distance)
			{
				$distance = $candidate;
				$pair = [$points[$a],$points[$b]];
			}
		}

		return $pair;
	}

	/** @return array{float,float} */
	private static function gradient(callable $field, array $point, float $epsilon): array
	{
		$x = $field($point['x'] + $epsilon, $point['z']) - $field($point['x'] - $epsilon, $point['z']);
		$z = $field($point['x'], $point['z'] + $epsilon) - $field($point['x'], $point['z'] - $epsilon);
		$length = hypot($x, $z) ?: 1;

		return [$x / $length,$z / $length];
	}

	private static function clippedTriangle(array $triangle): array
	{
		$polygon = [];
		$crossings = [];

		for($edge = 0; $edge < 3; $edge++)
		{
			$a = $triangle[$edge];
			$b = $triangle[($edge + 1) % 3];
			$inside = $a['distance'] <= 0;
			$nextInside = $b['distance'] <= 0;
			if($inside) $polygon[] = $a;
			if(abs($a['distance']) < 1e-10) $crossings[] = $a;
			if($inside !== $nextInside)
			{
				$crossing = self::interpolate($a, $b);
				$polygon[] = $crossing;
				$crossings[] = $crossing;
			}
		}

		return [self::unique($polygon),self::endpoints(self::unique($crossings))];
	}

	/**
	 * Trace the sampled zero contour into closed, oriented loops and collapse only collinear spans.
	 *
	 * The sampling grid continues to control curved pocket mouths. Straight rail spans no longer retain every grid
	 * crossing as a separate wall and fillet segment, so their analytic shape is preserved with far fewer triangles.
	 *
	 * @param callable(float,float):float $field Signed-distance field sampled in table space.
	 * @param array{float,float,float,float} $bounds Minimum and maximum X/Z sampling bounds.
	 * @param int $columns Horizontal sampling-cell count.
	 * @param int $rows Vertical sampling-cell count.
	 * @return list<array{points:list<array{x:float,z:float,distance:float}>,closed:bool}>
	 */
	private static function boundaryLoops(callable $field, array $bounds, int $columns, int $rows): array
	{
		[$minX,$minZ,$maxX,$maxZ] = $bounds;
		$stepX = ($maxX - $minX) / $columns;
		$stepZ = ($maxZ - $minZ) / $rows;
		$epsilon = min($stepX, $stepZ) * .7;
		$segments = [];
		// Round before formatting and add zero so points on the table's centre axes never key as "-0.000000000".
		$key = static fn(array $point): string => sprintf('%.9f:%.9f', round($point['x'], 9) + 0.0, round($point['z'], 9) + 0.0);

		for($row = 0; $row < $rows; $row++) for($column = 0; $column < $columns; $column++)
		{
			$left = $minX + $column * $stepX;
			$right = $left + $stepX;
			$near = $minZ + $row * $stepZ;
			$far = $near + $stepZ;
			$corners = [
				self::point($field, $left, $near), self::point($field, $right, $near)
				, self::point($field, $right, $far), self::point($field, $left, $far)
			];
			$center = self::point($field, ($left + $right) / 2, ($near + $far) / 2);

			for($corner = 0; $corner < 4; $corner++)
			{
				[, $boundary] = self::clippedTriangle([$corners[$corner],$corners[($corner + 1) % 4],$center]);
				if(count($boundary) !== 2) continue;
				$segments[] = $boundary;
			}
		}

		$adjacent = [];
		foreach($segments as $index => [$a,$b])
		{
			$adjacent[$key($a)][] = $index;
			$adjacent[$key($b)][] = $index;
		}
		$used = [];
		$loops = [];
		// Follow unused neighbours from one end of a chain; segments arrive in grid order, so a chain must grow in
		// both directions or every contour running against that order splinters into per-cell fragments.
		$extend = static function(string $endKey, string $stopKey, array &$used) use ($segments,$adjacent,$key): array {
			$points = [];

			while($endKey !== $stopKey)
			{
				$nextSegment = null;
				foreach($adjacent[$endKey] ?? [] as $candidate) if(!isset($used[$candidate]))
				{
					$nextSegment = $candidate;
					break;
				}
				if($nextSegment === null) break;
				$used[$nextSegment] = true;
				[$a,$b] = $segments[$nextSegment];
				$next = $key($a) === $endKey ? $b : $a;
				$points[] = $next;
				$endKey = $key($next);
			}

			return [$points,$endKey === $stopKey];
		};

		foreach($segments as $start => [$first,$second])
		{
			if(isset($used[$start])) continue;
			$used[$start] = true;
			[$forward,$closed] = $extend($key($second), $key($first), $used);
			$points = [$first,$second,...$forward];

			if($closed) array_pop($points);
			else
			{
				[$backward] = $extend($key($first), $key(end($points)), $used);
				$points = [...array_reverse($backward),...$points];
			}
			$a = $points[0];
			$b = $points[1];
			$midpoint = ['x' => ($a['x'] + $b['x']) / 2,'z' => ($a['z'] + $b['z']) / 2];
			[$nx,$nz] = self::gradient($field, $midpoint, $epsilon);
			if(-($b['z'] - $a['z']) * $nx + ($b['x'] - $a['x']) * $nz < 0) $points = array_reverse($points);
			$changed = true;
			while($changed && count($points) > 3)
			{
				$changed = false;
				$reduced = $closed ? [] : [$points[0]];
				$count = count($points);
				$startIndex = $closed ? 0 : 1;
				$endIndex = $closed ? $count : $count - 1;
				for($index = $startIndex; $index < $endIndex; $index++)
				{
					$previous = $points[$closed ? ($index - 1 + $count) % $count : $index - 1];
					$currentPoint = $points[$index];
					$next = $points[$closed ? ($index + 1) % $count : $index + 1];
					$ax = $currentPoint['x'] - $previous['x'];
					$az = $currentPoint['z'] - $previous['z'];
					$bx = $next['x'] - $currentPoint['x'];
					$bz = $next['z'] - $currentPoint['z'];
					$length = hypot($ax, $az) * hypot($bx, $bz);
					$collinear = $length > 1e-15
						&& abs($ax * $bz - $az * $bx) / $length < 1e-8
						&& ($ax * $bx + $az * $bz) > 0;
					if($collinear)
					{
						$changed = true;
						continue;
					}
					$reduced[] = $currentPoint;
				}
				if(!$closed) $reduced[] = $points[$count - 1];
				if(count($reduced) >= 3) $points = $reduced; else $changed = false;
			}
			$loops[] = ['points' => $points,'closed' => $closed];
		}

		return $loops;
	}

	/**
	 * Add one planar cap while retaining the full sampling resolution at its outline.
	 *
	 * Boundary cells still use the marching-triangle clipping that defines the pocket mouths and curved perimeter.
	 * Consecutive cells wholly inside the profile become one row quad because their normals and world-linear UVs are
	 * identical; this avoids sending hundreds of thousands of coplanar triangles to the runtime renderer.
	 *
	 * @param callable(float,float):float $field Signed-distance field sampled in table space.
	 * @param array{float,float,float,float} $bounds Minimum and maximum X/Z sampling bounds.
	 * @param int $columns Horizontal sampling-cell count.
	 * @param int $rows Vertical sampling-cell count.
	 * @param float $offset Inward signed-distance offset for the cap outline.
	 * @param float $y Cap height.
	 * @param int $direction Positive for an upward cap and negative for a downward cap.
	 */
	private static function appendCap(
		callable $field, array $bounds, int $columns, int $rows, float $offset, float $y
		, int $direction, MeshData $mesh
	): void {
		[$minX,$minZ,$maxX,$maxZ] = $bounds;
		$stepX = ($maxX - $minX) / $columns;
		$stepZ = ($maxZ - $minZ) / $rows;
		$normal = [0,$direction > 0 ? 1 : -1,0];
		$vertex = static function(array $point) use ($mesh,$y,$normal,$minX,$minZ,$maxX,$maxZ): int {
			return $mesh->vertex([
				$point['x'],$y,$point['z'],...$normal
				, ($point['x'] - $minX) / ($maxX - $minX),($point['z'] - $minZ) / ($maxZ - $minZ)
			]);
		};
		$run = static function(int $start, int $end, float $near, float $far) use (
			$mesh,$vertex,$minX,$stepX,$direction
		): void {
			$left = $minX + $start * $stepX;
			$right = $minX + $end * $stepX;
			$points = $direction > 0
				? [
					['x' => $left,'z' => $near],['x' => $left,'z' => $far]
					, ['x' => $right,'z' => $far],['x' => $right,'z' => $near]
				]
				: [
					['x' => $left,'z' => $near],['x' => $right,'z' => $near]
					, ['x' => $right,'z' => $far],['x' => $left,'z' => $far]
				];
			$indices = array_map($vertex, $points);
			$mesh->quad(...$indices);
		};

		for($row = 0; $row < $rows; $row++)
		{
			$near = $minZ + $row * $stepZ;
			$far = $near + $stepZ;
			$runStart = null;

			for($column = 0; $column < $columns; $column++)
			{
				$left = $minX + $column * $stepX;
				$right = $left + $stepX;
				$corners = [
					self::point($field, $left, $near, $offset), self::point($field, $right, $near, $offset)
					, self::point($field, $right, $far, $offset), self::point($field, $left, $far, $offset)
				];
				$center = self::point($field, ($left + $right) / 2, ($near + $far) / 2, $offset);
				$fullyInside = $center['distance'] <= 0;
				foreach($corners as $corner) $fullyInside = $fullyInside && $corner['distance'] <= 0;

				if($fullyInside)
				{
					$runStart ??= $column;
					continue;
				}

				if($runStart !== null)
				{
					$run($runStart, $column, $near, $far);
					$runStart = null;
				}

				for($corner = 0; $corner < 4; $corner++)
				{
					[$polygon] = self::clippedTriangle([$corners[$corner],$corners[($corner + 1) % 4],$center]);
					for($index = 1; $index < count($polygon) - 1; $index++)
					{
						$entries = $direction > 0
							? [$polygon[0],$polygon[$index + 1],$polygon[$index]]
							: [$polygon[0],$polygon[$index],$polygon[$index + 1]];
						$indices = array_map($vertex, $entries);
						$mesh->triangle(...$indices);
					}
				}
			}

			if($runStart !== null) $run($runStart, $columns, $near, $far);
		}
	}

	/**
	 * Triangulate the planar region bounded by closed contour loops, holes included.
	 *
	 * Each hole is bridged into its enclosing outer loop at a mutually visible vertex pair and the resulting simple
	 * polygon is ear-clipped. Triangles reuse the contour's own points, so a cap shares its outline exactly with the
	 * walls or fillet ring built from the same loops instead of re-sampling the field on a dense grid.
	 *
	 * @param list<list<array{x:float,z:float}>> $loops Closed loops as produced by `boundaryLoops()`.
	 * @return list<array{array{x:float,z:float},array{x:float,z:float},array{x:float,z:float}}> Counter-clockwise
	 * triangles in the X/Z plane.
	 */
	public static function triangulateLoops(array $loops): array
	{
		$area = static function(array $loop): float {
			$sum = 0.0;
			$count = count($loop);
			for($index = 0; $index < $count; $index++)
			{
				$a = $loop[$index];
				$b = $loop[($index + 1) % $count];
				$sum += $a['x'] * $b['z'] - $b['x'] * $a['z'];
			}

			return $sum / 2;
		};
		$inside = static function(array $point, array $loop): bool {
			$inside = false;
			$count = count($loop);
			for($index = 0, $previous = $count - 1; $index < $count; $previous = $index++)
			{
				$a = $loop[$index];
				$b = $loop[$previous];
				if(($a['z'] > $point['z']) !== ($b['z'] > $point['z'])
					&& $point['x'] < ($b['x'] - $a['x']) * ($point['z'] - $a['z']) / ($b['z'] - $a['z']) + $a['x'])
				$inside = !$inside;
			}

			return $inside;
		};

		$loops = array_values(array_filter($loops, static fn(array $loop): bool => count($loop) >= 3));
		if(!$loops) return [];
		$areas = array_map($area, $loops);
		$largest = 0;
		foreach($areas as $index => $value) if(abs($value) > abs($areas[$largest])) $largest = $index;
		// The largest loop is always an outer boundary; every loop wound the same way is one too.
		$outerSign = $areas[$largest] <=> 0;
		$outers = $holes = [];
		foreach($loops as $index => $loop)
		{
			// Outer boundaries run counter-clockwise and holes clockwise, whatever orientation the contour used.
			$isOuter = ($areas[$index] <=> 0) === $outerSign;
			if(($areas[$index] > 0) !== $isOuter) $loop = array_reverse($loop);
			if($isOuter) $outers[] = ['loop' => $loop,'area' => abs($areas[$index]),'holes' => []];
			else $holes[] = $loop;
		}
		foreach($holes as $hole)
		{
			$owner = null;
			foreach($outers as $index => $outer) if($inside($hole[0], $outer['loop'])
				&& ($owner === null || $outer['area'] < $outers[$owner]['area'])) $owner = $index;
			if($owner !== null) $outers[$owner]['holes'][] = $hole;
		}

		$triangles = [];
		foreach($outers as $outer)
		{
			$polygon = self::bridgeHoles($outer['loop'], $outer['holes']);
			array_push($triangles, ...self::earClip($polygon));
		}

		return $triangles;
	}

	/**
	 * Splice holes into a counter-clockwise outer loop through mutually visible vertex bridges.
	 *
	 * @param list<array{x:float,z:float}> $outer Counter-clockwise outer loop.
	 * @param list<list<array{x:float,z:float}>> $holes Clockwise hole loops inside it.
	 * @return list<array{x:float,z:float}> One weakly simple polygon.
	 */
	private static function bridgeHoles(array $outer, array $holes): array
	{
		$crosses = static function(array $a, array $b, array $c, array $d): bool {
			$orient = static fn(array $p, array $q, array $r): float
				=> ($q['x'] - $p['x']) * ($r['z'] - $p['z']) - ($q['z'] - $p['z']) * ($r['x'] - $p['x']);
			$same = static fn(array $p, array $q): bool => abs($p['x'] - $q['x']) < 1e-12 && abs($p['z'] - $q['z']) < 1e-12;
			if($same($a, $c) || $same($a, $d) || $same($b, $c) || $same($b, $d)) return false;

			return ($orient($a, $b, $c) > 0) !== ($orient($a, $b, $d) > 0)
				&& ($orient($c, $d, $a) > 0) !== ($orient($c, $d, $b) > 0);
		};

		// Rightmost holes first, so every later bridge can still see an outer or previously spliced vertex.
		usort($holes, static fn(array $a, array $b): int
			=> max(array_column($b, 'x')) <=> max(array_column($a, 'x')));
		$pending = $holes;

		foreach($holes as $holeIndex => $hole)
		{
			array_shift($pending);
			$start = array_keys(array_column($hole, 'x'), max(array_column($hole, 'x')))[0];
			$from = $hole[$start];
			$candidates = array_keys($outer);
			usort($candidates, static fn(int $a, int $b): int
				=> hypot($outer[$a]['x'] - $from['x'], $outer[$a]['z'] - $from['z'])
				<=> hypot($outer[$b]['x'] - $from['x'], $outer[$b]['z'] - $from['z']));
			$edges = [];
			foreach([$outer,$hole,...$pending] as $loop) for($index = 0, $count = count($loop); $index < $count; $index++)
			$edges[] = [$loop[$index],$loop[($index + 1) % $count]];

			$bridge = null;
			foreach($candidates as $candidate)
			{
				$to = $outer[$candidate];
				$blocked = false;
				foreach($edges as [$a,$b]) if($crosses($from, $to, $a, $b)) {$blocked = true; break;}
				if(!$blocked) {$bridge = $candidate; break;}
			}
			if($bridge === null) throw new \RuntimeException('No visible bridge into a contour hole');

			$rotated = [...array_slice($hole, $start),...array_slice($hole, 0, $start)];
			$outer = [
				...array_slice($outer, 0, $bridge + 1)
				, ...$rotated, $hole[$start]
				, $outer[$bridge]
				, ...array_slice($outer, $bridge + 1)
			];
		}

		return $outer;
	}

	/**
	 * Ear-clip a weakly simple counter-clockwise polygon.
	 *
	 * @param list<array{x:float,z:float}> $polygon Polygon whose bridge vertices may repeat.
	 * @return list<array{array{x:float,z:float},array{x:float,z:float},array{x:float,z:float}}>
	 */
	private static function earClip(array $polygon): array
	{
		$cross = static fn(array $a, array $b, array $c): float
			=> ($b['x'] - $a['x']) * ($c['z'] - $a['z']) - ($b['z'] - $a['z']) * ($c['x'] - $a['x']);
		$count = count($polygon);
		$next = $previous = [];
		for($index = 0; $index < $count; $index++)
		{
			$next[$index] = ($index + 1) % $count;
			$previous[$index] = ($index - 1 + $count) % $count;
		}
		$triangles = [];
		$remaining = $count;
		$current = 0;
		$stalled = 0;

		while($remaining > 3)
		{
			$a = $previous[$current];
			$c = $next[$current];
			[$pa,$pb,$pc] = [$polygon[$a],$polygon[$current],$polygon[$c]];
			$ear = $cross($pa, $pb, $pc) > 1e-14;

			for($probe = $next[$c]; $ear && $probe !== $a; $probe = $next[$probe])
			{
				$point = $polygon[$probe];
				// Bridge duplicates coincide with a corner; they are not inside the candidate ear.
				if(($point['x'] === $pa['x'] && $point['z'] === $pa['z']) || ($point['x'] === $pb['x'] && $point['z'] === $pb['z'])
					|| ($point['x'] === $pc['x'] && $point['z'] === $pc['z'])) continue;
				if($cross($pa, $pb, $point) >= 0 && $cross($pb, $pc, $point) >= 0 && $cross($pc, $pa, $point) >= 0) $ear = false;
			}

			// A full lap without an ear means only collinear or degenerate corners remain; clip one to make progress.
			if($ear || $stalled > $remaining)
			{
				if($cross($pa, $pb, $pc) > 1e-14) $triangles[] = [$pa,$pb,$pc];
				$next[$a] = $c;
				$previous[$c] = $a;
				$remaining--;
				$current = $c;
				$stalled = 0;
				continue;
			}

			$current = $c;
			$stalled++;
		}

		$a = $previous[$current];
		$c = $next[$current];
		if($cross($polygon[$a], $polygon[$current], $polygon[$c]) > 1e-14)
		$triangles[] = [$polygon[$a],$polygon[$current],$polygon[$c]];

		return $triangles;
	}

	/**
	 * Add a planar cap triangulated from closed contour loops, or return false when the contour is not closed.
	 *
	 * @param list<array{points:list<array>,closed:bool}> $paths Contour loops for the cap outline.
	 * @param array{float,float,float,float} $bounds Minimum and maximum X/Z bounds for planar UVs.
	 * @param float $y Cap height.
	 * @param int $direction Positive for an upward cap and negative for a downward cap.
	 */
	private static function appendLoopCap(array $paths, array $bounds, float $y, int $direction, MeshData $mesh): bool
	{
		foreach($paths as $path) if(!$path['closed']) return false;
		[$minX,$minZ,$maxX,$maxZ] = $bounds;
		$normal = [0,$direction > 0 ? 1 : -1,0];

		foreach(self::triangulateLoops(array_column($paths, 'points')) as $triangle)
		{
			// Counter-clockwise in X/Z faces downward in this right-handed, Y-up space; reverse it for upward caps.
			if($direction > 0) $triangle = [$triangle[0],$triangle[2],$triangle[1]];
			$indices = [];
			foreach($triangle as $point) $indices[] = $mesh->vertex([
				$point['x'],$y,$point['z'],...$normal
				, ($point['x'] - $minX) / ($maxX - $minX),($point['z'] - $minZ) / ($maxZ - $minZ)
			]);
			$mesh->triangle(...$indices);
		}

		return true;
	}

	/**
	 * Extrude a sampled two-dimensional signed-distance profile into a watertight mesh.
	 *
	 * Marching boundary samples become shared top, bottom, and side vertices with consistent winding, normals, UVs,
	 * and optional pocket-aware surface mapping suitable for all solid table layers.
	 *
	 * @param callable(float,float):float $field Signed-distance field sampled in table space.
	 * @param array{float,float,float,float} $bounds Minimum and maximum X/Z sampling bounds.
	 * @param int $columns Horizontal sampling-cell count.
	 * @param int $rows Vertical sampling-cell count.
	 * @param float $bottom Lower extrusion height.
	 * @param float $top Upper extrusion height.
	 */
	public static function extrude(
		callable $field, array $bounds, int $columns, int $rows, float $bottom, float $top
	): MeshData {
		[$minX,$minZ,$maxX,$maxZ] = $bounds;
		$stepX = ($maxX - $minX) / $columns;
		$stepZ = ($maxZ - $minZ) / $rows;
		$epsilon = min($stepX, $stepZ) * .7;
		$mesh = new MeshData;
		$loops = self::boundaryLoops($field, $bounds, $columns, $rows);
		if(!self::appendLoopCap($loops, $bounds, $top, 1, $mesh))
		self::appendCap($field, $bounds, $columns, $rows, 0, $top, 1, $mesh);
		if(!self::appendLoopCap($loops, $bounds, $bottom, -1, $mesh))
		self::appendCap($field, $bounds, $columns, $rows, 0, $bottom, -1, $mesh);

		foreach($loops as $path)
		{
			$points = $path['points'];
			$limit = count($points) - ($path['closed'] ? 0 : 1);
			for($index = 0; $index < $limit; $index++)
			{
				$a = $points[$index];
				$b = $points[($index + 1) % count($points)];
				$normalA = self::gradient($field, $a, $epsilon);
				$normalB = self::gradient($field, $b, $epsilon);
				$base = $mesh->vertex([$a['x'],$bottom,$a['z'],$normalA[0],0,$normalA[1],0,0]);
				$second = $mesh->vertex([$b['x'],$bottom,$b['z'],$normalB[0],0,$normalB[1],1,0]);
				$third = $mesh->vertex([$b['x'],$top,$b['z'],$normalB[0],0,$normalB[1],1,1]);
				$fourth = $mesh->vertex([$a['x'],$top,$a['z'],$normalA[0],0,$normalA[1],0,1]);
				$mesh->quad($base, $second, $third, $fourth);
			}
		}

		$mesh->validate();
		return $mesh;
	}

	/**
	 * Sweep explicit quarter-round bands around the high-resolution CSG contour.
	 *
	 * The sweep retains a flush lower face while adding analytic normals across the upper outer and inner edges,
	 * avoiding the disconnected caps and jagged pocket seams produced by piecewise rounding.
	 *
	 * @param callable(float,float):float $field Signed-distance field sampled in table space.
	 * @param array{float,float,float,float} $bounds Minimum and maximum X/Z sampling bounds.
	 * @param int $columns Horizontal sampling-cell count.
	 * @param int $rows Vertical sampling-cell count.
	 * @param float $bottom Lower extrusion height.
	 * @param float $top Upper extrusion height.
	 * @param float $radius Upper edge-fillet radius.
	 * @param int $roundSegments Number of segments across the upper fillet.
	 * @param float $bottomRadius Lower edge-fillet radius.
	 */
	public static function extrudeRounded(
		callable $field, array $bounds, int $columns, int $rows, float $bottom, float $top
		, float $radius, int $roundSegments = 6, ?float $bottomRadius = null
	): MeshData {
		$bottomRadius ??= $radius;
		[$minX,$minZ,$maxX,$maxZ] = $bounds;
		$stepX = ($maxX - $minX) / $columns;
		$stepZ = ($maxZ - $minZ) / $rows;
		$epsilon = min($stepX, $stepZ) * .7;
		$mesh = new MeshData;
		$insets = [];
		$quad = static function(array $entries) use ($mesh,$minX,$minZ,$maxX,$maxZ): void {
			$indices = [];
			foreach($entries as $entry) $indices[] = $mesh->vertex([
				$entry['x'],$entry['y'],$entry['z'],...$entry['normal']
				, ($entry['x'] - $minX) / ($maxX - $minX),($entry['z'] - $minZ) / ($maxZ - $minZ)
			]);
			$mesh->quad(...$indices);
		};
		$insetDistance = static function(array $entry, float $edgeRadius) use ($field,$epsilon,&$insets): float {
			if($edgeRadius <= 0) return 0;
			$key = sprintf('%.12f:%.12f:%.12f', $entry['x'], $entry['z'], $edgeRadius);
			if(isset($insets[$key])) return $insets[$key];
			[$nx,$nz] = self::gradient($field, $entry, $epsilon);
			$target = -$edgeRadius;
			$low = 0.0;
			$high = $edgeRadius;
			$value = $field($entry['x'] - $nx * $high, $entry['z'] - $nz * $high);
			while($value > $target && $high < $edgeRadius * 6)
			{
				$high *= 1.5;
				$value = $field($entry['x'] - $nx * $high, $entry['z'] - $nz * $high);
			}
			if($value <= $target) for($iteration = 0; $iteration < 12; $iteration++)
			{
				$middle = ($low + $high) / 2;
				$middleValue = $field($entry['x'] - $nx * $middle, $entry['z'] - $nz * $middle);
				if($middleValue > $target) $low = $middle; else $high = $middle;
			}
			else $high = $edgeRadius;

			return $insets[$key] = $high;
		};
		$ringPoint = static function(array $entry, float $angle, int $direction) use (
			$field,$epsilon,$radius,$bottomRadius,$top,$bottom,$insetDistance
		): array {
			$edgeRadius = $direction > 0 ? $radius : $bottomRadius;
			[$nx,$nz] = self::gradient($field, $entry, $epsilon);
			$horizontalRadius = $insetDistance($entry, $edgeRadius);
			$inset = $horizontalRadius * (1 - cos($angle));

			return [
				'x' => $entry['x'] - $nx * $inset
				, 'z' => $entry['z'] - $nz * $inset
				, 'y' => $direction > 0 ? $top - $radius + sin($angle) * $radius
					: $bottom + $bottomRadius - sin($angle) * $bottomRadius
				, 'normal' => self::roundedFilletNormal($nx, $nz, $angle, $horizontalRadius, $edgeRadius, $direction)
			];
		};

		$loops = self::boundaryLoops($field, $bounds, $columns, $rows);
		$inset = static fn(float $distance): array => $distance > 0
			? self::boundaryLoops(static fn(float $x, float $z): float => $field($x, $z) + $distance, $bounds, $columns, $rows)
			: $loops;
		if(!self::appendLoopCap($inset($radius), $bounds, $top, 1, $mesh))
		self::appendCap($field, $bounds, $columns, $rows, $radius, $top, 1, $mesh);
		if(!self::appendLoopCap($inset($bottomRadius), $bounds, $bottom, -1, $mesh))
		self::appendCap($field, $bounds, $columns, $rows, $bottomRadius, $bottom, -1, $mesh);

		foreach($loops as $path)
		{
			$points = $path['points'];
			$limit = count($points) - ($path['closed'] ? 0 : 1);
			for($index = 0; $index < $limit; $index++)
			{
				$a = $points[$index];
				$b = $points[($index + 1) % count($points)];
				$normalA = self::gradient($field, $a, $epsilon);
				$normalB = self::gradient($field, $b, $epsilon);
				$quad([
					['x' => $a['x'],'y' => $bottom + $bottomRadius,'z' => $a['z'],'normal' => [$normalA[0],0,$normalA[1]]]
					, ['x' => $b['x'],'y' => $bottom + $bottomRadius,'z' => $b['z'],'normal' => [$normalB[0],0,$normalB[1]]]
					, ['x' => $b['x'],'y' => $top - $radius,'z' => $b['z'],'normal' => [$normalB[0],0,$normalB[1]]]
					, ['x' => $a['x'],'y' => $top - $radius,'z' => $a['z'],'normal' => [$normalA[0],0,$normalA[1]]]
				]);

				for($segment = 0; $segment < $roundSegments; $segment++)
				{
					$angle = $segment / $roundSegments * M_PI / 2;
					$next = ($segment + 1) / $roundSegments * M_PI / 2;
					$quad([$ringPoint($a, $angle, 1),$ringPoint($b, $angle, 1),$ringPoint($b, $next, 1),$ringPoint($a, $next, 1)]);
					if($bottomRadius > 0)
					$quad([$ringPoint($a, $next, -1),$ringPoint($b, $next, -1),$ringPoint($b, $angle, -1),$ringPoint($a, $angle, -1)]);
				}
			}
		}

		$mesh->validate();
		return $mesh;
	}

	/**
	 * @param float $nx Horizontal X component of the boundary normal.
	 * @param float $nz Horizontal Z component of the boundary normal.
	 * @param float $angle Fillet angle in radians.
	 * @param float $horizontalRadius Fillet radius in the boundary plane.
	 * @param float $verticalRadius Fillet radius along the height axis.
	 * @param float $direction Outward-normal orientation multiplier.
	 * @return array{float,float,float}
	 */
	public static function roundedFilletNormal(
		float $nx, float $nz, float $angle, float $horizontalRadius, float $verticalRadius, int $direction = 1
	): array {
		$horizontal = cos($angle) / max($horizontalRadius, 1e-9);
		$vertical = sin($angle) / max($verticalRadius, 1e-9);
		$length = hypot($horizontal, $vertical) ?: 1;

		return [$nx * $horizontal / $length,$direction * $vertical / $length,$nz * $horizontal / $length];
	}

	/**
	 * @param float $horizontalDistance Signed distance from the horizontal outline.
	 * @param float $y Sample height.
	 * @param float $bottom Lower extrusion height.
	 * @param float $top Upper extrusion height.
	 * @param float $radius Edge-rounding radius.
	 */
	public static function roundedExtrusionSdf(
		float $horizontalDistance, float $y, float $bottom, float $top, float $radius
	): float {
		$radius = max(0, min($radius, ($top - $bottom) / 2));
		$center = ($bottom + $top) / 2;
		$halfHeight = ($top - $bottom) / 2;
		$horizontal = $horizontalDistance + $radius;
		$vertical = abs($y - $center) - ($halfHeight - $radius);

		return min(max($horizontal, $vertical), 0)
			+ hypot(max($horizontal, 0), max($vertical, 0)) - $radius;
	}

	/**
	 * Build all six analytic felt rail caps with shared corner rings.
	 *
	 * Canonical cushion polygons are inset and joined through continuous rounded bands, keeping padding width and
	 * normals stable as each rail tapers into corner and side pocket facings.
	 *
	 * @param list<list<array{float,float}>> $polygons Cushion outlines in table X/Z coordinates.
	 * @param array{float,float,float,float} $bounds Minimum and maximum X/Z sampling bounds.
	 * @param float $bottom Cushion base height.
	 * @param float $top Cushion top height.
	 * @param float $radius Radius of the exposed cushion fillet.
	 */
	public static function cushions(
		array $polygons, array $bounds, float $bottom = .013, float $top = .083
		, float $radius = TableGeometry::CUSHION_EDGE_RADIUS
	): MeshData {
		[$minX,$minZ,$maxX,$maxZ] = $bounds;
		$mesh = new MeshData;
		$add = static function(array $point, float $y, array $normal) use ($mesh,$minX,$minZ,$maxX,$maxZ): int {
			return $mesh->vertex([
				$point[0],$y,$point[1],...$normal
				, ($point[0] - $minX) / ($maxX - $minX),($point[1] - $minZ) / ($maxZ - $minZ)
			]);
		};

		foreach($polygons as $polygon)
		{
			$count = count($polygon);
			$edgeNormals = [];
			foreach($polygon as $index => [$x,$z])
			{
				[$nextX,$nextZ] = $polygon[($index + 1) % $count];
				$dx = $nextX - $x;
				$dz = $nextZ - $z;
				$length = hypot($dx, $dz);
				$edgeNormals[] = [$dz / $length,-$dx / $length];
			}
			$cornerNormals = [];
			foreach($edgeNormals as $index => $normal)
			{
				$previous = $edgeNormals[($index + $count - 1) % $count];
				$x = $previous[0] + $normal[0];
				$z = $previous[1] + $normal[1];
				$length = hypot($x, $z) ?: 1;
				$cornerNormals[] = [$x / $length,$z / $length];
			}

			foreach($polygon as $edge => $point)
			{
				$next = ($edge + 1) % $count;
				[$nx,$nz] = $edgeNormals[$edge];
				$mesh->quad(
					$add($point, $bottom, [$nx,0,$nz])
					, $add($point, $top - $radius, [$nx,0,$nz])
					, $add($polygon[$next], $top - $radius, [$nx,0,$nz])
					, $add($polygon[$next], $bottom, [$nx,0,$nz])
				);
			}

			$rings = [];
			for($segment = 0; $segment <= TableGeometry::CUSHION_TOP_SEGMENTS; $segment++)
			{
				$angle = $segment / TableGeometry::CUSHION_TOP_SEGMENTS * M_PI / 2;
				$ring = TableGeometry::insetPolygon($polygon, $radius * (1 - cos($angle)));
				$current = [];
				foreach($ring as $index => $point)
				{
					[$nx,$nz] = $cornerNormals[$index];
					$current[] = $add($point, $top - $radius + sin($angle) * $radius, [$nx * cos($angle),sin($angle),$nz * cos($angle)]);
				}
				$rings[] = $current;
			}

			for($segment = 0; $segment < count($rings) - 1; $segment++) for($edge = 0; $edge < $count; $edge++)
			{
				$next = ($edge + 1) % $count;
				$mesh->quad($rings[$segment][$edge],$rings[$segment + 1][$edge],$rings[$segment + 1][$next],$rings[$segment][$next]);
			}

			$topRing = $rings[array_key_last($rings)];
			$bottomRing = [];
			foreach($polygon as $point) $bottomRing[] = $add($point, $bottom, [0,-1,0]);
			for($index = 1; $index < $count - 1; $index++)
			{
				$mesh->triangle($topRing[0],$topRing[$index + 1],$topRing[$index]);
				$mesh->triangle($bottomRing[0],$bottomRing[$index],$bottomRing[$index + 1]);
			}
		}

		$mesh->validate();
		return $mesh;
	}

	/**
	 * Build tapered leather pocket drops beginning at the exact circular tabletop cuts.
	 *
	 * Each liner shares its upper ring with the table's canonical void, expands with the configured back draft, and
	 * closes below the visible ball path without placing wood or felt across the opening.
	 *
	 * @param list<array<string,mixed>> $pockets Pocket definitions shared with the table model.
	 * @param float $ballRadius Physical ball radius used to size the drop channel.
	 */
	public static function liners(array $pockets, float $ballRadius): MeshData
	{
		$mesh = new MeshData(true);
		$top = TableGeometry::POCKET_LINER_TOP;
		$throat = TableGeometry::POCKET_THROAT;
		$bottom = TableGeometry::POCKET_FLOOR;
		$segments = TableGeometry::POCKET_SEGMENTS;

		foreach($pockets as $pocket)
		{
			$lipRadius = TableGeometry::pocketVoidRadius();
			$outline = $outer = [];
			for($step = 0; $step < $segments; $step++)
			{
				$angle = $step / $segments * M_PI * 2;
				$c = cos($angle);
				$s = sin($angle);
				$outline[] = [$pocket['x'] + $c * $lipRadius,$pocket['z'] + $s * $lipRadius];
				$outer[] = [$pocket['x'] + $c * $pocket['radius'],$pocket['z'] + $s * $pocket['radius']];
			}
			$throatRadius = TableGeometry::pocketFloorRadius($ballRadius);
			$rim = $upper = $lower = [];

			for($step = 0; $step < $segments; $step++)
			{
				[$x,$z] = $outline[$step];
				$dx = $x - $pocket['x'];
				$dz = $z - $pocket['z'];
				$radial = hypot($dx, $dz) ?: 1;
				$c = $dx / $radial;
				$s = $dz / $radial;
				$slope = ($radial - $throatRadius) / ($top - $throat);
				$normalLength = hypot(1, $slope);
				$nx = -$c / $normalLength;
				$ny = $slope / $normalLength;
				$nz = -$s / $normalLength;
				$u = $step / $segments;
				$rim[] = [
					$mesh->vertex([$outer[$step][0],$top,$outer[$step][1],0,1,0,$u,0])
					, $mesh->vertex([$x,$top,$z,0,1,0,$u,1])
				];
				$upper[] = [
					$mesh->vertex([$x,$top,$z,$nx,$ny,$nz,$u,0])
					, $mesh->vertex([$pocket['x'] + $c * $throatRadius,$throat,$pocket['z'] + $s * $throatRadius,$nx,$ny,$nz,$u,.55])
				];
				$lower[] = [
					$mesh->vertex([$pocket['x'] + $c * $throatRadius,$throat,$pocket['z'] + $s * $throatRadius,-$c,0,-$s,$u,.55])
					, $mesh->vertex([$pocket['x'] + $c * $throatRadius,$bottom,$pocket['z'] + $s * $throatRadius,-$c,0,-$s,$u,1])
				];
			}

			for($step = 0; $step < $segments; $step++)
			{
				$next = ($step + 1) % $segments;
				[$outerA,$innerA] = $rim[$step];
				[$outerB,$innerB] = $rim[$next];
				$mesh->quad($outerA,$innerA,$innerB,$outerB);
				foreach([$upper,$lower] as $wall)
				{
					[$topA,$bottomA] = $wall[$step];
					[$topB,$bottomB] = $wall[$next];
					$mesh->quad($topA,$bottomA,$bottomB,$topB);
				}
			}
		}

		$mesh->validate();
		return $mesh;
	}

	public static function cube(): MeshData
	{
		$mesh = new MeshData;
		$faces = [
			[[1,0,0],[[1,-1,-1],[1,1,-1],[1,1,1],[1,-1,1]]]
			, [[-1,0,0],[[-1,-1,1],[-1,1,1],[-1,1,-1],[-1,-1,-1]]]
			, [[0,1,0],[[-1,1,-1],[-1,1,1],[1,1,1],[1,1,-1]]]
			, [[0,-1,0],[[-1,-1,1],[-1,-1,-1],[1,-1,-1],[1,-1,1]]]
			, [[0,0,1],[[1,-1,1],[1,1,1],[-1,1,1],[-1,-1,1]]]
			, [[0,0,-1],[[-1,-1,-1],[-1,1,-1],[1,1,-1],[1,-1,-1]]]
		];
		$uvs = [[0,0],[0,1],[1,1],[1,0]];

		foreach($faces as [$normal,$points])
		{
			$indices = [];
			foreach($points as $index => $point)
			$indices[] = $mesh->vertex([$point[0] / 2,$point[1] / 2,$point[2] / 2,...$normal,...$uvs[$index]]);
			$mesh->quad(...$indices);
		}

		$mesh->validate();
		return $mesh;
	}

	/**
	 * @param float $rearRadius Radius at the rear end.
	 * @param float $frontRadius Radius at the front end.
	 * @param int $segments Radial tessellation count.
	 */
	public static function cylinder(float $rearRadius = .5, float $frontRadius = .5, int $segments = 48): MeshData
	{
		$mesh = new MeshData;
		$slope = $rearRadius - $frontRadius;
		$normalLength = hypot($slope, 1);
		$rings = [];

		for($end = 0; $end < 2; $end++)
		{
			$rings[$end] = [];
			for($segment = 0; $segment <= $segments; $segment++)
			{
				$amount = $segment / $segments;
				$angle = $amount * M_PI * 2;
				$c = cos($angle);
				$s = sin($angle);
				$radius = $end ? $frontRadius : $rearRadius;
				$rings[$end][] = $mesh->vertex([
					$end - .5,$c * $radius,$s * $radius,$slope / $normalLength,$c / $normalLength,$s / $normalLength,$end,$amount
				]);
			}
		}

		for($segment = 0; $segment < $segments; $segment++)
		$mesh->quad($rings[0][$segment],$rings[0][$segment + 1],$rings[1][$segment + 1],$rings[1][$segment]);

		foreach([[-.5,$rearRadius,[-1,0,0],-1],[.5,$frontRadius,[1,0,0],1]] as [$x,$radius,$normal,$direction])
		{
			$center = $mesh->vertex([$x,0,0,...$normal,.5,.5]);
			$ring = [];
			for($segment = 0; $segment <= $segments; $segment++)
			{
				$angle = $segment / $segments * M_PI * 2;
				$c = cos($angle);
				$s = sin($angle);
				$ring[] = $mesh->vertex([$x,$c * $radius,$s * $radius,...$normal,($c + 1) / 2,($s + 1) / 2]);
				if($segment > 0)
				{
					if($direction > 0) $mesh->triangle($center,$ring[$segment - 1],$ring[$segment]);
					else $mesh->triangle($center,$ring[$segment],$ring[$segment - 1]);
				}
			}
		}

		$mesh->validate();
		return $mesh;
	}

	/**
	 * @param int $rows Latitude tessellation count.
	 * @param int $columns Longitude tessellation count.
	 */
	public static function sphere(int $rows = 32, int $columns = 64): MeshData
	{
		$mesh = new MeshData;
		$grid = [];
		for($row = 0; $row <= $rows; $row++)
		{
			$grid[$row] = [];
			for($column = 0; $column <= $columns; $column++)
			{
				$y = $row / $rows;
				$x = $column / $columns;
				$phi = $y * M_PI;
				$theta = $x * M_PI * 2;
				$nx = sin($phi) * cos($theta);
				$ny = cos($phi);
				$nz = sin($phi) * sin($theta);
				$grid[$row][] = $mesh->vertex([$nx,$ny,$nz,$nx,$ny,$nz,$x,$y]);
			}
		}
		for($row = 0; $row < $rows; $row++) for($column = 0; $column < $columns; $column++)
		{
			$a = $grid[$row][$column];
			$b = $grid[$row + 1][$column];
			$mesh->triangle($a,$a + 1,$b);
			$mesh->triangle($b,$a + 1,$b + 1);
		}
		$mesh->validate();
		return $mesh;
	}

	/**
	 * @param int $segments Radial tessellation count.
	 */
	public static function disc(int $segments = 40): MeshData
	{
		$mesh = new MeshData;
		$center = $mesh->vertex([0,0,0,0,1,0,.5,.5]);
		$ring = [];
		for($index = 0; $index <= $segments; $index++)
		{
			$angle = $index / $segments * M_PI * 2;
			$c = cos($angle);
			$s = sin($angle);
			$ring[] = $mesh->vertex([$c,0,$s,0,1,0,($c + 1) / 2,($s + 1) / 2]);
			if($index > 0) $mesh->triangle($center,$ring[$index],$ring[$index - 1]);
		}
		$mesh->validate();
		return $mesh;
	}
}

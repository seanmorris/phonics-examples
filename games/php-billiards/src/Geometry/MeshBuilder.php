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
	/** Longest outline edge in metres; long straight runs are split so caps and fillet bands never become needles. */
	private const LONGEST_EDGE = .05;

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
			// Sampled as finely as the rail: the shelf around each side cut ends in crescent tips a few millimetres wide.
			, 'bed' => self::extrude(
				$profiles['bed'], $profiles['bounds'], TableGeometry::COLUMNS, TableGeometry::ROWS, -.047, TableGeometry::TABLETOP_TOP
			)
			// The rail reaches down to the apron so no slot under it exposes the open pocket shafts; the join is flush.
			, 'wood' => self::extrudeRounded(
				$profiles['wood'], $profiles['bounds'], TableGeometry::COLUMNS, TableGeometry::ROWS
				, TableGeometry::APRON_TOP, TableGeometry::RAIL_TOP, TableGeometry::WOOD_EDGE_RADIUS, 3, 0.0
			)
			, 'cushion' => self::cushions($profiles['cushionRails'], $profiles['bounds'])
			, 'liners' => self::liners($profiles['pockets'], Ball::RADIUS)
			, 'cube' => self::cube()
			, 'cue' => self::cue()
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
			$points = self::snapCorners($field, $points, $closed, hypot($stepX, $stepZ));
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
			$loops[] = ['points' => self::subdivide($points, $closed),'closed' => $closed];
		}

		return $loops;
	}

	/**
	 * Split straight outline runs so no edge exceeds `LONGEST_EDGE`.
	 *
	 * Merging collinear samples leaves a rail span as one edge, and caps and fillet bands built on it become needles
	 * the length of the table. Seen from a camera hovering over the rail, such needles reach far off screen and their
	 * shared edges rasterize with pinhole seams.
	 *
	 * @param list<array{x:float,z:float,distance:float}> $points
	 * @return list<array{x:float,z:float,distance:float}>
	 */
	private static function subdivide(array $points, bool $closed): array
	{
		$count = count($points);
		$result = [];
		for($index = 0; $index < $count; $index++)
		{
			$a = $result[] = $points[$index];
			if(!$closed && $index === $count - 1) break;
			$b = $points[($index + 1) % $count];
			$pieces = (int)ceil(hypot($b['x'] - $a['x'], $b['z'] - $a['z']) / self::LONGEST_EDGE);
			for($piece = 1; $piece < $pieces; $piece++) $result[] = [
				'x' => $a['x'] + ($b['x'] - $a['x']) * $piece / $pieces,'z' => $a['z'] + ($b['z'] - $a['z']) * $piece / $pieces
				, 'distance' => 0.0
			];
		}

		return $result;
	}

	/**
	 * Restore sharp outline corners, such as pocket jaws, that marching cuts off inside a sampling cell.
	 *
	 * Where the exact contour normals of two neighbouring vertices turn by more than 25 degrees, their tangent lines
	 * meet at the corner. The intersection is kept only when it lies on the contour itself and within one cell of both
	 * vertices, so tessellated curves never sprout spikes.
	 *
	 * @param callable(float,float):float $field Signed-distance field the loop follows.
	 * @param list<array{x:float,z:float,distance:float}> $points Ordered loop vertices.
	 * @param bool $closed Whether the last vertex joins the first.
	 * @param float $reach Sampling-cell diagonal.
	 * @return list<array{x:float,z:float,distance:float}>
	 */
	private static function snapCorners(callable $field, array $points, bool $closed, float $reach): array
	{
		$count = count($points);
		$normals = [];
		$projected = [];
		foreach($points as $index => $point)
		{
			// Chamfer vertices sit off the contour; project each onto its own branch before taking its tangent.
			$normals[$index] = self::gradient($field, $point, $reach * 1e-5);
			$offset = $field($point['x'], $point['z']);
			$projected[$index] = [$point['x'] - $normals[$index][0] * $offset,$point['z'] - $normals[$index][1] * $offset];
		}
		$corners = [];
		for($index = 0; $index < $count - ($closed ? 0 : 1); $index++)
		{
			$next = ($index + 1) % $count;
			[$ax,$az] = $normals[$index];
			[$bx,$bz] = $normals[$next];
			$determinant = $ax * $bz - $az * $bx;
			if(abs($determinant) < sin(deg2rad(25))) continue;
			$ca = $ax * $projected[$index][0] + $az * $projected[$index][1];
			$cb = $bx * $projected[$next][0] + $bz * $projected[$next][1];
			$x = ($ca * $bz - $az * $cb) / $determinant;
			$z = ($ax * $cb - $ca * $bx) / $determinant;
			if(hypot($x - $points[$index]['x'], $z - $points[$index]['z']) > $reach
				|| hypot($x - $points[$next]['x'], $z - $points[$next]['z']) > $reach
				|| abs($field($x, $z)) > $reach * .02) continue;
			$corners[$index] = ['x' => $x,'z' => $z,'distance' => 0.0];
		}
		if(!$corners) return $points;

		// Drop the chamfer vertices beside each restored corner unless they already lie on the contour.
		$drop = [];
		foreach($corners as $index => $corner) foreach([$index,($index + 1) % $count] as $neighbour)
		if(abs($field($points[$neighbour]['x'], $points[$neighbour]['z'])) > $reach * .01)
		$drop[$neighbour] = true;
		$result = [];
		for($index = 0; $index < $count; $index++)
		{
			if(!isset($drop[$index])) $result[] = $points[$index];
			if(isset($corners[$index])) $result[] = $corners[$index];
		}

		return $result;
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
			array_push($triangles, ...self::delaunayFlip(self::earClip($polygon)));
		}

		return $triangles;
	}

	/**
	 * Flip interior edges of a triangulation until every one is locally Delaunay.
	 *
	 * Ear clipping fans a straight rail edge out from one vertex into slivers metres long and microns wide; their
	 * depth interpolates so unstably that the cushion under the rail wins the depth test along a dashed line. Flipping
	 * keeps exactly the same covered area and boundary edges while replacing the fans with well-shaped triangles.
	 *
	 * @param list<array{array{x:float,z:float},array{x:float,z:float},array{x:float,z:float}}> $triangles CCW triangles.
	 * @return list<array{array{x:float,z:float},array{x:float,z:float},array{x:float,z:float}}>
	 */
	private static function delaunayFlip(array $triangles): array
	{
		$points = [];
		$ids = [];
		$id = static function(array $point) use (&$points,&$ids): int {
			$key = sprintf('%.12f:%.12f', $point['x'], $point['z']);
			if(!isset($ids[$key])) {$ids[$key] = count($points); $points[] = $point;}
			return $ids[$key];
		};
		$faces = array_map(static fn(array $triangle): array => array_map($id, $triangle), $triangles);
		$orient = static fn(int $a, int $b, int $c): float => ($points[$b]['x'] - $points[$a]['x']) * ($points[$c]['z'] - $points[$a]['z'])
			- ($points[$b]['z'] - $points[$a]['z']) * ($points[$c]['x'] - $points[$a]['x']);
		// Positive when d lies inside the circumcircle of the counter-clockwise triangle a, b, c.
		$inCircle = static function(int $a, int $b, int $c, int $d) use ($points): float {
			$rows = [];
			foreach([$a,$b,$c] as $index)
			{
				$x = $points[$index]['x'] - $points[$d]['x'];
				$z = $points[$index]['z'] - $points[$d]['z'];
				$rows[] = [$x,$z,$x * $x + $z * $z];
			}
			[[$ax,$az,$aw],[$bx,$bz,$bw],[$cx,$cz,$cw]] = $rows;

			return $ax * ($bz * $cw - $bw * $cz) - $az * ($bx * $cw - $bw * $cx) + $aw * ($bx * $cz - $bz * $cx);
		};
		$edgeKey = static fn(int $a, int $b): string => $a < $b ? "$a:$b" : "$b:$a";

		for($pass = 0; $pass < 4096; $pass++)
		{
			$edges = [];
			foreach($faces as $face => [$a,$b,$c]) foreach([[$a,$b,$c],[$b,$c,$a],[$c,$a,$b]] as [$p,$q,$opposite])
			$edges[$edgeKey($p, $q)][] = [$face,$p,$q,$opposite];
			$flipped = false;
			$touched = [];
			foreach($edges as $sides)
			{
				if(count($sides) !== 2) continue;
				[[$first,$p,$q,$c],[$second,,,$d]] = $sides;
				if(isset($touched[$first]) || isset($touched[$second])) continue;
				// Triangle (p, q, c) is counter-clockwise; flip when d sits inside its circumcircle and both new
				// triangles stay counter-clockwise, which also proves the quad is convex.
				if($inCircle($p, $q, $c, $d) <= 1e-18 || $orient($c, $d, $q) <= 0 || $orient($d, $c, $p) <= 0) continue;
				$faces[$first] = [$c,$d,$q];
				$faces[$second] = [$d,$c,$p];
				$touched[$first] = $touched[$second] = true;
				$flipped = true;
			}
			if(!$flipped) break;
		}

		return array_map(static fn(array $face): array => [$points[$face[0]],$points[$face[1]],$points[$face[2]]], $faces);
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
	 * Every fillet step is the outline shrunk by that step's inset, contoured from the distance field itself. Shrunken
	 * contours of a distance field nest and never cross, so where a pocket cut meets the cushion line in a sharp corner
	 * the round-over keeps a clean miter instead of folding one band into the next, and silhouettes stay round.
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
		$reach = hypot(($maxX - $minX) / $columns, ($maxZ - $minZ) / $rows);
		$mesh = new MeshData;
		$emit = static fn(array $point, float $y, array $normal): int => $mesh->vertex([
			$point['x'],$y,$point['z'],...$normal
			, ($point['x'] - $minX) / ($maxX - $minX),($point['z'] - $minZ) / ($maxZ - $minZ)
		]);
		$contours = static fn(float $inset): array => self::sides($field, self::boundaryLoops(
			static fn(float $x, float $z): float => $field($x, $z) + $inset, $bounds, $columns, $rows
		), $reach);
		$base = $contours(0);
		$levels = static function(float $edgeRadius) use ($contours,$base,$roundSegments): array {
			$levels = [];
			for($step = 0; $step <= $roundSegments; $step++)
			{
				$angle = $step / $roundSegments * M_PI / 2;
				$inset = $edgeRadius * (1 - cos($angle));
				$levels[] = ['angle' => $angle,'inset' => $inset,'loops' => $inset > 0 ? $contours($inset) : $base];
			}

			return $levels;
		};

		$upper = $radius > 0 ? $levels($radius) : [['angle' => M_PI / 2,'inset' => 0.0,'loops' => $base]];
		$lower = $bottomRadius > 0 ? $levels($bottomRadius) : [['angle' => M_PI / 2,'inset' => 0.0,'loops' => $base]];
		$caps = [[end($upper)['loops'],$top,1],[end($lower)['loops'],$bottom,-1]];
		foreach($caps as [$loops,$y,$direction])
		{
			$paths = array_map(static fn(array $loop): array => ['points' => $loop['points'],'closed' => $loop['closed']], $loops);
			if(!self::appendLoopCap($paths, $bounds, $y, $direction, $mesh))
			throw new \RuntimeException('Rounded extrusion caps need closed contours');
		}

		// Straight walls between the two fillets; a snapped corner splits its normal between the faces that meet there.
		foreach($base as $loop)
		{
			$points = $loop['points'];
			$count = count($points);
			for($index = 0; $index < $count; $index++)
			{
				$next = ($index + 1) % $count;
				[$ax,$az] = $loop['after'][$index];
				[$bx,$bz] = $loop['before'][$next];
				$mesh->quad(
					$emit($points[$index], $bottom + $bottomRadius, [$ax,0,$az]),$emit($points[$next], $bottom + $bottomRadius, [$bx,0,$bz])
					, $emit($points[$next], $top - $radius, [$bx,0,$bz]),$emit($points[$index], $top - $radius, [$ax,0,$az])
				);
			}
		}

		foreach([[$upper,$radius,1],[$lower,$bottomRadius,-1]] as [$steps,$edgeRadius,$direction])
		for($step = 0; $step + 1 < count($steps); $step++)
		{
			[$from,$to] = [$steps[$step],$steps[$step + 1]];
			if(count($from['loops']) !== count($to['loops'])) throw new \RuntimeException('A fillet step changed the outline topology');
			$height = static fn(float $angle): float => $direction > 0
				? $top - $edgeRadius + sin($angle) * $edgeRadius
				: $bottom + $edgeRadius - sin($angle) * $edgeRadius;
			foreach($from['loops'] as $loop)
			{
				$partner = self::nearestLoop($loop, $to['loops']);
				foreach(self::zipLoops($loop['points'], $partner['points']) as $triangle)
				{
					$centroid = [
						'x' => ($triangle[0][1]['x'] + $triangle[1][1]['x'] + $triangle[2][1]['x']) / 3
						, 'z' => ($triangle[0][1]['z'] + $triangle[1][1]['z'] + $triangle[2][1]['z']) / 3
					];
					$indices = [];
					foreach($triangle as [$side,$point,$index])
					{
						$ring = $side === 0 ? $loop : $partner;
						$angle = $side === 0 ? $from['angle'] : $to['angle'];
						[$nx,$nz] = self::sideNormal($ring, $index, $centroid);
						$indices[] = $emit($point, $height($angle)
							, self::roundedFilletNormal($nx, $nz, $angle, $edgeRadius, $edgeRadius, $direction));
					}
					$direction > 0 ? $mesh->triangle(...$indices) : $mesh->triangle($indices[0],$indices[2],$indices[1]);
				}
			}
		}

		$mesh->validate();
		return $mesh;
	}

	/**
	 * Attach one-sided outline normals to closed contours.
	 *
	 * Each vertex keeps the normal just before and just after it along the loop. They match on smooth curves; at a
	 * snapped corner they are the two faces that meet there, so walls and fillet bands keep a crisp miter.
	 *
	 * @param list<array{points:list<array{x:float,z:float}>,closed:bool}> $loops
	 * @return list<array{points:list<array{x:float,z:float}>,closed:bool,before:list<array{float,float}>,after:list<array{float,float}>}>
	 */
	private static function sides(callable $field, array $loops, float $reach): array
	{
		foreach($loops as &$loop)
		{
			if(!$loop['closed']) throw new \RuntimeException('Rounded extrusion needs closed contours');
			$points = $loop['points'];
			$count = count($points);
			$toward = static function(array $from, array $to) use ($field,$reach): array {
				$step = .01 * min(1, $reach / max(1e-12, hypot($to['x'] - $from['x'], $to['z'] - $from['z'])));
				return self::gradient($field, [
					'x' => $from['x'] + ($to['x'] - $from['x']) * $step,'z' => $from['z'] + ($to['z'] - $from['z']) * $step
				], $reach * 1e-5);
			};
			$loop['before'] = $loop['after'] = [];
			foreach($points as $index => $point)
			{
				$loop['before'][$index] = $toward($point, $points[($index - 1 + $count) % $count]);
				$loop['after'][$index] = $toward($point, $points[($index + 1) % $count]);
			}
		}

		return $loops;
	}

	/** One-sided normal of a contour vertex for the face lying toward `$centroid`. */
	private static function sideNormal(array $loop, int $index, array $centroid): array
	{
		[$bx,$bz] = $loop['before'][$index];
		[$ax,$az] = $loop['after'][$index];
		if($bx * $ax + $bz * $az > cos(deg2rad(10)))
		{
			$length = hypot($bx + $ax, $bz + $az) ?: 1;
			return [($bx + $ax) / $length,($bz + $az) / $length];
		}
		$points = $loop['points'];
		$count = count($points);
		$previous = $points[($index - 1 + $count) % $count];
		$next = $points[($index + 1) % $count];
		$point = $points[$index];
		$forward = ($centroid['x'] - $point['x']) * ($next['x'] - $previous['x'])
			+ ($centroid['z'] - $point['z']) * ($next['z'] - $previous['z']);

		return $forward >= 0 ? [$ax,$az] : [$bx,$bz];
	}

	/** The contour in `$candidates` closest to `$loop`, which is its neighbour one fillet step inward or outward. */
	private static function nearestLoop(array $loop, array $candidates): array
	{
		$best = null;
		$distance = INF;
		$probe = $loop['points'][0];
		foreach($candidates as $candidate) foreach($candidate['points'] as $point)
		{
			$gap = hypot($point['x'] - $probe['x'], $point['z'] - $probe['z']);
			if($gap < $distance) {$distance = $gap; $best = $candidate;}
		}

		return $best;
	}

	/**
	 * Stitch two neighbouring contours into one band, always taking the shorter diagonal.
	 *
	 * Both loops run the same way around their shared boundary. Triangles wind like the quad (a, a+1, b+1, b).
	 *
	 * @return list<array{array{int,array,int},array{int,array,int},array{int,array,int}}> Vertices as [loop, point, index].
	 */
	private static function zipLoops(array $a, array $b): array
	{
		$n = count($a);
		$m = count($b);
		$start = 0;
		$closest = INF;
		foreach($b as $index => $point)
		{
			$gap = hypot($point['x'] - $a[0]['x'], $point['z'] - $a[0]['z']);
			if($gap < $closest) {$closest = $gap; $start = $index;}
		}
		$length = static fn(array $p, array $q): float => hypot($p['x'] - $q['x'], $p['z'] - $q['z']);
		$triangles = [];
		for($i = 0, $j = 0; $i < $n || $j < $m;)
		{
			$a0 = $i % $n; $a1 = ($i + 1) % $n;
			$b0 = ($start + $j) % $m; $b1 = ($start + $j + 1) % $m;
			$advanceA = $j >= $m || ($i < $n && $length($a[$a1], $b[$b0]) <= $length($a[$a0], $b[$b1]));
			if($advanceA)
			{
				$triangles[] = [[0,$a[$a0],$a0],[0,$a[$a1],$a1],[1,$b[$b0],$b0]];
				$i++;
			}
			else
			{
				$triangles[] = [[0,$a[$a0],$a0],[1,$b[$b1],$b1],[1,$b[$b0],$b0]];
				$j++;
			}
		}

		return $triangles;
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
	 * Turn the cue from its anatomy: x runs from the butt (-1.35) to the tip (0), and u is the distance from the tip.
	 *
	 * Leather tip and ivory ferrule, a pro-taper maple shaft swelling to 21 mm at the joint, a steel collar, a tapering
	 * forearm, the wrap, the butt sleeve at 28.5 mm, and a rounded rubber bumper. Zones are painted in billiards.frag.
	 *
	 * @param int $segments Facets around the cue.
	 */
	public static function cue(int $segments = 32): MeshData
	{
		// [distance from the tip, radius] in metres; the ferrule is turned flush with the shaft, and repeated distances
		// make the steps at the collar and the bumper.
		$profile = [
			[0,0],[0,.0045],[.0012,.006],[.007,.0063],[.032,.0063],[.3,.0066],[.655,.0105]
			, [.655,.0107],[.658,.0107],[.658,.0109],[.683,.0109],[.683,.0107],[.686,.0107],[.686,.0109],[.985,.0128]
			, [1.235,.0138],[1.335,.01425],[1.343,.0142],[1.349,.0128],[1.35,.008],[1.35,0]
		];
		$mesh = new MeshData;
		$rings = [];
		$count = count($profile);
		foreach($profile as $index => [$distance,$radius])
		{
			// Each ring takes its normal from the profile slope around it, so caps face along the axis and steps stay
			// square while the tapers shade smoothly.
			[$previousDistance,$previousRadius] = $profile[max(0, $index - 1)];
			[$nextDistance,$nextRadius] = $profile[min($count - 1, $index + 1)];
			$along = $nextDistance - $previousDistance;
			$rise = $nextRadius - $previousRadius;
			if($radius == 0) [$along,$rise] = [0,$index === 0 ? 1 : -1];
			elseif($nextDistance == $distance && $nextRadius != $radius) [$along,$rise] = [$distance - $previousDistance,$radius - $previousRadius];
			$length = hypot($along, $rise) ?: 1;
			$ring = [];
			for($segment = 0; $segment <= $segments; $segment++)
			{
				$turn = $segment / $segments;
				$c = cos($turn * M_PI * 2);
				$s = sin($turn * M_PI * 2);
				// A surface of revolution r(d) with x = -d has the outward normal (dr/dd, cos, sin), before normalising.
				$normal = [$rise / $length,$c * $along / $length,$s * $along / $length];
				$normalLength = sqrt($normal[0] ** 2 + $normal[1] ** 2 + $normal[2] ** 2) ?: 1;
				$ring[] = $mesh->vertex([
					-$distance,$c * $radius,$s * $radius
					, $normal[0] / $normalLength,$normal[1] / $normalLength,$normal[2] / $normalLength,$distance,$turn
				]);
			}
			$rings[] = $ring;
		}
		for($index = 0; $index + 1 < $count; $index++) for($segment = 0; $segment < $segments; $segment++)
		$mesh->quad($rings[$index + 1][$segment],$rings[$index + 1][$segment + 1],$rings[$index][$segment + 1],$rings[$index][$segment]);

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

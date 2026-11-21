<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Bounded quadtree selection with complete coarse fallback coverage.
 *
 * A child group replaces its parent only when all four children are resident.
 * Selection uses projected cell size and elevation, without changing collision detail.
 */
final class Lod
{
	/** Mesh cells on each side of every patch. */
	public const CELLS = 32;
	/** Maximum depth used by the selector; minimum mesh spacing is configurable. */
	private int $depth;
	private array $childGroups = [];
	private ?float $cachedExtent = null;

	/**
	 * Configure a finite quadtree without allocating world meshes.
	 * @param float $extent Width of the square world in meters.
	 * @param float $spacing Minimum physical sample spacing in meters.
	 * @return void Establishes the quadtree's finite leaf depth.
	 */
	public function __construct(public float $extent = 32768, float $spacing = 1)
	{
		if(!is_finite($extent) || !is_finite($spacing) || $extent <= 0 || $spacing <= 0)
		throw new \InvalidArgumentException('LOD extent and spacing must be finite and positive');

		$this->depth = max(0, (int)floor(log($extent / (self::CELLS * $spacing), 2)));
	}

	/**
	 * Describe one patch by depth and integer quadtree coordinates.
	 * @param int $level Depth, with zero covering the whole world.
	 * @param int $x Horizontal tile index at this depth.
	 * @param int $z Vertical tile index at this depth.
	 * @return array Stable key and spatial footprint.
	 */
	public function node(int $level = 0, int $x = 0, int $z = 0): array
	{
		if($level < 0 || $level > 20 || $x < 0 || $z < 0 || $x >= (1 << $level) || $z >= (1 << $level))
		throw new \InvalidArgumentException('Invalid quadtree node');

		$size = $this->extent / (1 << $level);

		return [
			'key' => "$level:$x:$z"
			, 'level' => $level
			, 'ix' => $x
			, 'iz' => $z
			, 'x' => $x * $size - $this->extent / 2
			, 'z' => $z * $size - $this->extent / 2
			, 'size' => $size
		];
	}

	/**
	 * Reuse the four immutable footprints visited by view traversal, balance and prefetch.
	 * @param array $parent Parent quadtree node.
	 * @return array Four child nodes in Z-major order.
	 */
	public function children(array $parent): array
	{
		if($this->cachedExtent !== $this->extent)
		{
			$this->cachedExtent = $this->extent;
			$this->childGroups = [];
		}
		$key = $parent['key'];
		if(isset($this->childGroups[$key])) return $this->childGroups[$key];
		if(count($this->childGroups) >= 512) unset($this->childGroups[array_key_first($this->childGroups)]);
		$level = $parent['level'] + 1;
		$x = $parent['ix'] * 2;
		$z = $parent['iz'] * 2;
		return $this->childGroups[$key] = [
			$this->node($level,$x,$z), $this->node($level,$x+1,$z),
			$this->node($level,$x,$z+1), $this->node($level,$x+1,$z+1)
		];
	}

	/**
	 * Select resident patches and report missing sibling groups with projected importance.
	 *
	 * A node refines only where its detail can show. The caller either passes a predicate, or passes the
	 * clip planes of the view and the selector tests each node's padded bounds itself, which spares a
	 * predicate call and a copied node for every node that wants to split. The padded bounds are the node
	 * widened by its own size on every side, from the lower of its ground and $detailLow to the higher of
	 * its ground and $detailHigh, stretched up and down by the same margin. The six planes of
	 * Camera::clipPlanes() get the fast test; any other number of planes selects alike, more slowly.
	 * @param array $eye Camera world position.
	 * @param array $resident Resident patch metadata keyed by node key.
	 * @param float $quality Detail multiplier, from .5 to 2.
	 * @param float $projection Vertical focal length in pixels for projected geometric error.
	 * @param callable|null $inDetailView Optional predicate for the padded view used to request finer meshes.
	 * @param array|null $detailPlanes Optional clip planes relative to the eye, which replace the predicate.
	 * @param float $detailLow Lowest height the padded bounds must reach, such as the lowest water.
	 * @param float $detailHigh Highest height the padded bounds must reach, such as the highest water.
	 * @return array Lists named draw, request and refine; requested/refining nodes include group priority.
	 */
	public function select(
		array $eye
		, array $resident
		, float $quality = 1
		, float $projection = 600
		, ?callable $inDetailView = null
		, ?array $detailPlanes = null
		, float $detailLow = INF
		, float $detailHigh = -INF
	): array {

		$walk = $this->walk($eye, $resident, $quality, $projection, $inDetailView, $detailPlanes, $detailLow
			, $detailHigh);

		// Without a credit the walk never pauses, so its first step is the whole selection.
		$walk->current();

		return $walk->getReturn();
	}

	/**
	 * Walk the quadtree as select() does, pausing after a given number of refined nodes.
	 *
	 * A caller that spreads one selection over several frames resumes the walk with send(), passing the credit
	 * for the next slice, and takes the lists from getReturn() once valid() is false. The walk keeps the
	 * resident set it was given, so patches that arrive or leave meanwhile change neither its lists nor its
	 * course. Only a node that refines spends credit: the walk visits at most a few dozen nodes between two
	 * of those, and a test there would cost every other node a decrement and a comparison.
	 * @param array $eye Camera world position.
	 * @param array $resident Resident patch metadata keyed by node key.
	 * @param float $quality Detail multiplier, from .5 to 2.
	 * @param float $projection Vertical focal length in pixels for projected geometric error.
	 * @param callable|null $inDetailView Optional predicate for the padded view used to request finer meshes.
	 * @param array|null $detailPlanes Optional clip planes relative to the eye, which replace the predicate.
	 * @param float $detailLow Lowest height the padded bounds must reach, such as the lowest water.
	 * @param float $detailHigh Highest height the padded bounds must reach, such as the highest water.
	 * @param int $credit Nodes to refine before the first pause; zero or less never pauses.
	 * @return \Generator Yields at each pause and returns the lists that select() returns.
	 */
	public function walk(
		array $eye
		, array $resident
		, float $quality = 1
		, float $projection = 600
		, ?callable $inDetailView = null
		, ?array $detailPlanes = null
		, float $detailLow = INF
		, float $detailHigh = -INF
		, int $credit = 0
	): \Generator {

		if(count($eye) !== 3 || !array_is_list($eye) || !is_finite($quality) || $quality <= 0
			|| !is_finite($projection) || $projection <= 0
		)
		throw new \InvalidArgumentException('Invalid LOD view');

		foreach($eye as $value)
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('LOD eye must be finite');

		$sixPlanes = false;

		if($detailPlanes !== null)
		{
			if($inDetailView !== null)
			throw new \InvalidArgumentException('Pass a detail predicate or detail planes, not both');

			if(count($detailPlanes) === 6 && array_is_list($detailPlanes)
				&& $detailLow === $detailLow && $detailHigh === $detailHigh
			){
				// The loop tests the six planes of Camera::clipPlanes() one by one from scalars: reading a
				// plane from an array costs more there than the arithmetic on it.
				[$a0, $b0, $c0, $d0] = $detailPlanes[0];
				[$a1, $b1, $c1, $d1] = $detailPlanes[1];
				[$a2, $b2, $c2, $d2] = $detailPlanes[2];
				[$a3, $b3, $c3, $d3] = $detailPlanes[3];
				[$a4, $b4, $c4, $d4] = $detailPlanes[4];
				[$a5, $b5, $c5, $d5] = $detailPlanes[5];

				// A plane judges a box by the corner farthest along its normal. Which corner that is depends
				// on the plane alone, so three comparisons per plane settle it here for the whole call.
				[$ap0, $bp0, $cp0] = [$a0 > 0, $b0 > 0, $c0 > 0];
				[$ap1, $bp1, $cp1] = [$a1 > 0, $b1 > 0, $c1 > 0];
				[$ap2, $bp2, $cp2] = [$a2 > 0, $b2 > 0, $c2 > 0];
				[$ap3, $bp3, $cp3] = [$a3 > 0, $b3 > 0, $c3 > 0];
				[$ap4, $bp4, $cp4] = [$a4 > 0, $b4 > 0, $c4 > 0];
				[$ap5, $bp5, $cp5] = [$a5 > 0, $b5 > 0, $c5 > 0];
				$sixPlanes = true;
			}
			else
			{

				/**
				 * Test one node's padded bounds with the reference arithmetic.
				 *
				 * Another number of planes has no unrolled test, and a NAN bound has no portable one,
				 * because PHP versions disagree about min() and max() of NAN.
				 * @param array $node Quadtree node to consider refining.
				 * @param array $patch Resident mesh carrying its vertical bounds.
				 * @return bool Whether the padded bounds can intersect the view.
				 */
				$inDetailView = static fn(array $node, array $patch): bool => self::paddedInView(
					$detailPlanes
					, $eye
					, $node
					, $patch
					, $detailLow
					, $detailHigh
				);
			}
		}

		// children() drops its cache when the extent has changed. The loop reads that cache without calling
		// children(), so the same check runs here first.
		if($this->cachedExtent !== $this->extent)
		{
			$this->cachedExtent = $this->extent;
			$this->childGroups = [];
		}

		$draw = [];
		$request = [];
		$refine = [];
		$depth = $this->depth;
		[$ex, $ey, $ez] = $eye;
		// A counted stack replaces array_pop() and array_push(): the loop ignores stale entries above $top.
		$stack = [$this->node()];
		$top = 1;

		while($top > 0)
		{
			$n = $stack[--$top];
			$key = $n['key'];
			$patch = $resident[$key] ?? null;

			if($patch === null)
			{
				$request[] = $n + ['priority' => PHP_FLOAT_MAX];

				continue;
			}

			// From here to $importance is importance() with comparisons in place of its max() calls: in
			// the browser's PHP a function call costs many times what a comparison does, and this runs for
			// every visited node. The eye can be outside the node on only one side of each axis, so the far
			// edge needs measuring only when the eye is past the near edge.
			$x = $n['x'];
			$z = $n['z'];
			$size = $n['size'];
			$dx = $x - $ex;

			if($dx < 0)
			{
				$dx = $ex - $x - $size;

				if($dx <= 0) $dx = 0;
			}

			$dz = $z - $ez;

			if($dz < 0)
			{
				$dz = $ez - $z - $size;

				if($dz <= 0) $dz = 0;
			}

			$dy = $ey - ($patch['centerHeight'] ?? 0);
			$error = (float)($patch['geometricError'] ?? 0);

			if($error <= 0.0) $error = 0.0;

			$demand = $error * $projection * $quality / 2;
			$bySize = $size * 1.7 * $quality;

			if($demand <= $bySize) $demand = $bySize;

			// Importance is demand over distance, and it exceeds one exactly when demand exceeds distance.
			// A node whose squared demand is clearly below its squared distance therefore stays coarse,
			// which spares most nodes three powers and a square root. Plain products stand in for the
			// powers here: the two can differ in the last bit, and the margin is ten orders of magnitude wider.
			$near = $dx * $dx + $dz * $dz + $dy * $dy;

			if($near - $demand * $demand > $near * 1e-6)
			{
				$draw[] = $n;

				continue;
			}

			// Every sum, product and power below keeps the operands and order importance() uses, so the
			// result agrees with it to the last bit.
			$distance = sqrt($dx ** 2 + $dz ** 2 + $dy ** 2);

			if($distance < 1.0) $distance = 1.0;

			$importance = $demand / $distance;

			// The comparisons above carry a NAN height or error through to here. PHP versions disagree about
			// max() of NAN, so importance() itself says what such a node is worth.
			if($importance !== $importance)
			$importance = $this->importance($n, $eye, $resident, $quality, $projection);

			// Traversal is already bounded by resident meshes. A separate greedy leaf
			// cutoff can strand an adjacent quadrant at root depth and collapse balance.
			if(!($importance > 1) || $n['level'] >= $depth)
			{
				$draw[] = $n;

				continue;
			}

			if($sixPlanes)
			{
				$low = $patch['minY'];

				if($detailLow < $low) $low = $detailLow;

				$high = $patch['maxY'];

				if($high < $detailHigh) $high = $detailHigh;

				$low -= $size;
				$high += $size;

				// A NAN bound, or bounds out of order, would mean different things to min() and max() in
				// different PHP versions. The arithmetic below takes ordinary bounds only, and the reference
				// test decides every other node.
				if($low < $high)
				{
					// These are the bounds paddedInView() hands to Camera::inView(), taken relative to the eye
					// with the same subtractions.
					$span = $size + 2 * $size;
					$x0 = $x - $size - $ex;
					$x1 = $x0 + $span;
					$x0 += 0;
					$z0 = $z - $size - $ez;
					$z1 = $z0 + $span;
					$z0 += 0;
					$y0 = $low - $ey;
					$y1 = $high - $ey;

					// Camera::inView() hides a box only when one of these sums falls below a negative rounding
					// allowance. Nearly every sum is positive, so only a box with a negative sum goes on to the
					// exact test, which Camera::rejectingPlane() runs with the arithmetic of inView().
					if($a0 * ($ap0 ? $x1 : $x0) + $b0 * ($bp0 ? $y1 : $y0) + $c0 * ($cp0 ? $z1 : $z0) + $d0 < 0
						|| $a1 * ($ap1 ? $x1 : $x0) + $b1 * ($bp1 ? $y1 : $y0) + $c1 * ($cp1 ? $z1 : $z0) + $d1 < 0
						|| $a2 * ($ap2 ? $x1 : $x0) + $b2 * ($bp2 ? $y1 : $y0) + $c2 * ($cp2 ? $z1 : $z0) + $d2 < 0
						|| $a3 * ($ap3 ? $x1 : $x0) + $b3 * ($bp3 ? $y1 : $y0) + $c3 * ($cp3 ? $z1 : $z0) + $d3 < 0
						|| $a4 * ($ap4 ? $x1 : $x0) + $b4 * ($bp4 ? $y1 : $y0) + $c4 * ($cp4 ? $z1 : $z0) + $d4 < 0
						|| $a5 * ($ap5 ? $x1 : $x0) + $b5 * ($bp5 ? $y1 : $y0) + $c5 * ($cp5 ? $z1 : $z0) + $d5 < 0
					){
						$padded = ['x' => $x - $size, 'z' => $z - $size, 'size' => $span];

						if(Camera::rejectingPlane($detailPlanes, $eye, $padded, $low, $high) >= 0)
						{
							$draw[] = $n;

							continue;
						}
					}
				}
				elseif(!self::paddedInView($detailPlanes, $eye, $n, $patch, $detailLow, $detailHigh))
				{
					$draw[] = $n;

					continue;
				}
			}
			elseif($inDetailView !== null && !$inDetailView($n, $resident[$key]))
			{
				$draw[] = $n;

				continue;
			}

			$children = $this->childGroups[$key] ?? $this->children($n);
			$ready = true;
			$refine[] = $n + ['priority' => $importance];
			$base = $top;

			foreach($children as $c)
			{
				if(isset($resident[$c['key']])) $stack[$top++] = $c;
				else
				{
					$ready = false;
					$request[] = $c + ['priority' => $importance];
				}
			}

			// A credit that starts at zero falls below it and never comes back, so a whole walk never pauses.
			if(--$credit === 0) $credit = yield;

			if($ready) continue;

			// Children wait until all four are resident, so drop the ones pushed and keep drawing the parent.
			$top = $base;
			$draw[] = $n;
		}

		return ['draw' => $draw,'request' => $request, 'refine' => $refine];
	}

	/**
	 * Test a node's padded bounds against clip planes with min(), max() and Camera::inView() as they stand.
	 *
	 * select() runs this arithmetic inline for speed. This is the definition that inline code matches, and
	 * the path select() takes for the inputs the inline code leaves out.
	 * @param array $planes Clip planes relative to the eye.
	 * @param array $eye Camera world position.
	 * @param array $node Quadtree node to consider refining.
	 * @param array $patch Resident mesh carrying its vertical bounds.
	 * @param float $low Lowest height the padded bounds must reach.
	 * @param float $high Highest height the padded bounds must reach.
	 * @return bool Whether the padded bounds can intersect the view.
	 */
	private static function paddedInView(
		array $planes
		, array $eye
		, array $node
		, array $patch
		, float $low
		, float $high
	): bool {
		$margin = $node['size'];

		$node['x'] -= $margin;
		$node['z'] -= $margin;
		$node['size'] += 2 * $margin;

		return Camera::inView(
			$planes
			, $eye
			, $node
			, min($patch['minY'], $low) - $margin
			, max($patch['maxY'], $high) + $margin
		);
	}

	/**
	 * Measure refinement demand using distance and sampled projected geometric error.
	 * @param array $node Quadtree node whose children may be required.
	 * @param array $eye Current or predicted camera position.
	 * @param array $resident Resident geometry and sampled error metadata.
	 * @param float $quality User detail multiplier.
	 * @param float $projection Vertical focal length in pixels.
	 * @return float Values above one request refinement; larger values run first.
	 */
	private function importance(array $node, array $eye, array $resident, float $quality, float $projection): float
	{
		$height = $resident[$node['key']]['centerHeight'] ?? 0;
		$dx = max($node['x'] - $eye[0], 0, $eye[0] - $node['x'] - $node['size']);
		$dz = max($node['z'] - $eye[2], 0, $eye[2] - $node['z'] - $node['size']);
		$distance = max(1.0, sqrt($dx ** 2 + $dz ** 2 + ($eye[1] - $height) ** 2));
		$error = max(0.0, (float)($resident[$node['key']]['geometricError'] ?? 0));

		return max($node['size'] * 1.7 * $quality, $error * $projection * $quality / 2) / $distance;
	}
}

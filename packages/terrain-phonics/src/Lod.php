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
	 * @param float $extent Width of the square world in metres.
	 * @param float $spacing Minimum physical sample spacing in metres.
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
	 * @param array $eye Camera world position.
	 * @param array $resident Resident patch metadata keyed by node key.
	 * @param float $quality Detail multiplier, from .5 to 2.
	 * @param float $projection Vertical focal length in pixels for projected geometric error.
	 * @param callable|null $inDetailView Optional predicate for the padded view used to request finer meshes.
	 * @return array Lists named draw, request and refine; requested/refining nodes include group priority.
	 */
	public function select(array $eye, array $resident, float $quality = 1, float $projection = 600, ?callable $inDetailView = null): array
	{
		if(count($eye) !== 3 || !array_is_list($eye) || !is_finite($quality) || $quality <= 0
			|| !is_finite($projection) || $projection <= 0
		)
		throw new \InvalidArgumentException('Invalid LOD view');

		foreach($eye as $value)
		if(!is_numeric($value) || !is_finite((float)$value))
		throw new \InvalidArgumentException('LOD eye must be finite');
		$draw = [];
		$request = [];
		$refine = [];
		$stack = [$this->node()];

		while($stack !== [])
		{
			$n = array_pop($stack);

			if(!isset($resident[$n['key']]))
			{
				$request[$n['key']] = $n + ['priority' => PHP_FLOAT_MAX];
				continue;
			}

			$importance = $this->importance($n, $eye, $resident, $quality, $projection);
			$split = $n['level'] < $this->depth && $importance > 1
				&& ($inDetailView === null || $inDetailView($n, $resident[$n['key']]));

			// Traversal is already bounded by resident meshes. A separate greedy leaf
			// cutoff can strand an adjacent quadrant at root depth and collapse balance.
			if(!$split)
			{
				$draw[] = $n;
				continue;
			}

			$children = $this->children($n);
			$ready = true;
			$refine[] = $n + ['priority' => $importance];

			foreach($children as $c)
			{
				if(!isset($resident[$c['key']]))
				{
					$ready = false;
					$request[$c['key']] = $c + ['priority' => $importance];
				}
			}

			if($ready) array_push($stack, ...$children);
			else $draw[] = $n;
		}

		return ['draw' => $draw,'request' => array_values($request), 'refine' => $refine];
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

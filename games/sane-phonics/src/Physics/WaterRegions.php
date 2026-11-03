<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;
use Phonics\Tiled\TileMap;
use Phonics\Tiled\Loader;

/** Loads and queries rectangular water objects without coupling physics to rendering. */
final class WaterRegions
{
	/** @var list<WaterRegion> Water volumes in map and object painter order. */
	public array $regions;

	/**
	 * Create a query collection from explicit regions.
	 *
	 * @param WaterRegion ...$regions Water volumes retained in the supplied precedence order.
	 * @return void Stores regions for point, segment, and surface queries.
	 */
	public function __construct(WaterRegion ...$regions)
	{
		$this->regions = $regions;
	}

	/**
	 * Load Water, WaterRegion, water-region, and legacy region rectangle objects from Tiled maps.
	 * A boolean `water` property is also accepted for legacy untyped rectangles; object-reference
	 * properties named `water` belong to switches and buoyant platforms and are not volumes.
	 *
	 * @param TileMap ...$maps Loaded maps whose placement and inherited layer offsets are applied.
	 * @return self Query collection containing every recognized water object.
	 */
	public static function fromMaps(TileMap ...$maps): self
	{
		$regions = [];

		foreach($maps as $map)
		{
			foreach($map->layers as $layer)
			{
				foreach($layer->objects as $object)
				{
					$properties = Loader::properties($object['properties'] ?? []);
					$class = strtolower((string)(($object['class'] ?? '')?:($object['type'] ?? '')));

					if(!in_array($class, ['water','waterregion','water-region','region'], true)
						&& ($properties['water'] ?? null) !== true
					){
						continue;
					}

					if(!empty($object['polygon']) || !empty($object['polyline']) || !empty($object['ellipse'])
						|| (float)($object['rotation'] ?? 0) !== 0.0
					){
						throw new \UnexpectedValueException('Water regions must be unrotated rectangles');
					}

					$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
					$y = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
					$width = (float)($object['width'] ?? 0);
					$height = (float)($object['height'] ?? 0);

					if(isset($object['gid']))
					{
						$y -= $height;
					}

					$regions[] = new WaterRegion(
						$x
						, $y
						, $width
						, $height
						, (float)($properties['gravity'] ?? .4)
						, (float)($properties['drag'] ?? .85)
						, (float)($properties['density'] ?? 1)
						, (float)($properties['skimSpeed'] ?? 900)
						, (float)($properties['currentX'] ?? 0)
						, (float)($properties['currentY'] ?? 0)
						, Loader::color((string)($properties['color'] ?? '#4d147ab8'))
						, (float)($properties['displacement'] ?? 4)
						, (float)($properties['displacementScale'] ?? 64)
						, (float)($properties['displacementSpeed'] ?? 42.6666667)
						, isset($object['id']) ? (int)$object['id'] : null
					);
				}
			}
		}

		return new self(...$regions);
	}

	/**
	 * Find a region by its source Tiled object ID.
	 *
	 * @param int $sourceId Tiled object ID referenced by a switch or buoyant object property.
	 * @return WaterRegion|null Last region with the supplied ID, or null when it is absent.
	 */
	public function bySourceId(int $sourceId): ?WaterRegion
	{
		$result = null;

		foreach($this->regions as $region)
		{
			if($region->sourceId === $sourceId)
			{
				$result = $region;
			}
		}

		return $result;
	}

	/**
	 * Select the highest-precedence water region containing a point.
	 *
	 * @param float $x World X coordinate.
	 * @param float $y World Y coordinate.
	 * @return WaterRegion|null Last matching region, or null outside water.
	 */
	public function at(float $x, float $y): ?WaterRegion
	{
		$result = null;

		foreach($this->regions as $region)
		{
			if($region->contains($x, $y))
			{
				$result = $region;
			}
		}

		return $result;
	}

	/**
	 * Find a water surface immediately below or intersecting a point.
	 *
	 * @param float $x World X coordinate that must lie within the region's horizontal span.
	 * @param float $y World Y coordinate compared with each water surface.
	 * @param float $tolerance Maximum vertical distance from the surface in pixels.
	 * @return WaterRegion|null Last matching surface, or null when no surface is close enough.
	 */
	public function surfaceAt(float $x, float $y, float $tolerance): ?WaterRegion
	{
		$result = null;

		foreach($this->regions as $region)
		{
			if($x >= $region->x && $x <= $region->x + $region->width && abs($y - $region->y) <= $tolerance)
			{
				$result = $region;
			}
		}

		return $result;
	}

	/**
	 * Return water volumes crossed by a point segment, including high-speed pass-throughs.
	 *
	 * @param float $x Starting world X coordinate.
	 * @param float $y Starting world Y coordinate.
	 * @param float $dx Horizontal segment displacement.
	 * @param float $dy Vertical segment displacement.
	 * @return list<WaterRegion> Regions intersected in collection order.
	 */
	public function crossed(float $x, float $y, float $dx, float $dy): array
	{
		$result = [];

		foreach($this->regions as $region)
		{
			if($this->intersects($region, $x, $y, $dx, $dy))
			{
				$result[] = $region;
			}
		}

		return $result;
	}

	/**
	 * Apply a slab test between one segment and one axis-aligned water rectangle.
	 *
	 * @param WaterRegion $region Rectangle being tested.
	 * @param float $x Segment start X coordinate.
	 * @param float $y Segment start Y coordinate.
	 * @param float $dx Segment horizontal displacement.
	 * @param float $dy Segment vertical displacement.
	 * @return bool True when any segment fraction from zero through one lies inside the rectangle.
	 */
	private function intersects(WaterRegion $region, float $x, float $y, float $dx, float $dy): bool
	{
		$near = 0.0;
		$far = 1.0;

		foreach([
			[$x,$dx,$region->x,$region->x + $region->width]
			, [$y,$dy,$region->y,$region->y + $region->height]
		] as [$origin,$delta,$minimum,$maximum])
		{
			if(abs($delta) < 1e-12)
			{
				if($origin < $minimum || $origin > $maximum)
				{
					return false;
				}

				continue;
			}

			$a = ($minimum - $origin) / $delta;
			$b = ($maximum - $origin) / $delta;
			$near = max($near, min($a, $b));
			$far = min($far, max($a, $b));

			if($near > $far)
			{
				return false;
			}
		}

		return true;
	}
}

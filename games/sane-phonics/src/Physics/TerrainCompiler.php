<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\MapTerrain;
use Phonics\Tiled\Layer;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;
use SanePhonics\Gameplay\BendableBridges;
use SanePhonics\Gameplay\Platforms;
use SanePhonics\Gameplay\VehicleLoader;

/**
 * Compiles Tiled maps into Sane terrain through the engine's MapTerrain, adding what Sane reads differently.
 *
 * Tile shapes carry a grinding flag instead of surface metadata; vehicle loaders, platforms, bridges and grapple
 * anchors are left to their own systems; and objects become layer switches, grind rails, one-way paths or solid
 * shapes by class and property.
 */
final class TerrainCompiler
{
	/**
	 * Convert Tiled collision shapes and directional gates into shared world-space terrain.
	 *
	 * @param TileMap ...$maps Loaded maps whose world placement and inherited layer offsets are applied.
	 * @return Terrain Spatially indexed polygons, selected collision planes, and layer-switch regions.
	 */
	public static function build(TileMap ...$maps): Terrain
	{
		$terrain = new Terrain;

		MapTerrain::compile($terrain, [
			'surface' => self::grinding(...)
			, 'skip' => self::skip(...)
			, 'object' => self::object(...)
		], ...$maps);

		return $terrain;
	}

	/**
	 * Decide whether a tile shape is a grind surface from its own, its tile's and its layer's properties.
	 *
	 * @param array<string,mixed> $props Shape properties.
	 * @param array<string,mixed> $tileProps Tile properties.
	 * @param array<string,mixed> $layerProps Layer properties.
	 * @return bool True when any level flags grinding.
	 */
	private static function grinding(array $props, array $tileProps, array $layerProps): bool
	{
		return (bool)($props['grinding'] ?? $props['grind'] ?? $tileProps['grinding'] ?? $tileProps['grind']
			?? $layerProps['grinding'] ?? $layerProps['grind'] ?? false
		);
	}

	/**
	 * Leave objects that other systems build to those systems.
	 *
	 * @param array<string,mixed> $object Tiled object definition.
	 * @param Layer $layer Layer the object sits on.
	 * @return bool True for vehicle loaders, platforms, bendable bridges and grapple anchors.
	 */
	private static function skip(array $object, Layer $layer): bool
	{
		return VehicleLoader::matches($object)
			|| Platforms::matches($object)
			|| BendableBridges::matches($object)
			|| in_array(strtolower(($object['class'] ?? '') ?: ($object['type'] ?? '')), [
				'grapple-point', 'grapplepoint', 'rope-anchor', 'ropeanchor'
			], true);
	}

	/**
	 * Add one object as a layer switch, grind rail, one-way path or solid shape, or ignore it.
	 *
	 * @param Terrain $terrain Terrain being filled.
	 * @param array<string,mixed> $object Tiled object definition.
	 * @param Layer $layer Layer the object sits on.
	 * @param int $collisionLayer Collision plane the layer selects.
	 * @param callable $project Maps object-space points to world space.
	 * @return bool Always true: Sane decides every object itself.
	 */
	private static function object(Terrain $terrain, array $object, Layer $layer, int $collisionLayer, callable $project): bool
	{
		$layerGrinding = (bool)($layer->properties['grinding'] ?? $layer->properties['grind'] ?? false);
		$props = Loader::properties($object['properties'] ?? []);
		$class = ($object['class'] ?? '')?:($object['type'] ?? '');
		$switch = in_array($class, ['layer-switch', 'LayerSwitch'], true);
		$grinding = in_array(strtolower($class), ['rail', 'grinding'], true)
			|| (bool)($props['grinding'] ?? $props['grind'] ?? $layerGrinding);

		if(!$switch && !$grinding && !($props['solid'] ?? $layer->properties['solid'] ?? false))
		{
			return true;
		}

		if($switch && isset($object['gid']))
		{
			// Tiled tile objects rotate around their bottom-left anchor.
			$angle = deg2rad($object['rotation'] ?? 0);
			$object['x'] += sin($angle) * ($object['height'] ?? 0);
			$object['y'] -= cos($angle) * ($object['height'] ?? 0);
		}

		$points = $project(MapTerrain::points($object));

		if($switch)
		{
			$terrain->addSwitch(
				new LayerSwitch(
					$points
					, (int)($props['fromLayer'] ?? 1)
					, (int)($props['toLayer'] ?? 2)
					, (bool)(int)($props['back'] ?? 0)
					, (bool)(int)($props['roll'] ?? 0)
				)
			);

			return true;
		}

		$plane = (int)($props['collisionLayer'] ?? $collisionLayer);

		if($grinding && isset($object['polyline']))
		{
			$terrain->addRail($points, $plane);
		}
		elseif(isset($object['polyline']))
		{
			$terrain->addPathShape($points, (bool)($props['oneWay'] ?? false), $plane);
		}
		else
		{
			$terrain->add($points, (bool)($props['oneWay'] ?? $grinding), $plane, $grinding);
		}

		return true;
	}
}

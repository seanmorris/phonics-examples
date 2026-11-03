<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;
use Phonics\Tiled\TileMap;
use Phonics\Tiled\Loader;

/** Builds collision terrain and layer-switch regions from loaded Tiled maps. */
final class MapTerrain
{

	/**
	 * Convert Tiled collision shapes and directional gates into shared world-space terrain.
	 *
	 * @param TileMap ...$maps Loaded maps whose world placement and inherited layer offsets are
	 * applied.
	 * @return Terrain Spatially indexed polygons, selected collision planes, and layer-switch
	 * regions.
	 */
	public static function build(TileMap ...$maps): Terrain
	{
		$terrain = new Terrain;

		foreach($maps as $map)
		{
			foreach($map->layers as $layer)
			{
				if(!($layer->properties['collision'] ?? true))
				{
					continue;
				}

				$collisionLayer = (int)($layer->properties['collisionLayer'] ?? (preg_match(
					'/^Collision\s*(\d+)$/i'
					, $layer->name
					, $match
				)
					? $match[1]
					: 0
				));

				$ox = $map->x + $layer->offsetX;
				$oy = $map->y + $layer->offsetY;
				$layerGrinding = (bool)($layer->properties['grinding'] ?? $layer->properties['grind'] ?? false);

				foreach($layer->chunks as $chunk)
				{
					for($y = $chunk->y; $y < $chunk->y + $chunk->height; $y++)
					{
						for($x = $chunk->x; $x < $chunk->x + $chunk->width; $x++)
						{
							$gid = $chunk->gidAt($x, $y);
							$tile = $map->tile($gid->id);

							if(!$tile)
							{
								continue;
							}

							[$px,$py] = $map->projection->project($x, $y);
							$w = $tile['rect'][2];
							$h = $tile['rect'][3];

							if($map->projection->orientation === 'isometric')
							{
								$px -= $w / 2;
							}

							$py += $map->projection->tileHeight - $h;
							$px += $tile['offset'][0];
							$py += $tile['offset'][1];
							$shapes = $tile['objectgroup']['objects'] ?? [];

							if(!$shapes && ($tile['properties']['solid'] ?? $layer->properties[
								'solid'
							]
								?? false
							)){
								$shapes = [['x' => 0,'y' => 0,'width' => $w,'height' => $h]];
							}

							foreach($shapes as $shape)
							{
								$points = self::points($shape);
								$transformed = [];

								foreach($points as [$u,$v])
								{
									[$u,$v] = $gid->uv($u / $w, $v / $h);
									$transformed[] = [$ox + $px + $u * $w,$oy + $py + $v * $h];
								}

								$props = Loader::properties($shape['properties'] ?? []);
								$grinding = (bool)($props['grinding'] ?? $props['grind']
									?? $tile['properties']['grinding'] ?? $tile['properties']['grind']
									?? $layerGrinding
								);

								$terrain->add(
									$transformed
									, (bool)($props['oneWay'] ?? $tile['properties']['oneWay']
										?? $layer->properties['oneWay'] ?? false
									)
									, $collisionLayer
									, $grinding
								);
							}
						}
					}
				}

				foreach($layer->objects as $object)
				{
					if(\SanePhonics\Gameplay\VehicleLoader::matches($object)
						|| \SanePhonics\Gameplay\Platforms::matches($object)
						|| \SanePhonics\Gameplay\BendableBridges::matches($object)
						|| in_array(strtolower(($object['class'] ?? '') ?: ($object['type'] ?? '')), [
							'grapple-point', 'grapplepoint', 'rope-anchor', 'ropeanchor'
						], true
						)
					){
						continue;
					}

					$props = Loader::properties($object['properties'] ?? []);

					$class = ($object['class'] ?? '')?:($object['type'] ?? '');
					$switch = in_array($class, ['layer-switch', 'LayerSwitch'], true);
					$grinding = in_array(strtolower($class), ['rail', 'grinding'], true)
						|| (bool)($props['grinding'] ?? $props['grind'] ?? $layerGrinding);

					if(!$switch && !$grinding && !($props['solid'] ?? $layer->properties['solid'] ?? false))
					{
						continue;
					}

					if($switch && isset($object['gid']))
					{
						// Tiled tile objects rotate around their bottom-left anchor.
						$angle = deg2rad($object['rotation'] ?? 0);
						$object['x'] += sin($angle) * ($object['height'] ?? 0);
						$object['y'] -= cos($angle) * ($object['height'] ?? 0);
					}

					$points = self::points($object);

					if($map->projection->orientation === 'isometric')
					{
						$points = array_map(
							/**
							 * Project a Tiled isometric object point into map pixel coordinates.
							 *
							 * @param array{0:float,1:float} $p Object-space X and Y measured in
							 * Tiled pixel units.
							 * @return array{0:float,1:float} Projected map-local X and Y.
							 */
							fn($p) => $map->projection->project(
								$p[0] / $map->projection->tileHeight
								, $p[1] / $map->projection->tileHeight
							)
							, $points
						);
					}

					$points = array_map(
						/**
						 * Translate a shape vertex by its map placement and inherited layer offset.
						 *
						 * @param array{0:float,1:float} $p Vertex before world translation.
						 * @return array{0:float,1:float} World-space X and Y in pixels.
						 */
						fn($p) => [$p[0] + $ox,$p[1] + $oy]
						, $points
					);

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
					}
					else
					{
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
					}
				}
			}
		}

		return $terrain;
	}

	/**
	 * Expand a Tiled rectangle, ellipse, polygon, or polyline and apply object rotation.
	 *
	 * @param array<string,mixed> $object Tiled object definition; unresolved templates are
	 * rejected.
	 * @return list<array{0:float|int,1:float|int}> Points in the object layer's coordinate space,
	 * before map placement.
	 */
	public static function points(array $object): array
	{
		if(isset($object['template']))
		{
			throw new \UnexpectedValueException('Resolve object templates before export');
		}

		$w = $object['width'] ?? 0;
		$h = $object['height'] ?? 0;
		$points = $object['polygon'] ?? $object['polyline'] ?? [
			['x' => 0,'y' => 0]
			, [
				'x' => $w
				, 'y' => 0
			]
			, ['x' => $w,'y' => $h],['x' => 0,'y' => $h]
		];

		if(!empty($object['ellipse']))
		{
			$points = [];

			for($i = 0; $i < 24; $i++)
			{
				$points[] = [
					'x' => $w / 2 + cos($i * M_PI / 12) * $w / 2
					, 'y' => $h / 2 + sin($i * M_PI / 12) * $h / 2
				];
			}
		}

		$angle = deg2rad($object['rotation'] ?? 0);
		$c = cos($angle);
		$s = sin($angle);

		return array_map(
			/**
			 * Rotate a local object vertex and translate it to the object position.
			 *
			 * @param array{x:float|int,y:float|int} $p Local unrotated shape vertex.
			 * @return array{0:float,1:float} Vertex in the containing layer's coordinate space.
			 */
			fn($p) => [
				($object['x'] ?? 0) + $p['x'] * $c - $p['y'] * $s
				, ($object['y'] ?? 0) + $p[
				'x'
				] * $s + $p['y'] * $c
			]
			, $points
		);
	}
}

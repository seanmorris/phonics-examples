<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Render\Sprite;
use Phonics\Render\SpriteLayers;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Layer;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Tiled vehicle objects and supply/drop regions sharing the level's sprite atlas. */
final class VehicleLoader
{

	/**
	 * Identify dynamic vehicle objects before building static map collision.
	 *
	 * @param array<string,mixed> $object Raw Tiled object definition.
	 * @return bool True for Tornado, EggWalker, skateboard, supply-region, and drop-region objects.
	 */
	public static function matches(array $object): bool
	{
		return in_array(
			strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''))
			, [
				'skateboard'
				, 'skate-board'
				, 'tornado'
				, 'eggwalker'
				, 'egg-walker'
				, 'vehicleregion'
				, 'vehicle-region'
				, 'dropvehicleregion'
				, 'drop-vehicle-region'
			]
			, true
		);
	}

	/**
	 * Promote map objects into owned vehicle and region entities.
	 *
	 * @param Vehicles $system Vehicle owner receiving the new entities.
	 * @param TileMap $map Normalized map with inherited offsets, tint, and collision layers.
	 * @return void Loads supported objects and rejects unsupported supply types or rotations.
	 */
	public function load(Vehicles $system, TileMap $map): void
	{
		$fallback = null;
		$tornado = [];
		$walker = [];

		foreach($map->tilesets as $set)
		foreach($set['definitions'] as $local => $tile)
		{
			$class = strtolower((string)($tile['class'] ?? $tile['type'] ?? ''));

			if($class === 'skateboard') $fallback = $set['firstgid'] + $local;

			if(str_starts_with($class, 'tornado')) $tornado[$class] = $set['firstgid'] + $local;

			if(str_starts_with($class, 'egg-walker')) $walker[$class] = $set['firstgid'] + $local;
		}

		foreach($map->layers as $layer)
		foreach($layer->objects as $object)
		{
			if(!self::matches($object)) continue;

			if(($object['rotation'] ?? 0) != 0)
			throw new \UnexpectedValueException('Vehicle objects must be axis aligned');

			$class = strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''));
			$p = Loader::properties($object['properties'] ?? []);
			$plane = (int)($p['collisionLayer'] ?? $layer->properties['collisionLayer'] ?? 1);
			$x = (float)$object['x'] + $map->x + $layer->offsetX;
			$y = (float)$object['y'] + $map->y + $layer->offsetY;
			$isTornado = $class === 'tornado';
			$isWalker = in_array($class, ['eggwalker', 'egg-walker'], true);
			$baseGid = $isTornado
				? ($tornado['tornado'] ?? null)
				: ($isWalker ? ($walker['egg-walker'] ?? null) : $fallback);

			$sprite = $this->sprite($map, $object, $layer, $baseGid);

			if(!in_array($class, ['skateboard', 'skate-board', 'tornado', 'eggwalker', 'egg-walker'], true))
			{
				$drop = str_starts_with($class, 'drop');

				if($sprite !== null)
				{
					// MB2 excludes invisible supply/drop regions even when an editor tile is present.
					$sprite->outline = false;
				}

				if(!$drop && !in_array($p['vehicle'] ?? 'skate-board', ['skate-board', 'skateboard'], true))
				{
					throw new \UnexpectedValueException('This vehicle region requires a skateboard');
				}

				$region = new VehicleRegion(
					$x
					, $y
					, (float)$object['width']
					, (float)$object['height']
					, (bool)($p['restricted'] ?? false)
					, $drop
					, $sprite
					, $plane
				);

				$system->region($region);

				continue;
			}

			$x += (float)($object['width'] ?? 0) / 2;
			$y += isset($object['gid']) ? 0 : (float)($object['height'] ?? 0);
			$body = $isTornado
				? Tornado::body($x, $y - 24)
				: ($isWalker ? EggWalker::body($x, $y - 32) : Skateboard::body($x, $y - 6));

			$body->collisionLayer = $plane;
			$body->facing = $body->direction = (int)($p['direction'] ?? 1) < 0 ? -1 : 1;

			$vehicle = new Vehicle(
				$body
				, (float)($p['seatHeight'] ?? ($isTornado ? 14 : ($isWalker ? 48 : 12)))
				, (float)($p['seatForward'] ?? 0)
				, (float)($p['seatAngle'] ?? 0)
				, (bool)($p['lockedIn'] ?? false)
				, (bool)($p['quickDrop'] ?? false)
				, (int)$object['id']
			);

			$layers = null;

			if($isWalker && $sprite !== null && isset($walker['egg-walker-front']))
			{
				$front = clone $sprite;
				$front->gid = $walker['egg-walker-front'];
				$layers = new SpriteLayers([$sprite], [$front]);
			}

			$behavior = $isTornado
				? new Tornado($tornado)
				: ($isWalker ? new EggWalker($walker, $layers) : new Skateboard);

			$system->add($vehicle, $behavior, $sprite);
		}
	}

	/**
	 * Decode artwork and flips before atlas lookup, retaining layer appearance.
	 *
	 * @param TileMap $map Map whose tiles define image dimensions.
	 * @param array<string,mixed> $object Optional tile object or region definition.
	 * @param Layer $layer Inherited appearance of the object layer.
	 * @param int|null $fallback Annotated skateboard tile used when no GID is supplied.
	 * @return Sprite|null Bottom-centered sprite, or null for an artless scripted vehicle.
	 */
	private function sprite(TileMap $map, array $object, Layer $layer, ?int $fallback): ?Sprite
	{
		$raw = $object['gid'] ?? $fallback;

		if($raw === null) return null;

		$gid = Gid::from($raw);
		$tile = $map->tile($gid->id) ?? throw new \UnexpectedValueException('Vehicle artwork tile is missing');
		$sprite = new Sprite($gid->id);
		$sprite->flipX = (bool)($gid->flags & 8);
		$sprite->flipY = (bool)($gid->flags & 4);
		$sprite->opacity = $layer->visible && ($object['visible'] ?? true) ? $layer->opacity : 0;
		$sprite->tint = $layer->tint;

		if(isset($object['gid']))
		{
			$sprite->scaleX = (float)(($object['width'] ?? 0) ?: $tile['rect'][2]) / $tile['rect'][2];
			$sprite->scaleY = (float)(($object['height'] ?? 0) ?: $tile['rect'][3]) / $tile['rect'][3];
		}

		if($gid->flags & 2)
		{
			$sprite->originX = 1;
			$sprite->originY = .5;
			$sprite->rotation = M_PI_2;
			[$sprite->scaleX, $sprite->scaleY] = [
				$sprite->scaleY * $tile['rect'][3] / $tile['rect'][2]
				, $sprite->scaleX * $tile['rect'][2] / $tile['rect'][3]
			];

			[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
		}

		return $sprite;
	}
}

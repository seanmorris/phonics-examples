<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Render\Sprite;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Layer;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Resolve Tiled handles, suspension references, and annotated rope artwork. */
final class GrappleLoader
{

	/**
	 * Load handles and shared anchors from flattened object layers.
	 *
	 * @param Grapples $system Owner receiving the newly created entities.
	 * @param TileMap $map Map supplying world offsets, object references, and sprite definitions.
	 * @param callable(int):?int|null $resolveAnchor Optional source ID to moving entity resolver.
	 * @return void Creates fixed anchors when no live entity is supplied; missing references throw.
	 */
	public function load(Grapples $system, TileMap $map, ?callable $resolveAnchor = null): void
	{
		$objects = $art = $anchors = [];

		foreach($map->tilesets as $set)
		{
			foreach($set['definitions'] as $local => $tile)
			{
				$class = $tile['class'] ?? $tile['type'] ?? '';

				if(in_array($class, ['grapple-handle', 'rope-segment', 'rope-anchor'], true))
				{
					$art[$class] ??= $set['firstgid'] + $local;
				}
			}
		}

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				$object['x'] = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
				$object['y'] = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
				$objects[(int)$object['id']] = [$object, $layer];
			}
		}

		foreach($objects as [$object, $layer])
		{
			$class = strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''));

			if(!in_array($class, ['grapple-point', 'grapplepoint'], true))
			{
				continue;
			}

			$properties = Loader::properties($object['properties'] ?? []);
			$source = (int)($properties['tiedTo'] ?? 0);
			[$target, $targetLayer] = $objects[$source]
				?? throw new \UnexpectedValueException('Grapple tiedTo references a missing anchor');

			$targetProperties = Loader::properties($target['properties'] ?? []);

			if(!isset($anchors[$source]))
			{
				[$x, $y] = $this->position($target);
				$follow = $resolveAnchor === null ? null : $resolveAnchor($source);
				$anchor = new RopeAnchor($x, $y, $follow, $source);
				$sprite = null;

				if($follow === null)
				{
					$sprite = $this->sprite($map, $target, $targetLayer, $art['rope-anchor'] ?? null);
				}

				$anchors[$source] = $system->anchor($anchor, $sprite);
			}

			[$x, $y] = $this->position($object);

			$point = new GrapplePoint(
				(float)(($object['width'] ?? 0) ?: 28)
				, (float)(($object['height'] ?? 0) ?: 16)
				, (int)($properties['collisionLayer'] ?? $layer->properties['collisionLayer'] ?? 0)
				, (int)$object['id']
			);

			$rope = new Rope(
				$anchors[$source]
				, $x
				, $y
				, (float)($properties['ropeLength'] ?? $targetProperties['ropeLength'] ?? 64)
				, (bool)($properties['noSwing'] ?? false)
			);

			$sprite = $this->sprite($map, $object, $layer, $art['grapple-handle'] ?? null);
			$segment = $art['rope-segment'] ?? null;
			$system->add($point, $rope, $sprite, $segment, $segment === null ? 8 : $map->tile($segment)['rect'][3]);
		}
	}

	/**
	 * Convert a Tiled point or rectangle into its bottom-centered gameplay anchor.
	 *
	 * @param array<string,mixed> $object Object with already translated world coordinates.
	 * @return array{float,float} Anchor coordinates; rotated object rectangles are unsupported.
	 */
	private function position(array $object): array
	{
		if(($object['rotation'] ?? 0) != 0)
		{
			throw new \UnexpectedValueException('Grapple objects must be axis aligned');
		}

		return [
			$object['x'] + (float)($object['width'] ?? 0) / 2
			, $object['y'] + (isset($object['gid']) ? 0 : (float)($object['height'] ?? 0))
		];
	}

	/**
	 * Resolve optional sprite artwork, decoding unsigned tile transforms before atlas lookup.
	 *
	 * @param TileMap $map Map whose normalized tiles define image dimensions.
	 * @param array<string,mixed> $object Object with optional explicit GID and display bounds.
	 * @param Layer $layer Inherited visibility, opacity, and tint.
	 * @param int|null $fallback Annotated tile GID when the object has no tile image.
	 * @return Sprite|null Bottom-centered artwork, or null when no image is available.
	 */
	private function sprite(TileMap $map, array $object, Layer $layer, ?int $fallback): ?Sprite
	{
		$raw = $object['gid'] ?? $fallback;

		if($raw === null)
		{
			return null;
		}

		$gid = Gid::from($raw);
		$tile = $map->tile($gid->id) ?? throw new \UnexpectedValueException('Grapple sprite tile is missing');
		$width = (float)(($object['width'] ?? 0) ?: $tile['rect'][2]);
		$height = (float)(($object['height'] ?? 0) ?: $tile['rect'][3]);
		$sprite = new Sprite($gid->id);
		$sprite->scaleX = $width / $tile['rect'][2];
		$sprite->scaleY = $height / $tile['rect'][3];
		$sprite->flipX = (bool)($gid->flags & 8);
		$sprite->flipY = (bool)($gid->flags & 4);
		$sprite->tint = $layer->tint;
		$sprite->opacity = $layer->visible && ($object['visible'] ?? true) ? $layer->opacity : 0;

		if($gid->flags & 2)
		{
			$sprite->originX = 1;
			$sprite->originY = .5;
			$sprite->scaleX = $height / $tile['rect'][2];
			$sprite->scaleY = $width / $tile['rect'][3];
			$sprite->rotation = M_PI_2;
			[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
		}

		return $sprite;
	}
}

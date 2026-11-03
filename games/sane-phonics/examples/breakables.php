<?php
declare(strict_types=1);

use Phonics\Engine;
use Phonics\Ecs\Registry;
use Phonics\Effects\ParticleSystem;
use SanePhonics\Gameplay\BreakableBlocks;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/**
 * Populate the breakable-block scene and bind reset and particle updates.
 *
 * @param Engine $engine Engine providing the registry, controls, scheduler, and clock.
 * @param Terrain $terrain Terrain shared by the player and stationary blocks.
 * @param TileMap $map Demo map containing editable breakable tile objects.
 * @param string $prefix Unique prefix for systems owned by this level instance.
 * @return BreakableBlocks Block owner to close when the scene stops.
 */
function configureBreakables(
	Engine $engine
	, Terrain $terrain
	, TileMap $map
	, string $prefix = ''
): BreakableBlocks {
	$blocks = new BreakableBlocks($engine->entities, $terrain, $map);
	$ids = [];

	/**
	 * Recreate the demo's Tiled block objects, retiring intact blocks and remaining debris.
	 *
	 * @return void Restores block poses and per-object shatter grids.
	 */
	$reset = function() use ($engine, $map, $blocks, &$ids): void {
		foreach($ids as $id)
		{
			if($engine->entities->alive($id))
			{
				$engine->entities->destroy($id);
			}
		}

		$ids = [];

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				if(($object['class'] ?? $object['type'] ?? '') !== 'breakable')
				{
					continue;
				}

				$properties = Loader::properties($object['properties'] ?? []);
				$gid = Gid::from($object['gid']);

				$sprite = new Sprite(
					$gid->id
					, $object['x'] + $map->x + $layer->offsetX
					, $object['y'] + $map->y + $layer->offsetY
					, 0
					, 1
				);

				$rect = $map->tile($sprite->gid)['rect'];
				$sprite->scaleX = $object['width'] / $rect[2];
				$sprite->scaleY = $object['height'] / $rect[3];
				$sprite->rotation = deg2rad($object['rotation'] ?? 0);
				$sprite->flipX = (bool)($gid->flags & 8);
				$sprite->flipY = (bool)($gid->flags & 4);

				if($gid->flags & 2)
				{
					// Swap source axes before H/V flips, keeping Tiled's bottom-left pivot.
					$sprite->originX = 1;
					$sprite->scaleX = $object['height'] / $rect[2];
					$sprite->scaleY = $object['width'] / $rect[3];
					$sprite->rotation += M_PI / 2;
					[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
				}

				$ids[] = $blocks->create($sprite, $properties['columns'], $properties['rows']);
			}
		}
	};

	try
	{
		$reset();
	}
	catch(Throwable $error)
	{
		$blocks->close();
		throw $error;
	}

	/**
	 * Restore the scene's intact blocks and retire old debris when reset is pressed.
	 *
	 * @param Registry $entities Scene registry, unused because the reset closure owns its IDs.
	 * @param float $dt Fixed simulation delta in seconds, unused by reset.
	 * @return void Recreates blocks on the reset edge.
	 */
	$breakableSystem = function(Registry $entities, float $dt) use ($engine, $reset): void {
		if($engine->controls->pressed('reset'))
		{
			$reset();
		}
	};

	$engine->systems->add($prefix . 'breakables', $breakableSystem, after: ['movement']);
	$engine->systems->add($prefix . 'particles', [new ParticleSystem, 'update'], after: [$prefix . 'breakables']);

	return $blocks;
}

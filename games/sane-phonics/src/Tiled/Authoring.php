<?php
declare(strict_types=1);
namespace SanePhonics\Tiled;

use Phonics\Tiled\ClassCatalog;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Expand Sane's editor presets at the boundary to its existing gameplay loaders. */
final class Authoring
{

	/**
	 * Resolve nested settings without changing source documents, geometry, or object IDs.
	 * @param array $object Resolved object or tile definition, including template property layers.
	 * @return array Equivalent legacy loader record; unrelated classes pass through unchanged.
	 */
	public static function object(array $object): array
	{
		$name = ($object['class'] ?? '') ?: ($object['type'] ?? '');

		if(!str_starts_with($name, 'SanePhonics.Entity.')) return $object;

		static $catalog;

		if($catalog === null)
		{
			$source = __DIR__ . '/../../worlds/phonics.classes.json';
			$data = json_decode(file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
			// These bindings request resolved values only. Gameplay systems own the actual ECS entities.
			$data['bindings'] = [];

			foreach($data['classes'] as $class)
			if(str_starts_with($class['name'], 'SanePhonics.Entity.'))
			$data['bindings'][$class['name']] = 'settings';
			$catalog = new ClassCatalog($data, $source);
		}

		$resolved = $catalog->components($object, $object['sourceUrl'] ?? '')[0]['values']
			?? throw new \UnexpectedValueException("Unknown Sane entity preset $name");

		$type = substr($name, strlen('SanePhonics.Entity.'));
		$records = $object['propertyRecords'] ?? $object['properties'] ?? [];
		$properties = [];

		foreach($records as $record)
		if(($record['type'] ?? '') !== 'class') $properties[$record['name']] = $record;

		foreach($resolved as $settings)
		foreach($settings as $key => $value)
		{
			if(in_array($type, ['Spring', 'GravitySwitch'], true))
			{
				if($key === 'length' && $value == 0) continue;

				if(in_array($key, ['normalX', 'normalY'], true)
					&& ($settings['normalX'] ?? 0) == 0 && ($settings['normalY'] ?? 0) == 0
				) continue;
			}

			if($key === 'collisionLayer' && $value < 0) continue;

			// Unset links preserve the legacy optional/fallback behavior, including ride-only paths.
			if($value === 0 && (in_array($key, ['path', 'ridePath'], true) || ($key === 'water' && $type === 'Block')))
			continue;

			if($type === 'ring') $key = 'ring.' . $key;

			$properties[$key] = ['name' => $key, 'value' => $value];
		}

		unset($object['propertyRecords']);
		$object['class'] = $object['type'] = $type;
		$object['properties'] = array_values($properties);

		return $object;
	}

	/**
	 * Expand a parsed map once before its gameplay systems inspect objects and tiles.
	 * @param TileMap $map Parsed map whose authored data remains available in data.
	 * @return void Normalizes loader records while preserving tile identity and spatial placement.
	 */
	public static function prepare(TileMap $map): void
	{
		static $prepared;
		$prepared ??= new \WeakMap;

		if(isset($prepared[$map])) return;

		foreach($map->layers as $layer)
		foreach($layer->objects as &$object) $object = self::object($object);
		unset($object);

		foreach($map->tilesets as &$tileset)
		foreach($tileset['definitions'] as &$tile)
		{
			$tile = self::object($tile);

			if(isset($tile['properties'][0]['name']))
			{
				$tile['propertyRecords'] = $tile['properties'];
				$tile['properties'] = Loader::properties($tile['properties']);
			}
		}

		unset($tileset, $tile);
		$prepared[$map] = true;
	}
}

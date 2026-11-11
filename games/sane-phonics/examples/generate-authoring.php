<?php
declare(strict_types=1);
namespace SanePhonics\Examples;

/**
 * Keep generated maps on the same authoring format as hand-edited Tiled projects.
 * @param array $data Generated map, world, or tileset document.
 * @return array Document with typed system lists and nested spawn components.
 */
function authoredMap(array $data): array
{
	$type = $data['type'] ?? '';

	if(!in_array($type, ['map', 'world'], true)) return $data;

	$properties = $data['properties'] ?? [];
	$found = false;

	foreach($properties as &$property)
	{
		if(!in_array($property['name'], ['phonics.systems', 'phonics.modules'], true)) continue;

		if($property['name'] === 'phonics.systems') $found = true;

		if($property['type'] !== 'string') continue;

		$list = json_decode($property['value'], true, 512, JSON_THROW_ON_ERROR);

		if(array_filter($list, /** Detect bindings that must retain their legacy option records.
		 * @param mixed $value System binding or reference.
		 * @return bool Whether the value cannot be represented as a string item.
		 */ static fn($value): bool => !is_string($value)
		)) continue;

		$property['type'] = 'list';

		$property['value'] = array_map(
			/** Encode one native Tiled string-list item.
		 * @param string $value System reference.
		 * @return array Typed list item.
		 */ static fn(string $value): array => [
			 'type' => 'string'
			 , 'value' => $value
		 ]
			, $list
		);
	}

	unset($property);

	if(!$found)
	$properties[] = [
		'name' => 'phonics.systems'
		, 'type' => 'list'
		, 'value' => [['type' => 'string', 'value' => $type === 'world' ? '@sane/spatial-maps' : '@sane/demo-map']]
	];

	$data['properties'] = $properties;

	/** Migrate spawn markers within recursively nested object layers.
	 * @param array $layers Authored layers at this depth.
	 * @return array Layers preserving geometry and unrelated properties.
	 */
	$visit = static function(array $layers) use (&$visit): array {
		foreach($layers as &$layer)
		{
			if(isset($layer['layers'])) $layer['layers'] = $visit($layer['layers']);

			foreach($layer['objects'] ?? [] as $index => $object)
			{
				if(($object['class'] ?? $object['type'] ?? '') !== 'Player') continue;

				unset($object['class']);
				$object['type'] = '';
				$object['properties'][] = [
					'name' => 'SpawnPoint'
					, 'type' => 'class'
					, 'propertytype' => 'SanePhonics.SpawnPoint'
					, 'value' => new \stdClass
				];

				$layer['objects'][$index] = $object;
			}
		}

		unset($layer);

		return $layers;
	};

	if(isset($data['layers'])) $data['layers'] = $visit($data['layers']);

	return $data;
}

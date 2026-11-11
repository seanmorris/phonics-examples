<?php
use Phonics\Asset\Assets;
use Phonics\Ecs\Registry;
use Phonics\Tiled\Loader;
use SanePhonics\Tiled\Authoring;
use SanePhonics\Gameplay\Ring;
use SanePhonics\Gameplay\Rings;
use SanePhonics\Gameplay\Monitors;
use SanePhonics\Gameplay\Monitor;
use SanePhonics\Gameplay\RingMonitorEffect;
use SanePhonics\Physics\Terrain;

/** Build a map using actual shipped templates and tilesets with deliberately shifted GIDs.
 * @param array $objects Template instances and overrides.
 * @return \Phonics\Tiled\TileMap Loaded map with resolved templates and typed defaults.
 */
function authoredFixture(array $objects): \Phonics\Tiled\TileMap
{
	$root = dirname(__DIR__);
	$path = $root . '/worlds/authoring-fixture.tmj';
	$data = [
		'type' => 'map'
		, 'orientation' => 'orthogonal'
		, 'width' => 32
		, 'height' => 24
		, 'tilewidth' => 32
		, 'tileheight' => 32
		, 'tilesets' => []
		, 'layers' => [
			[
				'id' => 1
				, 'type' => 'objectgroup'
				, 'name' => 'Actors'
				, 'offsetx' => 7
				, 'offsety' => -11
				, 'objects' => $objects
			]
		]
	];

	foreach(glob($root . '/examples/assets/*.tsj') as $index => $file)
	$data['tilesets'][] = ['firstgid' => 1 + $index * 10000, 'source' => '../examples/assets/' . basename($file)];

	$assets = new Assets(
		static fn(string $file): string => $file === $path
			? json_encode($data, JSON_THROW_ON_ERROR)
			: file_get_contents($file)
	);

	return (new Loader($assets))->map($path);
}

test('every Sane concrete template resolves its artwork and typed settings', function() {
	$root = dirname(__DIR__);
	$objects = [];

	foreach(glob($root . '/templates/*.tj') as $index => $file)
	$objects[] = ['id' => $index + 1, 'template' => '../templates/' . basename($file), 'x' => 100, 'y' => 200];
	$map = authoredFixture($objects);
	check(count($map->layers[0]->objects) === 34);

	foreach($map->layers[0]->objects as $object)
	{
		check(str_starts_with($object['type'], 'SanePhonics.Entity.'));

		if(isset($object['gid'])) check($map->tile((int)$object['gid']) !== null);

		$expanded = Authoring::object($object);
		check(!str_starts_with($expanded['type'], 'SanePhonics.'));
		check($expanded['x'] === 100 && $expanded['y'] === 200);
	}

	$source = $map->data;
	Authoring::prepare($map);
	$first = serialize([$map->layers, $map->tilesets]);
	Authoring::prepare($map);
	check(serialize([$map->layers, $map->tilesets]) === $first);
	check($map->data === $source, 'Authored source remains inspectable');
});

test('ring template instances honor nested overrides, GID remapping, transforms and ownership', function() {
	$map = authoredFixture([
		[
			'id' => 1
			, 'template' => '../templates/ring.tj'
			, 'x' => 100
			, 'y' => 200
			, 'rotation' => 90
			, 'properties' => [
				[
					'name' => 'Ring'
					, 'type' => 'class'
					, 'propertytype' => 'SanePhonics.Ring'
					, 'value' => [
						'value' => 3
						, 'radius' => 18
					]
				]
			]
		]
		, ['id' => 2, 'template' => '../templates/ring.tj', 'x' => 0, 'y' => 0, 'visible' => false]
	]);

	$map->x = 50;
	$map->y = -20;
	$registry = new Registry;
	$rings = new Rings($registry, $map);
	$ids = $registry->query(Ring::class);
	check(count($ids) === 1);
	$ring = $registry->get($ids[0], Ring::class);
	near($ring->x, 173);
	near($ring->y, 185);
	near($ring->radius, 18);
	check($ring->value === 3 && $ring->points === 0);
	$rings->close();
	check($registry->query(Ring::class) === []);
});

test('monitor templates keep reward defaults independent and use the tile bottom center', function() {
	$map = authoredFixture([
		['id' => 1, 'template' => '../templates/ring-monitor.tj', 'x' => 100, 'y' => 200]
		, ['id' => 2, 'template' => '../templates/triple-ring-monitor.tj', 'x' => 200, 'y' => 200]
	]);

	$registry = new Registry;
	$monitors = new Monitors($registry, new Terrain, $map);
	$ids = $registry->query(Monitor::class);
	check(count($ids) === 2);
	near($registry->get($ids[0], Monitor::class)->x, 122);
	near($registry->get($ids[0], Monitor::class)->y, 189);
	check($registry->get($ids[0], RingMonitorEffect::class)->rings === 10);
	check($registry->get($ids[1], RingMonitorEffect::class)->rings === 30);
	$monitors->close();
});

test('nested defaults retain optional paths, inherited collision planes and rotated spring normals', function() {
	$path = Authoring::object([
		'type' => 'SanePhonics.Entity.PathPlatform'
		, 'properties' => [
			[
				'name' => 'PlatformPath'
				, 'type' => 'class'
				, 'propertytype' => 'SanePhonics.PlatformPath'
				, 'value' => [
					'ridePath' => 17
				]
			]
		]
	]);

	$p = Loader::properties($path['properties']);
	check(!isset($p['path'], $p['collisionLayer']) && $p['ridePath'] === 17);
	$spring = Authoring::object(['type' => 'SanePhonics.Entity.Spring', 'rotation' => 90]);
	$p = Loader::properties($spring['properties']);
	check(!isset($p['normalX'], $p['normalY'], $p['length']));
	check($spring['rotation'] === 90 && $p['power'] === 960);
});

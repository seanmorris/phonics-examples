<?php
declare(strict_types=1);

use Phonics\Engine;
use SanePhonics\Gameplay\Badnik;
use SanePhonics\Gameplay\Badniks;
use SanePhonics\Gameplay\BendableBridges;
use SanePhonics\Gameplay\BossRegions;
use SanePhonics\Gameplay\BreakableBlocks;
use SanePhonics\Gameplay\CrabMeats;
use SanePhonics\Gameplay\MiniBosses;
use SanePhonics\Gameplay\LightDashes;
use SanePhonics\Gameplay\Monitors;
use SanePhonics\Gameplay\Platforms;
use SanePhonics\Gameplay\Grapples;
use SanePhonics\Gameplay\GravityFeatures;
use SanePhonics\Gameplay\Vehicles;
use SanePhonics\Gameplay\Rings;
use SanePhonics\Gameplay\Shields;
use Phonics\Ui\TextActors;
use SanePhonics\Gameplay\WaterFeatures;
use SanePhonics\Gameplay\WaterParticles;
use SanePhonics\Physics\Body;
use Phonics\Physics\Hit;
use SanePhonics\Physics\TerrainCompiler;
use SanePhonics\Physics\Platformer;
use SanePhonics\Physics\Terrain;
use SanePhonics\Physics\WaterRegions;
use Phonics\Render\BackdropTwistRenderer;
use Phonics\Render\Camera;
use Phonics\Render\MapRenderer;
use Phonics\Render\MotionBlur;
use Phonics\Render\MotionBlurRenderer;
use Phonics\Render\SpriteRenderer;
use SanePhonics\Render\WaterRenderer;
use Phonics\Render\WorldRenderer;
use Phonics\Tiled\Loader;
use Phonics\Tiled\World;
use Phonics\Tiled\WorldStream;

/** Assets, graphics, and collision owned by one demo level, independent of the game session. */
final class DemoLevel
{
	public ?WorldStream $stream = null;
	public MapRenderer|WorldRenderer|null $renderer = null;
	public ?SpriteRenderer $sprites = null;
	public ?Terrain $terrain = null;
	public ?Platformer $movement = null;
	public ?WaterRegions $waters = null;
	public ?WaterRenderer $waterRenderer = null;
	public ?BackdropTwistRenderer $backdropTwist = null;
	public ?MotionBlur $motionBlur = null;
	public ?MotionBlurRenderer $motionBlurRenderer = null;
	public ?BreakableBlocks $blocks = null;
	public ?Rings $rings = null;
	public ?LightDashes $lightDashes = null;
	public ?Badniks $badniks = null;
	public ?BossRegions $bossRegions = null;
	public ?CrabMeats $crabs = null;
	public ?MiniBosses $miniBosses = null;
	public ?Monitors $monitors = null;
	public ?Shields $shields = null;
	public ?WaterFeatures $waterFeatures = null;
	public ?Platforms $platforms = null;
	public ?BendableBridges $bridges = null;
	public ?Grapples $grapples = null;
	public ?Vehicles $vehicles = null;
	public ?GravityFeatures $gravityFeatures = null;
	public ?WaterParticles $waterParticles = null;
	public ?TextActors $textActors = null;
	/** @var array{float,float} Player center loaded from the map's Player point object. */
	public array $spawn = [80.0, 100.0];
	/** @var array{float,?float,float,float}|null Optional player reset bounds in world pixels. */
	public ?array $resetBounds = null;
	private string $systemPrefix;
	private bool $closed = false;
	private ?\Phonics\World\WorldRuntime $runtime = null;
	private ?\Phonics\World\ScopeContext $mapScope = null;
	private array $leases = [], $prepared = [];
	private bool $active = false;
	/** Whether map selection is supplied by declared world systems. */
	public bool $worldSystems = false;
	private string $sourceId;

	/**
	 * Create a staging level without touching the active scene or engine.
	 *
	 * @param string $id Validated demo level identifier.
	 * @param object $assets Host asset scope with request, release, and close methods.
	 * @return void Records ownership without requesting files or allocating graphics.
	 */
	public function __construct(public string $id, private object $assets)
	{
		$this->sourceId = 'sane-level-' . spl_object_id($this);
		$this->systemPrefix = $this->sourceId . ':';
	}

	/**
	 * Poll dependencies and construct the destination before retiring the current level.
	 *
	 * @param Engine $engine Running session supplying the registry and simulation clock.
	 * @param Camera $camera Current viewport; its zoom is retained for the new scene.
	 * @return bool True when the scene can be committed; false while transfers are pending.
	 */
	public function prepare(Engine $engine, Camera $camera): bool
	{
		if($this->stream === null)
		{
			$loader = new Loader;
			$path = __DIR__ . '/assets/stream.world';

			$config = vrzno_env('saneWorld');
			$config = is_string($config) ? json_decode($config, true) : null;

			if($config && $this->id === $config['scene'])
			{
				$world = new World($loader, $config['sourceUrl'], $config);

				if($this->id !== 'world')
				{
					$world = new World($loader, $config['sourceUrl'], [
						'properties' => $world->data['properties'] ?? []
						, 'maps' => [
							[
								'fileName' => $config['start']
								, 'x' => 0
								, 'y' => 0
								, 'width' => 1
								, 'height' => 1
							]
						]
					]);
				}
			}
			elseif($this->id === 'world')
			{
				$state = $this->assets->request($path);

				if(is_string($state))
				{
					throw new RuntimeException($state);
				}

				if(!$state)
				{
					return false;
				}

				$world = $loader->world($path);
			}
			else
			{
				// Single-map scenes use the same dependency discovery as world streaming.
				$world = new World(
					$loader
					, $path
					, [
						'maps' => [
							[
								'fileName' => $this->id . '.tmj'
								, 'x' => 0
								, 'y' => 0
								, 'width' => 1
								, 'height' => 1
							]
						]
					]
				);
			}

			if(!isset(Loader::properties($world->data['properties'] ?? [])['phonics.classes']))
			$world->data['properties'][] = [
				'name' => 'phonics.classes'
				, 'type' => 'file'
				, 'value' => \Phonics\Asset\Resource::source(__DIR__ . '/../worlds/phonics.classes.json')
			];

			global $phonics;
			$this->runtime = $phonics->runtime->fork();
			$this->runtime->service('sane.level', $this);
			$this->runtime->service('sane.engine', $engine);
			$this->runtime->service('sane.camera', $camera);

			$this->runtime->adapter('sane-world', new class($world) implements \Phonics\World\ContentAdapter {

				/** Retain the already loaded world for this scene.
				 * @param World $world Parsed world owned by the level.
				 * @return void Stores the content reference.
				 */
				public function __construct(private World $world) {}

				/** Adapt this scene's world to the content runtime.
				 * @param \Phonics\World\SourceDefinition $definition Source registration.
				 * @param \Phonics\World\AdapterContext $context Runtime services.
				 * @return \Phonics\World\ContentSource Tiled content source for this scene.
				 */
				public function open(
					\Phonics\World\SourceDefinition $definition
					, \Phonics\World\AdapterContext $context
				): \Phonics\World\ContentSource {
					return new \Phonics\Tiled\Source($this->world);
				}
			});

			$this->runtime->source(new \Phonics\World\SourceDefinition($this->sourceId, 'sane-world', [], [
				'lifecycle' => 'reset'
			]));

			$this->stream = new WorldStream(
				$world
				, /**
				 * Poll a file using the shared host transport.
				 *
				 * @param string $path Resolved dependency path in the mounted engine directory.
				 * @param bool $retry Whether to retry a failed transfer.
				 * @return bool|string True when mounted, false while pending, or an error message.
				 */
				fn(string $path, bool $retry): bool|string => $this->assets->request($path, $retry)
				, /**
				 * Release this level's ownership when its last map drops a file.
				 *
				 * @param string $path Resolved dependency path no longer needed by this stream.
				 * @return void Keeps files referenced by other live level scopes mounted.
				 */
				function(string $path): void { $this->assets->release($path); }
			);
		}

		$this->runtime->service('sane.stream', $this->stream);
		$this->runtime->prepare($this->sourceId, 'world');

		$this->worldSystems = !empty(\Phonics\Tiled\Loader::properties(
			$this->stream->world->data['properties'] ?? []
		)['phonics.systems']);

		$this->stream->classes = $this->runtime->load($this->sourceId)->classes;
		$view = clone $camera;
		[$view->x, $view->y] = $this->origin();

		if($this->id === 'world')
		{
			$this->stream->update($view->x, $view->y, $view->width / $view->zoom, $view->height / $view->zoom);
		}
		else
		{
			$this->stream->update(0, 0, 1, 1);
		}

		$maps = $this->stream->loaded();

		if(!$maps)
		{
			$pending = array_diff($this->stream->pending(), array_keys($this->stream->errors));

			if(!$pending)
			{
				throw new RuntimeException(implode('; ', $this->stream->errors) ?: 'No starting map');
			}

			return false;
		}

		$this->syncFactories($engine);
		$this->motionBlur = new MotionBlur;
		$this->motionBlurRenderer = new MotionBlurRenderer($engine->device->width, $engine->device->height);

		if($this->id === 'world')
		{
			$this->renderer = new WorldRenderer($this->stream);
			// Warm visible GPU resources before the old scene is released.
			$this->renderer->render($view, $engine->time * 1000, 0);

			return true;
		}

		return true;
	}

	/** Assemble the systems selected by the map's phonics.systems property.
	 * @param \Phonics\World\ScopeContext $scope Owner of this map's entities and systems.
	 * @return void Prepares map features and their cleanup callbacks.
	 */
	public function composeMap(\Phonics\World\ScopeContext $scope): void
	{
		if($this->id === 'world') return;

		$this->mapScope = $scope;
		$this->systemPrefix = $scope->key() . '/';

		$scope->onClose(/** Release this map's gameplay managers.
		 * @return void Cleans up entities and graphics.
		 */ fn() => $this->releaseMapFeatures()
		);

		$engine = $scope->service('sane.engine');
		$view = clone $scope->service('sane.camera');
		[$view->x, $view->y] = $this->origin();
		$map = $scope->content();
		$this->spawn = $this->playerSpawn($map);

		if(in_array($this->id, ['ceiling-curves', 'tornado'], true))
		{
			$padding = 256.0;
			$this->resetBounds = [
				$map->x - $padding
				// The airplane's reference damping already limits sustained climbs. Keep horizontal
				// and fall recovery without imposing an artificial maximum altitude.
				, $this->id === 'tornado' ? null : $map->y - $padding
				, $map->x + (float)$map->data['width'] * (float)$map->data['tilewidth'] + $padding
				, $map->y + (float)$map->data['height'] * (float)$map->data['tileheight'] + $padding
			];
		}

		$this->terrain = TerrainCompiler::build($map);
		$this->waters = WaterRegions::fromMaps($map);
		$this->lightDashes = new LightDashes($engine->entities);

		$this->rings = new Rings(
			$engine->entities
			, $map
			, terrain: $this->terrain
			, onCollect: [$this->lightDashes, 'collected']
		);

		$this->renderer = new MapRenderer($map);
		$this->sprites = new SpriteRenderer($this->renderer->atlas);
		$this->textActors = new TextActors($engine->entities, $map);
		$this->waterFeatures = new WaterFeatures($engine->entities, $this->terrain, $map, $this->waters);
		$this->platforms = new Platforms($engine->entities, $this->terrain, $map);
		$this->bridges = new BendableBridges($engine->entities, $this->terrain, $map);
		$this->gravityFeatures = new GravityFeatures($engine->entities, $this->terrain, $map);

		$this->system($this->systemPrefix . 'platforms', [$this->platforms, 'update'], after: ['movement']);
		$this->system($this->systemPrefix . 'bridges', [$this->bridges, 'update'], after: ['movement']);

		if($this->gravityFeatures->isEmpty())
		{
			$this->gravityFeatures->close();
			$this->gravityFeatures = null;
		}
		else
		{
			$this->system(
				$this->systemPrefix . 'gravity-features'
				, [$this->gravityFeatures, 'update']
				, after: ['movement']
			);
		}

		if($this->id !== 'isometric')
		{
			$this->backdropTwist = new BackdropTwistRenderer($engine->device->width, $engine->device->height);
		}

		if($this->waterFeatures->isEmpty())
		{
			$this->waterFeatures->close();
			$this->waterFeatures = null;
		}
		else
		{
			$this->system(
				$this->systemPrefix . 'water-features'
				, [$this->waterFeatures, 'update']
				, after: ['movement']
			);
		}

		$this->grapples = new Grapples(
			$engine->entities
			, $this->terrain
			, $map
			, /**
			 * Resolve a rope suspension reference to an existing moving platform entity.
			 *
			 * @param int $sourceId Original Tiled object ID shared by platform and rope loaders.
			 * @return int|null Live platform entity, or null for a fixed map anchor.
			 */
			function(int $sourceId): ?int {
				foreach($this->platforms->platforms as $platform)
				{
					if($platform->sourceId === $sourceId)
					{
						return $platform->entity;
					}
				}

				return $this->waterFeatures?->platforms[$sourceId]->entity ?? null;
			}
		);

		$after = [$this->systemPrefix . 'platforms'];

		if($this->waterFeatures !== null)
		{
			$after[] = $this->systemPrefix . 'water-features';
		}

		$this->system($this->systemPrefix . 'grapples', [$this->grapples, 'update'], after: $after);

		if($this->waters->regions)
		{
			$this->waterRenderer = new WaterRenderer($engine->device->width, $engine->device->height);

			$this->waterParticles = new WaterParticles(
				$engine->entities
				, $map
				, $this->waters
				, milliseconds: $engine->time * 1000
			);

			$this->system(
				$this->systemPrefix . 'water-particles'
				, [$this->waterParticles, 'update']
				, after: [
					$this->waterFeatures === null
						? 'movement'
						: $this->systemPrefix . 'water-features'
				]
			);
		}

		$this->renderer->render($view, $engine->time * 1000);

		$this->system(
			$this->systemPrefix . 'rings'
			, [$this->rings, 'update']
			, after: [$this->systemPrefix . 'vehicle-seats']
		);

		$features = [];

		foreach($map->layers as $layer)
		foreach($layer->objects as $object)
		$features[$object['class'] ?? $object['type'] ?? ''] = true;

		if(isset($features['CrabMeat']))
		{
			$this->badniks = new Badniks($engine->entities, $this->terrain, $this->rings);
			$this->crabs = new CrabMeats($engine->entities, $this->terrain, $map, $this->badniks);
			$this->system($this->systemPrefix . 'crabs', [$this->crabs, 'update'], after: ['movement']);

			$this->system(
				$this->systemPrefix . 'badniks'
				, [$this->badniks, 'update']
				, after: [$this->systemPrefix . 'crabs']
			);
		}

		$combatAfter = $this->badniks ? $this->systemPrefix . 'badniks' : 'movement';

		if(isset($features['MiniBoss']) || isset($features['mini-boss']))
		{
			$this->miniBosses = new MiniBosses(
				$engine->entities
				, $this->terrain
				, $map
				, $this->rings
				, advanceCombat: $this->badniks === null
			);

			$this->system($this->systemPrefix . 'mini-bosses', [$this->miniBosses, 'update'], after: [$combatAfter]);
			$combatAfter = $this->systemPrefix . 'mini-bosses';
		}

		foreach(array_keys($features) as $feature)
		{
			if(!str_ends_with($feature, 'Monitor')) continue;

			$this->shields = new Shields($engine->entities, $map);
			$this->monitors = new Monitors($engine->entities, $this->terrain, $map, shields: $this->shields);
			$this->system($this->systemPrefix . 'monitors', [$this->monitors, 'update'], after: ['movement']);
			$this->system($this->systemPrefix . 'shields', [$this->shields, 'update'], after: [$combatAfter]);
			break;
		}

		if(isset($features['breakable']))
		{
			$this->blocks = configureBreakables($engine, $this->terrain, $map, $this->systemPrefix, $scope);
		}

		$bossRegions = BossRegions::fromMaps(
			/**
			 * Resolve a Tiled boss reference through the demo's spawned encounter entities.
			 *
			 * @param int $sourceId Referenced Tiled object ID.
			 * @return bool True while a referenced multi-hit boss or legacy badnik remains live.
			 */
			function(int $sourceId) use ($engine): bool {
				if($this->miniBosses?->aliveSource($sourceId))
				{
					return true;
				}

				$entity = $this->crabs?->entityForSourceId($sourceId);

				return $entity !== null && $engine->entities->has($entity, Badnik::class);
			}
			, $map
		);

		if($bossRegions->regions)
		{
			$this->bossRegions = $bossRegions;
		}

		/**
		 * Remove breakable blocks or monitors contacted in an eligible movement pose.
		 *
		 * @param Body $body Actor at the beginning of the collision sweep.
		 * @param Hit $hit First impact in the continuous terrain cast.
		 * @return bool True when the contact removed an owned collision shape.
		 */
		$impact = function(Body $body, Hit $hit) use ($engine): bool {
			return ($this->blocks?->impact($body, $hit, $engine->time * 1000) ?? false)
				|| ($this->monitors?->impact($body, $hit, $engine->time * 1000) ?? false);
		};

		$this->movement = new Platformer($this->terrain, $impact, $this->waters);
		$this->vehicles = new Vehicles($engine->entities, $this->terrain, $this->movement, $map);

		$this->system(
			$this->systemPrefix . 'vehicle-seats'
			, [$this->vehicles, 'carry']
			, after: [$this->systemPrefix . 'grapples']
		);

	}

	/**
	 * Resolve a map-authored player point with inherited world and layer placement.
	 *
	 * @param \Phonics\Tiled\TileMap $map Loaded destination map containing object layers.
	 * @return array{float,float} Authored spawn point's world position, or the demo's legacy spawn.
	 */
	private function playerSpawn(\Phonics\Tiled\TileMap $map): array
	{
		foreach($this->mapScope?->entities() ?? [] as $id)
		{
			$registry = $this->mapScope->registry;

			if(!$registry->has($id, \SanePhonics\Gameplay\SpawnPoint::class)) continue;

			$point = $registry->get($id, \SanePhonics\Gameplay\SpawnPoint::class);
			$target = $registry->get($id, \Phonics\Tiled\Target::class);

			return [
				$point->x + $map->x + ($target->layer?->offsetX ?? 0)
				, $point->y + $map->y + ($target->layer?->offsetY ?? 0
				)
			];
		}

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				$class = strtolower((string)(($object['class'] ?? '') ?: ($object['type'] ?? '')));

				if($class === 'player')
				{
					return [
						(float)($object['x'] ?? 80) + $map->x + $layer->offsetX
						, (float)($object['y'] ?? 100) + $map->y + $layer->offsetY
					];
				}
			}
		}

		return [80.0, 100.0];
	}

	/**
	 * Decide whether a player has escaped the playable reset region.
	 *
	 * @param Body $body Player position to compare with this level's authored world bounds.
	 * @return bool True when gameplay should place the player back at the level spawn.
	 */
	public function escaped(Body $body): bool
	{
		if($this->resetBounds === null)
		{
			return $body->y > 1200;
		}

		[$left, $top, $right, $bottom] = $this->resetBounds;

		return $body->x < $left || $body->x > $right
			|| ($top !== null && $body->y < $top) || $body->y > $bottom;
	}

	/**
	 * Select the initial camera position for the destination demo.
	 *
	 * @return array{float,float} Top-left camera coordinates in world pixels.
	 */
	public function origin(): array
	{
		return match($this->id)
		{
			'world' => [32.0, 0.0],
			'isometric' => [-160.0, -80.0],
			'ceiling-curves' => [$this->spawn[0] - 500, $this->spawn[1] - 280],
			'tornado' => [$this->spawn[0] - 240, $this->spawn[1] - 180],
			default => [0.0, 0.0]
		};
	}

	/**
	 * Count the dependencies discovered so far for the staged level.
	 *
	 * @return array{ready:int,total:int,failed:int} Counts owned by this level's asset scope.
	 */
	public function progress(): array
	{
		$counts = $this->assets->progress();

		return ['ready' => $counts->ready, 'total' => $counts->total, 'failed' => $counts->failed];
	}

	/**
	 * Rebuild active graphics while preserving downloaded files and collision state.
	 *
	 * @return void Restores map and sprite GPU objects in their original ownership order.
	 */
	public function restore(): void
	{
		$this->renderer?->restore();
		$this->backdropTwist?->restore();
		$this->motionBlurRenderer?->restore();
		$this->motionBlur?->reset();
		$this->sprites?->restore();
		$this->waterRenderer?->restore();
	}

	/**
	 * Keep imported module entities scoped to the maps currently owned by this level.
	 * @param Engine $engine Session sharing the package context's entity registry.
	 * @return void Creates new placements and retires entities from evicted maps.
	 */
	public function syncFactories(Engine $engine): void
	{
		if(!$this->runtime) return;

		$maps = $this->stream->loaded();

		foreach($this->prepared as $key => $_)
		{
			if(isset($maps[$key])) continue;

			$this->runtime->unloadScope($this->sourceId, $key);
			unset($this->leases[$key], $this->prepared[$key]);
		}

		foreach($maps as $key => $map)
		{
			if(isset($this->prepared[$key])) continue;

			if(!isset(Loader::properties($map->data['properties'] ?? [])['phonics.systems']))
			$map->data['properties'][] = [
				'name' => 'phonics.systems'
				, 'type' => 'list'
				, 'value' => [
					[
						'type' => 'string'
						, 'value' => \Phonics\Asset\Resource::source(__DIR__ . '/../phonics.json') . '#demo-map'
					]
				]
			];

			$this->runtime->prepare($this->sourceId, $key);
			$this->prepared[$key] = true;

			if($this->active) $this->leases[$key] = $this->runtime->activate($this->sourceId, $key);
		}
	}

	/**
	 * Retire only this level's systems, entities, GPU resources, and file references.
	 *
	 * @param Engine $engine Persistent session from which owned systems and entities are removed.
	 * @return void Cancels pending transfers when no other scene needs them; safe to repeat.
	 */
	public function close(Engine $engine): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		$this->runtime?->close();

		if($this->id === 'world') $this->renderer?->close();

		$this->leases = $this->prepared = [];
		$this->motionBlurRenderer?->close();
		$this->stream?->close();
		$this->assets->close();
	}

	/** Enable prepared scopes only once the scene switch commits.
	 * @return void Acquires the world and prepared map leases.
	 */
	public function activate(): void
	{
		$this->leases['world'] = $this->runtime->activate($this->sourceId, 'world');

		foreach(array_keys($this->prepared) as $key)
		$this->leases[$key] = $this->runtime->activate($this->sourceId, $key);
		$this->active = true;
	}

	/** Register a game system under map ownership, preserving its explicit dependencies.
	 * @param string $name Qualified system name.
	 * @param callable $system Scheduled callback.
	 * @param array $after Names of prerequisite systems.
	 * @return void Registers this system with its map scope.
	 */
	private function system(string $name, callable $system, array $after = []): void
	{
		$this->mapScope->system(
			substr($name, strlen($this->systemPrefix))
			, $system
			, array_map(
				/** Resolve the shared movement system dependency.
			 * @param string $dependency Authored dependency name.
			 * @return string Scoped or shared scheduler name.
			 */ fn($dependency) => $dependency === 'movement' ? '@movement' : $dependency
				, $after
			)
		);
	}

	/** Release map managers and graphics, including a failed preparation.
	 * @return void Closes each owned gameplay and rendering service.
	 */
	private function releaseMapFeatures(): void
	{
		$this->vehicles?->close();
		$this->grapples?->close();
		$this->blocks?->close();
		$this->rings?->close();
		$this->badniks?->close();
		$this->miniBosses?->close();
		$this->monitors?->close();
		$this->shields?->close();
		$this->waterFeatures?->close();
		$this->platforms?->close();
		$this->bridges?->close();
		$this->gravityFeatures?->close();
		$this->waterParticles?->close();
		$this->textActors?->close();
		$this->waterRenderer?->close();
		$this->backdropTwist?->close();
		$this->sprites?->close();
		$this->renderer?->close();
	}
}

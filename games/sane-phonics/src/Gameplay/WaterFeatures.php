<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use SanePhonics\Physics\WaterLevel;
use SanePhonics\Physics\WaterRegion;
use SanePhonics\Physics\WaterRegions;
use Phonics\Render\Sprite;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Tiled water switches and density-driven solid platforms with moving collision shapes. */
final class WaterFeatures
{
	/** @var array<int,WaterLevel> Level controllers keyed by Tiled water object ID. */
	public array $levels = [];
	/** @var array<int,WaterSwitch> Switches keyed by Tiled object ID. */
	public array $switches = [];
	/** @var array<int,BuoyantPlatform> Floating solids keyed by Tiled object ID. */
	public array $platforms = [];
	public ?int $activeSwitch = null;

	/** @var list<int> Registry entities owned by this manager. */
	private array $owned = [];
	private bool $closed = false;

	/**
	 * Promote reference-compatible water controls and density blocks from a Tiled map.
	 *
	 * Switch or WaterSwitch objects reference `water` and `setPoint` object IDs. Any tile object
	 * with a positive `density` becomes a buoyant solid; `hLock` retains the reference map's
	 * horizontal-lock annotation. All object positions include map and inherited layer offsets.
	 *
	 * @param Registry $registry Registry receiving platform, switch, and sprite components.
	 * @param Terrain $terrain Collision index receiving replaceable platform rectangles.
	 * @param TileMap $map Map containing water, point, switch, and density object definitions.
	 * @param WaterRegions $waters Regions already loaded from the same map.
	 * @return void Builds script-addressable components keyed by their Tiled object IDs.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, private TileMap $map
		, private WaterRegions $waters
	){
		$points = [];

		foreach($waters->regions as $water)
		{
			if($water->sourceId !== null)
			{
				$this->levels[$water->sourceId] = new WaterLevel($water);
			}
		}

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				if(isset($object['id']))
				{
					$points[(int)$object['id']] = [
						(float)($object['x'] ?? 0) + $map->x + $layer->offsetX
						, (float)($object['y'] ?? 0) + $map->y + $layer->offsetY
					];
				}
			}
		}

		try
		{
			$this->spawn($points);
		}
		catch(\Throwable $error)
		{
			$this->close();

			throw $error;
		}
	}

	/**
	 * Report whether the map supplied no switches or buoyant solids.
	 *
	 * @return bool True when the manager has no simulation or render work.
	 */
	public function isEmpty(): bool
	{
		return !$this->switches && !$this->platforms;
	}

	/**
	 * Activate a switch from gameplay code or a body overlap.
	 *
	 * @param int $sourceId Tiled switch object ID present in the public switches map.
	 * @return void Selects its target and movement rates while clearing the previous active flag.
	 */
	public function activate(int $sourceId): void
	{
		if($this->closed)
		{
			throw new \LogicException('Water feature manager is closed');
		}

		$switch = $this->switches[$sourceId] ?? throw new \OutOfBoundsException('Unknown water switch');
		$level = $this->levels[$switch->waterId] ?? throw new \OutOfBoundsException('Switch water is missing');

		foreach($this->switches as $candidate)
		{
			$candidate->active = false;
			$this->registry->get($candidate->entity, Sprite::class)->tint = [1,1,1,1];
		}

		$switch->active = true;
		$this->activeSwitch = $sourceId;
		$this->registry->get($switch->entity, Sprite::class)->tint = [1,.72,.24,1];
		$level->setTarget($switch->targetY, $switch->fillSpeed, $switch->drainSpeed);
	}

	/**
	 * Advance switches, water surfaces, buoyancy, rider motion, sprites, and colliders.
	 *
	 * @param Registry $registry Registry containing player bodies and owned visual components.
	 * @param float $dt Finite positive fixed simulation delta in seconds.
	 * @return void Mutates the referenced regions and floating platform entities in place.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Water feature delta must be positive');
		}

		foreach($registry->query(Body::class) as $bodyId)
		{
			$body = $registry->get($bodyId, Body::class);

			foreach($registry->query(WaterSwitch::class) as $switchId)
			{
				$switch = $registry->get($switchId, WaterSwitch::class);

				if(($this->switches[$switch->sourceId] ?? null) !== $switch)
				{
					continue;
				}

				if(!$switch->active && $switch->pressedBy($body))
				{
					$this->activate($switch->sourceId);

					break;
				}
			}
		}

		foreach($this->levels as $level)
		{
			$level->update($dt);
		}

		foreach($registry->query(BuoyantPlatform::class) as $platformId)
		{
			$platform = $registry->get($platformId, BuoyantPlatform::class);

			if(($this->platforms[$platform->sourceId] ?? null) !== $platform)
			{
				continue;
			}

			$this->floatPlatform($platform, $registry, $dt);
		}
	}

	/**
	 * Return a serialization-safe diagnostic view for browser tests and scripted demos.
	 *
	 * @return array<string,mixed> Current controls and moving objects.
	 */
	public function snapshot(): array
	{
		$levels = [];
		$switches = [];
		$platforms = [];

		foreach($this->levels as $id => $level)
		{
			$levels[$id] = ['surface' => $level->region->y, 'target' => $level->target];
		}

		foreach($this->platforms as $id => $platform)
		{
			$platforms[$id] = ['y' => $platform->y, 'velocityY' => $platform->velocityY];
		}

		foreach($this->switches as $id => $switch)
		{
			$switches[$id] = ['active' => $switch->active, 'top' => $switch->top()];
		}

		return [
			'activeSwitch' => $this->activeSwitch
			, 'levels' => $levels
			, 'switches' => $switches
			, 'platforms' => $platforms
		];
	}

	/**
	 * Remove every owned collider and entity.
	 *
	 * @return void Leaves map terrain, regions, and unrelated registry entries untouched.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach($this->switches as $switch)
		{
			if($switch->shape !== null)
			{
				$this->terrain->removeShape($switch->shape);
				$switch->shape = null;
			}
		}

		foreach($this->platforms as $platform)
		{
			if($platform->shape !== null)
			{
				$this->terrain->removeShape($platform->shape);
				$platform->shape = null;
			}
		}

		foreach($this->owned as $id)
		{
			if($this->registry->alive($id))
			{
				$this->registry->destroy($id);
			}
		}

		$this->owned = [];
	}

	/**
	 * Parse and create switches and buoyant objects after point references are indexed.
	 *
	 * @param array<int,array{0:float,1:float}> $points World positions keyed by Tiled object ID.
	 * @return void Adds valid feature objects in layer and object order.
	 */
	private function spawn(array $points): void
	{
		foreach($this->map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				$properties = Loader::properties($object['properties'] ?? []);
				$class = strtolower((string)(($object['class'] ?? '') ?: ($object['type'] ?? '')));

				if(isset($properties['water'], $properties['setPoint'])
					&& in_array($class, ['switch', 'waterswitch', 'water-switch'], true)
				){
					$this->spawnSwitch($layer->offsetX, $layer->offsetY, $object, $properties, $points);
				}
				elseif(isset($properties['density'], $object['gid']))
				{
					$this->spawnPlatform($layer->offsetX, $layer->offsetY, $object, $properties);
				}
			}
		}
	}

	/**
	 * Promote one switch and its optional tile sprite.
	 *
	 * @param float $layerX Inherited object-layer X offset.
	 * @param float $layerY Inherited object-layer Y offset.
	 * @param array<string,mixed> $object Raw Tiled switch object.
	 * @param array<string,mixed> $properties Normalized object properties.
	 * @param array<int,array{0:float,1:float}> $points Referenced set-point positions.
	 * @return void Creates one public switch and owned registry entity.
	 */
	private function spawnSwitch(
		float $layerX
		, float $layerY
		, array $object
		, array $properties
		, array $points
	): void {
		$id = (int)($object['id'] ?? 0);
		$waterId = (int)$properties['water'];
		$pointId = (int)$properties['setPoint'];

		if(!isset($this->levels[$waterId], $points[$pointId]))
		{
			throw new \UnexpectedValueException('Water switch references a missing object');
		}

		$x = (float)($object['x'] ?? 0) + $this->map->x + $layerX;
		$y = (float)($object['y'] ?? 0) + $this->map->y + $layerY;
		$width = (float)($object['width'] ?? 32);
		$height = (float)($object['height'] ?? 8);

		$switch = new WaterSwitch(
			$id
			, $waterId
			, $points[$pointId][1]
			, $x
			, $y
			, $width
			, $height
			, (float)($properties['fillSpeed'] ?? 240)
			, (float)($properties['drainSpeed'] ?? 480)
			, (int)($properties['collisionLayer'] ?? 0)
		);

		$sprite = $this->sprite($object, $x + $width / 2, $y, $width, $height, 4);
		$switch->shape = $this->terrain->addShape($switch->points(), true, $switch->collisionLayer);
		$this->switches[$id] = $switch;
		$switch->entity = $this->registry->create($switch, $sprite);
		$this->owned[] = $switch->entity;
	}

	/**
	 * Promote one density-annotated tile object into a moving solid.
	 *
	 * @param float $layerX Inherited object-layer X offset.
	 * @param float $layerY Inherited object-layer Y offset.
	 * @param array<string,mixed> $object Raw Tiled buoyant tile object.
	 * @param array<string,mixed> $properties Normalized object properties.
	 * @return void Creates one public platform, sprite, registry entity, and terrain shape.
	 */
	private function spawnPlatform(
		float $layerX
		, float $layerY
		, array $object
		, array $properties
	): void {
		$id = (int)($object['id'] ?? 0);
		$waterId = (int)($properties['water'] ?? array_key_first($this->levels));

		if(!isset($this->levels[$waterId]))
		{
			throw new \UnexpectedValueException('Buoyant platform references missing water');
		}

		$left = (float)($object['x'] ?? 0) + $this->map->x + $layerX;
		$y = (float)($object['y'] ?? 0) + $this->map->y + $layerY;
		$width = (float)($object['width'] ?? 32);
		$height = (float)($object['height'] ?? 32);

		$platform = new BuoyantPlatform(
			$id
			, $waterId
			, $left + $width / 2
			, $y
			, $width
			, $height
			, (float)$properties['density']
			, (bool)($properties['hLock'] ?? true)
			, (bool)($properties['oneWay'] ?? false)
			, (int)($properties['collisionLayer'] ?? 0)
			, (float)($properties['spring'] ?? 36)
			, (float)($properties['damping'] ?? 12)
			, (float)($properties['maxSpeed'] ?? 720)
		);

		$sprite = $this->sprite($object, $platform->x, $platform->y, $width, $height, 1);
		$sprite->outline = false;
		$platform->shape = $this->terrain->addShape($platform->points(), $platform->oneWay, $platform->collisionLayer);

		$platform->entity = $this->registry->create($platform, $sprite);
		$this->owned[] = $platform->entity;
		$this->platforms[$id] = $platform;
	}

	/**
	 * Create a scaled sprite for one Tiled tile object.
	 *
	 * @param array<string,mixed> $object Raw object with an optional encoded global tile ID.
	 * @param float $x World X anchor, centered for platforms and left-aligned for switches.
	 * @param float $y World bottom Y anchor.
	 * @param float $width Desired rendered width in world pixels.
	 * @param float $height Desired rendered height in world pixels.
	 * @param int $fallbackGid Tile ID used when the object has no explicit tile.
	 * @return Sprite Atlas sprite with Tiled flips and dimensions applied.
	 */
	private function sprite(
		array $object
		, float $x
		, float $y
		, float $width
		, float $height
		, int $fallbackGid
	): Sprite {
		$gid = Gid::from($object['gid'] ?? $fallbackGid);
		$tile = $this->map->tile($gid->id) ?? throw new \OutOfBoundsException('Feature sprite tile is missing');
		$sprite = new Sprite($gid->id, $x, $y, .5, 1);
		$sprite->scaleX = $width / $tile['rect'][2];
		$sprite->scaleY = $height / $tile['rect'][3];
		$sprite->flipX = (bool)($gid->flags & 8);
		$sprite->flipY = (bool)($gid->flags & 4);

		if($gid->flags & 2)
		{
			$sprite->originX = 1;
			$sprite->scaleX = $height / $tile['rect'][2];
			$sprite->scaleY = $width / $tile['rect'][3];
			$sprite->rotation += M_PI / 2;
			[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
		}

		return $sprite;
	}

	/**
	 * Apply spring buoyancy, carry riders, and replace one moving collider.
	 *
	 * @param BuoyantPlatform $platform Platform to advance in place.
	 * @param Registry $registry Registry containing potential rider bodies and the platform sprite.
	 * @param float $dt Fixed simulation delta in seconds.
	 * @return void Synchronizes physics, collision, and rendering to the new bottom position.
	 */
	private function floatPlatform(BuoyantPlatform $platform, Registry $registry, float $dt): void
	{
		$water = $this->levels[$platform->waterId]->region;
		$target = $platform->equilibrium($water);
		$oldTop = $platform->top();
		$acceleration = ($target - $platform->y) * $platform->spring
			- $platform->velocityY * $platform->damping;

		$platform->velocityY += $acceleration * $dt;
		$platform->velocityY = max(-$platform->maxSpeed, min($platform->maxSpeed, $platform->velocityY));
		$dy = $platform->velocityY * $dt;

		if(abs($target - $platform->y) < .01 && abs($platform->velocityY) < .01)
		{
			$dy = $target - $platform->y;
			$platform->velocityY = 0;
		}

		if(abs($dy) < 1e-9)
		{
			return;
		}

		foreach($registry->query(Body::class) as $bodyId)
		{
			$body = $registry->get($bodyId, Body::class);

			if($body->grounded
				&& abs($body->y + $body->halfHeight - $oldTop) <= 2
				&& $body->x + $body->radius >= $platform->x - $platform->width / 2
				&& $body->x - $body->radius <= $platform->x + $platform->width / 2
			){
				$body->y += $dy;
			}
		}

		$platform->y += $dy;

		if($platform->shape !== null)
		{
			$this->terrain->removeShape($platform->shape);
		}

		$platform->shape = $this->terrain->addShape($platform->points(), $platform->oneWay, $platform->collisionLayer);

		$sprite = $registry->get($platform->entity, Sprite::class);
		$sprite->x = $platform->x;
		$sprite->y = $platform->y;
	}
}

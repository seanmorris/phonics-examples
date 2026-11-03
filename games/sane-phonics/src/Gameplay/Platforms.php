<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** ECS ownership, Tiled loading, settling, and motion of suspended platforms. */
final class Platforms
{
	/** @var array<int,MovingPlatform> Platforms keyed by entity ID. */
	public array $platforms = [];
	private PlatformTransport $transport;
	private bool $closed = false;

	/**
	 * Create a platform system and optionally promote objects from a Tiled map.
	 *
	 * @param Registry $registry Registry receiving platform, motion, and sprite components.
	 * @param Terrain $terrain Collision index shared with the movement system.
	 * @param TileMap|null $map Optional map with suspended platforms and linked path nodes.
	 * @return void Creates owned entities and colliders, rolling back on invalid map references.
	 */
	public function __construct(private Registry $registry, private Terrain $terrain, ?TileMap $map = null)
	{
		$this->transport = new PlatformTransport($terrain);

		try
		{
			if($map !== null)
			{
				$this->load($map);
			}
		}
		catch(\Throwable $error)
		{
			$this->close();

			throw $error;
		}
	}

	/**
	 * Identify supported platform objects before static terrain is built.
	 *
	 * @param array<string,mixed> $object Raw Tiled object definition.
	 * @return bool Whether this system owns its collider; density blocks belong to WaterFeatures.
	 */
	public static function matches(array $object): bool
	{
		$class = strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''));
		$properties = Loader::properties($object['properties'] ?? []);

		return !isset($properties['density']) && !isset($properties['droop']) && (
			in_array($class, ['floatingplatform', 'movingplatform', 'pathplatform'], true)
			|| ($class === 'block' && !empty($properties['float']))
		);
	}

	/**
	 * Register a scripted platform, its optional motion, and its optional sprite.
	 *
	 * @param MovingPlatform $platform Unregistered platform whose original anchor is retained.
	 * @param Sprite|null $sprite Optional sprite with a centered bottom anchor and desired scale.
	 * @param PlatformOscillation|PlatformPath|null $motion Optional ECS motion component.
	 * @return int New entity ID, also stored on the platform component.
	 */
	public function add(
		MovingPlatform $platform
		, ?Sprite $sprite = null
		, PlatformOscillation|PlatformPath|null $motion = null
	): int {
		if($this->closed || $platform->shape !== null || $platform->entity !== 0)
		{
			throw new \LogicException('Cannot register this platform');
		}

		if($motion instanceof PlatformPath)
		{
			$platform->x = $motion->x;
			$platform->y = $motion->y;
		}
		elseif($motion instanceof PlatformOscillation)
		{
			[$dx, $dy] = $motion->offset();
			$platform->x = $platform->originX + $dx;
			$platform->y = $platform->originY + $dy;
		}

		$components = [$platform];

		if($motion !== null)
		{
			$components[] = $motion;
		}

		if($sprite !== null)
		{
			$sprite->x = $platform->x;
			$sprite->y = $platform->y;
			$components[] = $sprite;
		}

		$platform->shape = $this->terrain->addShape($platform->points(), $platform->oneWay, $platform->collisionLayer);
		$id = $platform->entity = $this->registry->create(...$components);
		$this->platforms[$id] = $platform;

		return $id;
	}

	/**
	 * Advance platforms after body movement so jumps and drop-throughs release their riders.
	 *
	 * @param Registry $registry Owning registry passed by the scheduler.
	 * @param float $dt Finite positive simulation delta in seconds.
	 * @return void Updates motion components, colliders, passenger positions, and sprites.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Invalid platform registry or delta');
		}

		foreach($this->platforms as $id => $platform)
		{
			if(!$registry->has($id, MovingPlatform::class)
				|| $registry->get($id, MovingPlatform::class) !== $platform
			){
				$this->remove($id);
				continue;
			}

			$oldX = $platform->x;
			$oldY = $platform->y;
			$platform->weighted = (bool)$this->transport->riders($platform, $registry);
			$this->settle($platform, $dt);

			if($registry->has($id, PlatformPath::class))
			{

				/**
				 * Transport a route segment with the platform's current settling offset.
				 *
				 * @param float $x Requested route X coordinate.
				 * @param float $y Requested route Y coordinate.
				 * @return float Accepted movement fraction after passenger collision checks.
				 */
				$move = function(float $x, float $y) use ($platform, $registry): float {
					return $this->transport->move($platform, $registry, $x, $y + $platform->settlement);
				};

				$registry->get($id, PlatformPath::class)->advance($dt, $platform->weighted, $move);
			}
			elseif($registry->has($id, PlatformOscillation::class))
			{
				$motion = $registry->get($id, PlatformOscillation::class);
				$motion->time += $dt;
				[$dx, $dy] = $motion->offset();

				$this->transport->move(
					$platform
					, $registry
					, $platform->originX + $dx
					, $platform->originY + $dy + $platform->settlement
				);
			}

			$platform->velocityX = ($platform->x - $oldX) / $dt;
			$platform->velocityY = ($platform->y - $oldY) / $dt;

			if($registry->has($id, Sprite::class))
			{
				$sprite = $registry->get($id, Sprite::class);
				$sprite->x = $platform->x;
				$sprite->y = $platform->y;
			}
		}
	}

	/**
	 * Sink under a rider and recover toward the unweighted route position.
	 *
	 * @param MovingPlatform $platform Platform with a signed settlement range.
	 * @param float $dt Positive simulation delta in seconds.
	 * @return void Applies only accepted settlement motion so blocked platforms do not accumulate drift.
	 */
	private function settle(MovingPlatform $platform, float $dt): void
	{
		$target = $platform->weighted ? $platform->settle : 0;
		$rate = min(abs($platform->settle), $platform->weighted ? 64 : 32) * 7.5;
		$delta = max(-$rate * $dt, min($rate * $dt, $target - $platform->settlement));

		$fraction = $this->transport->move($platform, $this->registry, $platform->x, $platform->y + $delta);
		$platform->settlement += $delta * $fraction;
	}

	/**
	 * Remove a platform and its collision geometry.
	 *
	 * @param int $entity Owned platform entity ID; unknown IDs are ignored.
	 * @return void Destroys a still-live owned entity and releases its shape exactly once.
	 */
	public function remove(int $entity): void
	{
		$platform = $this->platforms[$entity] ?? null;

		if($platform === null)
		{
			return;
		}

		if($platform->shape !== null)
		{
			$this->terrain->removeShape($platform->shape);
			$platform->shape = null;
		}

		if($this->registry->alive($entity))
		{
			$this->registry->destroy($entity);
		}

		unset($this->platforms[$entity]);
	}

	/**
	 * Retire the system and all owned platforms when a level unloads.
	 *
	 * @return void Releases entities and colliders; repeated calls are harmless.
	 */
	public function close(): void
	{
		foreach(array_keys($this->platforms) as $id)
		{
			$this->remove($id);
		}

		$this->closed = true;
	}

	/**
	 * Publish serializable platform state for scripts and browser diagnostics.
	 *
	 * @return list<array<string,mixed>> Current position, motion kind, velocity, and rider state.
	 */
	public function snapshot(): array
	{
		$result = [];

		foreach($this->platforms as $id => $platform)
		{
			$result[] = [
				'entity' => $id
				, 'sourceId' => $platform->sourceId
				, 'x' => $platform->x
				, 'y' => $platform->y
				, 'top' => $platform->top()
				, 'weighted' => $platform->weighted
				, 'settlement' => $platform->settlement
				, 'velocityX' => $platform->velocityX
				, 'velocityY' => $platform->velocityY
				, 'motion' => $this->registry->has($id, PlatformPath::class)
					? 'path'
					: ($this->registry->has($id, PlatformOscillation::class) ? 'oscillation' : 'floating')
			];
		}

		return $result;
	}

	/**
	 * Promote Tiled objects, resolving path references across flattened object layers.
	 *
	 * @param TileMap $map Loaded map providing sprites, world offsets, and object definitions.
	 * @return void Registers supported platforms with map and inherited layer offsets applied once.
	 */
	private function load(TileMap $map): void
	{
		$objects = [];

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
			if(!self::matches($object))
			{
				continue;
			}

			if(($object['rotation'] ?? 0) != 0)
			{
				throw new \UnexpectedValueException('Moving platform rectangles must be axis aligned');
			}

			$properties = Loader::properties($object['properties'] ?? []);
			$class = strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''));
			$width = (float)($object['width'] ?? 96);
			$height = (float)($object['height'] ?? 16);

			$platform = new MovingPlatform(
				$object['x'] + $width / 2
				, $object['y'] + (isset($object['gid']) ? 0 : $height)
				, $width
				, $height
				, (bool)($properties['oneWay'] ?? $properties['platform']
					?? $class !== 'block'
				)
				, (float)($properties['settle'] ?? 0)
				, (int)($properties['collisionLayer'] ?? $layer->properties['collisionLayer'] ?? 0)
				, (int)$object['id']
			);

			$motion = null;

			if(isset($properties['path']) || isset($properties['ridePath']))
			{
				$motion = $this->path($objects, $properties);
			}
			elseif(!empty($properties['oscillateX']) || !empty($properties['oscillateY']))
			{
				$motion = new PlatformOscillation(
					(float)($properties['oscillateX'] ?? 0)
					, (float)($properties['oscillateY'] ?? 0)
					, (float)($properties['squinePeriod'] ?? M_PI * 1.5)
					, (float)($properties['timeX'] ?? 90) / 60
					, (float)($properties['timeY'] ?? 90) / 60
					, (float)($properties['offset'] ?? 0) / 60
				);
			}

			$sprite = null;

			if(isset($object['gid']))
			{
				$gid = Gid::from($object['gid']);
				$tile = $map->tile($gid->id) ?? throw new \UnexpectedValueException('Platform tile is missing');
				$sprite = new Sprite($gid->id);
				$sprite->scaleX = $width / $tile['rect'][2];
				$sprite->scaleY = $height / $tile['rect'][3];
				$sprite->flipX = (bool)($gid->flags & 8);
				$sprite->flipY = (bool)($gid->flags & 4);
				$sprite->outline = (bool)($properties['outline'] ?? $class !== 'block');
				$sprite->tint = $layer->tint;
				$sprite->opacity = $layer->visible && ($object['visible'] ?? true) ? $layer->opacity : 0;

				if($gid->flags & 2)
				{
					$sprite->originX = 1;
					$sprite->originY = .5;
					$sprite->scaleX = $height / $tile['rect'][2];
					$sprite->scaleY = $width / $tile['rect'][3];
					$sprite->rotation = M_PI / 2;
					[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
				}
			}

			$this->add($platform, $sprite, $motion);
		}
	}

	/**
	 * Resolve a chain of object references into a route, retaining its terminal or cyclic link.
	 *
	 * @param array<int,array> $objects World-translated Tiled objects paired with their layers.
	 * @param array<string,mixed> $properties Platform properties containing path or ridePath.
	 * @return PlatformPath Route with speeds converted from reference pixels/frame to pixels/second.
	 */
	private function path(array $objects, array $properties): PlatformPath
	{
		$id = (int)($properties['path'] ?? $properties['ridePath']);
		$points = [];
		$indices = [];

		while($id !== 0 && !isset($indices[$id]))
		{
			$node = $objects[$id][0] ?? throw new \UnexpectedValueException('Platform path references a missing node');
			$indices[$id] = count($points);
			$points[] = [$node['x'], $node['y']];
			$next = Loader::properties($node['properties'] ?? []);
			$id = (int)($next['next'] ?? 0);
		}

		return new PlatformPath(
			$points
			, (float)($properties['pathSpeed'] ?? 8) * 60
			, $indices[$id] ?? null
			, !isset($properties['path']) && isset($properties['ridePath'])
		);
	}
}

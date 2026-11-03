<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteGroup;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** ECS loading, multi-body deformation, collision, and segmented rendering for flexible spans. */
final class BendableBridges
{
	/** @var array<int,BendableBridge> Bridges keyed by entity ID. */
	public array $bridges = [];
	/** @var array<int,list<float>> Initial horizontal sprite scales keyed by bridge entity. */
	private array $spriteScales = [];
	private bool $closed = false;

	/**
	 * Create a bridge system and optionally promote supported objects from a Tiled map.
	 *
	 * @param Registry $registry Registry receiving bridge and optional sprite-group components.
	 * @param Terrain $terrain Collision index shared with platformer movement.
	 * @param TileMap|null $map Optional map containing BendableBridge or BendableRail objects.
	 * @return void Creates owned entities and removable surface colliders.
	 */
	public function __construct(private Registry $registry, private Terrain $terrain, ?TileMap $map = null)
	{
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
	 * Identify native bridge classes and MB2-compatible drooping block objects.
	 *
	 * @param array<string,mixed> $object Raw Tiled object definition.
	 * @return bool Whether this system owns the object's collision and rendering.
	 */
	public static function matches(array $object): bool
	{
		$class = strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''));
		$properties = Loader::properties($object['properties'] ?? []);

		return in_array($class, ['bendablebridge', 'bendable-bridge', 'bendablerail', 'bendable-rail'], true)
			|| ($class === 'block' && isset($properties['droop']));
	}

	/**
	 * Register one bridge and its optional segmented artwork.
	 *
	 * @param BendableBridge $bridge Unregistered flexible surface.
	 * @param SpriteGroup|null $sprites Optional mutable segments matching the bridge samples.
	 * @return int New entity ID, also stored on the bridge component.
	 */
	public function add(BendableBridge $bridge, ?SpriteGroup $sprites = null): int
	{
		if($this->closed || $bridge->shape !== null || $bridge->entity !== 0)
		{
			throw new \LogicException('Cannot register this bridge');
		}

		$components = [$bridge];

		if($sprites !== null)
		{
			$components[] = $sprites;
		}

		$bridge->shape = $this->terrain->addPathShape(
			$bridge->points()
			, true
			, $bridge->collisionLayer
			, $bridge->grinding
		);

		$id = $bridge->entity = $this->registry->create(...$components);
		$this->bridges[$id] = $bridge;

		if($sprites !== null)
		{
			$this->spriteScales[$id] = array_map(
				/**
				 * Retain one segment's flat scale for slope-length correction.
				 *
				 * @param Sprite $sprite Segment sprite supplied to this bridge.
				 * @return float Initial horizontal scale on the flat span.
				 */
				fn($sprite) => $sprite->scaleX
				, $sprites->sprites
			);
		}

		$this->positionSprites($id, $bridge);

		return $id;
	}

	/**
	 * Advance every elastic surface after movement and carry all bodies supported by it.
	 *
	 * @param Registry $registry Owning registry passed by the scheduler.
	 * @param float $dt Finite positive simulation delta in seconds.
	 * @return void Replaces colliders, updates segment artwork, and publishes current riders.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Invalid bridge registry or delta');
		}

		foreach($this->bridges as $id => $bridge)
		{
			if(!$registry->has($id, BendableBridge::class)
				|| $registry->get($id, BendableBridge::class) !== $bridge
			){
				$this->remove($id);

				continue;
			}

			$riders = $this->riders($bridge);
			$old = $bridge->offsets;

			$targets = $bridge->targets(array_map(
				/**
				 * Resolve one rider's sole position for deformation.
				 *
				 * @param Body $body Grounded body supported by this bridge.
				 * @return float World X coordinate of its surface contact.
				 */
				fn($body) => $body->supportX()
				, $riders
			));

			$this->integrate($bridge, $targets, $dt);

			if($old !== $bridge->offsets)
			{
				$this->terrain->removeShape($bridge->shape);

				$bridge->shape = $this->terrain->addPathShape(
					$bridge->points()
					, true
					, $bridge->collisionLayer
					, $bridge->grinding
				);
			}

			foreach($riders as $body)
			{
				$contactX = $body->supportX();
				$angle = $bridge->angle($contactX);
				$body->x = $contactX + sin($angle) * $body->halfHeight;
				$body->y = $bridge->surfaceY($contactX) - cos($angle) * $body->halfHeight - .001;
				$body->angle = $angle;
				$body->grounded = true;
				$body->grinding = $bridge->grinding;
			}

			$bridge->riders = array_keys($riders);
			$this->positionSprites($id, $bridge);
		}
	}

	/**
	 * Find downward-gravity bodies whose sole ray names this bridge's live shape.
	 *
	 * @param BendableBridge $bridge Surface whose contact handle must be matched.
	 * @return array<int,Body> Supported bodies keyed by entity ID.
	 */
	private function riders(BendableBridge $bridge): array
	{
		$riders = [];

		foreach($this->registry->query(Body::class) as $id)
		{
			$body = $this->registry->get($id, Body::class);

			if(!$body->grounded || $body->dropTimer > 0 || $body->gravityX !== 0 || $body->gravityY !== 1)
			{
				continue;
			}

			$hit = $this->terrain->raycast(
				$body->supportX()
				, $body->supportY() - 4
				, 0
				, 8
				, false
				, $body->collisionLayer
			);

			if($hit !== null && $hit->shape === $bridge->shape)
			{
				$riders[$id] = $body;
			}
		}

		return $riders;
	}

	/**
	 * Integrate critically damped sample springs toward summed rider targets.
	 *
	 * @param BendableBridge $bridge Mutable offsets and velocities to advance.
	 * @param list<float> $targets Desired downward displacement at every sample.
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return void Preserves fixed endpoint position and slope while damping released spans.
	 */
	private function integrate(BendableBridge $bridge, array $targets, float $dt): void
	{
		$frequency = $bridge->response;
		$decay = exp(-$frequency * $dt);
		$limit = $bridge->droop * $bridge->maxLoads;

		for($i = 2; $i < $bridge->segments - 1; $i++)
		{
			$delta = $bridge->offsets[$i] - $targets[$i];
			$rate = $bridge->velocities[$i] + $frequency * $delta;
			$bridge->offsets[$i] = $targets[$i] + ($delta + $rate * $dt) * $decay;
			$bridge->velocities[$i] = ($bridge->velocities[$i] - $frequency * $rate * $dt) * $decay;
			$bridge->offsets[$i] = max(0, min($limit, $bridge->offsets[$i]));

			if($bridge->offsets[$i] === 0 || $bridge->offsets[$i] === $limit)
			{
				$bridge->velocities[$i] = 0;
			}
			elseif($targets[$i] === 0 && $bridge->offsets[$i] < .001
				&& abs($bridge->velocities[$i]) < .01
			){
				$bridge->offsets[$i] = $bridge->velocities[$i] = 0;
			}
		}

		$bridge->offsets[0] = $bridge->offsets[$bridge->segments] = 0.0;
		$bridge->velocities[0] = $bridge->velocities[$bridge->segments] = 0.0;
		$bridge->offsets[1] = $bridge->offsets[$bridge->segments - 1] = 0.0;
		$bridge->velocities[1] = $bridge->velocities[$bridge->segments - 1] = 0.0;
	}

	/**
	 * Align repeated tile sprites with every current collision segment.
	 *
	 * @param int $entity Bridge entity optionally containing a SpriteGroup.
	 * @param BendableBridge $bridge Surface providing segment endpoints.
	 * @return void Positions, rotates, and stretches sprites without reallocating them.
	 */
	private function positionSprites(int $entity, BendableBridge $bridge): void
	{
		if(!$this->registry->has($entity, SpriteGroup::class))
		{
			return;
		}

		$points = $bridge->points();
		$group = $this->registry->get($entity, SpriteGroup::class);

		foreach($group->sprites as $i => $sprite)
		{
			[$x, $y] = $points[$i];
			[$u, $v] = $points[$i + 1];
			$sprite->x = ($x + $u) / 2;
			$sprite->y = ($y + $v) / 2;
			$sprite->rotation = atan2($v - $y, $u - $x);

			if(isset($this->spriteScales[$entity][$i]))
			{
				$sprite->scaleX = $this->spriteScales[$entity][$i]
					* hypot($u - $x, $v - $y) / $bridge->step();
			}
		}
	}

	/**
	 * Remove one bridge, its collision geometry, and its owned ECS entity.
	 *
	 * @param int $entity Owned bridge entity ID; unknown IDs are ignored.
	 * @return void Releases each resource exactly once.
	 */
	public function remove(int $entity): void
	{
		$bridge = $this->bridges[$entity] ?? null;

		if($bridge === null)
		{
			return;
		}

		if($bridge->shape !== null)
		{
			$this->terrain->removeShape($bridge->shape);
			$bridge->shape = null;
		}

		if($this->registry->alive($entity))
		{
			$this->registry->destroy($entity);
		}

		unset($this->bridges[$entity], $this->spriteScales[$entity]);
	}

	/**
	 * Retire the system and all owned bridge entities.
	 *
	 * @return void Repeated calls remain harmless.
	 */
	public function close(): void
	{
		foreach(array_keys($this->bridges) as $id)
		{
			$this->remove($id);
		}

		$this->closed = true;
	}

	/**
	 * Publish serializable bridge state for scripts and browser diagnostics.
	 *
	 * @return list<array<string,mixed>> Current deformation, rider IDs, and rail metadata.
	 */
	public function snapshot(): array
	{
		$result = [];

		foreach($this->bridges as $id => $bridge)
		{
			$result[] = [
				'entity' => $id
				, 'sourceId' => $bridge->sourceId
				, 'x' => $bridge->x
				, 'y' => $bridge->y
				, 'width' => $bridge->width
				, 'grinding' => $bridge->grinding
				, 'riders' => $bridge->riders
				, 'offsets' => $bridge->offsets
			];
		}

		return $result;
	}

	/**
	 * Promote axis-aligned Tiled objects and build repeated segment sprites from their tile GID.
	 *
	 * @param TileMap $map Loaded map providing object placement and tile definitions.
	 * @return void Registers every supported object after applying map and layer offsets.
	 */
	private function load(TileMap $map): void
	{
		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				if(!self::matches($object))
				{
					continue;
				}

				if(($object['rotation'] ?? 0) != 0)
				{
					throw new \UnexpectedValueException('Bendable bridges must be axis aligned');
				}

				$properties = Loader::properties($object['properties'] ?? []);
				$class = strtolower(($object['class'] ?? '') ?: ($object['type'] ?? ''));
				$width = (float)($object['width'] ?? 0);
				$height = (float)($object['height'] ?? 16);
				$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
				$top = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY
					- (isset($object['gid']) ? $height : 0);

				$bridge = new BendableBridge(
					$x
					, $top
					, $width
					, $height
					, (float)($properties['droop'] ?? $properties['bend'] ?? 32)
					, (float)($properties['segmentWidth'] ?? 16)
					, (float)($properties['response'] ?? 14)
					, (int)($properties['maxLoads'] ?? 4)
					, str_contains($class, 'rail') || (bool)($properties['grinding'] ?? $properties['grind'] ?? false)
					, (int)($properties['collisionLayer'] ?? $layer->properties['collisionLayer'] ?? 0)
					, (int)($object['id'] ?? 0)
				);

				$sprites = isset($object['gid'])
					? $this->sprites($bridge, $object, $layer->tint, $layer->visible ? $layer->opacity : 0, $map)
					: null;

				$this->add($bridge, $sprites);
			}
		}
	}

	/**
	 * Build one repeated tile sprite per sampled surface segment.
	 *
	 * @param BendableBridge $bridge Bridge controlling segment count and dimensions.
	 * @param array<string,mixed> $object Tiled tile object containing visibility and a raw GID.
	 * @param array{float,float,float,float} $tint Inherited layer tint.
	 * @param float $opacity Inherited layer opacity.
	 * @param TileMap $map Map resolving tile dimensions.
	 * @return SpriteGroup Mutable sprites aligned during every deformation update.
	 */
	private function sprites(
		BendableBridge $bridge
		, array $object
		, array $tint
		, float $opacity
		, TileMap $map
	): SpriteGroup {
		$gid = Gid::from($object['gid']);
		$tile = $map->tile($gid->id) ?? throw new \UnexpectedValueException('Bridge tile is missing');
		$sprites = [];

		if($gid->flags & 2)
		{
			throw new \UnexpectedValueException('Diagonal bridge tile flips are not supported');
		}

		for($i = 0; $i < $bridge->segments; $i++)
		{
			$sprite = new Sprite($gid->id, originX: .5, originY: 0);
			$sprite->scaleX = hypot($bridge->step(), 0) / $tile['rect'][2] * 1.02;
			$sprite->scaleY = $bridge->height / $tile['rect'][3];
			$sprite->flipX = (bool)($gid->flags & 8);
			$sprite->flipY = (bool)($gid->flags & 4);
			$sprite->outline = false;
			$sprite->tint = $tint;
			$sprite->opacity = ($object['visible'] ?? true) ? $opacity : 0;
			$sprites[] = $sprite;
		}

		return new SpriteGroup(...$sprites);
	}
}

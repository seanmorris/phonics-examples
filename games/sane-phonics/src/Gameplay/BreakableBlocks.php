<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use Phonics\Ecs\Query;
use Phonics\Effects\ParticleBurst;
use SanePhonics\Effects\Rumble;
use SanePhonics\Physics\Terrain;
use SanePhonics\Physics\Body;
use Phonics\Physics\Hit;
use Phonics\Render\Sprite;
use Phonics\Tiled\TileMap;

/** Stationary sprite blocks whose colliders share the platformer's continuous terrain casts. */
final class BreakableBlocks
{
	/** @var array<int,array{object,?int}> Owned entity markers and removable terrain handles. */
	private array $owned = [];
	/** @var array<int,int> Removable shape handles mapped to intact block entity IDs. */
	private array $shapes = [];
	private bool $closed = false;
	private \Closure $removed;

	/**
	 * Bind block lifecycle to one registry and one terrain collision index.
	 *
	 * @param Registry $registry Registry receiving intact blocks and their eventual debris.
	 * @param Terrain $terrain Collision index shared with the player movement system.
	 * @param TileMap $map Tilesets matching the sprites' renderer atlas.
	 * @return void Registers cleanup when owned components are removed or entities destroyed.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, private TileMap $map
	){
		$owner = \WeakReference::create($this);

		/**
		 * Release geometry when its owning marker or completed burst leaves the registry.
		 *
		 * @param Registry $entities Registry emitting the component removal.
		 * @param int $id Entity whose component was removed.
		 * @param object $component Exact detached component instance.
		 * @return void Removes only geometry owned by this component, if the manager exists.
		 */
		$this->removed = static function(Registry $entities, int $id, object $component) use ($owner): void {
			$manager = $owner->get();

			if($manager !== null && ($manager->owned[$id][0] ?? null) === $component)
			{
				$handle = $manager->owned[$id][1];

				if($handle !== null)
				{
					$manager->terrain->removeShape($handle);
					unset($manager->shapes[$handle]);
				}

				unset($manager->owned[$id]);
			}
		};

		$registry->on('removed', $this->removed);
	}

	/**
	 * Create a stationary solid block from a sprite's scaled, rotated, cropped rectangle.
	 *
	 * @param Sprite $sprite Primary sprite retained by the entity, with its outline disabled.
	 * @param int $columns Horizontal shard count, no greater than the source crop's pixel width.
	 * @param int $rows Vertical shard count, no greater than the source crop's pixel height.
	 * @param int $collisionLayer Nonnegative solid plane; zero collides with every body.
	 * @param float $milliseconds Animation time used to choose the initial collision dimensions.
	 * @return int New entity ID; its collider remains at this pose until removed or shattered.
	 */
	public function create(
		Sprite $sprite
		, int $columns
		, int $rows
		, int $collisionLayer = 0
		, float $milliseconds = 0
	): int {
		if($this->closed)
		{
			throw new \LogicException('Breakable block manager is closed');
		}

		$block = new BreakableBlock($columns, $rows);

		foreach([
			$sprite->x, $sprite->y, $sprite->scaleX, $sprite->scaleY, $sprite->rotation
			, $sprite->originX, $sprite->originY, $sprite->time, $milliseconds
		] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Block pose and animation time must be finite');
			}
		}

		$gid = $sprite->frame($this->map, $milliseconds);
		$tile = $this->map->tile($gid)
			?? throw new \OutOfBoundsException("Sprite GID $gid is missing");

		[, , $width, $height] = $sprite->rectangle($tile['rect']);

		if($columns > $width || $rows > $height || $sprite->scaleX == 0 || $sprite->scaleY == 0)
		{
			throw new \InvalidArgumentException('Block grid or scale produces empty pieces');
		}

		$points = [];
		$cos = cos($sprite->rotation);
		$sin = sin($sprite->rotation);

		foreach([[0, 0], [1, 0], [1, 1], [0, 1]] as [$u, $v])
		{
			$x = ($u - $sprite->originX) * $width * $sprite->scaleX;
			$y = ($v - $sprite->originY) * $height * $sprite->scaleY;
			$points[] = [
				$sprite->x + $x * $cos - $y * $sin
				, $sprite->y + $x * $sin + $y * $cos
			];
		}

		$handle = $this->terrain->addShape($points, collisionLayer: $collisionLayer);
		$id = $this->registry->create();
		$this->owned[$id] = [$block, $handle];
		$this->shapes[$handle] = $id;
		$outline = $sprite->outline;

		try
		{
			// Tile-like blocks and their cropped debris should join without outline seams.
			$sprite->outline = false;
			$this->registry->add($id, $sprite);
			$this->registry->add($id, $block);
		}
		catch(\Throwable $error)
		{
			$sprite->outline = $outline;
			$this->terrain->removeShape($handle);
			unset($this->owned[$id], $this->shapes[$handle]);

			if($this->registry->alive($id))
			{
				$this->registry->destroy($id);
			}

			throw $error;
		}

		return $id;
	}

	/**
	 * Shatter the contacted block when the moving body is rolling.
	 *
	 * @param Body $body Actor at the start of the current sweep, before collision response.
	 * @param Hit $hit Earliest movement contact from this manager's terrain, not a sensor probe.
	 * @param float $milliseconds Simulation time at which to freeze the visible animation.
	 * @return bool True when this contact shattered an intact block; walking leaves it solid.
	 */
	public function impact(Body $body, Hit $hit, float $milliseconds = 0): bool
	{
		if(!$body->rolling || $hit->shape === null)
		{
			return false;
		}

		$id = $this->shapes[$hit->shape] ?? null;
		$shattered = $id !== null && $this->shatter($id, $milliseconds);

		if($shattered)
		{
			foreach($this->registry->query(Query::all(Body::class, Rumble::class)) as $actor)
			{
				if($this->registry->get($actor, Body::class) === $body)
				{
					$this->registry->get($actor, Rumble::class)->breakBlock();

					break;
				}
			}
		}

		return $shattered;
	}

	/**
	 * Remove a block's solid geometry and replace its primary sprite with a particle group.
	 *
	 * @param int $id Intact block created by this manager.
	 * @param float $milliseconds Simulation time at which to freeze the visible animation.
	 * @param float $speed Outward velocity scale in pixels per second, nonnegative.
	 * @param float $lift Initial upward velocity in pixels per second.
	 * @param float $gravity Downward acceleration in pixels per second squared.
	 * @param float $lifetime Positive duration of debris in seconds.
	 * @return bool True on the first break; false for unknown, removed, or already broken blocks.
	 */
	public function shatter(
		int $id
		, float $milliseconds = 0
		, float $speed = 240
		, float $lift = 240
		, float $gravity = 900
		, float $lifetime = 1.2
	): bool {
		$block = $this->owned[$id][0] ?? null;

		if(!$block instanceof BreakableBlock)
		{
			return false;
		}

		// Validate and construct everything before removing a visible sprite or its collision.
		$sprite = $this->registry->get($id, Sprite::class);

		$burst = ParticleBurst::grid(
			$sprite
			, $this->map
			, $block->columns
			, $block->rows
			, $milliseconds
			, $speed
			, $lift
			, $gravity
			, $lifetime
		);

		$this->registry->remove($id, BreakableBlock::class);
		$this->registry->remove($id, Sprite::class);
		$this->owned[$id] = [$burst, null];
		$this->registry->add($id, $burst->sprites);
		$this->registry->add($id, $burst);

		return true;
	}

	/**
	 * Destroy all blocks and debris still owned by this manager.
	 *
	 * @return void Removes owned geometry and entities, leaving unrelated terrain untouched.
	 */
	public function close(): void
	{
		$this->closed = true;

		foreach(array_keys($this->owned) as $id)
		{
			if($this->registry->alive($id))
			{
				$this->registry->destroy($id);
			}
		}

		$this->registry->off('removed', $this->removed);
	}
}

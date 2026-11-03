<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Tiled spawning and timed patrol, animation, and firing behavior for CrabMeat badniks. */
final class CrabMeats
{
	/** @var array<string,int> Global tile IDs keyed by annotated sprite class. */
	private array $gids;
	/** @var array<int,int> Spawned entity IDs keyed by their source Tiled object IDs. */
	private array $sources = [];

	/**
	 * Promote Tiled CrabMeat objects into generic badniks with species behavior.
	 *
	 * Timing properties retain the reference actor's frame units at 60 updates per second. Speed
	 * and gravity properties likewise use pixels per frame and pixels per frame squared.
	 *
	 * @param Registry $registry Registry receiving CrabMeat, Badnik, and Sprite components.
	 * @param Terrain $terrain Collision source used to stop patrols at walls and ledges.
	 * @param TileMap $map Loaded map containing CrabMeat objects and annotated art tiles.
	 * @param Badniks $badniks Generic contact and projectile manager receiving spawned entities.
	 * @param string $objectClass Tiled object class to promote.
	 * @return void Creates every matching object at its bottom-center world coordinate.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, TileMap $map
		, private Badniks $badniks
		, string $objectClass = 'CrabMeat'
	){
		if($objectClass === '')
		{
			throw new \InvalidArgumentException('CrabMeat object class cannot be empty');
		}

		$this->gids = $this->resolveGids($map);

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				if(($object['class'] ?? $object['type'] ?? '') !== $objectClass)
				{
					continue;
				}

				$properties = Loader::properties($object['properties'] ?? []);
				$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
				$y = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
				$width = (float)($object['width'] ?? 0) ?: (float)($properties['width'] ?? 32);
				$height = (float)($object['height'] ?? 0) ?: (float)($properties['height'] ?? 32);
				$direction = (int)($properties['direction'] ?? 1);
				$collisionLayer = (int)($properties['collisionLayer'] ?? 1);

				$crab = new CrabMeat(
					$direction
					, $this->seconds($properties, 'patrolBeat', 90)
					, $this->seconds($properties, 'patrolPause', 25)
					, $this->perSecond($properties, 'patrolSpeed', .25)
					, $this->seconds($properties, 'patrolOffset', 0)
					, $this->seconds($properties, 'shotTelegraph', 65)
					, $this->seconds($properties, 'shotDelay', 3)
					, $this->perSecond($properties, 'projectileSpeed', 1.5)
					, $this->perSecond($properties, 'projectileLift', 3)
					, $this->perSecondSquared($properties, 'projectileGravity', .15)
					, $this->seconds($properties, 'projectileLifetime', 200)
					, $collisionLayer
				);

				$sprite = new Sprite($this->gids['crabmeat-walking'], $x, $y, .5, 1);
				$sprite->flipX = $direction < 0;
				$sprite->tint = $layer->tint;
				$sprite->opacity = $layer->opacity;

				$entity = $this->badniks->create(
					new Badnik(
						$x
						, $y
						, $width
						, $height
						, (int)($properties['points'] ?? 100)
						, $this->gids['badnik-explosion']
					)
					, $crab
					, $sprite
				);

				if(isset($object['id']))
				{
					$this->sources[(int)$object['id']] = $entity;
				}
			}
		}
	}

	/**
	 * Advance every live CrabMeat patrol and emit all shot events crossed by this update.
	 *
	 * @param Registry $registry Bound registry supplied by the scheduler.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Moves badniks, updates sprites, and creates paired ballistic projectiles.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('CrabMeat update requires its registry and a positive delta');
		}

		foreach($registry->query(Query::all(Badnik::class, CrabMeat::class, Sprite::class)) as $id)
		{
			$badnik = $registry->get($id, Badnik::class);
			$crab = $registry->get($id, CrabMeat::class);
			$sprite = $registry->get($id, Sprite::class);
			$from = $crab->age + $crab->patrolOffset;
			$to = $from + $dt;

			$this->patrol($badnik, $crab, $from, $to);
			$this->fireCrossedShots($id, $badnik, $crab, $from, $to);
			$crab->age += $dt;
			$this->pose($badnik, $crab, $sprite, $to);
		}
	}

	/**
	 * Return live CrabMeat entity IDs for diagnostics and scripted examples.
	 *
	 * @return list<int> Registry snapshot ordered by entity creation.
	 */
	public function entities(): array
	{
		return $this->registry->query(Query::all(Badnik::class, CrabMeat::class));
	}

	/**
	 * Resolve a spawned CrabMeat from its source Tiled object ID.
	 *
	 * @param int $sourceId Object ID referenced by another Tiled object property.
	 * @return int|null Entity ID allocated for that object, or null when it was not spawned.
	 */
	public function entityForSourceId(int $sourceId): ?int
	{
		return $this->sources[$sourceId] ?? null;
	}

	/**
	 * Integrate only the moving portions of each alternating patrol interval.
	 *
	 * @param Badnik $badnik Generic position and contact bounds to move.
	 * @param CrabMeat $crab Species timing and speed configuration.
	 * @param float $from Absolute schedule time before the update.
	 * @param float $to Absolute schedule time after the update.
	 * @return void Preserves pause time and handles any number of crossed beat boundaries.
	 */
	private function patrol(Badnik $badnik, CrabMeat $crab, float $from, float $to): void
	{
		$cursor = $from;
		$moveDuration = $crab->patrolBeat - $crab->patrolPause;

		while($cursor < $to - 1e-10)
		{
			$cycle = (int)floor($cursor / $crab->patrolBeat);
			$start = $cycle * $crab->patrolBeat;
			$cycleEnd = $start + $crab->patrolBeat;
			$moveEnd = $start + $moveDuration;
			$end = min($to, $cycleEnd);
			$direction = $crab->initialDirection * ($cycle % 2 === 0 ? 1 : -1);

			if($cursor < $moveEnd)
			{
				$movingEnd = min($end, $moveEnd);
				$this->move($badnik, $crab, $direction * $crab->patrolSpeed * ($movingEnd - $cursor));
				$cursor = $movingEnd;
			}
			else
			{
				$cursor = $end;
			}

			if($end <= $cursor + 1e-10 && $cursor < $to && $cursor >= $cycleEnd - 1e-10)
			{
				$cursor = $cycleEnd;
			}
		}
	}

	/**
	 * Move one patrol segment while continuous casts stop walls and foot probes stop ledges.
	 *
	 * @param Badnik $badnik Bottom-anchored badnik to move.
	 * @param CrabMeat $crab Species state supplying the collision plane.
	 * @param float $dx Signed horizontal displacement for this schedule slice.
	 * @return void Moves as far as collision permits and snaps the bottom to nearby ground.
	 */
	private function move(Badnik $badnik, CrabMeat $crab, float $dx): void
	{
		if(abs($dx) < 1e-10)
		{
			return;
		}

		$direction = $dx < 0 ? -1 : 1;
		$leadingX = $badnik->x + $dx + $direction * max(0, $badnik->width / 2 - 1);
		$ground = $this->terrain->raycast($leadingX, $badnik->y - 4, 0, 12, false, $crab->collisionLayer);

		if($ground === null)
		{
			return;
		}

		$probe = new Body($badnik->x, $badnik->y - $badnik->height / 2);
		$probe->radius = max(.5, $badnik->width / 2 - 1);
		$probe->halfHeight = max(.5, $badnik->height / 2 - 1);
		$probe->collisionLayer = $crab->collisionLayer;
		$wall = $this->terrain->castBody($probe, $dx, 0);

		if($wall !== null)
		{
			$dx *= max(0, $wall->t - 1e-6);
		}

		$badnik->x += $dx;
		$under = $this->terrain->raycast($badnik->x, $badnik->y - 4, 0, 12, false, $crab->collisionLayer);

		if($under !== null)
		{
			$badnik->y = $under->y;
		}
	}

	/**
	 * Spawn both outward shots for every firing instant crossed by the current update.
	 *
	 * @param int $owner Live CrabMeat entity that owns the shots.
	 * @param Badnik $badnik Owner position used for the two muzzle offsets.
	 * @param CrabMeat $crab Species projectile and schedule configuration.
	 * @param float $from Absolute schedule time before the update.
	 * @param float $to Absolute schedule time after the update.
	 * @return void Emits each cycle once, including when one update crosses several cycles.
	 */
	private function fireCrossedShots(
		int $owner
		, Badnik $badnik
		, CrabMeat $crab
		, float $from
		, float $to
	): void {
		$firePhase = $crab->patrolBeat - 3 / 60;
		$first = (int)floor($from / $crab->patrolBeat);
		$last = (int)floor($to / $crab->patrolBeat);

		for($cycle = $first; $cycle <= $last; $cycle++)
		{
			$instant = $cycle * $crab->patrolBeat + $firePhase;

			if($instant <= $from + 1e-10 || $instant > $to + 1e-10)
			{
				continue;
			}

			foreach([-1, 1] as $direction)
			{
				$x = $badnik->x + $direction * 19;
				$y = $badnik->y - 19;

				$projectile = new BadnikProjectile(
					$owner
					, $x
					, $y
					, $direction * $crab->projectileSpeed
					, -$crab->projectileLift
					, $crab->projectileGravity
					, $crab->shotDelay
					, $crab->projectileLifetime
					, 4
					, $crab->collisionLayer
				);

				$this->badniks->projectile($projectile, new Sprite($this->gids['badnik-projectile'], $x, $y, .5, .5));
			}

			$crab->shots += 2;
		}
	}

	/**
	 * Select the current reference pose and synchronize the actor sprite.
	 *
	 * @param Badnik $badnik Generic position to copy into the sprite.
	 * @param CrabMeat $crab Species state receiving direction, moving, and animation values.
	 * @param Sprite $sprite Visible actor sprite to update.
	 * @param float $clock Absolute schedule time after the update.
	 * @return void Applies walking, standing, or shooting art without restarting animations.
	 */
	private function pose(Badnik $badnik, CrabMeat $crab, Sprite $sprite, float $clock): void
	{
		$cycle = (int)floor($clock / $crab->patrolBeat);
		$phase = $clock - $cycle * $crab->patrolBeat;
		$crab->direction = $crab->initialDirection * ($cycle % 2 === 0 ? 1 : -1);
		$crab->moving = $phase < $crab->patrolBeat - $crab->patrolPause && $crab->patrolSpeed > 0;

		if($crab->moving)
		{
			$animation = 'walking';
		}
		elseif($phase < $crab->shotTelegraph || $phase > $crab->patrolBeat - 15 / 60)
		{
			$animation = 'shooting';
		}
		else
		{
			$animation = 'standing';
		}

		$crab->animation = $animation;
		$sprite->gid = $this->gids['crabmeat-' . $animation];
		$sprite->x = $badnik->x;
		$sprite->y = $badnik->y;
		$sprite->flipX = $crab->direction < 0;
	}

	/**
	 * Resolve each required sprite class from normalized Tiled tileset definitions.
	 *
	 * @param TileMap $map Map whose atlas will render the spawned sprites.
	 * @return array<string,int> Required class names mapped to global tile IDs.
	 */
	private function resolveGids(TileMap $map): array
	{
		$required = [
			'crabmeat-standing'
			, 'crabmeat-walking'
			, 'crabmeat-shooting'
			, 'badnik-projectile'
			, 'badnik-explosion'
		];

		$result = [];

		foreach($map->tilesets as $set)
		{
			foreach($set['definitions'] as $local => $tile)
			{
				$class = $tile['class'] ?? $tile['type'] ?? '';

				if(in_array($class, $required, true) && !isset($result[$class]))
				{
					$result[$class] = $set['firstgid'] + $local;
				}
			}
		}

		$missing = array_values(array_diff($required, array_keys($result)));

		if($missing)
		{
			throw new \UnexpectedValueException('Missing CrabMeat sprite classes: ' . implode(', ', $missing));
		}

		return $result;
	}

	/**
	 * Read a frame-count property as seconds.
	 *
	 * @param array<string,mixed> $properties Flattened Tiled object properties.
	 * @param string $name Property name to read.
	 * @param float $default Default frame count when the property is absent.
	 * @return float Configured duration in seconds at 60 reference updates per second.
	 */
	private function seconds(array $properties, string $name, float $default): float
	{
		return (float)($properties[$name] ?? $default) / 60;
	}

	/**
	 * Read a pixels-per-frame property as pixels per second.
	 *
	 * @param array<string,mixed> $properties Flattened Tiled object properties.
	 * @param string $name Property name to read.
	 * @param float $default Default pixels per frame when the property is absent.
	 * @return float Configured velocity in pixels per second.
	 */
	private function perSecond(array $properties, string $name, float $default): float
	{
		return (float)($properties[$name] ?? $default) * 60;
	}

	/**
	 * Read a pixels-per-frame-squared property as pixels per second squared.
	 *
	 * @param array<string,mixed> $properties Flattened Tiled object properties.
	 * @param string $name Property name to read.
	 * @param float $default Default pixels per frame squared when the property is absent.
	 * @return float Configured acceleration in pixels per second squared.
	 */
	private function perSecondSquared(array $properties, string $name, float $default): float
	{
		return (float)($properties[$name] ?? $default) * 3600;
	}
}

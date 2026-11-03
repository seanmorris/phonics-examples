<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Tiled\TileMap;

/** Tiled ring promotion, swept player pickup detection, and owned entity cleanup. */
final class Rings
{
	private array $entities = [], $previous = [], $ringPrevious = [];
	private ?int $ringGid = null;
	private bool $closed = false;
	private ?\Closure $onCollect;

	/**
	 * Promote tiles annotated with a ring class into collectible sprite entities.
	 *
	 * @param Registry $registry Registry receiving ring and sprite components.
	 * @param TileMap $map Loaded map whose matching tile cells become entities and are cleared.
	 * @param string $tileClass Nonempty Tiled tile class identifying collectible rings.
	 * @param Terrain|null $terrain Optional collision source for scattered ring bounces.
	 * @param callable(int,int,Ring):void|null $onCollect Optional collector, removed entity, and
	 * pickup notification after rewards are granted and the ring is removed.
	 * @return void Spawns every visible matching tile with metadata-defined radius and rewards.
	 */
	public function __construct(
		private Registry $registry
		, TileMap $map
		, string $tileClass = 'ring'
		, private ?Terrain $terrain = null
		, ?callable $onCollect = null
	){
		$this->onCollect = $onCollect === null ? null : \Closure::fromCallable($onCollect);

		if($tileClass === '')
		{
			throw new \InvalidArgumentException('Ring tile class cannot be empty');
		}

		foreach($map->tilesets as $set)
		{
			foreach($set['definitions'] as $local => $tile)
			{
				if(($tile['class'] ?? $tile['type'] ?? '') === $tileClass)
				{
					$this->ringGid ??= $set['firstgid'] + $local;
				}
			}
		}

		foreach($map->layers as $layer)
		{
			if(!$layer->visible || $layer->type !== 'tilelayer')
			{
				continue;
			}

			foreach($layer->chunks as $chunk)
			{
				for($row = $chunk->y; $row < $chunk->y + $chunk->height; $row++)
				{
					for($column = $chunk->x; $column < $chunk->x + $chunk->width; $column++)
					{
						$gid = $chunk->gidAt($column, $row);
						$tile = $gid->id ? $map->tile($gid->id) : null;

						if(($tile['class'] ?? $tile['type'] ?? '') !== $tileClass)
						{
							continue;
						}

						[$x, $y] = $map->projection->project($column, $row);
						[$offsetX, $offsetY] = $tile['offset'];
						[, , $width, $height] = $tile['rect'];

						$x += $map->x + $layer->offsetX + $offsetX;
						$y += $map->y + $layer->offsetY + $offsetY
							+ $map->projection->tileHeight - $height;

						if($map->projection->orientation === 'isometric')
						{
							$x -= $width / 2;
						}

						$x += $width / 2;
						$y += $height / 2;
						$properties = $tile['properties'];

						$ring = new Ring(
							$x
							, $y
							, (float)($properties['ring.radius'] ?? min($width, $height) * .3125)
							, (int)($properties['ring.value'] ?? 1)
							, (int)($properties['ring.points'] ?? 0)
						);

						$sprite = new Sprite($gid->id, $x, $y, .5, .5);
						$sprite->flipX = (bool)($gid->flags & 8);
						$sprite->flipY = (bool)($gid->flags & 4);
						$sprite->tint = $layer->tint;
						$sprite->opacity = $layer->opacity;

						if($gid->flags & 2)
						{
							$sprite->rotation = M_PI / 2;
							[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
						}

						$entity = $registry->create($ring, $sprite);
						$this->entities[] = $entity;
						$this->ringPrevious[$entity] = [$ring->x, $ring->y];
						$layer->set($column, $row, 0);
					}
				}
			}
		}
	}

	/**
	 * Collect rings intersected by each body's swept bounds during the current fixed step.
	 *
	 * @param Registry $registry Registry containing Body plus RingCounter collectors and Ring
	 * entities.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Moves scattered rings, awards eligible pickups, and retires expired entities.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Ring update requires its registry and a positive delta');
		}

		$this->moveScattered($dt);
		$rings = $registry->query(Ring::class);
		$active = [];

		foreach($registry->query(Query::all(Body::class, RingCounter::class)) as $collector)
		{
			$body = $registry->get($collector, Body::class);
			$counter = $registry->get($collector, RingCounter::class);
			$vehicle = Vehicle::forRider($registry, $collector);
			$from = $this->previous[$collector] ?? [$body->x, $body->y];
			$active[$collector] = $this->previous[$collector] = [$body->x, $body->y];

			foreach($rings as $key => $entity)
			{
				if(!$registry->alive($entity))
				{
					unset($rings[$key]);

					continue;
				}

				$ring = $registry->get($entity, Ring::class);
				$scattered = $registry->has($entity, ScatteredRing::class)
					? $registry->get($entity, ScatteredRing::class)
					: null;

				if($scattered !== null && (!$scattered->collectible || $scattered->age < $scattered->pickupDelay))
				{
					continue;
				}

				$ringFrom = $this->ringPrevious[$entity] ?? [$ring->x, $ring->y];

				if(!$this->intersects($body, $from, $ring, $ringFrom)
					&& ($vehicle === null || !$this->intersects(
						$vehicle->body
						, [$vehicle->previousX, $vehicle->previousY]
						, $ring
						, $ringFrom
					))
				){
					continue;
				}

				$counter->collect($ring);
				$registry->destroy($entity);
				unset($rings[$key]);

				if($this->onCollect !== null)
				{
					($this->onCollect)($collector, $entity, $ring);
				}
			}
		}

		$this->previous = array_intersect_key($this->previous, $active);
		$live = [];

		foreach($registry->query(Ring::class) as $entity)
		{
			$ring = $registry->get($entity, Ring::class);
			$live[$entity] = $this->ringPrevious[$entity] = [$ring->x, $ring->y];
		}

		$this->ringPrevious = array_intersect_key($this->ringPrevious, $live);
	}

	/**
	 * Scatter up to sixteen rings from a damaged player in two radial groups.
	 *
	 * Every third ring is a short-lived visual, following the reference actor's reduced recovery
	 * set. Remaining rings become collectible after 48 reference frames.
	 *
	 * @param int $owner Player entity whose inventory supplied the rings.
	 * @param Body $body Player body supplying position, height, and collision plane.
	 * @param RingCounter $counter Inventory inspected without being mutated by this method.
	 * @return list<int> New scattered ring entity IDs, capped by the held ring count and sixteen.
	 */
	public function scatter(int $owner, Body $body, RingCounter $counter): array
	{
		if($this->closed)
		{
			throw new \LogicException('Ring manager is closed');
		}

		if($owner < 1 || !$this->registry->alive($owner))
		{
			throw new \InvalidArgumentException('Scattered rings require a live owner');
		}

		if($this->ringGid === null || $counter->rings < 1)
		{
			return [];
		}

		$result = [];
		$count = min($counter->rings, 16);

		for($index = 0; $index < $count; $index++)
		{
			$circle = intdiv($index, 8) + 1;
			$angle = ($index % 8) * M_PI / 4 + M_PI / 4;
			$cos = cos($angle);
			$sin = sin($angle);
			$x = $body->x - $cos * $circle * 8;
			$y = $body->y - $sin * $circle * 8 - $body->halfHeight / 2;
			$collectible = $index % 3 !== 2;
			$lifetime = $collectible ? 6 - ($index % 5) * 35 / 60 : 100 / 60;
			$ring = new Ring($x, $y, 8);

			$motion = new ScatteredRing(
				$owner
				, -$cos * 180
				, -$sin * 180 - ($circle + 1) * 45
				, lifetime: $lifetime
				, collisionLayer: $body->collisionLayer
				, collectible: $collectible
			);

			$sprite = new Sprite($this->ringGid, $x, $y, .5, .5);
			$entity = $this->registry->create($ring, $motion, $sprite);
			$this->entities[] = $result[] = $entity;
			$this->ringPrevious[$entity] = [$x, $y];
		}

		return $result;
	}

	/**
	 * Count live damage-scattered ring visuals for diagnostics.
	 *
	 * @return int Number of recoverable and decorative scattered rings still in the registry.
	 */
	public function scatteredCount(): int
	{
		return count($this->registry->query(ScatteredRing::class));
	}

	/**
	 * Reset a collector's sweep origin after spawning, teleporting, or changing levels.
	 *
	 * @param int $entity Collector entity whose previous position is replaced.
	 * @param Body $body Body supplying the new world position.
	 * @return void Prevents a teleport path from collecting rings between its endpoints.
	 */
	public function sync(int $entity, Body $body): void
	{
		$this->previous[$entity] = [$body->x, $body->y];
	}

	/**
	 * Test a moving body's center against a ring-expanded oriented-body bounding box.
	 *
	 * @param Body $body Collector at the sweep endpoint, including current surface rotation.
	 * @param array{float,float} $from Collector center at the previous fixed update.
	 * @param Ring $ring Candidate pickup and its circular collection radius at the endpoint.
	 * @param array{float,float} $ringFrom Ring center at the previous fixed update.
	 * @return bool True when the relative center segment crosses the expanded ring bounds.
	 */
	private function intersects(Body $body, array $from, Ring $ring, array $ringFrom): bool
	{
		$cos = cos($body->angle);
		$sin = sin($body->angle);
		$halfWidth = abs($cos) * $body->radius + abs($sin) * $body->halfHeight + $ring->radius;
		$halfHeight = abs($sin) * $body->radius + abs($cos) * $body->halfHeight + $ring->radius;

		$delta = [
			($body->x - $from[0]) - ($ring->x - $ringFrom[0])
			, ($body->y - $from[1]) - ($ring->y - $ringFrom[1])
		];

		$origin = [$from[0] - $ringFrom[0], $from[1] - $ringFrom[1]];
		$entry = 0.0;
		$exit = 1.0;

		foreach([[$origin[0], $delta[0], $halfWidth], [$origin[1], $delta[1], $halfHeight]] as [
			$position
			, $movement
			, $extent
		]){
			if(abs($movement) < 1e-9)
			{
				if(abs($position) > $extent)
				{
					return false;
				}

				continue;
			}

			$near = (-$extent - $position) / $movement;
			$far = ($extent - $position) / $movement;

			if($near > $far)
			{
				[$near, $far] = [$far, $near];
			}

			$entry = max($entry, $near);
			$exit = min($exit, $far);

			if($entry > $exit)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Integrate scattered rings and bounce their complete displacements against terrain.
	 *
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Updates ring and sprite positions and retires expired visuals.
	 */
	private function moveScattered(float $dt): void
	{
		foreach($this->registry->query(Query::all(Ring::class, ScatteredRing::class, Sprite::class)) as $entity)
		{
			$ring = $this->registry->get($entity, Ring::class);
			$motion = $this->registry->get($entity, ScatteredRing::class);
			$start = $motion->age;
			$motion->age = min($motion->lifetime, $motion->age + $dt);
			$active = max(0, $motion->age - max($start, $motion->launchDelay));
			$this->ringPrevious[$entity] = [$ring->x, $ring->y];

			if($active > 0)
			{
				$this->moveScatteredRing($ring, $motion, $active);
			}

			if($motion->age >= $motion->lifetime)
			{
				$this->registry->destroy($entity);
				unset($this->ringPrevious[$entity]);

				continue;
			}

			$sprite = $this->registry->get($entity, Sprite::class);
			$sprite->x = $ring->x;
			$sprite->y = $ring->y;
		}
	}

	/**
	 * Advance one ballistic ring, reflecting velocity at up to four terrain contacts.
	 *
	 * @param Ring $ring Mutable ring center.
	 * @param ScatteredRing $motion Mutable velocity and collision configuration.
	 * @param float $duration Positive active motion time in seconds.
	 * @return void Commits collision-limited movement without crossing thin terrain.
	 */
	private function moveScatteredRing(Ring $ring, ScatteredRing $motion, float $duration): void
	{
		for($contact = 0; $contact < 4 && $duration > 1e-7; $contact++)
		{
			$dx = $motion->vx * $duration;
			$dy = $motion->vy * $duration + .5 * $motion->gravity * $duration * $duration;
			$hit = null;

			if($this->terrain !== null)
			{
				$probe = new Body($ring->x, $ring->y);
				$probe->radius = $probe->halfHeight = $ring->radius;
				$probe->collisionLayer = $motion->collisionLayer;
				$hit = $this->terrain->castBody($probe, $dx, $dy);
			}

			if($hit === null)
			{
				$ring->x += $dx;
				$ring->y += $dy;
				$motion->vy += $motion->gravity * $duration;

				return;
			}

			$travel = max(0, min(1, $hit->t));
			$elapsed = $duration * $travel;
			$ring->x += $dx * $travel + $hit->nx * .001;
			$ring->y += $dy * $travel + $hit->ny * .001;
			$motion->vy += $motion->gravity * $elapsed;
			$normalSpeed = $motion->vx * $hit->nx + $motion->vy * $hit->ny;

			if($normalSpeed < 0)
			{
				$impulse = (1 + $motion->restitution) * $normalSpeed;
				$motion->vx -= $impulse * $hit->nx;
				$motion->vy -= $impulse * $hit->ny;
			}

			$duration -= $elapsed;

			if($travel < 1e-7)
			{
				$duration = max(0, $duration - 1e-5);
			}
		}
	}

	/**
	 * Destroy uncollected rings owned by this map and discard collector sweep history.
	 *
	 * @return void Releases level entities once; collected rings are already absent.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->onCollect = null;

		foreach($this->entities as $entity)
		{
			if($this->registry->alive($entity))
			{
				$this->registry->destroy($entity);
			}
		}

		$this->entities = $this->previous = $this->ringPrevious = [];
	}
}

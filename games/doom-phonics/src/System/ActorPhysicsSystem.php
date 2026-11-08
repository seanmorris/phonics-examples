<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\ChargeAttack;
use DoomPhonics\Component\Gravity;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\RemoteActor;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\DoomGravity;
use DoomPhonics\Game\VerticalPhysics;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Integrates non-player DECORATE momentum and gravity after actor-state actions. */
final class ActorPhysicsSystem
{
	private Query $actors;
	private VerticalPhysics $physics;
	private Collision $collision;
	/** @var list<int>|null Registry snapshot the component cache was built from. */
	private ?array $known = null;
	/** @var array<int,array{Transform,Velocity,Body,?Gravity}> Components per actor; a structural change rebuilds it. */
	private array $cache = [];
	/**
	 * Bodies the step still visits. A body the step leaves at rest on a plain floor with no velocity sleeps: it is
	 * dropped from this set until something that can move it reports in. Damage thrust, a Collision move, and a plane
	 * change in its sector each wake it; new bodies start awake. Nearly every body sleeps nearly every tic.
	 *
	 * @var array<int,true>
	 */
	private array $awake = [];
	/** @var array<int,int> Sleeping bodies' sectors, so a plane change wakes the bodies standing in that sector. */
	private array $restingSector = [];
	/** @var array<int,array<int,true>> Sleeping bodies by sector. */
	private array $sleepers = [];

	public function __construct(
		Collision $collision
		, float $acceleration = DoomGravity::ACCELERATION
		, public float $friction = .90625
	){
		$this->actors = Query::all(
			Transform::class
			, Velocity::class
			, Body::class
			, Query::not(PlayerStart::class, Projectile::class, ChargeAttack::class, RemoteActor::class)
		);

		$this->collision = $collision;
		$this->physics = new VerticalPhysics($collision->level, $collision->maxStep, $acceleration);
	}

	public function update(Registry $registry, float $dt): void
	{
		$entities = $registry->query($this->actors);

		if($entities !== $this->known)
		{
			$this->known = $entities;
			$previous = $this->cache;
			$this->cache = [];

			foreach($entities as $entity)
			{
				$this->cache[$entity] = $previous[$entity] ?? [
					$registry->get($entity, Transform::class)
					, $registry->get($entity, Velocity::class)
					, $registry->get($entity, Body::class)
					, $registry->has($entity, Gravity::class) ? $registry->get($entity, Gravity::class) : null
				];

				if(!isset($previous[$entity])) $this->awake[$entity] = true;
			}

			foreach(array_keys($previous) as $entity) if(!isset($this->cache[$entity])) $this->forget($entity);
		}

		foreach(array_keys($this->awake) as $entity)
		{
			if(!isset($this->cache[$entity]))
			{
				unset($this->awake[$entity]);
				continue;
			}

			[$pose, $velocity, $body, $gravity] = $this->cache[$entity];

			if(hypot($velocity->x, $velocity->y) > .01)
			{
				$motion = $this->collision->moveAndSlide(
					$pose
					, $body
					, $velocity->x * max(0, $dt)
					, $velocity->y * max(0, $dt)
					, $entity
				);

				foreach($motion['normals'] as [$normalX,$normalY])
				{
					$into = $velocity->x * $normalX + $velocity->y * $normalY;

					if($into < 0)
					{
						$velocity->x -= $normalX * $into;
						$velocity->y -= $normalY * $into;
					}
				}

				if($body->grounded)
				{
					$drag = pow($this->friction, max(0, $dt) * 35);
					$velocity->x *= $drag;
					$velocity->y *= $drag;
				}

				if(hypot($velocity->x, $velocity->y) < .01) $velocity->x = $velocity->y = 0;
			}

			if($gravity !== null) $this->physics->step($pose, $velocity, $body, $dt, $gravity->scale);

			// A body left at rest on a plain floor sleeps until something that can move it reports in.
			if($velocity->z == 0 && $body->grounded && $velocity->x == 0 && $velocity->y == 0)
			{
				$sector = $this->collision->level->sectorAt($pose->x, $pose->y);

				if($pose->z === $sector->floor && !$sector->volumes && $sector->heightTransfer === null
					&& $sector->floorSlope === [0.0,0.0,0.0] && $sector->ceilingSlope === [0.0,0.0,0.0]
					&& !$this->collision->level->cameraPortalSurfaces()
				)
				{
					unset($this->awake[$entity]);
					$this->restingSector[$entity] = $sector->index;
					$this->sleepers[$sector->index][$entity] = true;
				}
			}
		}
	}

	/** Bring a sleeping body back into the step: its velocity or position may have changed. */
	public function wake(int $entity): void
	{
		if(!isset($this->cache[$entity]) || isset($this->awake[$entity])) return;

		$this->awake[$entity] = true;
		$sector = $this->restingSector[$entity] ?? null;

		if($sector !== null) unset($this->sleepers[$sector][$entity], $this->restingSector[$entity]);
	}

	/** A sector's planes changed: every body sleeping on its floor must be stepped again. */
	public function wakeSector(int $sector): void
	{
		foreach(array_keys($this->sleepers[$sector] ?? []) as $entity) $this->wake($entity);
	}

	private function forget(int $entity): void
	{
		$sector = $this->restingSector[$entity] ?? null;

		if($sector !== null) unset($this->sleepers[$sector][$entity]);

		unset($this->awake[$entity], $this->restingSector[$entity]);
	}
}

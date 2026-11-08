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
			$this->cache = [];

			foreach($entities as $entity)
			$this->cache[$entity] = [
				$registry->get($entity, Transform::class)
				, $registry->get($entity, Velocity::class)
				, $registry->get($entity, Body::class)
				, $registry->has($entity, Gravity::class) ? $registry->get($entity, Gravity::class) : null
			];
		}

		foreach($this->cache as $entity => [$pose, $velocity, $body, $gravity])
		{
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
		}
	}
}

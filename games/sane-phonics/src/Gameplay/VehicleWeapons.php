<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;

/** Generic ECS melee timers, projectile emitters, and continuous projectile terrain movement. */
final class VehicleWeapons
{
	/** @var array<int,bool> Projectile entity IDs owned by this weapon system. */
	private array $projectiles = [];
	private bool $closed = false;

	/**
	 * Bind generic vehicle weapon capabilities to their ECS registry and collision terrain.
	 *
	 * @param Registry $registry Registry containing vehicles, capabilities, shots, and targets.
	 * @param Terrain $terrain Collision source used for continuous projectile casts.
	 * @return void Subscribes to projectile component removals.
	 */
	public function __construct(private Registry $registry, private Terrain $terrain)
	{
		$registry->on('removed', [$this, 'removed']);
	}

	/**
	 * Advance capability timers and emit shots for one occupied vehicle.
	 *
	 * @param Vehicle $vehicle Chassis entity whose capability components are queried.
	 * @param Controls $input Complete logical controls routed from the seated rider.
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return void Mutates capability timers and may create one projectile entity.
	 */
	public function control(Vehicle $vehicle, Controls $input, float $dt): void
	{
		$id = $vehicle->entity;

		if($this->registry->has($id, VehicleMelee::class))
		{
			$melee = $this->registry->get($id, VehicleMelee::class);
			$melee->active = max(0, $melee->active - $dt);
			$melee->remaining = max(0, $melee->remaining - $dt);

			if($vehicle->occupant !== null && $input->pressed($melee->button) && $melee->remaining <= 1e-9)
			{
				$melee->active = $melee->duration;
				$melee->remaining = $melee->cooldown;
			}
		}

		if(!$this->registry->has($id, VehicleEmitter::class)) return;

		$emitter = $this->registry->get($id, VehicleEmitter::class);
		$emitter->remaining = max(0, $emitter->remaining - $dt);
		$emitter->flash = max(0, $emitter->flash - $dt);

		if($vehicle->occupant !== null && $input->down($emitter->button) && $emitter->remaining <= 1e-9)
		{
			$emitter->remaining = $emitter->interval;
			$emitter->flash = 1 / 60;
			$emitter->shots++;
			$this->fire($vehicle, $emitter);
		}
	}

	/**
	 * Advance every live projectile before combat systems inspect its previous-to-current path.
	 *
	 * @param float $dt Positive fixed simulation delta in seconds.
	 * @return void Moves shots and retires terrain impacts or expired lifetimes.
	 */
	public function update(float $dt): void
	{
		foreach(array_keys($this->projectiles) as $id)
		{
			if(!$this->registry->has($id, VehicleProjectile::class))
			{
				unset($this->projectiles[$id]);
				continue;
			}

			$shot = $this->registry->get($id, VehicleProjectile::class);
			$shot->previousX = $shot->x;
			$shot->previousY = $shot->y;
			$start = $shot->age;
			$shot->age = min($shot->lifetime, $shot->age + $dt);
			$gravityTime = max(0, $shot->age - max($start, $shot->gravityDelay));
			$dx = $shot->vx * $dt;
			$dy = $shot->vy * $dt + .5 * $shot->gravity * $gravityTime * $gravityTime;
			$probe = new Body($shot->x, $shot->y);
			$probe->radius = $probe->halfHeight = $shot->radius;
			$probe->collisionLayer = $shot->collisionLayer;

			if($this->terrain->castBody($probe, $dx, $dy) !== null || $shot->age >= $shot->lifetime)
			{
				$this->registry->destroy($id);
				continue;
			}

			$shot->x += $dx;
			$shot->y += $dy;
			$shot->vy += $shot->gravity * $gravityTime;

			if($this->registry->has($id, Sprite::class))
			{
				$sprite = $this->registry->get($id, Sprite::class);
				$sprite->x = $shot->x;
				$sprite->y = $shot->y;
				$sprite->rotation = atan2($shot->vy, $shot->vx);
				$sprite->flipY = $shot->vx < 0;
			}
		}
	}

	/**
	 * Remove one projectile from ownership when any system destroys its component.
	 *
	 * @param Registry $registry Registry dispatching the lifecycle notification.
	 * @param int $entity Entity whose component was removed.
	 * @param object $component Removed component instance.
	 * @return void Forgets removed VehicleProjectile entities and ignores other components.
	 */
	public function removed(Registry $registry, int $entity, object $component): void
	{
		if($component instanceof VehicleProjectile) unset($this->projectiles[$entity]);
	}

	/**
	 * Destroy outstanding shots and release the lifecycle subscription.
	 *
	 * @return void Makes repeated cleanup harmless.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;

		foreach(array_keys($this->projectiles) as $id)
		if($this->registry->alive($id))
		$this->registry->destroy($id);
		$this->projectiles = [];
		$this->registry->off('removed', [$this, 'removed']);
	}

	/**
	 * Create one projectile from the emitter's surface-relative muzzle configuration.
	 *
	 * @param Vehicle $vehicle Occupied chassis supplying transform, velocity, and attacker.
	 * @param VehicleEmitter $emitter Capability supplying muzzle and ballistic configuration.
	 * @return void Registers an owned ECS projectile with optional sprite artwork.
	 */
	private function fire(Vehicle $vehicle, VehicleEmitter $emitter): void
	{
		if($vehicle->occupant === null) return;

		$body = $vehicle->body;
		$direction = $body->direction ?: $body->facing ?: 1;
		$tangentX = cos($body->angle) * $direction;
		$tangentY = sin($body->angle) * $direction;
		$normalX = sin($body->angle);
		$normalY = -cos($body->angle);
		$x = $body->supportX() + $tangentX * $emitter->forwardOffset + $normalX * $emitter->normalOffset;
		$y = $body->supportY() + $tangentY * $emitter->forwardOffset + $normalY * $emitter->normalOffset;

		$projectile = new VehicleProjectile(
			$vehicle->entity
			, $vehicle->occupant
			, $x
			, $y
			, $body->vx + $tangentX * $emitter->speed
			, $body->vy + $tangentY * $emitter->speed
			, $emitter->gravity
			, $emitter->gravityDelay
			, $emitter->lifetime
			, $emitter->radius
			, $body->collisionLayer
		);

		$id = $emitter->spriteGid === null
			? $this->registry->create($projectile)
			: $this->registry->create($projectile, new Sprite($emitter->spriteGid, $x, $y, .5, .5));

		$this->projectiles[$id] = true;
	}
}

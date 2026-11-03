<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use SanePhonics\Effects\Rumble;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;

/** Reusable badnik contacts, player damage, score rewards, projectiles, and pop cleanup. */
final class Badniks
{
	/** @var array<int,bool> Entity IDs owned by this level's badnik system. */
	private array $owned = [];
	/** @var array<int,array{float,float}> Previous player centers for continuous contact tests. */
	private array $players = [];
	/** @var array<int,array{float,float}> Previous badnik centers for relative continuous tests. */
	private array $badniks = [];
	private bool $closed = false;

	/**
	 * Bind badnik gameplay to a registry and the active level's terrain.
	 *
	 * @param Registry $registry Registry containing badniks, players, sprites, and projectiles.
	 * @param Terrain $terrain Collision source used for continuous projectile body casts.
	 * @param Rings|null $rings Optional level ring owner used to scatter inventory on player damage.
	 * @return void Initializes empty ownership and sweep histories.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, private ?Rings $rings = null
	){
	}

	/**
	 * Create an owned badnik from generic contact state and one species behavior.
	 *
	 * @param Badnik $badnik Reusable position, bounds, reward, and pop-art component.
	 * @param BadnikBehavior $behavior Species component updated by its own behavior system.
	 * @param Sprite $sprite Visible sprite sharing the level renderer's atlas.
	 * @return int New entity ID owned until it pops, is destroyed, or this manager closes.
	 */
	public function create(Badnik $badnik, BadnikBehavior $behavior, Sprite $sprite): int
	{
		if($this->closed)
		{
			throw new \LogicException('Badnik manager is closed');
		}

		$id = $this->registry->create($badnik, $behavior, $sprite);
		$this->owned[$id] = true;
		$this->badniks[$id] = [$badnik->x, $badnik->y - $badnik->height / 2];

		return $id;
	}

	/**
	 * Create an owned projectile with a caller-selected sprite.
	 *
	 * @param BadnikProjectile $projectile Owner-tagged delayed ballistic state.
	 * @param Sprite $sprite Visible projectile centered on the component position.
	 * @return int New projectile entity ID.
	 */
	public function projectile(BadnikProjectile $projectile, Sprite $sprite): int
	{
		if($this->closed)
		{
			throw new \LogicException('Badnik manager is closed');
		}

		$sprite->x = $projectile->x;
		$sprite->y = $projectile->y;
		$id = $this->registry->create($projectile, $sprite);
		$this->owned[$id] = true;

		return $id;
	}

	/**
	 * Advance mercy, projectiles, continuous player contacts, and explosion lifetimes.
	 *
	 * @param Registry $registry Bound registry supplied by the scheduler.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Mutates gameplay components and retires expired owned entities.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Badnik update requires its registry and a positive delta');
		}

		$playerIds = $registry->query(Query::all(Body::class, PlayerCombat::class));

		foreach($playerIds as $id)
		{
			$registry->get($id, PlayerCombat::class)->update($dt);
		}

		$this->updateProjectiles($playerIds, $dt);
		$this->updateVehicleProjectiles();
		$this->updateContacts($playerIds);
		$this->updateExplosions($dt);
		$active = [];

		foreach($playerIds as $id)
		{
			if($registry->alive($id))
			{
				$body = $registry->get($id, Body::class);
				$active[$id] = $this->players[$id] = [$body->x, $body->y];
			}
		}

		$this->players = array_intersect_key($this->players, $active);
	}

	/**
	 * Pop the first badnik crossed by each rider-owned vehicle projectile.
	 *
	 * @return void Destroys each hitting shot and awards its rider through the common pop path.
	 */
	private function updateVehicleProjectiles(): void
	{
		foreach($this->registry->query(VehicleProjectile::class) as $shotId)
		{
			$shot = $this->registry->get($shotId, VehicleProjectile::class);

			foreach($this->registry->query(Badnik::class) as $badnikId)
			{
				$badnik = $this->registry->get($badnikId, Badnik::class);
				$center = [$badnik->x, $badnik->y - $badnik->height / 2];
				$from = $this->badniks[$badnikId] ?? $center;

				if(!$this->swept(
					[$shot->previousX, $shot->previousY]
					, [$shot->x, $shot->y]
					, $from
					, $center
					, $shot->radius + $badnik->width / 2
					, $shot->radius + $badnik->height / 2
				)) continue;

				$this->pop($badnikId, $shot->attacker);

				if($this->registry->alive($shotId)) $this->registry->destroy($shotId);

				continue 2;
			}
		}
	}

	/**
	 * Reset one player's sweep origin after a spawn, teleport, or level change.
	 *
	 * @param int $entity Player entity owning the supplied body.
	 * @param Body $body Body supplying the new world-space center.
	 * @return void Prevents the teleport path from touching badniks or their projectiles.
	 */
	public function sync(int $entity, Body $body): void
	{
		$this->players[$entity] = [$body->x, $body->y];
	}

	/**
	 * Count live generic badnik gameplay objects owned by the registry.
	 *
	 * @return array{badniks:int,projectiles:int,explosions:int} Current component totals.
	 */
	public function counts(): array
	{
		return [
			'badniks' => count($this->registry->query(Badnik::class))
			, 'projectiles' => count($this->registry->query(BadnikProjectile::class))
			, 'explosions' => count($this->registry->query(BadnikExplosion::class))
		];
	}

	/**
	 * Pop one live badnik, award its attacker, and retain an optional animated explosion.
	 *
	 * @param int $entity Entity containing the generic Badnik component.
	 * @param int|null $attacker Optional player entity receiving score and a rebound.
	 * @return bool True only for the first successful pop of a live badnik.
	 */
	public function pop(int $entity, ?int $attacker = null): bool
	{
		if(!$this->registry->has($entity, Badnik::class))
		{
			return false;
		}

		$badnik = $this->registry->get($entity, Badnik::class);

		if($attacker !== null && $this->registry->alive($attacker)
			&& $this->registry->has($attacker, PlayerCombat::class)
		){
			$combat = $this->registry->get($attacker, PlayerCombat::class);
			$combat->defeats++;

			if($this->registry->has($attacker, RingCounter::class))
			{
				$this->registry->get($attacker, RingCounter::class)->score += $badnik->points;
			}

			if($this->registry->has($attacker, Body::class))
			{
				$this->rebound(
					Vehicle::forRider($this->registry, $attacker)?->body ?? $this->registry->get($attacker, Body::class)
				);
			}

			if($this->registry->has($attacker, Rumble::class))
			{
				$this->registry->get($attacker, Rumble::class)->pop();
			}
		}

		$this->registry->remove($entity, BadnikBehavior::class);
		$this->registry->remove($entity, Badnik::class);
		$this->registry->remove($entity, Sprite::class);
		unset($this->badniks[$entity]);

		if($badnik->explosionGid > 0)
		{
			$this->registry->add(
				$entity
				, new Sprite($badnik->explosionGid, $badnik->x, $badnik->y - $badnik->height / 2, .5, .5)
			);

			$this->registry->add($entity, new BadnikExplosion);
		}
		else
		{
			$this->registry->destroy($entity);
			unset($this->owned[$entity]);
		}

		return true;
	}

	/**
	 * Move ballistic shots with continuous terrain and player collision.
	 *
	 * @param list<int> $playerIds Current player entities.
	 * @param float $dt Fixed simulation delta in seconds.
	 * @return void Destroys shots on their first terrain or player contact or at their lifetime.
	 */
	private function updateProjectiles(array $playerIds, float $dt): void
	{
		foreach($this->registry->query(BadnikProjectile::class) as $id)
		{
			$projectile = $this->registry->get($id, BadnikProjectile::class);
			$from = [$projectile->x, $projectile->y];
			$start = $projectile->age;
			$projectile->age = min($projectile->lifetime, $projectile->age + $dt);
			$active = max(0, $projectile->age - max($start, $projectile->delay));

			if($active > 0)
			{
				$dx = $projectile->vx * $active;
				$dy = $projectile->vy * $active + .5 * $projectile->gravity * $active * $active;
				$probe = new Body($projectile->x, $projectile->y);
				$probe->radius = $probe->halfHeight = $projectile->radius;
				$probe->collisionLayer = $projectile->collisionLayer;

				if($this->terrain->castBody($probe, $dx, $dy) !== null)
				{
					$this->retire($id);

					continue;
				}

				$projectile->x += $dx;
				$projectile->y += $dy;
				$projectile->vy += $projectile->gravity * $active;
			}

			if(!$this->registry->alive($id))
			{
				continue;
			}

			$sprite = $this->registry->get($id, Sprite::class);
			$sprite->x = $projectile->x;
			$sprite->y = $projectile->y;

			foreach($playerIds as $player)
			{
				if(!$this->registry->alive($player))
				{
					continue;
				}

				$body = $this->registry->get($player, Body::class);
				$playerFrom = $this->players[$player] ?? [$body->x, $body->y];

				if(!$this->intersectsProjectile($body, $playerFrom, $projectile, $from))
				{
					continue;
				}

				if($this->registry->has($projectile->owner, Badnik::class))
				{
					$this->damage($player, $body, $projectile->x, $projectile->damageType);
				}

				$this->retire($id);

				continue 2;
			}

			if($projectile->age >= $projectile->lifetime)
			{
				$this->retire($id);
			}
		}
	}

	/**
	 * Resolve player movement against each live badnik using relative swept bounds.
	 *
	 * @param list<int> $playerIds Current player entities.
	 * @return void Pops badniks for attack poses and damages other players subject to mercy.
	 */
	private function updateContacts(array $playerIds): void
	{
		$badnikIds = $this->registry->query(Badnik::class);
		$active = [];

		foreach($badnikIds as $id)
		{
			$badnik = $this->registry->get($id, Badnik::class);
			$center = [$badnik->x, $badnik->y - $badnik->height / 2];
			$from = $this->badniks[$id] ?? $center;
			$active[$id] = $center;

			foreach($playerIds as $player)
			{
				if(!$this->registry->alive($player))
				{
					continue;
				}

				$body = $this->registry->get($player, Body::class);
				$playerFrom = $this->players[$player] ?? [$body->x, $body->y];

				$vehicle = Vehicle::forRider($this->registry, $player);
				$melee = $vehicle !== null && $this->registry->has($vehicle->entity, VehicleMelee::class)
					? $this->registry->get($vehicle->entity, VehicleMelee::class)
					: null;

				$chassisHit = $vehicle !== null && $this->intersectsBadnik(
					$vehicle->body
					, [$vehicle->previousX, $vehicle->previousY]
					, $badnik
					, $from
				);

				$attackHit = $vehicle !== null && $melee?->attacking()
					&& $this->intersectsBadnik(
						$this->attackBody($vehicle, $melee)
						, $this->attackFrom($vehicle, $melee)
						, $badnik
						, $from
					);

				if(!$attackHit && !$chassisHit && !$this->intersectsBadnik($body, $playerFrom, $badnik, $from))
				{
					continue;
				}

				$combat = $this->registry->get($player, PlayerCombat::class);

				if($attackHit || $combat->attacking($vehicle?->body ?? $body))
				{
					$this->pop($id, $player);

					continue 2;
				}

				$shielded = $combat->mercy <= 0
					&& $this->registry->has($player, Shield::class)
					&& $this->registry->get($player, Shield::class)->active !== '';

				$this->damage($player, $body, $badnik->x);

				if($shielded)
				{
					$this->pop($id, $player);

					continue 2;
				}
			}
		}

		$this->badniks = array_intersect_key($active, array_fill_keys($this->registry->query(Badnik::class), true));
	}

	/**
	 * Build a one-sided surface-relative melee volume at its current endpoint.
	 *
	 * @param Vehicle $vehicle Chassis supplying support point, orientation, and facing.
	 * @param VehicleMelee $melee Capability supplying reach, thickness, and outward offset.
	 * @return Body Oriented collision body covering the configured attack volume.
	 */
	private function attackBody(Vehicle $vehicle, VehicleMelee $melee): Body
	{
		$body = clone $vehicle->body;
		$direction = $body->direction ?: $body->facing ?: 1;
		$tangent = $body->radius + $melee->reach / 2;
		$body->x = $body->supportX()
			+ cos($body->angle) * $direction * $tangent
			+ sin($body->angle) * $melee->normalOffset;

		$body->y = $body->supportY()
			+ sin($body->angle) * $direction * $tangent
			- cos($body->angle) * $melee->normalOffset;

		$body->radius = $melee->reach / 2;
		$body->halfHeight = $melee->height / 2;

		return $body;
	}

	/**
	 * Return the melee volume's previous center for relative continuous collision.
	 *
	 * @param Vehicle $vehicle Chassis supplying previous and current positions.
	 * @param VehicleMelee $melee Capability used to reconstruct the current attack volume.
	 * @return array{float,float} Previous world-space center approximated from chassis movement.
	 */
	private function attackFrom(Vehicle $vehicle, VehicleMelee $melee): array
	{
		$body = $this->attackBody($vehicle, $melee);

		return [
			$body->x + $vehicle->previousX - $vehicle->body->x
			, $body->y + $vehicle->previousY - $vehicle->body->y
		];
	}

	/**
	 * Apply player damage using optional ring inventory and contact-side knockback.
	 *
	 * @param int $player Player entity owning combat state and the supplied body.
	 * @param Body $body Player movement body at contact.
	 * @param float $sourceX Horizontal position of the damaging badnik or projectile.
	 * @param string $damageType Nonempty category used by shield immunity rules.
	 * @return bool True when mercy allowed this hit to change player state.
	 */
	private function damage(
		int $player
		, Body $body
		, float $sourceX
		, string $damageType = 'normal'
	): bool {
		$counter = $this->registry->has($player, RingCounter::class)
			? $this->registry->get($player, RingCounter::class)
			: null;

		$combat = $this->registry->get($player, PlayerCombat::class);
		$shield = $this->registry->has($player, Shield::class)
			? $this->registry->get($player, Shield::class)
			: null;

		if($combat->mercy <= 0 && $counter !== null && $counter->rings > 0
			&& !($shield?->protects($damageType) ?? false)
		){
			$this->rings?->scatter($player, $body, $counter);
		}

		$direction = $body->x < $sourceX ? -1 : 1;

		$damaged = $combat->damage($body, $counter, $direction, $shield, $damageType);

		if($damaged && $this->registry->has($player, Combo::class))
		{
			$this->registry->get($player, Combo::class)->fail();
		}

		if($damaged && $this->registry->has($player, Rumble::class))
		{
			$this->registry->get($player, Rumble::class)->playerDamage();
		}

		return $damaged;
	}

	/**
	 * Bounce an airborne attacker away from a popped badnik while preserving horizontal motion.
	 *
	 * @param Body $body Attacking player body.
	 * @return void Reverses a downward hit or softens an upward hit using reference-scale speeds.
	 */
	private function rebound(Body $body): void
	{
		if($body->grounded)
		{
			return;
		}

		$body->vy = $body->vy >= 0 ? min(-$body->vy, -420) : $body->vy + 480;
	}

	/**
	 * Fade and retire finished badnik explosions.
	 *
	 * @param float $dt Fixed simulation delta in seconds.
	 * @return void Updates opacity and destroys each effect at its exact configured lifetime.
	 */
	private function updateExplosions(float $dt): void
	{
		foreach($this->registry->query(BadnikExplosion::class) as $id)
		{
			$explosion = $this->registry->get($id, BadnikExplosion::class);
			$explosion->age = min($explosion->lifetime, $explosion->age + $dt);

			if($explosion->age >= $explosion->lifetime)
			{
				$this->retire($id);

				continue;
			}

			$this->registry->get($id, Sprite::class)->opacity = 1 - $explosion->age / $explosion->lifetime;
		}
	}

	/**
	 * Test player and badnik relative motion against their combined axis-aligned extents.
	 *
	 * @param Body $body Player at the current endpoint.
	 * @param array{float,float} $playerFrom Player center at the previous fixed update.
	 * @param Badnik $badnik Current bottom-anchored badnik bounds.
	 * @param array{float,float} $badnikFrom Badnik center at the previous fixed update.
	 * @return bool True when either moving rectangle crosses the other during this update.
	 */
	private function intersectsBadnik(Body $body, array $playerFrom, Badnik $badnik, array $badnikFrom): bool
	{
		[$halfWidth, $halfHeight] = $this->bodyExtents($body);

		return $this->swept(
			$playerFrom
			, [$body->x, $body->y]
			, $badnikFrom
			, [$badnik->x, $badnik->y - $badnik->height / 2]
			, $halfWidth + $badnik->width / 2
			, $halfHeight + $badnik->height / 2
		);
	}

	/**
	 * Test relative player and projectile motion without losing fast crossings between frames.
	 *
	 * @param Body $body Player at the current endpoint.
	 * @param array{float,float} $playerFrom Player center at the previous fixed update.
	 * @param BadnikProjectile $projectile Projectile at its current endpoint.
	 * @param array{float,float} $projectileFrom Projectile center before this update.
	 * @return bool True when the swept player bounds touch the projectile circle's square extent.
	 */
	private function intersectsProjectile(
		Body $body
		, array $playerFrom
		, BadnikProjectile $projectile
		, array $projectileFrom
	): bool {
		[$halfWidth, $halfHeight] = $this->bodyExtents($body);

		return $this->swept(
			$playerFrom
			, [$body->x, $body->y]
			, $projectileFrom
			, [$projectile->x, $projectile->y]
			, $halfWidth + $projectile->radius
			, $halfHeight + $projectile->radius
		);
	}

	/**
	 * Convert the player's oriented body to conservative world-axis extents.
	 *
	 * @param Body $body Oriented player collision body.
	 * @return array{float,float} Horizontal and vertical half extents in world axes.
	 */
	private function bodyExtents(Body $body): array
	{
		$cos = abs(cos($body->angle));
		$sin = abs(sin($body->angle));

		return [
			$cos * $body->radius + $sin * $body->halfHeight
			, $sin * $body->radius + $cos * $body->halfHeight
		];
	}

	/**
	 * Intersect relative center movement with a combined axis-aligned rectangle.
	 *
	 * @param array{float,float} $aFrom First center before the update.
	 * @param array{float,float} $aTo First center after the update.
	 * @param array{float,float} $bFrom Second center before the update.
	 * @param array{float,float} $bTo Second center after the update.
	 * @param float $halfWidth Positive combined horizontal half extent.
	 * @param float $halfHeight Positive combined vertical half extent.
	 * @return bool True when relative motion enters the rectangle during the normalized interval.
	 */
	private function swept(
		array $aFrom
		, array $aTo
		, array $bFrom
		, array $bTo
		, float $halfWidth
		, float $halfHeight
	): bool {
		$origin = [$aFrom[0] - $bFrom[0], $aFrom[1] - $bFrom[1]];
		$movement = [
			($aTo[0] - $aFrom[0]) - ($bTo[0] - $bFrom[0])
			, ($aTo[1] - $aFrom[1]) - ($bTo[1] - $bFrom[1])
		];

		$entry = 0.0;
		$exit = 1.0;

		foreach([[$origin[0], $movement[0], $halfWidth], [$origin[1], $movement[1], $halfHeight]] as [
			$position
			, $delta
			, $extent
		]){
			if(abs($delta) < 1e-9)
			{
				if(abs($position) > $extent)
				{
					return false;
				}

				continue;
			}

			$near = (-$extent - $position) / $delta;
			$far = ($extent - $position) / $delta;

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
	 * Destroy one owned live entity and clear all sweep bookkeeping for its ID.
	 *
	 * @param int $id Owned entity to retire if it remains live.
	 * @return void Leaves unknown or already removed IDs unchanged.
	 */
	private function retire(int $id): void
	{
		if($this->registry->alive($id))
		{
			$this->registry->destroy($id);
		}

		unset($this->owned[$id], $this->badniks[$id]);
	}

	/**
	 * Destroy every live badnik, projectile, and explosion owned by this level.
	 *
	 * @return void Clears sweep histories and makes repeated cleanup safe.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach(array_keys($this->owned) as $id)
		{
			$this->retire($id);
		}

		$this->players = $this->badniks = [];
	}
}

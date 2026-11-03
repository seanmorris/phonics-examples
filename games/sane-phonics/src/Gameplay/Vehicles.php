<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use Phonics\Ecs\Query;
use Phonics\Input\Controls;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use SanePhonics\Physics\Platformer;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteLayers;
use Phonics\Tiled\TileMap;

/** Own vehicle physics, single-seat associations, swept boarding, controls, and level teardown. */
final class Vehicles
{
	/** @var array<int,Vehicle> Owned vehicle components keyed by live entity ID. */
	public array $vehicles = [];
	private array $previous = [], $pending = [], $art = [], $regions = [], $retired = [];
	private VehicleWeapons $weapons;
	private bool $closed = false;

	/**
	 * Bind vehicles to the same terrain and movement solver used by the characters.
	 *
	 * @param Registry $registry Registry containing opt-in Body plus Rider entities.
	 * @param Terrain $terrain Shared terrain for chassis and passenger clearance casts.
	 * @param Platformer $movement Shared solver including collision switches and water.
	 * @param TileMap|null $map Optional Tiled vehicle objects, regions, and annotated artwork.
	 * @return void Subscribes to component removal and rolls back owned entities if loading fails.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, private Platformer $movement
		, ?TileMap $map = null
	){
		$registry->on('removed', [$this, 'removed']);
		$this->weapons = new VehicleWeapons($registry, $terrain);

		try
		{
			if($map !== null) (new VehicleLoader)->load($this, $map);
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	/**
	 * Register an independently simulated chassis and optional behavior and artwork.
	 *
	 * @param Vehicle $vehicle Unregistered seat and chassis configuration.
	 * @param VehicleBehavior|null $behavior Optional vehicle-specific control and scoring component.
	 * @param Sprite|null $sprite Optional bottom-centered chassis sprite.
	 * @return int New entity containing Vehicle, Body, and any supplied behavior or sprite.
	 */
	public function add(Vehicle $vehicle, ?VehicleBehavior $behavior = null, ?Sprite $sprite = null): int
	{
		if($this->closed || $vehicle->entity !== 0 || $vehicle->occupant !== null)
		{
			throw new \LogicException('A vehicle needs a new component and an open owner');
		}

		$id = $vehicle->entity = $this->registry->create($vehicle, $vehicle->body);
		$this->vehicles[$id] = $vehicle;

		if($behavior instanceof VehicleTerrainAware) $behavior->bindTerrain($this->terrain);

		if($behavior !== null)
		{
			$this->registry->add($id, $behavior);

			if($behavior instanceof VehicleComponents)
			foreach($behavior->vehicleComponents() as $component)
			$this->registry->add($id, $component);
		}

		if($sprite !== null)
		{
			$this->art[$id] = [$sprite->rotation, $sprite->flipX];
			$this->registry->add($id, $sprite);
		}

		$this->draw($vehicle);

		return $id;
	}

	/**
	 * Acquire an empty seat while keeping all character components on their original entity.
	 *
	 * @param int $player Entity with Body and Rider components.
	 * @param int $id Owned vehicle entity to board.
	 * @return bool True when the seat and passenger clearance checks permitted attachment.
	 */
	public function mount(int $player, int $id): bool
	{
		$vehicle = $this->vehicles[$id] ?? null;

		if($this->closed || $vehicle === null || !$vehicle->enabled || $vehicle->occupant !== null
			|| !$this->registry->has($player, Body::class) || !$this->registry->has($player, Rider::class)
		)
		return false;

		$rider = $this->registry->get($player, Rider::class);
		$body = $this->registry->get($player, Body::class);

		if($rider->vehicle !== null || isset($rider->cooldowns[$id]) || $body->ability !== ''
			|| ($vehicle->body->collisionLayer !== 0 && $vehicle->body->collisionLayer !== $body->collisionLayer)
		)
		return false;

		$probe = clone $body;
		$probe->halfHeight = $probe->standingHeight;
		[$x, $y, $angle] = $vehicle->seat($probe);
		$probe->angle = $angle;

		if(!$this->terrain->canPlace($probe)) return false;

		// This full alignment cast prevents a seat snap through a thin wall or ceiling.
		if($this->terrain->castBody($probe, $x - $probe->x, $y - $probe->y) !== null) return false;

		$probe->x = $x;
		$probe->y = $y;

		if(!$this->terrain->canPlace($probe)) return false;

		$behavior = $this->behavior($vehicle);
		$vehicle->occupant = $player;
		$rider->vehicle = $id;

		try
		{
			$behavior?->mounted($vehicle, $body);
		}
		catch(\Throwable $error)
		{
			$vehicle->occupant = $rider->vehicle = null;
			throw $error;
		}

		$vehicle->drive->reset();
		$body->halfHeight = $body->standingHeight;
		$body->charge = $body->dropCharge = $body->crouchTime = 0;
		$body->coyote = $body->jumpBuffer = $body->dropTimer = 0;

		$body->wall = $body->glideDirection = 0;
		$body->rolling = $body->jumping = $body->crouching = $body->lightDashed = false;
		$body->ability = 'riding';

		if($this->registry->has($id, SpriteLayers::class))
		{
			$this->registry->get($id, SpriteLayers::class)->anchor = $player;
		}

		$this->place($vehicle, $body);

		return true;
	}

	/**
	 * Route one simulation step's complete logical controls to the occupied vehicle.
	 *
	 * @param int $player Input-owning character entity.
	 * @param Controls $input Current numbered buttons, edges, and signed directional strengths.
	 * @param float $dt Positive simulation delta used for validation.
	 * @return bool True if the vehicle owned this input, including a consumed dismount press.
	 */
	public function control(int $player, Controls $input, float $dt): bool
	{
		if(!is_finite($dt) || $dt <= 0) throw new \InvalidArgumentException('Invalid vehicle control delta');

		$vehicle = $this->ridden($player);

		if($vehicle === null) return false;

		$body = $this->registry->get($player, Body::class);

		if($body->ability !== 'riding')
		{
			$this->dismount($player, 'damage');

			return false;
		}

		if($input->pressed(0) && ($input->down('up') || $vehicle->quickDrop)
			&& $this->dismount($player, 'jump')
		){
			return true;
		}

		$this->pending[$vehicle->entity] = clone $input;

		return true;
	}

	/**
	 * End an association with an explicit reference-compatible exit policy.
	 *
	 * @param int $player Rider to detach.
	 * @param string $reason Jump, transfer, damage, reset, or removed; only jump respects lockedIn.
	 * @return bool True when detached; a blocked voluntary exit leaves the passenger seated.
	 */
	public function dismount(int $player, string $reason = 'jump'): bool
	{
		if(!in_array($reason, ['jump', 'transfer', 'damage', 'reset', 'removed'], true))
		{
			throw new \InvalidArgumentException('Unknown vehicle exit reason');
		}

		$vehicle = $this->ridden($player);

		if($vehicle === null) return false;

		$body = $this->registry->get($player, Body::class);

		if($reason === 'jump')
		{
			$probe = clone $body;
			$probe->angle = 0;

			if($vehicle->lockedIn || !$this->terrain->canPlace($probe)
				|| $this->terrain->castBody($probe, 0, -16) !== null
			) return false;

			$body->y -= 16;
			$body->vx = $vehicle->quickDrop
				? $body->vx
				: $vehicle->body->direction * 120 * -(($vehicle->seatAngle ?: -1) <=> 0);

			$body->vy = -$body->jumpSpeed + ($vehicle->quickDrop ? max(0, $vehicle->body->vy) : 0);
			$body->grounded = false;
			$body->jumping = true;
			$body->angle = $body->groundSpeed = $body->coyote = $body->jumpBuffer = 0;
			$vehicle->body->vy = 0;
		}
		elseif($reason === 'transfer' || $reason === 'removed')
		{
			$body->vx = $vehicle->body->vx;
			$body->vy = $vehicle->body->vy;
			$body->groundSpeed = 0;
			$body->grounded = $body->jumping = false;
			$body->angle = 0;
		}

		$this->detach($player, $this->registry->get($player, Rider::class), $vehicle);

		if($reason === 'transfer') $this->transfer($vehicle, $body);

		return true;
	}

	/**
	 * Transfer a departing rider to the chassis surface and motion when terrain permits it.
	 *
	 * @param Vehicle $vehicle Previous carrier supplying its wheel contact and surface state.
	 * @param Body $body Detached character, initially falling from its old seat.
	 * @return void Preserves a safe ground or rail exit; obstructed handoffs keep the current fall.
	 */
	private function transfer(Vehicle $vehicle, Body $body): void
	{
		$chassis = $vehicle->body;
		$probe = clone $body;
		$probe->angle = $chassis->angle;
		$x = $chassis->supportX() + sin($probe->angle) * $probe->halfHeight;
		$y = $chassis->supportY() - cos($probe->angle) * $probe->halfHeight;
		$hit = $this->terrain->castBody($probe, $x - $body->x, $y - $body->y);
		$probe->x = $x;
		$probe->y = $y;

		if(($hit !== null && $hit->t < 1 - 1e-8) || !$this->terrain->canPlace($probe)) return;

		$body->x = $x;
		$body->y = $y;
		$body->angle = $chassis->angle;
		$body->groundSpeed = $chassis->groundSpeed;
		$body->grounded = $chassis->grounded;
		$body->grinding = $chassis->grinding;
		$body->jumping = $chassis->jumping;
	}

	/**
	 * Simulate each chassis once, carry its rider through casts, and detect swept seat contacts.
	 *
	 * @param Registry $registry The registry supplied at construction.
	 * @param float $dt Positive finite simulation seconds.
	 * @return void Consumes routed controls and updates owned bodies, sprites, and rider associations.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed) return;

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Invalid vehicle update');
		}

		foreach(array_keys($this->retired) as $id)
		{
			unset($this->retired[$id]);

			if($registry->alive($id)) $registry->destroy($id);
		}

		$this->weapons->update($dt);

		$players = $registry->query(Query::all(Body::class, Rider::class));

		foreach($players as $player)
		{
			$rider = $registry->get($player, Rider::class);

			foreach($rider->cooldowns as $id => $time)
			{
				if(($rider->cooldowns[$id] = $time - $dt) <= 1e-9) unset($rider->cooldowns[$id]);
			}

			if($rider->vehicle !== null && $registry->get($player, Body::class)->ability !== 'riding')
			{
				$this->dismount($player, 'damage');
			}
		}

		foreach($this->vehicles as $id => $vehicle)
		{
			if(!$vehicle->enabled)
			{
				if($vehicle->occupant !== null) $this->dismount($vehicle->occupant, 'removed');

				continue;
			}

			$from = clone $vehicle->body;
			$vehicle->previousX = $from->x;
			$vehicle->previousY = $from->y;
			$input = $this->pending[$id] ?? new Controls;
			$combo = $vehicle->occupant !== null && $registry->has($vehicle->occupant, Combo::class)
				? $registry->get($vehicle->occupant, Combo::class)
				: null;

			$behavior = $this->behavior($vehicle);
			$visualAngle = $vehicle->visualAngle;
			$behavior?->control($vehicle, $input, $combo, $dt);
			$this->weapons->control($vehicle, $input, $dt);
			$this->safeVisualAngle($vehicle, $visualAngle);

			$values = [
				'left' => $input->value('left')
				, 'right' => $input->value('right')
				, 0 => $vehicle->jumpEnabled ? $input->value(0) : 0
			];

			// Preserve the original button edge even when boarding with jump already held.
			$previous = $values;
			$previous[0] = $input->pressed(0) ? 0 : ($input->released(0) ? 1 : $input->value(0));
			$vehicle->drive->set($previous);
			$vehicle->drive->set($values);
			$this->move($vehicle, $values, $dt);
			$visualAngle = $vehicle->visualAngle;
			$behavior?->updated($vehicle, $combo, $dt);
			$this->safeVisualAngle($vehicle, $visualAngle);

			if($vehicle->occupant !== null)
			{
				$this->place($vehicle, $registry->get($vehicle->occupant, Body::class));

				if($combo !== null)
				{
					$scoring = clone $vehicle->body;
					$scoring->grounded = $scoring->grounded && !$vehicle->holdCombo;
					$scoring->grinding = $scoring->grinding && $vehicle->scoreGrinding;
					$score = $combo->update($scoring, $dt);

					if($score !== null && $registry->has($vehicle->occupant, RingCounter::class))
					{
						$registry->get($vehicle->occupant, RingCounter::class)->score += $score;
					}
				}
			}
			else
			{
				foreach($players as $player)
				{
					if($this->contact($player, $vehicle, $from) && $this->mount($player, $id)) break;
				}
			}

			$this->draw($vehicle);
		}

		$this->pending = [];
		$this->updateRegions($players);
		$this->previous = [];

		foreach($players as $player) $this->sync($player, $registry->get($player, Body::class));
	}

	/**
	 * Keep a presentation-only chassis rotation from sweeping its passenger into terrain.
	 *
	 * @param Vehicle $vehicle Occupied vehicle whose behavior may have changed visual pitch.
	 * @param float $fallback Last accepted visual angle in radians.
	 * @return void Retains the requested angle when the complete rider pose is clear, otherwise
	 * restores the previous presentation until the chassis gains room.
	 */
	private function safeVisualAngle(Vehicle $vehicle, float $fallback): void
	{
		if($vehicle->occupant === null || $vehicle->visualAngle === $fallback) return;

		$rider = $this->registry->get($vehicle->occupant, Body::class);
		[$x, $y, $angle] = $vehicle->seat($rider);
		$probe = clone $rider;
		$probe->angle = $angle;
		$probe->collisionLayer = $vehicle->body->collisionLayer;
		$hit = $this->terrain->castBody($probe, $x - $probe->x, $y - $probe->y);
		$probe->x = $x;
		$probe->y = $y;

		if($hit !== null || !$this->terrain->canPlace($probe)) $vehicle->visualAngle = $fallback;
	}

	/**
	 * Divide curved travel into short arcs and sweep each passenger through its full displacement.
	 *
	 * @param Vehicle $vehicle Chassis and optional passenger being transported.
	 * @param array<int|string,float> $values Held drive strengths without character-only actions.
	 * @param float $dt Positive simulation seconds for the complete step.
	 * @return void Stops both bodies at blocked passenger clearance without teleporting through geometry.
	 */
	private function move(Vehicle $vehicle, array $values, float $dt): void
	{
		$body = $vehicle->body;
		$speed = max(abs($body->groundSpeed), hypot($body->vx, $body->vy)) + $body->gravity * $dt;
		$steps = max(1, min(1024, (int)ceil($speed * $dt / 4)));

		for($step = 0; $step < $steps; $step++)
		{
			$before = clone $body;
			$this->movement->step($body, $vehicle->drive, $dt / $steps);
			$vehicle->drive->set($values);

			if($vehicle->occupant === null) continue;

			$rider = $this->registry->get($vehicle->occupant, Body::class);
			[$x, $y, $angle] = $vehicle->seat($rider);
			$probe = clone $rider;
			$probe->angle = $angle;
			$probe->collisionLayer = $body->collisionLayer;
			$hit = $this->terrain->castBody($probe, $x - $probe->x, $y - $probe->y);
			$probe->x = $x;
			$probe->y = $y;

			if($hit !== null || !$this->terrain->canPlace($probe))
			{
				foreach(get_object_vars($before) as $key => $value) $body->$key = $value;
				$body->vx = $body->vy = $body->groundSpeed = 0;
				break;
			}

			$this->place($vehicle, $rider);
		}
	}

	/**
	 * Synchronize a passenger's pose and observable motion with its occupied seat.
	 *
	 * @param Vehicle $vehicle Current movement authority.
	 * @param Body $body Passenger body kept out of independent character movement.
	 * @return void Copies surface, motion, water, and facing state without replacing character tuning.
	 */
	private function place(Vehicle $vehicle, Body $body): void
	{
		[$body->x, $body->y, $body->angle] = $vehicle->seat($body);

		foreach([
			'vx'
			, 'vy'
			, 'groundSpeed'
			, 'grounded'
			, 'grinding'
			, 'collisionLayer'
			, 'facing'
			, 'direction'
			, 'water'
			, 'underwater'
			, 'waterDepth'
			, 'waterEntered'
			, 'waterExited'
			, 'skimming'
		] as $key)
		{
			$body->$key = $vehicle->body->$key;
		}

		$body->jumping = $body->rolling = false;
		$body->crouching = $vehicle->pose === 'riding-crouching';
	}

	/**
	 * Reconcile seats after moving platforms or other systems transport the chassis.
	 *
	 * @param Registry $registry Registry supplied at construction.
	 * @param float $dt Positive simulation delta used by the scheduler.
	 * @return void Moves passengers with clearance casts and refreshes artwork without advancing physics.
	 */
	public function carry(Registry $registry, float $dt): void
	{
		if($this->closed) return;

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Invalid vehicle carry update');
		}

		foreach($this->vehicles as $vehicle)
		{
			if($vehicle->occupant !== null)
			{
				$player = $vehicle->occupant;
				$body = $registry->get($player, Body::class);

				if($body->ability !== 'riding')
				{
					$this->dismount($player, 'damage');
					continue;
				}

				[$x, $y, $angle] = $vehicle->seat($body);
				$probe = clone $body;
				$probe->angle = $angle;
				$hit = $this->terrain->castBody($probe, $x - $body->x, $y - $body->y);
				$probe->x = $x;
				$probe->y = $y;

				if($hit !== null || !$this->terrain->canPlace($probe)) $this->dismount($player, 'transfer');
				else $this->place($vehicle, $body);

				$this->sync($player, $body);
			}

			$this->draw($vehicle);
		}
	}

	/**
	 * Detect a descending relative crossing of the seat between two complete simulation poses.
	 *
	 * @param int $player Candidate passenger with a remembered previous center.
	 * @param Vehicle $vehicle Empty moving vehicle.
	 * @param Body $from Chassis pose before its current simulation step.
	 * @return bool True for an eligible top crossing within the deck's lateral bounds.
	 */
	private function contact(int $player, Vehicle $vehicle, Body $from): bool
	{
		$body = $this->registry->get($player, Body::class);

		if($body->ability !== '' || $body->vy < 0) return false;

		[$px, $py] = $this->previous[$player] ?? [$body->x, $body->y];
		$oldSeat = $from->supportY() - $vehicle->seatHeight;
		$newSeat = $vehicle->body->supportY() - $vehicle->seatHeight;
		$start = $py + $body->halfHeight - $oldSeat;
		$end = $body->supportY() - $newSeat;

		if($start > 2 || $end < -2 || $end <= $start) return false;

		$t = $end === $start ? 1 : max(0, min(1, -$start / ($end - $start)));
		$x = $px + ($body->x - $px) * $t;
		$vx = $from->x + ($vehicle->body->x - $from->x) * $t;

		return abs($x - $vx) <= $vehicle->body->radius + $body->radius;
	}

	/**
	 * Remember a character's current pose after a spawn, teleport, or explicit reset.
	 *
	 * @param int $player Character entity ID.
	 * @param Body $body Body at its new position.
	 * @return void Discards any sweep through the teleport path.
	 */
	public function sync(int $player, Body $body): void { $this->previous[$player] = [$body->x, $body->y]; }

	/**
	 * Find the vehicle whose association still matches this live rider.
	 *
	 * @param int $player Character entity ID.
	 * @return Vehicle|null Live mutually associated vehicle, or null when unmounted.
	 */
	public function ridden(int $player): ?Vehicle
	{
		if(!$this->registry->has($player, Rider::class) || !$this->registry->has($player, Body::class)) return null;

		$id = $this->registry->get($player, Rider::class)->vehicle;
		$vehicle = $this->vehicles[$id] ?? null;

		return $vehicle !== null && $vehicle->occupant === $player ? $vehicle : null;
	}

	/**
	 * Read an optional vehicle-specific behavior from the ECS type index.
	 *
	 * @param Vehicle $vehicle Owned chassis to inspect.
	 * @return VehicleBehavior|null Installed behavior or null for generic driving.
	 */
	private function behavior(Vehicle $vehicle): ?VehicleBehavior
	{
		return $this->registry->has($vehicle->entity, VehicleBehavior::class)
			? $this->registry->get($vehicle->entity, VehicleBehavior::class)
			: null;
	}

	/**
	 * Clear both ends of a seat association without replacing externally applied motion.
	 *
	 * @param int $player Passenger entity, possibly undergoing component removal.
	 * @param Rider $rider Association being released.
	 * @param Vehicle $vehicle Previous movement authority.
	 * @return void Releases controls and starts a short per-seat reboarding delay.
	 */
	private function detach(int $player, Rider $rider, Vehicle $vehicle): void
	{
		$rider->vehicle = null;
		$rider->cooldowns[$vehicle->entity] = .25;
		$vehicle->occupant = null;
		$vehicle->drive->reset();
		unset($this->pending[$vehicle->entity]);

		if($this->registry->has($vehicle->entity, SpriteLayers::class))
		{
			$this->registry->get($vehicle->entity, SpriteLayers::class)->anchor = null;
		}

		if($this->registry->has($player, Body::class))
		{
			$body = $this->registry->get($player, Body::class);

			if($body->ability === 'riding') $body->ability = '';

			$body->grinding = $body->crouching = false;
		}
	}

	/**
	 * React immediately when application code removes a chassis, rider, or movement body.
	 *
	 * @param Registry $registry Registry emitting a component removal event.
	 * @param int $entity Entity whose component was removed.
	 * @param object $component Removed component instance, still available during notification.
	 * @return void Clears stale associations and retires owned vehicle entities.
	 */
	public function removed(Registry $registry, int $entity, object $component): void
	{
		if($component instanceof VehicleRegion) unset($this->regions[$entity]);

		if(isset($this->vehicles[$entity]) && ($component instanceof Vehicle || $component instanceof Body))
		{
			$vehicle = $this->vehicles[$entity];

			if($vehicle->occupant !== null) $this->dismount($vehicle->occupant, 'removed');

			unset($this->vehicles[$entity], $this->pending[$entity], $this->art[$entity]);
			$this->retired[$entity] = true;

			return;
		}

		foreach($this->vehicles as $vehicle)
		{
			if($vehicle->occupant !== $entity || !($component instanceof Rider || $component instanceof Body)) continue;

			$rider = $component instanceof Rider
				? $component
				: ($registry->has($entity, Rider::class) ? $registry->get($entity, Rider::class) : null);

			if($rider !== null) $this->detach($entity, $rider, $vehicle);
			else $vehicle->occupant = null;
		}
	}

	/**
	 * Release a vehicle's rider and destroy only the owned chassis entity.
	 *
	 * @param int $id Vehicle entity ID; unknown IDs are ignored.
	 * @return void Removes artwork, behavior, and body without destroying the player.
	 */
	public function remove(int $id): void
	{
		$vehicle = $this->vehicles[$id] ?? null;

		if($vehicle === null) return;

		if($vehicle->occupant !== null) $this->dismount($vehicle->occupant, 'removed');

		unset($this->vehicles[$id], $this->pending[$id], $this->art[$id]);

		if($this->registry->alive($id)) $this->registry->destroy($id);
	}

	/**
	 * Position chassis artwork using its authored transform and live board pose.
	 *
	 * @param Vehicle $vehicle Vehicle with optional Sprite component.
	 * @return void Updates the atlas sprite without allocating graphics resources.
	 */
	private function draw(Vehicle $vehicle): void
	{
		$id = $vehicle->entity;

		if(!$this->registry->has($id, Sprite::class)) return;

		$sprite = $this->registry->get($id, Sprite::class);
		[$rotation, $flip] = $this->art[$id] ??= [$sprite->rotation, $sprite->flipX];

		if($vehicle->spriteGid !== null) $sprite->gid = $vehicle->spriteGid;

		$sprites = $this->registry->has($id, SpriteLayers::class)
			? [
				...$this->registry->get($id, SpriteLayers::class)->behind
				, ...$this->registry->get($id, SpriteLayers::class)->front
			]
			: [$sprite];

		foreach($sprites as $plane)
		{
			$plane->x = $vehicle->body->supportX();
			$plane->y = $vehicle->body->supportY();
			$plane->rotation = $rotation + $vehicle->body->angle
				+ $vehicle->visualAngle * $vehicle->body->facing
				+ ($vehicle->pose === 'manual' ? -.16 * $vehicle->body->facing : 0);

			$plane->flipX = $flip !== ($vehicle->body->facing < 0);
		}
	}

	/**
	 * Expose serializable vehicle telemetry for scripts and diagnostics.
	 *
	 * @return list<array<string,mixed>> Entity identity, rider, pose, and physical state per vehicle.
	 */
	public function snapshot(): array
	{
		$result = [];

		foreach($this->vehicles as $id => $vehicle)
		{
			$b = $vehicle->body;
			$behavior = $this->behavior($vehicle);
			$item = [
				'entity' => $id
				, 'sourceId' => $vehicle->sourceId
				, 'occupant' => $vehicle->occupant
				, 'x' => $b->x
				, 'y' => $b->y
				, 'vx' => $b->vx
				, 'vy' => $b->vy
				, 'speed' => $b->groundSpeed
				, 'grounded' => $b->grounded
				, 'grinding' => $b->grinding
				, 'collisionLayer' => $b->collisionLayer
				, 'angle' => $b->angle
				, 'pose' => $vehicle->pose
				, 'lockedIn' => $vehicle->lockedIn
			];

			$item += $behavior instanceof VehicleDiagnostics
				? $behavior->vehicleSnapshot($vehicle)
				: ['type' => 'generic'];

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Release all riders and owned entities when a level is unloaded.
	 *
	 * @return void Unsubscribes lifecycle callbacks; repeated calls are harmless.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;

		foreach(array_keys($this->vehicles) as $id) $this->remove($id);

		foreach(array_keys($this->regions + $this->retired) as $id)
		if($this->registry->alive($id))
		$this->registry->destroy($id);

		$this->regions = $this->previous = $this->pending = $this->retired = [];
		$this->weapons->close();
		$this->registry->off('removed', [$this, 'removed']);
	}

	/**
	 * Register a Tiled or scripted vehicle supply/drop region.
	 *
	 * @param VehicleRegion $region Mutable region with per-rider entry history.
	 * @return int Owned region entity ID.
	 */
	public function region(VehicleRegion $region): int
	{
		if($this->closed) throw new \LogicException('Vehicle system is closed');

		$id = $this->registry->create($region);
		$this->regions[$id] = $region;

		return $id;
	}

	/**
	 * Process supply, restricted exit, and forced-drop regions for eligible players.
	 *
	 * @param list<int> $players Current entities with Body and Rider components.
	 * @return void Creates one region vehicle per rider and releases ownership on restricted exit.
	 */
	private function updateRegions(array $players): void
	{
		foreach($this->regions as $region)
		foreach($players as $player)
		{
			$body = $this->registry->get($player, Body::class);
			$inside = $region->contains($body);

			if($region->crosses($body, $this->previous[$player] ?? [$body->x, $body->y]) && $region->drop)
			{
				$this->dismount($player, 'transfer');
			}
			elseif($inside && !array_key_exists($player, $region->spawned) && $this->ridden($player) === null)
			{
				$board = Skateboard::body($body->supportX(), $body->supportY() - 6);
				$board->vx = $body->vx;
				$board->vy = $body->vy;
				$board->groundSpeed = $body->groundSpeed;
				$board->grounded = $body->grounded;
				$board->collisionLayer = $body->collisionLayer;

				$id = $this->add(
					new Vehicle($board, lockedIn: true)
					, new Skateboard
					, $region->sprite === null ? null : clone $region->sprite
				);

				if($this->mount($player, $id)) $region->spawned[$player] = $id;
				else $this->remove($id);
			}
			elseif(!$inside && $region->restricted && !empty($region->spawned[$player]))
			{
				$id = $region->spawned[$player];

				if($this->ridden($player)?->entity === $id) $this->dismount($player, 'transfer');

				$this->remove($id);
				$region->spawned[$player] = false;
			}
		}
	}
}

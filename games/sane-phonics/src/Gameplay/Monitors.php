<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use SanePhonics\Effects\Rumble;
use SanePhonics\Physics\Body;
use Phonics\Physics\Hit;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Render\SpriteGroup;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Solid monitors, pluggable rewards, pop visuals, collision removal, and owned cleanup. */
final class Monitors
{
	/** @var array<int,Monitor> Intact and broken monitor entities owned by this manager. */
	private array $owned = [];
	/** @var array<int,int> Monitor entity IDs keyed by removable terrain shape handles. */
	private array $shapes = [];
	/** @var array<int,int> Removable terrain shape handles keyed by monitor entity ID. */
	private array $handles = [];
	/** @var array<int,bool> Popup and explosion entity IDs owned by this manager. */
	private array $transient = [];
	private bool $closed = false;
	private \Closure $removed;

	/**
	 * Bind monitor collision and lifecycle to a registry, optionally promoting Tiled objects.
	 *
	 * @param Registry $registry Registry receiving monitor, effect, sprite, and visual components.
	 * @param Terrain $terrain Collision index shared with player movement.
	 * @param TileMap|null $map Optional map containing annotated monitor art and objects.
	 * @param string $objectClass Nonempty base Tiled class promoted alongside built-in variants.
	 * @param Shields|null $shields Optional shield manager providing visuals and movement abilities.
	 * @return void Registers collision cleanup and creates matching map objects when supplied.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, ?TileMap $map = null
		, string $objectClass = 'Monitor'
		, private ?Shields $shields = null
	){
		$owner = \WeakReference::create($this);

		/**
		 * Release a monitor's shape when external code removes its component or entity.
		 *
		 * @param Registry $entities Registry emitting the component removal.
		 * @param int $id Entity whose component was removed.
		 * @param object $component Exact detached component instance.
		 * @return void Removes only geometry still owned by the same monitor component.
		 */
		$this->removed = static function(Registry $entities, int $id, object $component) use ($owner): void {
			$manager = $owner->get();

			if($manager !== null && ($manager->owned[$id] ?? null) === $component)
			{
				$manager->releaseShape($id);
				unset($manager->owned[$id]);
			}
		};

		$registry->on('removed', $this->removed);

		if($map !== null)
		{
			try
			{
				$this->spawn($map, $objectClass);
			}
			catch(\Throwable $error)
			{
				$this->close();

				throw $error;
			}
		}
	}

	/**
	 * Promote ring and shield monitor objects from one loaded Tiled map.
	 *
	 * A base monitor selects its effect with `reward`. Built-in classes cover ring, triple-ring,
	 * normal, electric, fire, water or bubble, and star shield monitors. The historical `Sheild`
	 * spelling from moving-boxes-2 is accepted at the Tiled boundary. Properties may override
	 * `amount`, `points`, `starDuration`, `width`, `height`, and `collisionLayer`.
	 *
	 * @param TileMap $map Map containing objects and tiles annotated with monitor sprite classes.
	 * @param string $objectClass Nonempty base object class in addition to the two ring variants.
	 * @return list<int> New monitor entity IDs in map layer and object order.
	 */
	public function spawn(TileMap $map, string $objectClass = 'Monitor'): array
	{
		if($this->closed)
		{
			throw new \LogicException('Monitor manager is closed');
		}

		if($objectClass === '')
		{
			throw new \InvalidArgumentException('Monitor object class cannot be empty');
		}

		$classes = $this->objectRewards($objectClass);
		$objects = [];
		$screens = [];

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				$class = $object['class'] ?? $object['type'] ?? '';

				if(isset($classes[$class]))
				{
					$properties = Loader::properties($object['properties'] ?? []);
					$reward = $this->reward((string)($properties['reward'] ?? $classes[$class]));
					$screen = $this->screenClass($reward);
					$objects[] = [$layer, $object, $properties, $reward, $screen];
					$screens[$screen] = true;
				}
			}
		}

		if(!$objects)
		{
			return [];
		}

		$gids = $this->resolveGids($map, array_keys($screens));
		$result = [];

		foreach($objects as [$layer, $object, $properties, $reward, $screenClass])
		{
			$triple = $reward === 'triple-ring';
			$amount = (int)($properties['amount'] ?? ($triple ? 30 : 10));
			$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
			$y = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
			$width = (float)($object['width'] ?? 0) ?: (float)($properties['width'] ?? 30);
			$height = (float)($object['height'] ?? 0) ?: (float)($properties['height'] ?? 32);

			$monitor = new Monitor(
				$x
				, $y
				, $width
				, $height
				, (int)($properties['points'] ?? 10)
				, $gids['monitor-broken']
				, $gids['monitor-explosion']
				, (int)($properties['collisionLayer'] ?? 0)
			);

			$shell = new Sprite($gids['monitor-shell'], $x, $y, .5, 1);
			$screen = new Sprite($gids[$screenClass], $x, $y - $height * .56, .5, .5);

			$effect = match($reward)
			{
				'ring', 'triple-ring' => new RingMonitorEffect($amount)
				, default => new ShieldMonitorEffect(
					substr($reward, strlen('shield-'))
					, (float)($properties['starDuration'] ?? 26)
					, $this->shields
				)
			};

			$result[] = $this->create($monitor, $effect, $shell, $screen);
		}

		return $result;
	}

	/**
	 * Create one monitor with caller-selected reward and atlas-backed art.
	 *
	 * @param Monitor $monitor Shared position, bounds, reward score, and broken-art metadata.
	 * @param MonitorEffect $effect Reward behavior invoked once for the breaking player.
	 * @param Sprite $shell Primary casing sprite, retained in its broken state after a pop.
	 * @param Sprite|null $screen Optional overlaid reward icon released as a popup on breakage.
	 * @return int New entity ID whose removable rectangle joins the shared terrain index.
	 */
	public function create(
		Monitor $monitor
		, MonitorEffect $effect
		, Sprite $shell
		, ?Sprite $screen = null
	): int {
		if($this->closed)
		{
			throw new \LogicException('Monitor manager is closed');
		}

		$halfWidth = $monitor->width / 2;

		$handle = $this->terrain->addShape(
			[
				[$monitor->x - $halfWidth, $monitor->y - $monitor->height]
				, [$monitor->x + $halfWidth, $monitor->y - $monitor->height]
				, [$monitor->x + $halfWidth, $monitor->y]
				, [$monitor->x - $halfWidth, $monitor->y]
			]
			, collisionLayer: $monitor->collisionLayer
		);

		$id = $this->registry->create();
		$this->owned[$id] = $monitor;
		$this->handles[$id] = $handle;
		$this->shapes[$handle] = $id;

		try
		{
			$this->registry->add($id, $monitor);
			$this->registry->add($id, $effect);
			$this->registry->add($id, $shell);

			if($screen !== null)
			{
				$this->registry->add($id, new SpriteGroup($screen));
			}
		}
		catch(\Throwable $error)
		{
			$this->releaseShape($id);
			unset($this->owned[$id]);

			if($this->registry->alive($id))
			{
				$this->registry->destroy($id);
			}

			throw $error;
		}

		return $id;
	}

	/**
	 * Break the monitor belonging to a movement hit when the body is in an attack pose.
	 *
	 * @param Body $body Actor at the beginning of the current continuous movement sweep.
	 * @param Hit $hit Earliest collision returned by the shared terrain index.
	 * @param float $milliseconds Simulation time used to start broken and popup animations.
	 * @return bool True when this impact removed an intact monitor shape; false when it stays solid.
	 */
	public function impact(Body $body, Hit $hit, float $milliseconds = 0): bool
	{
		if($hit->shape === null || !isset($this->shapes[$hit->shape]))
		{
			return false;
		}

		$player = $this->entityForBody($body);
		$attacking = $body->rolling || $body->jumping;

		if($player !== null && $this->registry->has($player, PlayerCombat::class))
		{
			$attacking = $this->registry->get($player, PlayerCombat::class)->attacking($body);
		}

		return $attacking && $this->pop($this->shapes[$hit->shape], $player, $body, $milliseconds);
	}

	/**
	 * Break one monitor, release its collision, apply its effect, and create pop visuals.
	 *
	 * @param int $id Intact monitor entity created by this manager.
	 * @param int|null $player Optional live player receiving score and the monitor effect.
	 * @param Body|null $body Optional attacking body receiving an airborne rebound.
	 * @param float $milliseconds Simulation time used as the replacement sprites' clock origin.
	 * @return bool True only for the first successful pop of an owned, live monitor.
	 */
	public function pop(
		int $id
		, ?int $player = null
		, ?Body $body = null
		, float $milliseconds = 0
	): bool {
		$monitor = $this->owned[$id] ?? null;

		if(!$monitor instanceof Monitor || $monitor->broken || !$this->registry->alive($id))
		{
			return false;
		}

		if(!is_finite($milliseconds))
		{
			throw new \InvalidArgumentException('Monitor animation time must be finite');
		}

		$monitor->broken = true;
		$this->releaseShape($id);
		$shell = $this->registry->get($id, Sprite::class);

		if($monitor->brokenGid > 0)
		{
			$shell->gid = $monitor->brokenGid;
			$shell->time = -$milliseconds;
		}
		else
		{
			$shell->opacity = .45;
		}

		$this->releaseScreen($id);
		$this->explode($monitor, $milliseconds);

		if($player !== null && $this->registry->alive($player))
		{
			if($this->registry->has($player, RingCounter::class))
			{
				$this->registry->get($player, RingCounter::class)->score += $monitor->points;
			}

			$this->registry->get($id, MonitorEffect::class)->apply($this->registry, $player, $monitor);

			if($this->registry->has($player, Rumble::class))
			{
				$this->registry->get($player, Rumble::class)->pop();
			}
		}

		if($body !== null && !$body->grounded)
		{
			$body->vy = $body->vy >= 0 ? min(-$body->vy, -420) : $body->vy + 480;
		}

		return true;
	}

	/**
	 * Advance released screen icons and explosions, retiring them at their exact lifetimes.
	 *
	 * @param Registry $registry Bound registry supplied by the scheduler.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Updates popup positions and opacity without changing broken monitor casings.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Monitor update requires its registry and a positive delta');
		}

		foreach($registry->query(Query::all(MonitorPopup::class, Sprite::class)) as $id)
		{
			if(!isset($this->transient[$id]))
			{
				continue;
			}

			$popup = $registry->get($id, MonitorPopup::class);
			$popup->age = min($popup->lifetime, $popup->age + $dt);

			if($popup->age >= $popup->lifetime)
			{
				$this->retire($id);

				continue;
			}

			$progress = min(1, $popup->age / $popup->riseDuration);
			$progress = 1 - (1 - $progress) * (1 - $progress);
			$sprite = $registry->get($id, Sprite::class);
			$sprite->x = $popup->x;
			$sprite->y = $popup->y - $popup->rise * $progress;
			$sprite->opacity = $popup->age <= $popup->fadeStart
				? 1
				: 1 - ($popup->age - $popup->fadeStart) / ($popup->lifetime - $popup->fadeStart);
		}

		foreach($registry->query(Query::all(MonitorExplosion::class, Sprite::class)) as $id)
		{
			if(!isset($this->transient[$id]))
			{
				continue;
			}

			$explosion = $registry->get($id, MonitorExplosion::class);
			$explosion->age = min($explosion->lifetime, $explosion->age + $dt);

			if($explosion->age >= $explosion->lifetime)
			{
				$this->retire($id);

				continue;
			}

			$registry->get($id, Sprite::class)->opacity = 1 - $explosion->age / $explosion->lifetime;
		}
	}

	/**
	 * Return live monitor entity IDs for scripting and diagnostics.
	 *
	 * @return list<int> Owned intact and broken casing entities in creation order.
	 */
	public function entities(): array
	{
		$result = [];

		foreach(array_keys($this->owned) as $id)
		{
			if($this->registry->alive($id))
			{
				$result[] = $id;
			}
		}

		return $result;
	}

	/**
	 * Count owned monitor states and transient pop visuals.
	 *
	 * @return array{intact:int,broken:int,popups:int,explosions:int} Current live totals.
	 */
	public function counts(): array
	{
		$intact = $broken = 0;
		$popups = $explosions = 0;

		foreach($this->entities() as $id)
		{
			$this->registry->get($id, Monitor::class)->broken ? $broken++ : $intact++;
		}

		foreach(array_keys($this->transient) as $id)
		{
			if($this->registry->has($id, MonitorPopup::class))
			{
				$popups++;
			}

			if($this->registry->has($id, MonitorExplosion::class))
			{
				$explosions++;
			}
		}

		return [
			'intact' => $intact
			, 'broken' => $broken
			, 'popups' => $popups
			, 'explosions' => $explosions
		];
	}

	/**
	 * Move a monitor's screen sprite to a separately timed popup entity.
	 *
	 * @param int $id Broken monitor entity whose optional SpriteGroup contains the screen.
	 * @return void Removes the overlay from the casing and retains it for one second when present.
	 */
	private function releaseScreen(int $id): void
	{
		if(!$this->registry->has($id, SpriteGroup::class))
		{
			return;
		}

		$group = $this->registry->get($id, SpriteGroup::class);
		$screen = $group->sprites[0] ?? null;
		$this->registry->remove($id, SpriteGroup::class);

		if($screen !== null)
		{
			$popup = new MonitorPopup($screen->x, $screen->y);
			$popupId = $this->registry->create($popup, $screen);
			$this->transient[$popupId] = true;
		}
	}

	/**
	 * Create the optional animated explosion at a monitor's visual center.
	 *
	 * @param Monitor $monitor Broken monitor supplying effect art and position.
	 * @param float $milliseconds Simulation time used as the animation clock origin.
	 * @return void Adds no entity when the monitor has no explosion tile.
	 */
	private function explode(Monitor $monitor, float $milliseconds): void
	{
		if($monitor->explosionGid < 1)
		{
			return;
		}

		$sprite = new Sprite($monitor->explosionGid, $monitor->x, $monitor->y - $monitor->height / 2, .5, .5);

		$sprite->time = -$milliseconds;
		$id = $this->registry->create(new MonitorExplosion, $sprite);
		$this->transient[$id] = true;
	}

	/**
	 * Resolve the registry entity that owns an exact Body object.
	 *
	 * @param Body $body Component instance supplied by the movement system.
	 * @return int|null Live owning entity, or null for an unregistered test or custom body.
	 */
	private function entityForBody(Body $body): ?int
	{
		foreach($this->registry->query(Body::class) as $id)
		{
			if($this->registry->get($id, Body::class) === $body)
			{
				if($this->registry->has($id, Vehicle::class))
				{
					$vehicle = $this->registry->get($id, Vehicle::class);

					return $vehicle->occupant !== null && Vehicle::forRider(
						$this->registry
						, $vehicle->occupant
					) === $vehicle
						? $vehicle->occupant
						: null;
				}

				return $id;
			}
		}

		return null;
	}

	/**
	 * Resolve required art classes from normalized map tilesets.
	 *
	 * @param TileMap $map Map whose atlas renders monitors and their effects.
	 * @param list<string> $screens Screen classes required by the discovered monitor rewards.
	 * @return array<string,int> Required Tiled tile classes mapped to global tile IDs.
	 */
	private function resolveGids(TileMap $map, array $screens): array
	{
		$required = [
			'monitor-shell'
			, 'monitor-broken'
			, 'monitor-explosion'
		];

		array_push($required, ...$screens);
		$required = array_values(array_unique($required));

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
			throw new \UnexpectedValueException('Missing monitor sprite classes: ' . implode(', ', $missing));
		}

		return $result;
	}

	/**
	 * Map supported Tiled object classes to their default reward names.
	 *
	 * @param string $objectClass Caller-selected base class whose default reward is ten rings.
	 * @return array<string,string> Exact object classes mapped to canonical or accepted rewards.
	 */
	private function objectRewards(string $objectClass): array
	{
		return [
			$objectClass => 'ring'
			, 'RingMonitor' => 'ring'
			, 'TripleRingMonitor' => 'triple-ring'
			, 'NormalShieldMonitor' => 'shield-normal'
			, 'ShieldNormalMonitor' => 'shield-normal'
			, 'SheildNormalMonitor' => 'shield-normal'
			, 'ElectricShieldMonitor' => 'shield-electric'
			, 'ShieldElectricMonitor' => 'shield-electric'
			, 'SheildElectricMonitor' => 'shield-electric'
			, 'FireShieldMonitor' => 'shield-fire'
			, 'ShieldFireMonitor' => 'shield-fire'
			, 'SheildFireMonitor' => 'shield-fire'
			, 'WaterShieldMonitor' => 'shield-water'
			, 'BubbleShieldMonitor' => 'shield-water'
			, 'ShieldWaterMonitor' => 'shield-water'
			, 'SheildWaterMonitor' => 'shield-water'
			, 'StarShieldMonitor' => 'shield-star'
			, 'ShieldStarMonitor' => 'shield-star'
			, 'SheildStarMonitor' => 'shield-star'
		];
	}

	/**
	 * Normalize accepted reward spellings at the map boundary.
	 *
	 * @param string $reward Tiled reward property or object-class default.
	 * @return string Ring, triple-ring, or shield-* canonical reward.
	 */
	private function reward(string $reward): string
	{
		$canonical = match($reward)
		{
			'ring', 'rings' => 'ring'
			, 'triple-ring', 'triple-rings' => 'triple-ring'
			, 'normal-shield', 'shield-normal', 'normal-sheild', 'sheild-normal' => 'shield-normal'
			, 'electric-shield', 'shield-electric', 'electric-sheild', 'sheild-electric' => 'shield-electric'
			, 'fire-shield', 'shield-fire', 'fire-sheild', 'sheild-fire' => 'shield-fire'
			, 'water-shield', 'bubble-shield', 'shield-water', 'water-sheild', 'sheild-water' => 'shield-water'
			, 'star-shield', 'shield-star', 'star-sheild', 'sheild-star' => 'shield-star'
			, default => ''
		};

		if($canonical === '')
		{
			throw new \UnexpectedValueException("Unsupported monitor reward: $reward");
		}

		return $canonical;
	}

	/**
	 * Select the annotated screen tile class for one canonical reward.
	 *
	 * @param string $reward Canonical reward returned by reward().
	 * @return string Required Tiled tile class for the animated monitor screen.
	 */
	private function screenClass(string $reward): string
	{
		return 'monitor-screen-' . $reward;
	}

	/**
	 * Remove one monitor's terrain shape and both ownership indexes.
	 *
	 * @param int $id Monitor entity whose shape may still be active.
	 * @return void Leaves broken or externally removed shapes unchanged.
	 */
	private function releaseShape(int $id): void
	{
		$handle = $this->handles[$id] ?? null;

		if($handle !== null)
		{
			$this->terrain->removeShape($handle);
			unset($this->handles[$id], $this->shapes[$handle]);
		}
	}

	/**
	 * Destroy one owned popup or explosion if it remains live.
	 *
	 * @param int $id Transient entity ID to retire.
	 * @return void Clears manager ownership even when external code already removed the entity.
	 */
	private function retire(int $id): void
	{
		if($this->registry->alive($id))
		{
			$this->registry->destroy($id);
		}

		unset($this->transient[$id]);
	}

	/**
	 * Destroy every owned casing and pop visual and release all monitor collision shapes.
	 *
	 * @return void Makes repeated level cleanup safe and detaches the registry listener.
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
			if($this->registry->alive($id))
			{
				$this->registry->destroy($id);
			}
		}

		foreach(array_keys($this->transient) as $id)
		{
			$this->retire($id);
		}

		$this->owned = $this->shapes = $this->handles = [];
		$this->registry->off('removed', $this->removed);
	}
}

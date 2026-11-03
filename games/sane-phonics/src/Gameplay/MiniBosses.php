<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use SanePhonics\Effects\Rumble;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;
use Phonics\Render\Sprite;
use Phonics\Tiled\Gid;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Tiled spawning and complete stalking mini-boss encounter behavior. */
final class MiniBosses
{
	/** @var array<string,int> Global tile IDs keyed by annotated sprite class. */
	private array $gids;
	/** @var array<int,int> Spawned boss entity IDs keyed by source Tiled object ID. */
	private array $sources = [];
	/** @var array<int,bool> Bosses, bombs, fragments, and effects owned by this manager. */
	private array $owned = [];
	/** @var array<int,array{float,float}> Previous player centers for continuous contacts. */
	private array $players = [];
	/** @var array<int,array{float,float}> Previous boss centers for relative continuous contacts. */
	private array $bosses = [];
	private bool $closed = false;

	/**
	 * Promote Tiled MiniBoss objects and bind their combat behavior to a level.
	 *
	 * Frame-based Tiled timing and speed properties use 60 reference updates per second.
	 * Recognized classes are MiniBoss and mini-boss after punctuation-insensitive normalization.
	 *
	 * @param Registry $registry Registry receiving boss, projectile, effect, and sprite components.
	 * @param Terrain $terrain Collision source used by defeated bosses and falling spike bombs.
	 * @param TileMap $map Loaded map containing boss objects and annotated art tiles.
	 * @param Rings|null $rings Optional ring owner used to scatter inventory on player damage.
	 * @param string $objectClass Tiled object class normalized for matching.
	 * @param bool $advanceCombat Whether this manager advances PlayerCombat mercy clocks. Disable
	 * when another combat manager already advances the same players.
	 * @return void Creates each matching boss at its bottom-center world coordinate.
	 */
	public function __construct(
		private Registry $registry
		, private Terrain $terrain
		, TileMap $map
		, private ?Rings $rings = null
		, string $objectClass = 'MiniBoss'
		, private bool $advanceCombat = true
	){
		if($objectClass === '')
		{
			throw new \InvalidArgumentException('Mini-boss object class cannot be empty');
		}

		$this->gids = $this->resolveGids($map);
		$expected = $this->normalize($objectClass);

		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				$class = (string)(($object['class'] ?? '') ?: ($object['type'] ?? '') ?: ($object['name'] ?? ''));

				if($this->normalize($class) !== $expected)
				{
					continue;
				}

				$properties = Loader::properties($object['properties'] ?? []);
				$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
				$y = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
				$width = (float)($object['width'] ?? 0) ?: (float)($properties['width'] ?? 48);
				$height = (float)($object['height'] ?? 0) ?: (float)($properties['height'] ?? 48);

				if((float)($object['rotation'] ?? 0) !== 0.0)
				{
					throw new \UnexpectedValueException('Mini-boss objects must be unrotated');
				}

				$gid = isset($object['gid']) ? Gid::from($object['gid']) : null;

				if($gid !== null)
				{
					$x += $width / 2;
				}

				$boss = new Boss(
					$x
					, $y
					, $width
					, $height
					, (int)($properties['hitPoints'] ?? 8)
					, (int)($properties['points'] ?? 1000)
					, (int)($properties['collisionLayer'] ?? 1)
				);

				$behavior = new MiniBoss(
					$this->perSecond($properties, 'stalkSpeed', 4.5)
					, $this->seconds($properties, 'bombInterval', 60)
					, $this->seconds($properties, 'hitCooldown', 45)
					, $this->seconds($properties, 'recovery', 20)
					, $this->seconds($properties, 'deathDelay', 6)
					, $this->seconds($properties, 'explosionDuration', 90)
					, $this->seconds($properties, 'explosionInterval', 3)
					, $this->perSecond($properties, 'escapeSpeed', 14)
					, $this->seconds($properties, 'escapeDuration', 60)
					, $this->perSecondSquared($properties, 'gravity', .2)
					, $this->perSecondSquared($properties, 'bombGravity', .25)
					, $this->seconds($properties, 'bombLifetime', 240)
				);

				$sprite = new Sprite($this->gids['mini-boss-idle'], $x, $y, .5, 1);
				$sprite->tint = $layer->tint;
				$sprite->opacity = $layer->opacity;

				if($gid !== null)
				{
					$sprite->flipX = (bool)($gid->flags & 8);
					$sprite->flipY = (bool)($gid->flags & 4);

					if($gid->flags & 2)
					{
						$sprite->originX = 1;
						$sprite->rotation = M_PI / 2;
						[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
					}
				}

				$behavior->opacity = $layer->opacity;
				$entity = $registry->create($boss, $behavior, $sprite);
				$this->owned[$entity] = true;
				$this->bosses[$entity] = [$boss->x, $boss->y - $boss->height / 2];

				if(isset($object['id']))
				{
					$this->sources[(int)$object['id']] = $entity;
				}
			}
		}
	}

	/**
	 * Advance bosses, spike bombs, explosion effects, and continuous player contacts.
	 *
	 * @param Registry $registry Bound registry supplied by the scheduler.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Mutates owned encounter state and retires finished entities.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		if($registry !== $this->registry || !is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Mini-boss update requires its registry and a positive delta');
		}

		$playerIds = $registry->query(Query::all(Body::class, PlayerCombat::class));

		if($this->advanceCombat)
		{
			foreach($playerIds as $player)
			{
				$registry->get($player, PlayerCombat::class)->update($dt);
			}
		}

		$this->updateBosses($playerIds, $dt);
		$this->updateBombs($playerIds, $dt);
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
	 * Activate a boss referenced by a Tiled object property.
	 *
	 * @param int $sourceId Source object ID used by a BossRegion or script.
	 * @return bool True when a live idle boss entered its stalking phase or was already active.
	 */
	public function wakeSource(int $sourceId): bool
	{
		$entity = $this->entityForSourceId($sourceId);

		if($entity === null || !$this->registry->has($entity, Boss::class))
		{
			return false;
		}

		$boss = $this->registry->get($entity, Boss::class);
		$behavior = $this->registry->get($entity, MiniBoss::class);

		if(!$boss->alive())
		{
			return false;
		}

		if($boss->phase === 'idle')
		{
			$this->phase($boss, $behavior, 'stalking');
		}

		return true;
	}

	/**
	 * Report whether a referenced boss still has health and keeps an arena locked.
	 *
	 * @param int $sourceId Source Tiled object ID.
	 * @return bool True only while the referenced live boss has positive hit points.
	 */
	public function aliveSource(int $sourceId): bool
	{
		$entity = $this->entityForSourceId($sourceId);

		return $entity !== null && $this->registry->has($entity, Boss::class)
			&& $this->registry->get($entity, Boss::class)->alive();
	}

	/**
	 * Resolve a spawned boss from its source Tiled object ID.
	 *
	 * @param int $sourceId Object ID referenced by another Tiled object property.
	 * @return int|null Entity ID allocated for that object, or null when it was not spawned.
	 */
	public function entityForSourceId(int $sourceId): ?int
	{
		$entity = $this->sources[$sourceId] ?? null;

		return $entity !== null && $this->registry->alive($entity) ? $entity : null;
	}

	/**
	 * Return live mini-boss entity IDs for diagnostics and scripted examples.
	 *
	 * @return list<int> Registry snapshot ordered by entity creation.
	 */
	public function entities(): array
	{
		return $this->registry->query(Query::all(Boss::class, MiniBoss::class));
	}

	/**
	 * Reset one player's sweep origin after a spawn, teleport, or level change.
	 *
	 * @param int $entity Player entity owning the supplied body.
	 * @param Body $body Body supplying the new world-space center.
	 * @return void Prevents the teleport path from touching bosses or their projectiles.
	 */
	public function sync(int $entity, Body $body): void
	{
		$this->players[$entity] = [$body->x, $body->y];
	}

	/**
	 * Describe the first live encounter for browser diagnostics and scripted tests.
	 *
	 * @return array<string,bool|int|float|string|null> Boss health, phase, position, and effect totals.
	 */
	public function snapshot(): array
	{
		$entities = $this->entities();
		$entity = $entities[0] ?? null;

		if($entity === null)
		{
			return [
				'present' => false
				, 'entity' => null
				, 'phase' => 'gone'
				, 'hitPoints' => 0
				, 'maxHitPoints' => 0
				, 'x' => null
				, 'y' => null
				, 'bombs' => count($this->registry->query(SpikeBomb::class))
				, 'explosions' => count($this->registry->query(BossExplosion::class))
			];
		}

		$boss = $this->registry->get($entity, Boss::class);
		$behavior = $this->registry->get($entity, MiniBoss::class);

		return [
			'present' => true
			, 'entity' => $entity
			, 'phase' => $boss->phase
			, 'hitPoints' => $boss->hitPoints
			, 'maxHitPoints' => $boss->maxHitPoints
			, 'x' => $boss->x
			, 'y' => $boss->y
			, 'bombs' => count($this->registry->query(SpikeBomb::class))
			, 'bombsDropped' => $behavior->bombs
			, 'hitsTaken' => $behavior->hits
			, 'explosions' => count($this->registry->query(BossExplosion::class))
		];
	}

	/**
	 * Advance each live boss state machine and resolve player contact after movement.
	 *
	 * @param list<int> $playerIds Current player entities.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Moves active bosses, emits attacks, and applies hits or player damage.
	 */
	private function updateBosses(array $playerIds, float $dt): void
	{
		$active = [];

		foreach($this->entities() as $entity)
		{
			$boss = $this->registry->get($entity, Boss::class);
			$behavior = $this->registry->get($entity, MiniBoss::class);
			$sprite = $this->registry->get($entity, Sprite::class);
			$from = $this->bosses[$entity] ?? [$boss->x, $boss->y - $boss->height / 2];
			$behavior->age += $dt;
			$behavior->phaseAge += $dt;

			if($boss->phase === 'stalking')
			{
				$this->stalk($entity, $boss, $behavior, $playerIds, $dt);
			}
			elseif(in_array($boss->phase, ['damaged', 'knocked'], true))
			{
				$duration = $boss->alive() ? $behavior->recovery : $behavior->deathDelay;

				if($behavior->phaseAge >= $duration)
				{
					$this->phase($boss, $behavior, $boss->alive() ? 'stalking' : 'dead');
				}
			}
			elseif($boss->phase === 'dead')
			{
				$this->fall($boss, $behavior, $dt);
			}
			elseif($boss->phase === 'exploding')
			{
				$this->explode($entity, $boss, $behavior, $dt);
			}
			elseif($boss->phase === 'escaped')
			{
				$behavior->escapeAge += $dt;
				$boss->y -= $behavior->escapeSpeed * $dt;

				if($behavior->escapeAge >= $behavior->escapeDuration)
				{
					$this->retire($entity);

					continue;
				}
			}

			if(!$this->registry->alive($entity))
			{
				continue;
			}

			$this->contacts($entity, $boss, $behavior, $from, $playerIds);
			$this->pose($boss, $sprite, $behavior);
			$active[$entity] = $this->bosses[$entity] = [$boss->x, $boss->y - $boss->height / 2];
		}

		$this->bosses = array_intersect_key($this->bosses, $active);
	}

	/**
	 * Track the first player horizontally and emit every crossed bomb interval.
	 *
	 * @param int $entity Boss entity that owns emitted projectiles.
	 * @param Boss $boss Mutable boss position and velocity.
	 * @param MiniBoss $behavior Encounter speed, timing, and counters.
	 * @param list<int> $playerIds Current player entities in query order.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Moves by a bounded displacement and creates falling spike bombs.
	 */
	private function stalk(int $entity, Boss $boss, MiniBoss $behavior, array $playerIds, float $dt): void
	{
		$player = $playerIds[0] ?? null;

		if($player !== null && $this->registry->alive($player))
		{
			$body = $this->registry->get($player, Body::class);
			$dx = max(-$behavior->stalkSpeed * $dt, min($behavior->stalkSpeed * $dt, $body->x - $boss->x));
			$boss->vx = $dx / $dt;
			$boss->x += $dx;
		}

		$behavior->bombAge += $dt;

		while($behavior->bombAge >= $behavior->bombInterval)
		{
			$behavior->bombAge -= $behavior->bombInterval;
			$this->bomb($entity, $boss, $behavior);
		}
	}

	/**
	 * Drop one owner-tagged spike bomb from the lower half of a stalking boss.
	 *
	 * @param int $entity Boss entity that owns the projectile.
	 * @param Boss $boss Boss position and inherited horizontal velocity.
	 * @param MiniBoss $behavior Projectile tuning and emitted-bomb counter.
	 * @return void Creates an owned projectile and centered sprite entity.
	 */
	private function bomb(int $entity, Boss $boss, MiniBoss $behavior): void
	{
		$x = $boss->x;
		$y = $boss->y + 16;

		$projectile = new SpikeBomb(
			$entity
			, $x
			, $y
			, $boss->vx
			, 0
			, $behavior->bombGravity
			, $behavior->bombLifetime
			, 10
			, $boss->collisionLayer
		);

		$sprite = new Sprite($this->gids['spike-bomb'], $x, $y, .5, .5);
		$id = $this->registry->create($projectile, $sprite);
		$this->owned[$id] = true;
		$behavior->bombs++;
	}

	/**
	 * Apply gravity to a zero-health boss until continuous terrain collision stops its fall.
	 *
	 * @param Boss $boss Defeated boss to move.
	 * @param MiniBoss $behavior Encounter gravity tuning.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Moves the boss no farther than its first terrain contact.
	 */
	private function fall(Boss $boss, MiniBoss $behavior, float $dt): void
	{
		$dy = $boss->vy * $dt + .5 * $behavior->gravity * $dt * $dt;
		$probe = new Body($boss->x, $boss->y - $boss->height / 2);
		$probe->radius = max(.5, $boss->width / 2 - 1);
		$probe->halfHeight = max(.5, $boss->height / 2 - 1);
		$probe->collisionLayer = $boss->collisionLayer;
		$hit = $this->terrain->castBody($probe, 0, $dy);

		if($hit !== null)
		{
			$boss->y += $dy * max(0, $hit->t - 1e-6);
			$boss->vy = 0;

			return;
		}

		$boss->y += $dy;
		$boss->vy += $behavior->gravity * $dt;
	}

	/**
	 * Emit deterministic effects during the staged defeat and begin the upward escape.
	 *
	 * @param int $entity Boss entity owning the sequence.
	 * @param Boss $boss Boss position and phase to mutate.
	 * @param MiniBoss $behavior Explosion timing and counters.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Creates every crossed effect interval and transitions after the configured time.
	 */
	private function explode(int $entity, Boss $boss, MiniBoss $behavior, float $dt): void
	{
		$from = $behavior->explosionAge;
		$behavior->explosionAge = min($behavior->explosionDuration, $behavior->explosionAge + $dt);
		$first = (int)floor($from / $behavior->explosionInterval) + 1;
		$last = (int)floor($behavior->explosionAge / $behavior->explosionInterval);

		for($index = $first; $index <= $last; $index++)
		{
			$this->explosion($boss, $behavior, $index);
		}

		if($behavior->explosionAge >= $behavior->explosionDuration)
		{
			$boss->vx = 0;
			$boss->vy = -$behavior->escapeSpeed;
			$this->phase($boss, $behavior, 'escaped');

			foreach($this->registry->query(SpikeBomb::class) as $projectile)
			{
				if($this->registry->get($projectile, SpikeBomb::class)->owner === $entity)
				{
					$this->retire($projectile);
				}
			}
		}
	}

	/**
	 * Spawn one explosion at a repeatable offset inside the boss silhouette.
	 *
	 * @param Boss $boss Boss supplying the effect center and dimensions.
	 * @param MiniBoss $behavior Encounter counter receiving the emitted effect.
	 * @param int $index One-based explosion sequence position.
	 * @return void Creates one fading owned visual entity.
	 */
	private function explosion(Boss $boss, MiniBoss $behavior, int $index): void
	{
		$offsets = [[-.3,-.65],[.28,-.28],[-.12,-.15],[.34,-.72],[-.36,-.38],[.08,-.88]];
		[$offsetX, $offsetY] = $offsets[($index - 1) % count($offsets)];
		$x = $boss->x + $boss->width * $offsetX;
		$y = $boss->y + $boss->height * $offsetY;
		$sprite = new Sprite($this->gids['boss-explosion'], $x, $y, .5, .5);
		$id = $this->registry->create(new BossExplosion, $sprite);
		$this->owned[$id] = true;
		$behavior->explosions++;
	}

	/**
	 * Resolve continuous contacts between one moving boss and every current player.
	 *
	 * @param int $entity Boss entity being contacted.
	 * @param Boss $boss Current boss state and contact bounds.
	 * @param MiniBoss $behavior Species hit cooldown and phase state.
	 * @param array{float,float} $bossFrom Boss center before this update.
	 * @param list<int> $playerIds Current player entities.
	 * @return void Applies attacks, dud hits, finishers, rebounds, or ordinary player damage.
	 */
	private function contacts(
		int $entity
		, Boss $boss
		, MiniBoss $behavior
		, array $bossFrom
		, array $playerIds
	): void {
		if(in_array($boss->phase, ['exploding', 'escaped'], true))
		{
			return;
		}

		foreach($playerIds as $player)
		{
			if(!$this->registry->alive($player))
			{
				continue;
			}

			$body = $this->registry->get($player, Body::class);
			$playerFrom = $this->players[$player] ?? [$body->x, $body->y];
			$side = $this->contact($body, $playerFrom, $boss, $bossFrom);

			if($side === null)
			{
				continue;
			}

			$combat = $this->registry->get($player, PlayerCombat::class);

			if(!$boss->alive())
			{
				if($boss->phase === 'dead' && $combat->attacking($body))
				{
					$this->rebound($body, $boss, $side);
					$this->phase($boss, $behavior, 'exploding');
				}

				continue;
			}

			if($combat->attacking($body))
			{
				$this->rebound($body, $boss, $side);

				if($side === 'bottom')
				{
					$boss->vx = 0;
					$this->phase($boss, $behavior, 'knocked');

					continue;
				}

				$this->hit($entity, $player, $boss, $behavior);

				continue;
			}

			$this->damage($player, $body, $boss->x, 'boss');
		}
	}

	/**
	 * Apply one cooldown-limited hit and reward the final successful attacker.
	 *
	 * @param int $entity Boss entity receiving the hit.
	 * @param int $attacker Player entity responsible for the attack.
	 * @param Boss $boss Boss health, score, and phase state.
	 * @param MiniBoss $behavior Encounter clock, cooldown table, and counters.
	 * @return bool True when this contact removed one hit point.
	 */
	private function hit(int $entity, int $attacker, Boss $boss, MiniBoss $behavior): bool
	{
		if(!$boss->alive() || $behavior->age - ($behavior->damagers[$attacker] ?? -INF) < $behavior->hitCooldown)
		{
			return false;
		}

		$boss->hitPoints--;
		$boss->vx = 0;
		$behavior->hits++;
		$behavior->damagers[$attacker] = $behavior->age;
		$this->phase($boss, $behavior, 'damaged');

		if($this->registry->has($attacker, Rumble::class))
		{
			$this->registry->get($attacker, Rumble::class)->bossHit();
		}

		if(!$boss->alive() && $this->registry->alive($attacker))
		{
			$combat = $this->registry->get($attacker, PlayerCombat::class);
			$combat->defeats++;

			if($this->registry->has($attacker, RingCounter::class))
			{
				$this->registry->get($attacker, RingCounter::class)->score += $boss->points;
			}

			foreach($this->registry->query(SpikeBomb::class) as $projectile)
			{
				if($this->registry->get($projectile, SpikeBomb::class)->owner === $entity)
				{
					$this->retire($projectile);
				}
			}
		}

		return true;
	}

	/**
	 * Bounce an attacking player according to the contacted boss face.
	 *
	 * @param Body $body Attacking player movement body.
	 * @param Boss $boss Boss position and bounds at contact.
	 * @param string $side Top, bottom, or side contact classification.
	 * @return void Preserves an attack pose while separating its velocity from the boss.
	 */
	private function rebound(Body $body, Boss $boss, string $side): void
	{
		if($side === 'top')
		{
			[, $halfHeight] = $this->bodyExtents($body);
			$body->y = $boss->y - $boss->height - $halfHeight - .01;
			$body->vy = $body->vy >= 0 ? min(-abs($body->vy), -240) : $body->vy;
			$body->grounded = false;

			return;
		}

		if($side === 'bottom')
		{
			$body->vy = max(420, abs($body->vy));
			$body->grounded = false;

			return;
		}

		$direction = $body->x < $boss->x ? -1 : 1;
		$body->vx = $direction * max(300, abs($body->vx));

		if($body->grounded)
		{
			$body->groundSpeed = $direction * max(240, abs($body->groundSpeed));
		}
	}

	/**
	 * Apply boss or projectile damage with rings, shields, mercy, and combo failure.
	 *
	 * @param int $player Player entity owning combat state and the supplied body.
	 * @param Body $body Player movement body at contact.
	 * @param float $sourceX Horizontal position of the damaging boss or projectile.
	 * @param string $damageType Nonempty category used by shield immunity rules.
	 * @return bool True when mercy allowed the hit to change player state.
	 */
	private function damage(int $player, Body $body, float $sourceX, string $damageType): bool
	{
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
	 * Move every spike bomb continuously and split a landed main bomb into two fragments.
	 *
	 * @param list<int> $playerIds Current player entities.
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Applies terrain and player contacts without speed-based tunneling.
	 */
	private function updateBombs(array $playerIds, float $dt): void
	{
		foreach($this->registry->query(SpikeBomb::class) as $entity)
		{
			if(!$this->registry->alive($entity))
			{
				continue;
			}

			$bomb = $this->registry->get($entity, SpikeBomb::class);
			$from = [$bomb->x, $bomb->y];
			$dx = $bomb->vx * $dt;
			$dy = $bomb->vy * $dt + .5 * $bomb->gravity * $dt * $dt;
			$hit = null;

			if(!$bomb->fragment)
			{
				$probe = new Body($bomb->x, $bomb->y);
				$probe->radius = $probe->halfHeight = $bomb->radius;
				$probe->collisionLayer = $bomb->collisionLayer;
				$hit = $this->terrain->castBody($probe, $dx, $dy);
			}

			if($hit !== null)
			{
				$bomb->x += $dx * max(0, $hit->t - 1e-6);
				$bomb->y += $dy * max(0, $hit->t - 1e-6);
				$bomb->vy += $bomb->gravity * $dt * $hit->t;
				$this->split($entity, $bomb);

				continue;
			}

			$bomb->x += $dx;
			$bomb->y += $dy;
			$bomb->vy += $bomb->gravity * $dt;
			$bomb->age += $dt;
			$sprite = $this->registry->get($entity, Sprite::class);
			$sprite->x = $bomb->x;
			$sprite->y = $bomb->y;

			foreach($playerIds as $player)
			{
				if(!$this->registry->alive($player))
				{
					continue;
				}

				$body = $this->registry->get($player, Body::class);
				$playerFrom = $this->players[$player] ?? [$body->x, $body->y];

				if(!$this->intersectsBomb($body, $playerFrom, $bomb, $from))
				{
					continue;
				}

				if($this->registry->alive($bomb->owner) && $this->registry->has($bomb->owner, Boss::class))
				{
					$this->damage($player, $body, $bomb->x, 'projectile');
				}

				if($bomb->fragment)
				{
					$this->retire($entity);
				}
				else
				{
					$this->split($entity, $bomb);
				}

				continue 2;
			}

			if($bomb->age >= $bomb->lifetime)
			{
				$this->retire($entity);
			}
		}
	}

	/**
	 * Replace a main spike bomb with two outward, terrain-ignoring damaging fragments.
	 *
	 * @param int $entity Main bomb entity to retire.
	 * @param SpikeBomb $bomb Impact position and velocity used by both fragments.
	 * @return void Creates two owned fragments unless the owning boss has already gone.
	 */
	private function split(int $entity, SpikeBomb $bomb): void
	{
		$this->retire($entity);

		if(!$this->registry->alive($bomb->owner) || !$this->registry->has($bomb->owner, Boss::class))
		{
			return;
		}

		$speed = max(150, abs($bomb->vy) * .35);
		$lift = -max(180, abs($bomb->vy) * .5);

		foreach([-1, 1] as $direction)
		{
			$fragment = new SpikeBomb(
				$bomb->owner
				, $bomb->x
				, $bomb->y
				, $direction * $speed
				, $lift
				, $bomb->gravity
				, .75
				, 4
				, $bomb->collisionLayer
				, true
			);

			$sprite = new Sprite($this->gids['spike-fragment'], $bomb->x, $bomb->y, .5, .5);
			$id = $this->registry->create($fragment, $sprite);
			$this->owned[$id] = true;
		}
	}

	/**
	 * Fade and retire completed boss explosion visuals.
	 *
	 * @param float $dt Fixed positive simulation delta in seconds.
	 * @return void Updates effect opacity and destroys each entity at its configured lifetime.
	 */
	private function updateExplosions(float $dt): void
	{
		foreach($this->registry->query(BossExplosion::class) as $entity)
		{
			$effect = $this->registry->get($entity, BossExplosion::class);
			$effect->age = min($effect->lifetime, $effect->age + $dt);

			if($effect->age >= $effect->lifetime)
			{
				$this->retire($entity);

				continue;
			}

			$this->registry->get($entity, Sprite::class)->opacity = 1 - $effect->age / $effect->lifetime;
		}
	}

	/**
	 * Classify relative player and boss movement against their combined axis-aligned bounds.
	 *
	 * @param Body $body Player at the current endpoint.
	 * @param array{float,float} $playerFrom Player center at the previous fixed update.
	 * @param Boss $boss Current bottom-anchored boss bounds.
	 * @param array{float,float} $bossFrom Boss center at the previous fixed update.
	 * @return string|null Top, bottom, or side for a swept contact; null when no contact occurred.
	 */
	private function contact(Body $body, array $playerFrom, Boss $boss, array $bossFrom): ?string
	{
		[$bodyWidth, $bodyHeight] = $this->bodyExtents($body);
		$halfWidth = $bodyWidth + $boss->width / 2;
		$halfHeight = $bodyHeight + $boss->height / 2;
		$bossTo = [$boss->x, $boss->y - $boss->height / 2];
		$origin = [$playerFrom[0] - $bossFrom[0], $playerFrom[1] - $bossFrom[1]];
		$movement = [
			($body->x - $playerFrom[0]) - ($bossTo[0] - $bossFrom[0])
			, ($body->y - $playerFrom[1]) - ($bossTo[1] - $bossFrom[1])
		];

		$entry = 0.0;
		$exit = 1.0;
		$axis = null;

		foreach([
			['x', $origin[0], $movement[0], $halfWidth]
			, ['y', $origin[1], $movement[1], $halfHeight]
		] as [$name, $position, $delta, $extent])
		{
			if(abs($delta) < 1e-9)
			{
				if(abs($position) > $extent)
				{
					return null;
				}

				continue;
			}

			$near = (-$extent - $position) / $delta;
			$far = ($extent - $position) / $delta;

			if($near > $far)
			{
				[$near, $far] = [$far, $near];
			}

			if($near > $entry)
			{
				$entry = $near;
				$axis = $name;
			}

			$exit = min($exit, $far);

			if($entry > $exit)
			{
				return null;
			}
		}

		if($exit < 0 || $entry > 1)
		{
			return null;
		}

		if($axis === 'x')
		{
			return 'side';
		}

		if($axis === 'y')
		{
			return $movement[1] >= 0 ? 'top' : 'bottom';
		}

		$relativeX = ($body->x - $bossTo[0]) / max(1e-9, $halfWidth);
		$relativeY = ($body->y - $bossTo[1]) / max(1e-9, $halfHeight);

		if(abs($relativeX) > abs($relativeY))
		{
			return 'side';
		}

		return $relativeY <= 0 ? 'top' : 'bottom';
	}

	/**
	 * Test relative player and spike-bomb motion without losing fast crossings.
	 *
	 * @param Body $body Player at the current endpoint.
	 * @param array{float,float} $playerFrom Player center at the previous fixed update.
	 * @param SpikeBomb $bomb Projectile at its current endpoint.
	 * @param array{float,float} $bombFrom Projectile center before this update.
	 * @return bool True when the swept player bounds touch the projectile extent.
	 */
	private function intersectsBomb(Body $body, array $playerFrom, SpikeBomb $bomb, array $bombFrom): bool
	{
		[$halfWidth, $halfHeight] = $this->bodyExtents($body);

		return $this->swept(
			$playerFrom
			, [$body->x, $body->y]
			, $bombFrom
			, [$bomb->x, $bomb->y]
			, $halfWidth + $bomb->radius
			, $halfHeight + $bomb->radius
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

		return $exit >= 0 && $entry <= 1;
	}

	/**
	 * Synchronize visible boss art with its current phase and movement.
	 *
	 * @param Boss $boss Boss position and phase.
	 * @param Sprite $sprite Atlas-backed sprite to update.
	 * @param MiniBoss $behavior Encounter clock used for damage flicker.
	 * @return void Selects phase art, position, facing, and opacity.
	 */
	private function pose(Boss $boss, Sprite $sprite, MiniBoss $behavior): void
	{
		$art = match($boss->phase)
		{
			'damaged', 'knocked', 'exploding' => 'mini-boss-damaged',
			'dead' => 'mini-boss-dead',
			default => 'mini-boss-idle'
		};

		$sprite->gid = $this->gids[$art];
		$sprite->x = $boss->x;
		$sprite->y = $boss->y;

		if(abs($boss->vx) > 1e-9)
		{
			$sprite->flipX = $boss->vx > 0;
		}

		$sprite->opacity = $behavior->opacity * (in_array($boss->phase, ['damaged', 'exploding'], true)
			&& (int)floor($behavior->phaseAge / .045) % 2 === 1
			? .35
			: 1
		);
	}

	/**
	 * Enter a named boss phase and reset phase-specific clocks.
	 *
	 * @param Boss $boss Boss whose phase changes.
	 * @param MiniBoss $behavior Encounter clocks associated with the phase.
	 * @param string $phase Nonempty state-machine phase name.
	 * @return void Resets the phase clock and initializes explosion or escape timing.
	 */
	private function phase(Boss $boss, MiniBoss $behavior, string $phase): void
	{
		if($phase === '')
		{
			throw new \InvalidArgumentException('Boss phase cannot be empty');
		}

		$boss->phase = $phase;
		$behavior->phaseAge = 0;

		if($phase === 'exploding')
		{
			$behavior->explosionAge = 0;
		}

		if($phase === 'escaped')
		{
			$behavior->escapeAge = 0;
		}
	}

	/**
	 * Resolve required mini-boss art from normalized Tiled tileset definitions.
	 *
	 * @param TileMap $map Map whose atlas will render boss and projectile sprites.
	 * @return array<string,int> Required class names mapped to global tile IDs.
	 */
	private function resolveGids(TileMap $map): array
	{
		$required = [
			'mini-boss-idle'
			, 'mini-boss-damaged'
			, 'mini-boss-dead'
			, 'spike-bomb'
			, 'spike-fragment'
			, 'boss-explosion'
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
			throw new \UnexpectedValueException('Missing mini-boss sprite classes: ' . implode(', ', $missing));
		}

		return $result;
	}

	/**
	 * Normalize editor object labels for case- and punctuation-insensitive matching.
	 *
	 * @param string $class Raw Tiled class, type, or name.
	 * @return string Lowercase alphanumeric object label.
	 */
	private function normalize(string $class): string
	{
		return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $class));
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
	 * @param float $default Default pixels per frame squared when absent.
	 * @return float Configured acceleration in pixels per second squared.
	 */
	private function perSecondSquared(array $properties, string $name, float $default): float
	{
		return (float)($properties[$name] ?? $default) * 3600;
	}

	/**
	 * Destroy one owned live entity and clear its sweep bookkeeping.
	 *
	 * @param int $entity Owned entity to retire if it remains live.
	 * @return void Leaves unknown or already removed IDs unchanged.
	 */
	private function retire(int $entity): void
	{
		if($this->registry->alive($entity))
		{
			$this->registry->destroy($entity);
		}

		unset($this->owned[$entity], $this->bosses[$entity]);
	}

	/**
	 * Destroy every boss, projectile, fragment, and effect owned by this level.
	 *
	 * @return void Clears source and sweep histories and makes repeated cleanup safe.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach(array_keys($this->owned) as $entity)
		{
			$this->retire($entity);
		}

		$this->sources = $this->players = $this->bosses = [];
	}
}

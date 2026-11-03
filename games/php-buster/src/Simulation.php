<?php
declare(strict_types=1);
namespace PhpBuster;

/** Deterministic 60 Hz Blockade Buster simulation with legacy wave timing expressed in seconds. */
final class Simulation
{
	public const WIDTH = 320;
	public const HEIGHT = 480;
	public const STEP = 1 / 60;
	public const LEGACY_TICK = .045;
	public const HIT_GRACE = .45;
	public const MOVE_SPEED = 240;

	public int $level = 1;
	public int $stage = 1;
	public int $score = 0;
	public int $shields = 5;
	public int $weapon = 0;
	public int $hits = 0;
	public int $shots = 0;
	public int $accuracy = 0;
	public int $bonus = 0;
	public string $phase = 'playing';
	public float $scroll = 0;
	public float $hitGrace = 0;
	public float $stageElapsed = 0;
	public float $intermission = 0;
	/** @var list<Actor> */
	public array $actors = [];
	/** @var list<string> */
	public array $events = [];
	public Actor $player;

	private Random $random;
	private int $nextId = 1;
	private array $schedule = [];
	private int $scheduleIndex = 0;
	private float $fireCooldown = 0;

	/**
	 * Start a reproducible run at level one and build its first wave.
	 *
	 * @param int $seed Positive deterministic random seed.
	 * @return void The initial player and stage schedule are created.
	 */
	public function __construct(public readonly int $seed = 1)
	{
		$this->random = new Random($seed);
		$this->player = $this->actor('player', 130, 400, 60, 70, team: 0, hits: 5, strength: 0);
		$this->actors[] = $this->player;
		$this->buildStage();
	}

	/**
	 * Advance gameplay by one fixed step.
	 *
	 * @param float $moveX Signed horizontal action value.
	 * @param float $moveY Signed vertical action value.
	 * @param bool $fire Whether the fire action is held.
	 * @param null|float $pointerX Optional absolute logical pointer coordinate.
	 * @param null|float $pointerY Optional absolute logical pointer coordinate.
	 * @return void The current simulation state is advanced in place.
	 */
	public function step(
		float $moveX = 0
		, float $moveY = 0
		, bool $fire = false
		, ?float $pointerX = null
		, ?float $pointerY = null
	): void {
		$this->events = [];
		$this->scroll = fmod($this->scroll + 2 / self::LEGACY_TICK * self::STEP, self::HEIGHT);

		foreach($this->actors as $actor)
		{
			$actor->previousX = $actor->x;
			$actor->previousY = $actor->y;
		}

		if($this->phase === 'gameover')
		{
			$this->animateEffects();
			$this->prune();

			return;
		}

		$this->movePlayer($moveX, $moveY, $pointerX, $pointerY);
		$this->hitGrace = max(0, $this->hitGrace - self::STEP);

		if($this->phase === 'playing')
		{
			$this->stageElapsed += self::STEP;
			$this->spawnSchedule();
			$this->fire($fire);
		} else
		{
			$this->intermission = max(0, $this->intermission - self::STEP);
			$this->fire(false);
		}

		$this->enemyAi();
		$this->moveActors();
		$this->collide();
		$this->animateEffects();
		$this->prune();

		if($this->phase === 'intermission' && $this->intermission <= 0 && $this->shields > 0)
		{
			$this->advanceStage();
		}
	}

	/**
	 * Remove and return transient events for an audio or telemetry host.
	 *
	 * @return list<string> Events produced since the previous read.
	 */
	public function consumeEvents(): array
	{
		$events = $this->events;
		$this->events = [];

		return $events;
	}

	/**
	 * Apply pointer or normalized directional movement to the player.
	 *
	 * @param float $moveX Signed horizontal action value.
	 * @param float $moveY Signed vertical action value.
	 * @param null|float $pointerX Optional absolute logical pointer coordinate.
	 * @param null|float $pointerY Optional absolute logical pointer coordinate.
	 * @return void The player remains fully inside the playfield.
	 */
	private function movePlayer(
		float $moveX
		, float $moveY
		, ?float $pointerX
		, ?float $pointerY
	): void {
		if($pointerX !== null && $pointerY !== null && is_finite($pointerX) && is_finite($pointerY))
		{
			$this->player->x = $pointerX - $this->player->width / 2;
			$this->player->y = $pointerY - $this->player->height / 2;
		} else
		{
			$moveX = max(-1, min(1, $moveX));
			$moveY = max(-1, min(1, $moveY));
			$length = hypot($moveX, $moveY);

			if($length > 1)
			{
				$moveX /= $length;
				$moveY /= $length;
			}

			$this->player->x += $moveX * self::MOVE_SPEED * self::STEP;
			$this->player->y += $moveY * self::MOVE_SPEED * self::STEP;
		}

		$this->player->x = max(0, min(self::WIDTH - $this->player->width, $this->player->x));
		$this->player->y = max(0, min(self::HEIGHT - $this->player->height, $this->player->y));
		$this->player->age += self::STEP;
		$this->player->frame = (int)floor($this->player->age / self::LEGACY_TICK) % 3;
	}

	/**
	 * Update the original held-fire cadence and emit a missile when ready.
	 *
	 * @param bool $fire Whether the fire action is held this step.
	 * @return void Missile and shot state are updated in place.
	 */
	private function fire(bool $fire): void
	{
		if(!$fire)
		{
			$this->fireCooldown = 0;

			return;
		}

		$this->fireCooldown -= self::STEP;

		if($this->fireCooldown > 0) return;

		[$width,$height] = [[36,18],[47,37],[50,22],[62,40]][$this->weapon];

		$this->actors[] = $this->actor(
			'missile'
			, $this->player->x + ($this->player->width - $width) / 2
			, $this->player->y - $height / 2
			, $width
			, $height
			, 0
			, -10 / self::LEGACY_TICK
			, 0
			, 1
			, 0
			, $this->weapon
		);

		$this->shots++;
		$this->fireCooldown += (6 - $this->weapon) * self::LEGACY_TICK;
	}

	/**
	 * Materialize every scheduled actor due on the current stage tick.
	 *
	 * @return void The schedule cursor and actor collection are updated.
	 */
	private function spawnSchedule(): void
	{
		while(isset($this->schedule[$this->scheduleIndex])
			&& $this->schedule[$this->scheduleIndex]['time'] <= $this->stageElapsed + 1e-9
		){
			$entry = $this->schedule[$this->scheduleIndex++];
			$this->spawn($entry['type'], $entry);
		}
	}

	/**
	 * Create one scheduled obstacle or enemy from normalized wave data.
	 *
	 * @param string $type Scheduled actor family.
	 * @param array<string,int|float|string> $data Spawn coordinates and variant metadata.
	 * @return void The new actor is appended to the active collection.
	 */
	private function spawn(string $type, array $data): void
	{
		if($type === 'rock')
		{
			$variant = $data['variant'];
			$size = $variant < 4 ? 50 : 29;

			$this->actors[] = $this->actor(
				'rock'
				, $data['x']
				, 1 - $size
				, $size
				, $size
				, $data['direction'] * ($this->level + 1) / self::LEGACY_TICK
				, 2 * ($this->level + 1) / self::LEGACY_TICK
				, 1
				, 1
				, 1
				, $variant
			);

			return;
		}

		if($type === 'midi')
		{
			$actor = $this->actor('midi', $data['x'], -35, 62, 36, 0, (3 + $this->level) / self::LEGACY_TICK, 1, 1, 2);
			$actor->timer = 20 * self::LEGACY_TICK;
			$this->actors[] = $actor;

			return;
		}

		if($type === 'mini')
		{
			$actor = $this->actor('mini', $data['x'], -31, 42, 32, 0, 3 / self::LEGACY_TICK, 1, 3, 5);
			$actor->timer = max(self::LEGACY_TICK, (40 - 3 * $this->level) * self::LEGACY_TICK);
			$this->actors[] = $actor;

			return;
		}

		$actor = $this->actor('maxi', $data['x'], -53, 62, 54, 0, 2 / self::LEGACY_TICK, 1, 5, 50);
		$actor->timer = max(self::LEGACY_TICK, (60 - 3 * $this->level) * self::LEGACY_TICK);
		$this->actors[] = $actor;
	}

	/**
	 * Advance fighter movement decisions and projectile firing timers.
	 *
	 * @return void Enemy velocities and spawned hostile shots are updated.
	 */
	private function enemyAi(): void
	{
		$spawned = [];

		foreach($this->actors as $actor)
		{
			if(!$actor->alive || !in_array($actor->type, ['midi','mini','maxi'], true)) continue;

			$actor->timer -= self::STEP;

			if($actor->timer > 0) continue;

			if($actor->type === 'midi')
			{
				$actor->timer += 20 * self::LEGACY_TICK;

				if($actor->y >= self::HEIGHT * .8) $actor->vy = -$actor->vy - 1 / self::LEGACY_TICK;

				$actor->vx = ($this->player->x < $actor->x ? -1 : 1)
					* (3 + $this->level) / self::LEGACY_TICK;
			}
			elseif($actor->type === 'mini')
			{
				$actor->timer += max(self::LEGACY_TICK, (40 - 3 * $this->level) * self::LEGACY_TICK);
				$actor->y -= 10;

				if($this->player->x > $actor->x - 42 && $this->player->x < $actor->x + 84)
				$spawned[] = $this->actor(
					'bomb'
					, $actor->x + 16
					, $actor->y + 28
					, 10
					, 10
					, 0
					, (5 + $this->level) / self::LEGACY_TICK
					, 1
					, 1
					, 2
				);

				if($actor->y >= self::HEIGHT / 2) $actor->vy = -$actor->vy - 1 / self::LEGACY_TICK;

				$actor->x += $this->random->int(2) === 0 ? 1 : 0;
			}
			else
			{
				$actor->timer += max(self::LEGACY_TICK, (60 - 3 * $this->level) * self::LEGACY_TICK);

				foreach([0, 2, -2] as $direction)
				$spawned[] = $this->actor(
					'flare'
					, $actor->x + 17
					, $actor->y + 28
					, 28
					, 27
					, $direction * 5 / self::LEGACY_TICK
					, (7 + $this->level) / self::LEGACY_TICK
					, 1
					, 2
					, 3
				);

				if($actor->y >= self::HEIGHT / 2) $actor->vy = -$actor->vy;
			}
		}

		array_push($this->actors, ...$spawned);
	}

	/**
	 * Integrate non-player actors and retire those outside the playfield.
	 *
	 * @return void Actor poses and animation frames are updated in place.
	 */
	private function moveActors(): void
	{
		foreach($this->actors as $actor)
		{
			if(!$actor->alive || $actor === $this->player || $actor->type === 'explosion') continue;

			$actor->x += $actor->vx * self::STEP;
			$actor->y += $actor->vy * self::STEP;
			$actor->age += self::STEP;
			$frames = in_array($actor->type, ['flare','missile'], true)
				? 4
				: (in_array($actor->type, ['midi','mini','maxi'], true) ? 3 : 1);

			$actor->frame = $frames > 1 ? (int)floor($actor->age / self::LEGACY_TICK) % $frames : 0;

			if($actor->x + $actor->width < 0 || $actor->x > self::WIDTH
				|| $actor->y + $actor->height < 0 || $actor->y > self::HEIGHT
			) $actor->alive = false;
		}
	}

	/**
	 * Resolve pickups, player impacts, missiles, and hostile separation.
	 *
	 * @return void Scores, shields, damage, and actor positions are updated.
	 */
	private function collide(): void
	{
		foreach($this->actors as $pickup)
		{
			if(!$pickup->alive || !in_array($pickup->type, ['ruby','shield'], true)
				|| !$this->overlaps($this->player, $pickup)
			) continue;

			$pickup->alive = false;

			if($pickup->type === 'ruby')
			{
				$this->weapon = min(3, $this->weapon + 1);
				$this->score += 100;
			} else
			{
				$this->shields++;
				$this->player->hits = $this->shields;
				$this->score += 10;
			}
		}

		foreach($this->actors as $missile)
		{
			if(!$missile->alive || $missile->type !== 'missile') continue;

			foreach($this->actors as $target)
			{
				if(!$target->alive || $target->team !== 1 || !$this->overlaps($missile, $target)) continue;

				$missile->alive = false;
				$this->hits++;
				$this->score += $target->strength;
				$this->damage($target);
				break;
			}
		}

		if($this->hitGrace <= 0)
		foreach($this->actors as $hostile)
		{
			if(!$hostile->alive || $hostile->team !== 1 || !$this->overlaps($this->player, $hostile)) continue;

			$this->weapon = 0;
			$this->shields--;
			$this->player->hits = max(0, $this->shields);
			$this->hitGrace = self::HIT_GRACE;
			$this->explode($this->player->x, $this->player->y);
			$this->damage($hostile);

			if($this->shields <= 0)
			{
				$this->shields = 0;
				$this->player->alive = false;
				$this->phase = 'gameover';
				$this->events[] = 'gameover';
			}

			break;
		}

		$count = count($this->actors);

		for($a = 0; $a < $count; $a++)
		for($b = $a + 1; $b < $count; $b++)
		{
			$one = $this->actors[$a];
			$two = $this->actors[$b];

			if(!$one->alive || !$two->alive || $one->team !== 1 || $two->team !== 1
				|| !$this->overlaps($one, $two)
			) continue;

			if($one->x < $two->x)
			{
				$one->x--;
				$two->x++;
			}
			else
			{
				$one->x++;
				$two->x--;
			}
		}
	}

	/**
	 * Apply one hit and process actor-specific destruction rewards.
	 *
	 * @param Actor $actor Target receiving the hit.
	 * @return void The actor, score, pickups, or stage phase may change.
	 */
	private function damage(Actor $actor): void
	{
		$actor->hits--;
		$this->explode($actor->x, $actor->y);

		if($actor->hits > 0) return;

		$actor->alive = false;

		if($actor->type === 'mini')
		$this->actors[] = $this->actor('shield', $actor->x + 7, $actor->y + 1, 28, 30, 0, -1 / self::LEGACY_TICK, 3);

		if($actor->type === 'maxi')
		{
			$this->actors[] = $this->actor(
				'ruby'
				, $actor->x + 18
				, $actor->y + 17
				, 26
				, 20
				, 0
				, -1 / self::LEGACY_TICK
				, 3
			);

			$this->accuracy = $this->shots > 0 ? intdiv($this->hits * 100, $this->shots) : 0;
			$this->bonus = $this->level * $this->accuracy;
			$this->score += $this->bonus;
			$this->hits = $this->shots = 0;
			$this->phase = 'intermission';
			$this->intermission = 100 * self::LEGACY_TICK;
			$this->events[] = 'stage-complete';
		}
	}

	/**
	 * Spawn an explosion and emit its transient sound event.
	 *
	 * @param float $x Horizontal logical position.
	 * @param float $y Vertical logical position.
	 * @return void The effect actor and event are appended.
	 */
	private function explode(float $x, float $y): void
	{
		$this->actors[] = $this->actor('explosion', $x, $y, 48, 44, team: 2, hits: 1, strength: 0);
		$this->events[] = 'explosion';
	}

	/**
	 * Advance explosion frames and retire completed effects.
	 *
	 * @return void Effect ages and frames are updated in place.
	 */
	private function animateEffects(): void
	{
		foreach($this->actors as $actor)
		{
			if(!$actor->alive || $actor->type !== 'explosion') continue;

			$actor->age += self::STEP;
			$actor->frame = min(6, (int)floor($actor->age / self::LEGACY_TICK));

			if($actor->age >= 7 * self::LEGACY_TICK) $actor->alive = false;
		}
	}

	/**
	 * Test the strict overlap of two actor collision rectangles.
	 *
	 * @param Actor $one First collision participant.
	 * @param Actor $two Second collision participant.
	 * @return bool True when both collision rectangles overlap.
	 */
	private function overlaps(Actor $one, Actor $two): bool
	{
		[$ax,$ay,$aw,$ah] = $one->bounds();
		[$bx,$by,$bw,$bh] = $two->bounds();

		return $ax < $bx + $bw && $ax + $aw > $bx && $ay < $by + $bh && $ay + $ah > $by;
	}

	/**
	 * Remove actors marked dead while preserving list order.
	 *
	 * @return void The actor list contains only live entries afterward.
	 */
	private function prune(): void
	{

		/**
		 * Select actors that remain active in the simulation.
		 *
		 * @param Actor $actor Candidate actor.
		 * @return bool True when the actor should remain in the list.
		 */
		$alive = static fn(Actor $actor): bool => $actor->alive;
		$this->actors = array_values(array_filter($this->actors, $alive));
	}

	/**
	 * Move to the next stage, wrapping every three stages into a new level.
	 *
	 * @return void A fresh deterministic stage schedule is installed.
	 */
	private function advanceStage(): void
	{
		$this->stage++;

		if($this->stage === 4)
		{
			$this->level++;
			$this->stage = 1;
		}

		$this->phase = 'playing';
		$this->buildStage();
	}

	/**
	 * Construct the original fighter, asteroid, artillery, and heavy-fighter sequence.
	 *
	 * @return void The stage schedule is rebuilt and sorted by spawn time.
	 */
	private function buildStage(): void
	{
		$this->schedule = [];
		$this->scheduleIndex = 0;
		$this->stageElapsed = 0;
		$tick = 1;

		for($wave = 0; $wave < $this->stage + 1; $wave++)
		{
			$this->fighterSquad($tick);
			$direction = 1 - $this->random->int(3);

			for($rock = 0; $rock < 5; $rock++)
			{
				$tick += 10;
				$variant = $this->random->int(8);
				$size = $variant < 4 ? 50 : 29;
				$this->schedule[] = [
					'time' => $tick * self::LEGACY_TICK
					, 'type' => 'rock'
					, 'direction' => $direction
					, 'variant' => $variant
					, 'x' => $this->random->int(self::WIDTH - $size + 1)
				];
			}
		}

		$this->fighterSquad($tick);
		$band = intdiv(self::WIDTH, $this->stage);

		for($index = 0; $index < $this->stage; $index++)
		$this->schedule[] = [
			'time' => ($tick + 10 - $this->random->int(20)) * self::LEGACY_TICK
			, 'type' => 'mini'
			, 'x' => $this->random->int($band) + $index * $band
		];

		$tick += 20;
		$this->schedule[] = [
			'time' => $tick * self::LEGACY_TICK
			, 'type' => 'maxi'
			, 'x' => $this->random->int(self::WIDTH - 62 + 1)
		];

		/**
		 * Sort spawn entries into chronological order.
		 *
		 * @param array<string,int|float|string> $a First schedule entry.
		 * @param array<string,int|float|string> $b Second schedule entry.
		 * @return int Negative, zero, or positive order comparison.
		 */
		$byTime = static fn(array $a, array $b): int => $a['time'] <=> $b['time'];
		usort($this->schedule, $byTime);
	}

	/**
	 * Append the original three medium-fighter formation to a schedule.
	 *
	 * @param int $tick Mutable legacy spawn tick cursor.
	 * @return void Entries are appended and the cursor advances.
	 */
	private function fighterSquad(int &$tick): void
	{
		$this->schedule[] = ['time' => $tick * self::LEGACY_TICK, 'type' => 'midi', 'x' => self::WIDTH / 2];
		$tick += 8;

		for($index = 0; $index < 2; $index++)
		$this->schedule[] = [
			'time' => $tick * self::LEGACY_TICK
			, 'type' => 'midi'
			, 'x' => $this->random->int(160) + $index * 160
		];
	}

	/**
	 * Construct an actor with the next run-local identifier.
	 *
	 * @param string $type Actor behavior and sprite family.
	 * @param float $x Horizontal logical position.
	 * @param float $y Vertical logical position.
	 * @param float $width Collision width in logical pixels.
	 * @param float $height Collision height in logical pixels.
	 * @param float $vx Horizontal velocity in logical pixels per second.
	 * @param float $vy Vertical velocity in logical pixels per second.
	 * @param int $team Collision team identifier.
	 * @param int $hits Initial hit points.
	 * @param int $strength Score value and collision damage metadata.
	 * @param int $variant Sprite or weapon variant index.
	 * @return Actor Newly initialized actor.
	 */
	private function actor(
		string $type
		, float $x
		, float $y
		, float $width
		, float $height
		, float $vx = 0
		, float $vy = 0
		, int $team = 1
		, int $hits = 1
		, int $strength = 1
		, int $variant = 0
	): Actor {
		return new Actor($this->nextId++, $type, $x, $y, $width, $height, $vx, $vy, $team, $hits, $strength, $variant);
	}
}

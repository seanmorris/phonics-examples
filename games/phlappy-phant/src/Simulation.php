<?php
declare(strict_types=1);
namespace PhlappyPhant;

/**
 * Deterministic 60 Hz flap-and-dodge simulation in world space.
 *
 * The phant never moves horizontally: it sits at world x zero while pillars scroll toward it, so the simulation
 * depends on the logical height alone and any viewport width only changes how far ahead a player can see. The world
 * is 352 units tall (ground at 288 plus two ground rows), small enough that even a 1x desktop frame draws it at 2x.
 */
final class Simulation
{
	public const HEIGHT = 352;
	public const STEP = 1 / 60;
	public const GROUND_Y = 288;
	public const PHANT_W = 64;
	public const PHANT_H = 52;
	public const PHANT_START_Y = 130;
	public const HITBOX_INSET_X = 8;
	public const HITBOX_INSET_Y = 8;
	public const GRAVITY = 1300;
	public const FLAP_VY = -330;
	public const MAX_FALL = 600;
	/** Pillar width including its outline, matching the terrain sheet's 56-pixel pillar. */
	public const PILLAR_W = 56;
	public const PILLAR_SPACING = 170;
	public const FIRST_PILLAR_X = 420;
	public const LOOKAHEAD = 1200;
	public const DESPAWN_X = -320;
	public const SPEED_BASE = 120;
	public const SPEED_PER_SCORE = 4;
	public const SPEED_MAX = 200;
	/** The terrain tiles are cut on an 8-unit grid, so every gap edge and pillar height lands on it. */
	public const GRID = 8;
	public const GAP_BASE = 136;
	/** The gap narrows one grid step every this many points. */
	public const GAP_NARROW_EVERY = 8;
	public const GAP_MIN = 104;
	public const GAP_MARGIN = 32;
	public const NIGHT_EVERY = 10;
	public const DEAD_SETTLE = .6;
	public const WING_BEATS = 3;
	public const WING_BEAT = .09;
	/** Steps the tool-assisted player plans ahead: two and a half seconds, two gaps beyond the current one at the
	 * speed cap, so a position that looks safe never turns out to be a corner once the following gap comes into view. */
	public const TAS_HORIZON = 150;
	/** Steps of each plan the tool-assisted player keeps before rebuilding the safe sets. */
	public const TAS_REPLAN = 10;
	/** Fewest steps between two of the tool-assisted player's flaps: 150 ms, a fast but human tapping rate. */
	public const TAS_FLAP_GAP = 12;

	/** One of ready, playing, or dead. */
	public string $phase = 'ready';
	public float $y = self::PHANT_START_Y;
	public float $previousY = self::PHANT_START_Y;
	public float $vy = 0;
	/** Distance the world has scrolled, for ground and cloud parallax. */
	public float $scroll = 0;
	/** Seconds since the run began, for the idle bob and ear animation. */
	public float $age = 0;
	/** Wing frame: zero folded, one spread; the wing beats a few times after each flap, then rests folded. */
	public int $frame = 0;
	public int $score = 0;
	/** Zero for day, one for night. */
	public int $palette = 0;
	/** Whether the dead phant has settled and the run may end. */
	public bool $finished = false;
	/** @var list<Pillar> */
	public array $pillars = [];
	/** @var list<string> Events raised by the latest step: flap, score, bonk, or night. */
	public array $events = [];
	/** Whether the built-in tool-assisted player decides every flap; it never misses a gap. */
	public bool $autopilot = false;

	private Random $random;
	private float $deadTimer = 0;
	private float $flappedAt = -1;
	/** Pillar pairs placed so far, numbering each for deterministic scenery. */
	private int $spawned = 0;
	/** @var list<array<int,list<array{float,float}>>> Safe sets for the next few steps of the tool-assisted player. */
	private array $plan = [];

	/**
	 * Start a reproducible run with its first pillars already placed.
	 *
	 * @param int $seed Positive deterministic random seed.
	 * @return void Equal seeds give equal gap positions.
	 */
	public function __construct(public readonly int $seed = 1)
	{
		$this->random = new Random($seed);
		$this->spawn();
	}

	/**
	 * Scroll speed for a score.
	 *
	 * @param int $score Pillars passed so far.
	 * @return float Logical units per second.
	 */
	public static function speedFor(int $score): float
	{
		return (float)min(self::SPEED_MAX, self::SPEED_BASE + self::SPEED_PER_SCORE * $score);
	}

	/**
	 * Gap height for a score.
	 *
	 * @param int $score Pillars passed so far.
	 * @return float Logical units between the two stacks.
	 */
	public static function gapFor(int $score): float
	{
		return (float)max(self::GAP_MIN, self::GAP_BASE - self::GRID * intdiv($score, self::GAP_NARROW_EVERY));
	}

	/**
	 * Palette for a score: day and night alternate every ten pillars.
	 *
	 * @param int $score Pillars passed so far.
	 * @return int Zero for day, one for night.
	 */
	public static function paletteFor(int $score): int
	{
		return intdiv($score, self::NIGHT_EVERY) % 2;
	}

	/**
	 * The phant's collision box in world space.
	 *
	 * @return array{float,float,float,float} Left, top, width, and height.
	 */
	public function hitbox(): array
	{
		return [
			(float)self::HITBOX_INSET_X
			, $this->y + self::HITBOX_INSET_Y
			, (float)(self::PHANT_W - 2 * self::HITBOX_INSET_X)
			, (float)(self::PHANT_H - 2 * self::HITBOX_INSET_Y)
		];
	}

	/**
	 * Advance one fixed step.
	 *
	 * @param bool $flap Whether a flap was pressed since the previous step.
	 * @return void Updates poses, pillars, score, phase, and the step's events.
	 */
	public function step(bool $flap = false): void
	{
		$this->events = [];
		$this->previousY = $this->y;

		if($this->autopilot && $this->phase !== 'dead') $flap = $this->plannedFlap();

		foreach($this->pillars as $pillar) $pillar->previousX = $pillar->x;

		$this->age += self::STEP;

		if($this->phase === 'ready')
		{
			$this->y = self::PHANT_START_Y + 6 * sin($this->age * 4);
			$this->scroll += self::SPEED_BASE * self::STEP;
			$this->frame = (int)floor($this->age * 2) % 2;

			if(!$flap) return;

			$this->phase = 'playing';
			$this->y = self::PHANT_START_Y;
		}

		if($this->phase === 'playing')
		{
			if($flap)
			{
				$this->vy = self::FLAP_VY;
				$this->flappedAt = $this->age;
				$this->events[] = 'flap';
			}

			$this->vy = min(self::MAX_FALL, $this->vy + self::GRAVITY * self::STEP);
			$this->y = max(0, $this->y + $this->vy * self::STEP);
			$since = $this->age - $this->flappedAt;
			$this->frame = $since < self::WING_BEATS * self::WING_BEAT && (int)floor($since / (self::WING_BEAT / 2)) % 2 === 0
				? 1 : 0;
			$speed = self::speedFor($this->score);

			foreach($this->pillars as $pillar) $pillar->x -= $speed * self::STEP;

			$this->scroll += $speed * self::STEP;
			$this->spawn();
			$this->pillars = array_values(array_filter(
				$this->pillars
				, static fn(Pillar $pillar): bool => $pillar->x + self::PILLAR_W >= self::DESPAWN_X
			));

			foreach($this->pillars as $pillar)
			{
				if($pillar->scored || $pillar->x + self::PILLAR_W >= 0) continue;

				$pillar->scored = true;
				$this->score++;
				$this->events[] = 'score';
				$palette = self::paletteFor($this->score);

				if($palette !== $this->palette)
				{
					$this->palette = $palette;
					$this->events[] = 'night';
				}
			}

			if($this->collides()) $this->die();

			return;
		}

		// The feet (the hitbox bottom) rest on the ground line; the sprite's last rows overlap the soil.
		$rest = self::GROUND_Y - self::PHANT_H + self::HITBOX_INSET_Y;

		if($this->y < $rest)
		{
			$this->vy = min(self::MAX_FALL, $this->vy + self::GRAVITY * self::STEP);
			$this->y = min($rest, $this->y + $this->vy * self::STEP);

			return;
		}

		$this->y = (float)$rest;

		$this->vy = 0;
		$this->deadTimer += self::STEP;
		$this->finished = $this->deadTimer >= self::DEAD_SETTLE;
	}

	/**
	 * Decide the tool-assisted player's flap for the coming step.
	 *
	 * The player flaps only when coasting would leave the safe set: the positions from which some sequence of flaps
	 * keeps the hitbox inside every gap and off the ground for the next two and a half seconds, with flaps no closer
	 * together than a human could tap. That set is built backward from
	 * the horizon over the velocity lattice (a flap always resets the velocity, so the velocity is just the number of
	 * steps since the last flap), with the pillars advanced exactly as the run will advance them. Flapping as late as
	 * possible keeps the flight low and smooth; the horizon always covers the entry into the next gap.
	 *
	 * @return bool True to flap this step.
	 */
	public function plannedFlap(): bool
	{
		if($this->phase === 'ready') return true;

		if(!$this->plan) $this->plan = $this->planRows();

		$row = array_shift($this->plan);
		$gravity = self::GRAVITY * self::STEP;
		$flapVy = self::FLAP_VY + $gravity;
		$lattice = count($row) - 1;
		$k = $this->vy >= self::MAX_FALL
			? $lattice
			: max(0, min($lattice, (int)round(($this->vy - $flapVy) / $gravity)));
		$coasted = min($lattice, $k + 1);
		$top = $this->y + self::HITBOX_INSET_Y + min(self::MAX_FALL, $flapVy + $coasted * $gravity) * self::STEP;

		foreach($row[$coasted] as [$a, $b]) if($top >= $a && $top <= $b) return false;

		// The table never relies on a flap sooner than a human could tap; if coasting is unsafe here, a flap is due.
		return $k + 1 >= self::TAS_FLAP_GAP;
	}

	/**
	 * Build the safe sets for the next few steps from a full backward pass over the horizon.
	 *
	 * The pillars only ever scroll, so rows near the present stay valid as the run advances; the planner keeps the
	 * first few rows and rebuilds when they run out, which spreads the cost of the pass over several steps.
	 *
	 * @return list<array<int,list<array{float,float}>>> One row per upcoming step, each indexed by steps since a flap.
	 */
	private function planRows(): array
	{
		$steps = self::TAS_HORIZON;
		$gravity = self::GRAVITY * self::STEP;
		$flapVy = self::FLAP_VY + $gravity;
		$lattice = (int)ceil((self::MAX_FALL - $flapVy) / $gravity);
		$velocity = [];

		for($k = 0; $k <= $lattice; $k++) $velocity[$k] = min(self::MAX_FALL, $flapVy + $k * $gravity);

		// Allowed hitbox-top band per future step, shrunk by a unit so float drift never counts as safe.
		[$left, , $boxWidth, $boxHeight] = $this->hitbox();
		$xs = [];
		$scored = [];

		foreach($this->pillars as $j => $pillar)
		{
			$xs[$j] = $pillar->x;
			$scored[$j] = $pillar->scored;
		}

		$score = $this->score;
		$allowed = [];

		for($t = 1; $t <= $steps; $t++)
		{
			$speed = self::speedFor($score) * self::STEP;
			// The run clamps the sprite at the ceiling, so the hitbox top never rises above its inset.
			$low = self::HITBOX_INSET_Y + 1.0;
			$high = self::GROUND_Y - $boxHeight - 1.0;

			foreach($this->pillars as $j => $pillar)
			{
				$xs[$j] -= $speed;

				if(!$scored[$j] && $xs[$j] + self::PILLAR_W < 0)
				{
					$scored[$j] = true;
					$score++;
				}

				if($xs[$j] >= $left + $boxWidth || $xs[$j] + self::PILLAR_W <= $left) continue;

				$low = max($low, $pillar->gapTop + 1.0);
				$high = min($high, $pillar->gapTop + $pillar->gap - $boxHeight - 1.0);
			}

			$allowed[$t] = $low <= $high ? [[$low, $high]] : [];
		}

		// safe[k]: hitbox-top intervals, with k steps since the last flap, from which survival is possible at the step
		// being filled; each pass moves one step closer to now. Sets stay tiny, so they are flat sorted lists of
		// [low, high] pairs merged inline rather than through helpers.
		$safe = array_fill(0, $lattice + 1, $allowed[$steps]);
		$coastBy = [];

		for($k = 0; $k <= $lattice; $k++) $coastBy[$k] = -$velocity[min($lattice, $k + 1)] * self::STEP;

		$flapBy = -$velocity[0] * self::STEP;
		$rows = [];

		for($t = $steps - 1; $t >= 1; $t--)
		{
			if(!$allowed[$t])
			{
				$safe = array_fill(0, $lattice + 1, []);

				if($t <= self::TAS_REPLAN) $rows[$t] = $safe;

				continue;
			}

			[$low, $high] = $allowed[$t][0];
			$next = $safe;
			$flapped = [];

			foreach($next[0] as [$a, $b])
			{
				$a = max($a + $flapBy, $low);
				$b = min($b + $flapBy, $high);

				if($a <= $b) $flapped[] = [$a, $b];
			}

			for($k = 0; $k <= $lattice; $k++)
			{
				$by = $coastBy[$k];
				// A flap is only an option once the human-speed interval since the last one has passed.
				$set = $k + 1 >= self::TAS_FLAP_GAP ? $flapped : [];

				foreach($next[min($lattice, $k + 1)] as [$a, $b])
				{
					$a = max($a + $by, $low);
					$b = min($b + $by, $high);

					if($a <= $b) $set[] = [$a, $b];
				}

				if(count($set) > 1)
				{
					usort($set, static fn(array $p, array $q): int => $p[0] <=> $q[0]);
					$merged = [];

					foreach($set as $interval)
					{
						$last = count($merged) - 1;

						if($last >= 0 && $interval[0] <= $merged[$last][1] + 1e-6)
						$merged[$last][1] = max($merged[$last][1], $interval[1]);
						else $merged[] = $interval;
					}

					$set = $merged;
				}

				$safe[$k] = $set;
			}

			if($t <= self::TAS_REPLAN) $rows[$t] = $safe;
		}

		ksort($rows);

		return array_values($rows);
	}

	/**
	 * Return and clear the events raised by the latest step.
	 *
	 * @return list<string> Event names in the order they happened.
	 */
	public function consumeEvents(): array
	{
		$events = $this->events;
		$this->events = [];

		return $events;
	}

	/**
	 * The next pair the phant has not passed, for status reporting.
	 *
	 * @return Pillar|null The nearest unscored pillar.
	 */
	public function nextPillar(): ?Pillar
	{
		foreach($this->pillars as $pillar) if(!$pillar->scored) return $pillar;

		return null;
	}

	/** Place pillars until the lookahead distance is covered, each gap drawn from the seeded sequence. */
	private function spawn(): void
	{
		$last = $this->pillars ? end($this->pillars)->x : self::FIRST_PILLAR_X - self::PILLAR_SPACING;

		while($last + self::PILLAR_SPACING <= self::LOOKAHEAD)
		{
			$last += self::PILLAR_SPACING;
			$gap = self::gapFor($this->score);
			$steps = intdiv((int)(self::GROUND_Y - $gap - 2 * self::GAP_MARGIN), self::GRID);
			$this->pillars[] = new Pillar(
				$last
				, self::GAP_MARGIN + self::GRID * $this->random->int($steps + 1)
				, $gap
				, $this->spawned++
			);
		}
	}

	/** Whether the hitbox overlaps a stack or rests on the ground. */
	private function collides(): bool
	{
		[$left, $top, $width, $height] = $this->hitbox();

		if($top + $height >= self::GROUND_Y) return true;

		foreach($this->pillars as $pillar)
		{
			if($pillar->x >= $left + $width || $pillar->x + self::PILLAR_W <= $left) continue;
			if($top < $pillar->gapTop || $top + $height > $pillar->gapTop + $pillar->gap) return true;
		}

		return false;
	}

	/** End the run: stop the world, raise the bonk, and let the phant fall. */
	private function die(): void
	{
		$this->phase = 'dead';
		$this->vy = min(0.0, $this->vy);
		$this->events[] = 'bonk';
	}
}

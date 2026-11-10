<?php
declare(strict_types=1);
namespace PhlappyPhant;

/**
 * Deterministic 60 Hz flap-and-dodge simulation in world space.
 *
 * The phant never moves horizontally: it sits at world x zero while barrels scroll toward it, so the simulation
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
	public const BARREL_W = 52;
	public const BARREL_SPACING = 170;
	public const FIRST_BARREL_X = 420;
	public const LOOKAHEAD = 1200;
	public const DESPAWN_X = -320;
	public const SPEED_BASE = 120;
	public const SPEED_PER_SCORE = 4;
	public const SPEED_MAX = 200;
	public const GAP_BASE = 132;
	public const GAP_PER_SCORE = 1;
	public const GAP_MIN = 100;
	public const GAP_MARGIN = 32;
	public const NIGHT_EVERY = 10;
	public const DEAD_SETTLE = .6;
	public const WING_BEATS = 3;
	public const WING_BEAT = .09;

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
	/** @var list<Barrel> */
	public array $barrels = [];
	/** @var list<string> Events raised by the latest step: flap, score, bonk, or night. */
	public array $events = [];

	private Random $random;
	private float $deadTimer = 0;
	private float $flappedAt = -1;

	/**
	 * Start a reproducible run with its first barrels already placed.
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
	 * @param int $score Barrels passed so far.
	 * @return float Logical units per second.
	 */
	public static function speedFor(int $score): float
	{
		return (float)min(self::SPEED_MAX, self::SPEED_BASE + self::SPEED_PER_SCORE * $score);
	}

	/**
	 * Gap height for a score.
	 *
	 * @param int $score Barrels passed so far.
	 * @return float Logical units between the two stacks.
	 */
	public static function gapFor(int $score): float
	{
		return (float)max(self::GAP_MIN, self::GAP_BASE - self::GAP_PER_SCORE * $score);
	}

	/**
	 * Palette for a score: day and night alternate every ten barrels.
	 *
	 * @param int $score Barrels passed so far.
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
	 * @return void Updates poses, barrels, score, phase, and the step's events.
	 */
	public function step(bool $flap = false): void
	{
		$this->events = [];
		$this->previousY = $this->y;

		foreach($this->barrels as $barrel) $barrel->previousX = $barrel->x;

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

			foreach($this->barrels as $barrel) $barrel->x -= $speed * self::STEP;

			$this->scroll += $speed * self::STEP;
			$this->spawn();
			$this->barrels = array_values(array_filter(
				$this->barrels
				, static fn(Barrel $barrel): bool => $barrel->x + self::BARREL_W >= self::DESPAWN_X
			));

			foreach($this->barrels as $barrel)
			{
				if($barrel->scored || $barrel->x + self::BARREL_W >= 0) continue;

				$barrel->scored = true;
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
	 * @return Barrel|null The nearest unscored barrel.
	 */
	public function nextBarrel(): ?Barrel
	{
		foreach($this->barrels as $barrel) if(!$barrel->scored) return $barrel;

		return null;
	}

	/** Place barrels until the lookahead distance is covered, each gap drawn from the seeded sequence. */
	private function spawn(): void
	{
		$last = $this->barrels ? end($this->barrels)->x : self::FIRST_BARREL_X - self::BARREL_SPACING;

		while($last + self::BARREL_SPACING <= self::LOOKAHEAD)
		{
			$last += self::BARREL_SPACING;
			$gap = self::gapFor($this->score);
			$range = (int)(self::GROUND_Y - $gap - 2 * self::GAP_MARGIN);
			$this->barrels[] = new Barrel($last, self::GAP_MARGIN + $this->random->int($range + 1), $gap);
		}
	}

	/** Whether the hitbox overlaps a stack or rests on the ground. */
	private function collides(): bool
	{
		[$left, $top, $width, $height] = $this->hitbox();

		if($top + $height >= self::GROUND_Y) return true;

		foreach($this->barrels as $barrel)
		{
			if($barrel->x >= $left + $width || $barrel->x + self::BARREL_W <= $left) continue;
			if($top < $barrel->gapTop || $top + $height > $barrel->gapTop + $barrel->gap) return true;
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

<?php
declare(strict_types=1);
namespace PhpSlalom;

/** Deterministic fixed-step Jet Slalom simulation, independent of rendering and browser state. */
final class Simulation
{
	public const STEP = .055;
	public const START_SCORES = [0, 8000, 12000, 25000, 40000, 100000];
	public const PALETTE = [
		[192,192,192]
		, [96,160,240]
		, [200,128,0]
		, [240,210,100]
	];

	/** @var list<Obstacle> Newest, farthest obstacles first. */
	public array $obstacles = [];
	/** @var list<Stage> */
	public array $stages;
	public int $stage = 0;
	public int $score = 0;
	public int $ticks = 0;
	public float $velocity = 0;
	public float $previousVelocity = 0;
	public int $crashTicks = 0;
	public bool $crashed = false;
	public bool $finished = false;
	public bool $impact = false;
	private Random $random;

	/**
	 * Create a deterministic run from a seed and one of the selectable starting stages.
	 *
	 * @param int $seed Random seed shared by native and WebAssembly runs.
	 * @param int $startStage Index into START_SCORES for the first stage.
	 * @return void Invalid starting stages throw before any state is created.
	 */
	public function __construct(public readonly int $seed, int $startStage = 0)
	{
		if($startStage < 0 || $startStage >= count(self::START_SCORES))
		throw new \InvalidArgumentException('Invalid starting stage');

		$this->stages = [
			new Stage(8000, [0,160,255], [0,200,64], 4)
			, new Stage(12000, [240,160,160], [64,180,64], 3)
			, new Stage(25000, [0,0,0], [0,128,64], 2)
			, new Stage(40000, [0,180,240], [0,200,64], 1, true)
			, new Stage(100000, [192,192,192], [64,180,64], 1, true, true)
			, new Stage(PHP_INT_MAX, [0,0,0], [0,128,64], 1)
		];

		foreach($this->stages as $stage) $stage->reset();
		$this->stage = $startStage;
		$this->score = self::START_SCORES[$startStage];
		$this->random = new Random($seed);
	}

	/**
	 * Advance one 55ms gameplay tick from held left and right actions.
	 *
	 * @param bool $left Whether steer-left is held.
	 * @param bool $right Whether steer-right is held.
	 * @return void Score, obstacles, and crash state are updated in place.
	 */
	public function step(bool $left, bool $right): void
	{
		$this->impact = false;

		if($this->finished) return;

		if($this->crashed)
		{
			$this->crashTicks++;

			if($this->crashTicks >= 20) $this->finished = true;

			return;
		}

		while($this->stage < count($this->stages) - 1
			&& $this->score >= $this->stages[$this->stage]->threshold
		) $this->stage++;

		$this->previousVelocity = $this->velocity;

		if($right) $this->velocity -= .1;

		if($left) $this->velocity += .1;

		$this->velocity = max(-.6, min(.6, $this->velocity));

		if(!$left && !$right)
		{
			if($this->velocity < 0) $this->velocity = min(0, $this->velocity + .025);

			if($this->velocity > 0) $this->velocity = max(0, $this->velocity - .025);
		}

		$cos = $this->bank()[1];
		$halfWidth = .7 * $cos;
		$remaining = [];

		foreach($this->obstacles as $obstacle)
		{
			if($obstacle->z + .5 <= 2.1)
			{
				if(-$halfWidth < $obstacle->x + $obstacle->width
					&& $obstacle->x - $obstacle->width < $halfWidth
				) $this->impact = true;
			} else
			{
				$obstacle->move($this->velocity, -1);
				$remaining[] = $obstacle;
			}
		}

		$this->obstacles = $remaining;
		$stage = $this->stages[$this->stage];

		if($stage->road)
		{
			$stage->roadLeft += $this->velocity;
			$stage->roadRight += $this->velocity;
		}

		$this->generate($stage);
		$this->score += 20;
		$this->ticks++;

		if($this->impact)
		{
			$this->crashed = true;
			$this->crashTicks = 0;
		}
	}

	/**
	 * Compute the bank sine and cosine from the original 128-entry response table.
	 *
	 * @param float $alpha Interpolation between the previous and current tick.
	 * @return array{float,float} Bank sine and cosine.
	 */
	public function bank(float $alpha = 1): array
	{
		$velocity = $this->previousVelocity + ($this->velocity - $this->previousVelocity) * $alpha;
		$angle = M_PI * (int)(abs($velocity) * 100) / 450;
		$sin = sin($angle);

		if($velocity > 0) $sin = -$sin;

		return [$sin, cos($angle)];
	}

	/**
	 * Blend the sky from the prior stage over a new stage's first 32 ticks.
	 *
	 * @return array{int,int,int} Sky RGB color.
	 */
	public function sky(): array
	{
		$current = $this->stages[$this->stage];

		if($this->stage === 0 || $current->time > 32) return $current->sky;

		$previous = $this->stages[$this->stage - 1]->sky;
		$n = $current->time;

		return [
			intdiv($previous[0] * (32 - $n) + $current->sky[0] * $n, 32)
			, intdiv($previous[1] * (32 - $n) + $current->sky[1] * $n, 32)
			, intdiv($previous[2] * (32 - $n) + $current->sky[2] * $n, 32)
		];
	}

	/**
	 * Report the current stage's ground, which changes immediately when a stage begins.
	 *
	 * @return array{int,int,int} Ground RGB color.
	 */
	public function ground(): array { return $this->stages[$this->stage]->ground; }

	/**
	 * Spawn this tick's gates from open-field timing or the winding road pattern.
	 *
	 * @param Stage $stage Current stage whose generator state advances.
	 * @return void New obstacles are added to the far end of the course.
	 */
	private function generate(Stage $stage): void
	{
		$stage->time++;

		if(!$stage->road)
		{
			$stage->counter++;

			if($stage->counter < $stage->interval) return;

			$stage->counter = 0;
			$this->spawn(($this->random->next() % 256) / 8 - 16, .6);

			return;
		}

		$stage->counter--;
		$width = 1.1;

		if($stage->broken && $stage->counter % 13 < 7)
		{
			$width = .7;
			$x = ($this->random->next() % 256) / 8 - 16;

			if($x < $stage->roadRight && $x > $stage->roadLeft)
			{
				$width = 1.2;
				$x = $this->random->next() % 256 > 128 ? $stage->roadLeft : $stage->roadRight;
			}
		} else $x = $stage->counter % 2 === 0 ? $stage->roadLeft : $stage->roadRight;

		if($stage->roadRight - $stage->roadLeft > 9)
		{
			$stage->roadLeft += .6;
			$stage->roadRight -= .6;

			if($stage->roadRight - $stage->roadLeft > 10) $width = 2;
		} elseif($stage->roadLeft > 18)
		{
			$stage->roadLeft -= .6;
			$stage->roadRight -= .6;
		} elseif($stage->roadRight < -18)
		{
			$stage->roadLeft += .6;
			$stage->roadRight += .6;
		} else
		{
			if($stage->counter < 0)
			{
				$stage->roadDirection = -$stage->roadDirection;
				$stage->counter += 2 * ($this->random->next() % 8 + 4);
			}

			$stage->roadVelocity += $stage->roadDirection > 0 ? .125 : -.125;
			$stage->roadVelocity = max(-.7, min(.7, $stage->roadVelocity));
			$stage->roadLeft += $stage->roadVelocity;
			$stage->roadRight += $stage->roadVelocity;
		}

		$this->spawn($x, $width);
	}

	/**
	 * Add one randomly colored gate at the horizon.
	 *
	 * @param float $x Lateral course position.
	 * @param float $width Gate width in course units.
	 * @return void The gate becomes the newest, farthest obstacle.
	 */
	private function spawn(float $x, float $width): void
	{
		$rgb = self::PALETTE[$this->random->next() % count(self::PALETTE)];

		/**
		 * Normalize one 8-bit color channel.
		 *
		 * @param int $channel Channel value from 0 to 255.
		 * @return float Channel value from 0 to 1.
		 */
		$normalize = static fn(int $channel): float => $channel / 255;

		$color = array_map($normalize, $rgb);
		array_unshift($this->obstacles, new Obstacle($x, $width, 25, $color));
	}
}

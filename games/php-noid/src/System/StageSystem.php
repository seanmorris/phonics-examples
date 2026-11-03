<?php
declare(strict_types=1);
namespace PhpNoid\System;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use Phonics\Ecs\Registry;

/** Campaign transitions and recovery for boards requiring a red energy ball. */
final class StageSystem
{

	/**
	 * Share the session's campaign state.
	 * @param Playfield $field Game lifecycle and loading services.
	 * @return void Initializes the system.
	 */
	public function __construct(private Playfield $field) {}

	/**
	 * Advance the campaign once every required brick is cleared or Exit is collected.
	 * @param Registry $registry Entity registry to inspect.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return void Updates presentation, recovery, restart, and progression.
	 */
	public function update(Registry $registry, float $dt): void
	{
		try
		{
			$this->advance($registry, $dt);
		}
		finally
		{
			$this->field->pointer->tap = false;
		}
	}

	/**
	 * Evaluate progression after all gameplay systems finish the current step.
	 * @param Registry $registry Entity registry to inspect.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return void Applies one campaign transition at most.
	 */
	private function advance(Registry $registry, float $dt): void
	{
		$state = $this->field->state;
		$state->frames++;
		$state->banner = max(0, $state->banner - $dt);
		$state->flash = max(0, $state->flash - $dt);

		if($this->field->input->pressed('reset'))
		{
			$this->field->restart();

			return;
		}

		if(in_array($state->phase, ['gameover', 'complete'], true))
		{
			if(!$this->field->firing()) $state->confirmReady = true;

			if($state->confirmReady && $this->field->launching())
			$this->field->restart($state->phase === 'gameover');

			return;
		}

		if($state->phase !== 'playing') return;

		$remaining = $green = false;

		foreach($registry->query(Brick::class) as $id)
		{
			$brick = $registry->get($id, Brick::class);

			if($brick->unbreakable) continue;

			$remaining = true;
			$green = $green || $brick->material === 'green';
		}

		if($remaining && !$state->exit)
		{
			if($green) $this->recoverRedBall($registry);

			return;
		}

		$state->events[] = 'stage';
		$state->phase = 'clearing';
		$state->nextStage = $state->stage + 1;
		$state->transitionTime = $state->fade = 0;
		$state->confirmReady = false;
		$this->field->pointer->reset();
	}

	/**
	 * Offer a replacement red ball when its last source disappears.
	 * @param Registry $registry Stage entities containing green bricks.
	 * @return void Keeps special-brick stages solvable without sacrificing a life.
	 */
	private function recoverRedBall(Registry $registry): void
	{
		foreach($registry->query(Ball::class) as $id)
		if($registry->get($id, Ball::class)->effect === 'red') return;

		foreach($registry->query(Enemy::class) as $id)
		{
			$enemy = $registry->get($id, Enemy::class);

			if($enemy->family === 'menacer' && $enemy->effect === 'red') return;
		}

		$paddle = $registry->get($this->field->paddle, Paddle::class);
		$ball = new Ball($paddle->x, $paddle->y - 14, 'enemy', 'red');
		$ball->offset = -12;
		$registry->create($ball);
		$this->field->state->notice = 'Red rescue ball ready — fire to clear green bricks';
		$this->field->state->banner = 4;
	}
}

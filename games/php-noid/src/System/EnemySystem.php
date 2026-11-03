<?php
declare(strict_types=1);
namespace PhpNoid\System;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use PhpNoid\Component\Capsule;
use PhpNoid\Component\Projectile;
use Phonics\Ecs\Registry;

/** Moves enemy ships and emits their capsules, balls, and hostile projectiles. */
final class EnemySystem
{

	/**
	 * Initialize EnemySystem state and its owned services.
	 *
	 * @param Playfield $field Playfield state shared by this system.
	 * @return void Completes the operation.
	 */
	public function __construct(private Playfield $field) {}

	/**
	 * Advance the current simulation or UI state.
	 *
	 * @param Registry $registry Entity registry to update.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return void Completes the operation.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->field->state->phase !== 'playing') return;

		$paddle = $registry->get($this->field->paddle, Paddle::class);

		foreach($registry->query(Enemy::class) as $id)
		{
			$enemy = $registry->get($id, Enemy::class);
			$hostile = in_array($enemy->family, ['destroyer', 'annihilator'], true);
			$speed = $hostile ? 95 : ($enemy->family === 'sympathizer' ? 120 : 65);
			$enemy->x += $enemy->direction * $speed * $dt;
			$enemy->y += ($hostile ? 20 : 12) * $dt;

			if($enemy->x < 22 || $enemy->x > $this->field->state->width - 22)
			{
				$enemy->x = max(22, min($this->field->state->width - 22, $enemy->x));
				$enemy->direction *= -1;
			}

			if(abs($enemy->y - $paddle->y) < 15 && abs($enemy->x - $paddle->x) < $paddle->width / 2 + 14)
			{
				$this->field->damageEnemy($id, $enemy->hits, false);
				continue;
			}

			if($enemy->y > $this->field->state->height + 20) $enemy->y = 24;

			if($enemy->family === 'floater') continue;

			$enemy->clock += $dt;

			if($enemy->clock < max(.25, $enemy->interval)) continue;

			$enemy->clock = 0;

			if($enemy->family === 'sympathizer')
			{
				$registry->create(new Capsule($enemy->x, $enemy->y + 15, $enemy->capsule));
				continue;
			}

			if($hostile)
			{
				$shot = new Projectile(
					$enemy->x
					, $enemy->y + 15
					, $enemy->family === 'annihilator' ? 'homing' : 'killer'
					, 230
					, 'enemy'
				);

				$dx = $paddle->x - $shot->x;
				$dy = $paddle->y - $shot->y;
				$length = max(1, hypot($dx, $dy));
				$shot->vx = $dx / $length * $shot->speed;
				$shot->vy = $dy / $length * $shot->speed;
				$registry->create($shot);
				continue;
			}

			$ball = new Ball($enemy->x, $enemy->y + 15, 'enemy', $enemy->effect);

			$ball->attached = false;
			$ball->vx = 65 * $enemy->direction;
			$ball->vy = $enemy->effect === 'pewter' ? 100 : 150;
			$registry->create($ball);
		}
	}
}

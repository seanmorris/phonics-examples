<?php
declare(strict_types=1);
namespace PhpNoid\System;
use PhpNoid\Playfield;
use PhpNoid\CollisionWorld;
use PhpNoid\Component\Projectile;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Enemy;
use PhpNoid\Component\Paddle;
use Phonics\Ecs\Registry;
use Phonics\Physics\Pose;

/** Swept weapons hit the first obstacle; hostile shots never become playable balls. */
final class ProjectileSystem
{

	/**
	 * Share the session's playfield with the weapon simulation.
	 * @param Playfield $field Game lifecycle and scoring services.
	 * @return void Initializes the system.
	 */
	public function __construct(private Playfield $field) {}

	/**
	 * Advance weapons and lethal attacks through their complete displacement.
	 * @param Registry $registry Entity registry to update.
	 * @param float $dt Elapsed simulation time in seconds.
	 * @return void Resolves impacts, ship damage, and life loss.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->field->state->phase !== 'playing') return;

		$paddle = $registry->get($this->field->paddle, Paddle::class);
		$collisions = $this->field->collisions;
		$collisions->sync($this->field->state->width, $this->field->state->height, $paddle);

		foreach($registry->query(Projectile::class) as $id)
		{
			$shot = $registry->get($id, Projectile::class);

			if($shot->team === 'enemy')
			{
				if($shot->kind === 'homing' && $shot->y < $paddle->y)
				{
					$angle = atan2($shot->vy, $shot->vx);
					$target = atan2($paddle->y - $shot->y, $paddle->x - $shot->x);
					$turn = atan2(sin($target - $angle), cos($target - $angle));
					$angle += max(-1.8 * $dt, min(1.8 * $dt, $turn));
					$shot->vx = cos($angle) * $shot->speed;
					$shot->vy = sin($angle) * $shot->speed;
				}
			}

			$dx = $shot->vx * $dt;
			$dy = $shot->vy * $dt;
			$hit = $collisions->projectile(new Pose($shot->x, $shot->y, 3, 4), $dx, $dy, $shot->team === 'enemy');
			$shot->x += $dx * ($hit ? $hit->t : 1);
			$shot->y += $dy * ($hit ? $hit->t : 1);

			if($hit)
			{
				if($shot->team === 'enemy')
				{
					if($paddle->respawnProtection > 0)
					{
						$registry->destroy($id);

						continue;
					}

					$this->field->loseLife();

					return;
				}

				$target = $hit->metadata['entity'];

				if($shot->kind === 'missile')
				{
					foreach($registry->query(Brick::class) as $brickId)
					{
						$brick = $registry->get($brickId, Brick::class);

						if($shot->x >= $brick->x - 3 && $shot->x <= $brick->x + $brick->width + 3)
						$this->field->damage($brickId, $brick->hits);
					}

					foreach($registry->query(Enemy::class) as $enemyId)
					{
						$enemy = $registry->get($enemyId, Enemy::class);

						if(abs($shot->x - $enemy->x) <= 17) $this->field->damageEnemy($enemyId, 3);
					}
				}
				elseif($hit->metadata['kind'] === 'brick') $this->field->damage($target);
				else $this->field->damageEnemy($target, 1);

				$registry->destroy($id);
			}
			elseif($shot->y < -20 || $shot->y > $this->field->state->height + 20
				|| $shot->x < -20 || $shot->x > $this->field->state->width + 20
			)
			$registry->destroy($id);
		}
	}

	/**
	 * Build an axis-aligned polygon for the core geometry query.
	 * @param float $x Left edge.
	 * @param float $y Top edge.
	 * @param float $width Horizontal extent.
	 * @param float $height Vertical extent.
	 * @return array Counterclockwise world-space vertices.
	 */
	public static function box(float $x, float $y, float $width, float $height): array
	{
		return CollisionWorld::box($x, $y, $width, $height);
	}
}

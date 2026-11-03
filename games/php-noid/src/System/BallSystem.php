<?php
declare(strict_types=1);
namespace PhpNoid\System;
use PhpNoid\Playfield;
use PhpNoid\Component\Ball;
use PhpNoid\Component\Brick;
use PhpNoid\Component\Paddle;
use Phonics\Physics\Pose;
use Phonics\Ecs\Registry;

/** Full-displacement collision prevents fast balls tunneling through thin authored bricks. */
final class BallSystem
{

	/**
	 * Initialize BallSystem state and its owned services.
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
		$height = $this->field->state->height;
		$collisions = $this->field->collisions;
		$collisions->sync($this->field->state->width, $height, $paddle);

		foreach($registry->query(Ball::class) as $id)
		{
			if(!$registry->alive($id)) continue;

			$ball = $registry->get($id, Ball::class);

			if($ball->attached)
			{
				$ball->offset = max(-$paddle->width / 2 + 6, min($paddle->width / 2 - 6, $ball->offset));
				$ball->x = $paddle->x + $ball->offset;
				$ball->y = $paddle->y - 14;

				if(!$this->field->launching()) continue;

				$ball->attached = false;
			}

			$remaining = min($dt, .25);
			$speed = isset($paddle->powers['fast']) ? 'fast' : (isset($paddle->powers['slow']) ? 'slow' : '');
			$factor = $ball->team === 'player' ? ($this->field->capsules[$speed]['speed'] ?? 1) : 1;
			$power = $ball->team === 'player' && isset($paddle->powers['power']);

			for($contact = 0; $remaining > .000001 && $contact < 12; $contact++)
			{
				$pose = new Pose($ball->x, $ball->y, 5, 5);
				$dx = $ball->vx * $remaining * $factor;
				$dy = $ball->vy * $remaining * $factor;
				$hit = $collisions->ball($pose, $dx, $dy, $ball->team === 'player');

				if(!$hit)
				{
					$ball->x += $dx;
					$ball->y += $dy;
					break;
				}

				$ball->x += $dx * $hit->t + $hit->nx / 256;
				$ball->y += $dy * $hit->t + $hit->ny / 256;
				$remaining *= 1 - $hit->t;

				if($hit->metadata['kind'] === 'paddle')
				{
					$ball->solidBounces = 0;
					$ball->solidContacts = [];

					if($ball->team === 'enemy')
					{
						if($ball->effect === 'cyan') $paddle->invisible = 5;
					}

					$offset = max(-1, min(1, ($ball->x - $paddle->x) / ($paddle->width / 2)));
					$speed = $ball->team === 'player'
						? min(600, max(340, hypot($ball->vx, $ball->vy) + 3))
						: hypot($ball->vx, $ball->vy);

					$ball->vx = sin($offset * 1.05) * $speed;
					$ball->vy = -cos($offset * 1.05) * $speed;

					if($ball->team === 'player' && isset($paddle->powers['catch']) && abs($offset) < .8)
					{
						$ball->attached = true;
						$ball->offset = $ball->x - $paddle->x;
						$paddle->catchFlash = .24;
						break;
					}
				}
				else
				{
					$through = false;
					$solid = $hit->metadata['kind'] === 'wall';

					if($hit->metadata['kind'] === 'brick')
					{
						$brickId = $hit->metadata['entity'];

						if($ball->team === 'player')
						{
							$brick = $registry->get($brickId, Brick::class);
							$solid = $brick->unbreakable || $brick->material === 'green';
							$this->field->damage($brickId, $power ? $brick->hits : 1);
							$through = $power && !$registry->alive($brickId);
						}
						else
						{
							$brick = $registry->get($brickId, Brick::class);

							if($ball->effect === 'red' && $brick->material === 'green')
							$this->field->damage($brickId, $brick->hits, false, 'red');
							elseif(!$brick->unbreakable && $brick->material !== 'green'
								&& in_array($ball->effect, ['copper', 'bronze', 'silver', 'green'], true)
							){
								$brick->material = $ball->effect === 'bronze' ? 'copper' : $ball->effect;
								$brick->hits = $ball->effect === 'green' ? 1 : ($ball->effect === 'silver' ? 3 : 2);
								$brick->capsule = '';
							}
						}
					}
					elseif($hit->metadata['kind'] === 'enemy')
					{
						$enemyId = $hit->metadata['entity'];
						$this->field->damageEnemy($enemyId);
						$through = $power && !$registry->alive($enemyId);
					}

					if(!$through)
					{
						$dot = $ball->vx * $hit->nx + $ball->vy * $hit->ny;
						$ball->vx -= 2 * $dot * $hit->nx;
						$ball->vy -= 2 * $dot * $hit->ny;

						if($ball->team === 'player' && $solid) $this->escapeOrbit($ball, $hit->nx, $hit->ny);
					}

					if(!$solid)
					{
						$ball->solidBounces = 0;
						$ball->solidContacts = [];
					}
				}
			}

			if($registry->alive($id) && $ball->y > $height + 12) $registry->destroy($id);
		}

		$players = array_filter(
			$registry->query(Ball::class)
			, /**
			 * Apply the registered session callback.
			 *
			 * @param mixed $id Entity identifier in the session registry.
			 * @return mixed Result of this operation.
			 */
			fn($id) => $registry->get($id, Ball::class)->team === 'player'
		);

		if(!$players) $this->field->loseLife();
	}

	/**
	 * Break repeating contact cycles and near-axis orbits without damaging terrain.
	 * @param Ball $ball Player ball with its velocity already reflected away from the surface.
	 * @param float $nx Outward horizontal surface normal.
	 * @param float $ny Outward vertical surface normal.
	 * @return void Adds a small escape angle without changing speed or bypassing collision casts.
	 */
	private function escapeOrbit(Ball $ball, float $nx, float $ny): void
	{
		$speed = hypot($ball->vx, $ball->vy);

		if($speed === 0.0) return;

		$ux = $ball->vx / $speed;
		$uy = $ball->vy / $speed;
		$cycle = false;

		foreach($ball->solidContacts as [$x, $y, $normalX, $normalY, $directionX, $directionY])
		{
			if($normalX === $nx && $normalY === $ny && abs($ball->x - $x) <= .5 && abs($ball->y - $y) <= .5
				&& $ux * $directionX + $uy * $directionY > .999
			)
			$cycle = true;
		}

		$ball->solidContacts[] = [$ball->x, $ball->y, $nx, $ny, $ux, $uy];

		if(count($ball->solidContacts) > 12) array_shift($ball->solidContacts);

		$ball->solidBounces++;

		if($ball->solidBounces < 3) return;

		$minimum = $speed * .2;

		if(abs($ball->vx) < $minimum - .000001)
		{
			$sign = $ball->vx < 0 || ($ball->vx === 0.0 && $ball->x < $this->field->state->width / 2) ? -1 : 1;
			$ball->vx = $sign * $minimum;
			$ball->vy = ($ball->vy < 0 ? -1 : 1) * sqrt($speed * $speed - $minimum * $minimum);
		}
		elseif(abs($ball->vy) < $minimum - .000001)
		{
			$ball->vy = ($ball->vy < 0 ? -1 : 1) * $minimum;
			$ball->vx = ($ball->vx < 0 ? -1 : 1) * sqrt($speed * $speed - $minimum * $minimum);
		}
		elseif($cycle || $ball->solidBounces >= 12)
		{
			// Turn twelve degrees away from an axis while still leaving the surface. The fallback
			// also breaks long cycles whose 1/256-pixel separation drifts outside the match tolerance.
			$c = cos(M_PI / 15);
			$s = sin(M_PI / 15);
			$ax = $ball->vx * $c - $ball->vy * $s;
			$ay = $ball->vx * $s + $ball->vy * $c;
			$bx = $ball->vx * $c + $ball->vy * $s;
			$by = -$ball->vx * $s + $ball->vy * $c;
			$chooseA = $ax * $nx + $ay * $ny > 0
				&& ($bx * $nx + $by * $ny <= 0 || min(abs($ax), abs($ay)) > min(abs($bx), abs($by)));

			[$ball->vx, $ball->vy] = $chooseA ? [$ax, $ay] : [$bx, $by];
		}
		else return;

		$ball->solidBounces = 0;
		$ball->solidContacts = [];
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;

/** Continuous platform pushes and rider transport against the world's collision index. */
final class PlatformTransport
{

	/**
	 * Bind transport to the same collision index used by player movement.
	 *
	 * @param Terrain $terrain Live terrain containing every registered platform shape.
	 * @return void Retains the terrain without allocating collision shapes.
	 */
	public function __construct(private Terrain $terrain) {}

	/**
	 * Find grounded bodies whose foot sensors actually contact this platform.
	 *
	 * @param MovingPlatform $platform Support whose current collider is tested.
	 * @param Registry $registry Registry containing potential rider bodies.
	 * @return array<int,Body> Riders keyed by entity ID; other planes and drop-throughs are excluded.
	 */
	public function riders(MovingPlatform $platform, Registry $registry): array
	{
		$riders = [];

		foreach($registry->query(Body::class) as $id)
		{
			$body = $registry->get($id, Body::class);

			if(!$body->grounded || abs($body->angle) > .01 || $body->dropTimer > 0
				|| abs($body->supportY() - $platform->top()) > .05
			){
				continue;
			}

			foreach([-.6, .6] as $side)
			{
				$hit = $this->terrain->raycast(
					$body->x + $side * $body->radius
					, $body->supportY() - .1
					, 0
					, .2
					, false
					, $body->collisionLayer
				);

				if($hit !== null && $hit->shape === $platform->shape)
				{
					$riders[$id] = $body;

					break;
				}
			}
		}

		return $riders;
	}

	/**
	 * Move one platform and every rider or body swept by its leading surface.
	 *
	 * Relative full-motion casts detect fast platform impacts; body casts then limit transport
	 * against thin world geometry. A blocked body stops the platform instead of being crushed.
	 *
	 * @param MovingPlatform $platform Mutable platform with a registered collision shape.
	 * @param Registry $registry Registry containing potential passengers and push targets.
	 * @param float $x Requested bottom-anchor X coordinate.
	 * @param float $y Requested bottom-anchor Y coordinate.
	 * @return float Accepted fraction from zero to one; collider and bodies share the accepted motion.
	 */
	public function move(MovingPlatform $platform, Registry $registry, float $x, float $y): float
	{
		$dx = $x - $platform->x;
		$dy = $y - $platform->y;

		if(abs($dx) + abs($dy) < 1e-9)
		{
			return 1;
		}

		$riders = $this->riders($platform, $registry);
		$local = new Terrain;
		$local->add($platform->points(), $platform->oneWay, $platform->collisionLayer);
		$moves = [];

		foreach($registry->query(Body::class) as $id)
		{
			$body = $registry->get($id, Body::class);

			if(isset($riders[$id]))
			{
				$moves[] = [$body, 0.0, $dx, $dy, true];
				continue;
			}

			if($platform->oneWay && ($body->dropTimer > 0 || (!$body->grounded && $body->vy < 0)))
			{
				continue;
			}

			$hit = $local->castBody($body, -$dx, -$dy, $body->dropTimer > 0);

			if($hit === null)
			{
				continue;
			}

			$remaining = 1 - $hit->t;
			$push = ($dx * $hit->nx + $dy * $hit->ny) * $remaining;
			$floor = $hit->ny < -.9;
			$moves[] = [
				$body
				, $hit->t
				, $floor ? $dx * $remaining : $hit->nx * $push
				, $floor ? $dy * $remaining : $hit->ny * $push
				, $floor
			];
		}

		// Remove only the moving support while casting passengers against all other geometry.
		$this->terrain->removeShape($platform->shape);
		$fraction = 1.0;

		foreach($moves as [$body, $start, $moveX, $moveY])
		{
			$hit = $this->terrain->castBody($body, $moveX, $moveY, $body->dropTimer > 0);

			if($hit !== null)
			{
				$clearance = .001 / max(.001, hypot($moveX, $moveY));
				$fraction = min($fraction, $start + (1 - $start) * max(0, $hit->t - $clearance));
			}
		}

		foreach($moves as [$body, $start, $moveX, $moveY, $floor])
		{
			if($fraction <= $start)
			{
				continue;
			}

			$amount = ($fraction - $start) / max(1e-9, 1 - $start);
			$body->x += $moveX * $amount;
			$body->y += $moveY * $amount;

			if($floor)
			{
				if(!$body->grounded)
				{
					$body->groundSpeed = $body->vx;
					$body->ability = '';
				}

				$body->grounded = true;
				$body->jumping = false;
				$body->grinding = false;
				$body->angle = 0;
				$body->vy = 0;
			}
		}

		$platform->x += $dx * $fraction;
		$platform->y += $dy * $fraction;
		$platform->shape = $this->terrain->addShape($platform->points(), $platform->oneWay, $platform->collisionLayer);

		return $fraction;
	}
}

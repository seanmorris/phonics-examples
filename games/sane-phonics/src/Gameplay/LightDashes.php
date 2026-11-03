<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Ecs\Registry;
use Phonics\Input\Controls;
use SanePhonics\Physics\Body;

/** Ring acquisition and pickup-driven steering; Platformer owns all displacement and collision. */
final class LightDashes
{
	public const ACQUIRE_RADIUS = 48.0;
	public const CHAIN_RADIUS = 128.0;
	public const TIMEOUT = 9 / 60;
	public const MAX_SPEED = 55 * .5 * 60;

	/**
	 * Bind light-dash controls to ECS ring and player components.
	 *
	 * @param Registry $registry Registry containing Body, LightDash, and RingCounter players.
	 * @return void Retains no entity ownership or event subscriptions.
	 */
	public function __construct(private Registry $registry) {}

	/**
	 * Acquire a trail or advance its pickup-driven target before platformer movement.
	 *
	 * Hold logical button 2 while airborne as the runner. The initial ring needs a second ring
	 * within 48 pixels that continues forward; pickups may then redirect within 128 pixels.
	 * Releasing the button does not cancel an acquired route. Gravity and collision remain active.
	 *
	 * @param int $player Entity with Body, LightDash, and RingCounter components.
	 * @param Controls $input Device-neutral held buttons for this simulation step.
	 * @param float $dt Positive finite simulation delta in seconds.
	 * @return bool True while light-dash owns directional and shield ability input.
	 */
	public function control(int $player, Controls $input, float $dt): bool
	{
		if(!is_finite($dt) || $dt <= 0)
		{
			throw new \InvalidArgumentException('Light-dash controls require a positive finite delta');
		}

		if(!$this->registry->has($player, Body::class) || !$this->registry->has($player, LightDash::class)
			|| !$this->registry->has($player, RingCounter::class)
		){
			return false;
		}

		$body = $this->registry->get($player, Body::class);
		$dash = $this->registry->get($player, LightDash::class);
		$dash->cooldown = max(0, $dash->cooldown - $dt);

		if($body->grounded || $body->profile !== 'runner')
		{
			$this->cancel($body, $dash);
			$dash->reset();
			$body->lightDashed = false;

			return false;
		}

		if($body->ability === 'lightdash')
		{
			if($dash->collected)
			{
				$dash->collected = false;
				$next = $this->nearest($body->x, $body->y, self::CHAIN_RADIUS);

				if($next !== null)
				{
					$this->aim($player, $body, $dash, $next);
				}
				else
				{
					$this->cancel($body, $dash);
				}
			}
			elseif($dash->target === null || !$this->eligible($dash->target))
			{
				// A removed target was not necessarily collected by this player.
				$this->cancel($body, $dash);
			}
			else
			{
				$dash->remaining = max(0, $dash->remaining - $dt);

				if($dash->remaining <= 1e-9)
				{
					$this->cancel($body, $dash);
				}
			}

			return $body->ability === 'lightdash';
		}

		// Collision, damage, or another ability may have interrupted the previous route.
		if($dash->target !== null || $dash->collected)
		{
			$this->cancel($body, $dash);

			return false;
		}

		if(!$input->down(2) || $dash->cooldown > 1e-9 || $body->ability !== '')
		{
			return false;
		}

		$ring = $this->first($body);

		if($ring === null)
		{
			return false;
		}

		$this->aim($player, $body, $dash, $ring);
		$dash->cooldown = self::TIMEOUT;

		return true;
	}

	/**
	 * Schedule a redirect after Rings has awarded and removed a pickup.
	 *
	 * Redirects are resolved on the next control step, after every pickup in the current sweep
	 * has retired. This prevents targeting another ring already crossed during that same step.
	 *
	 * @param int $player Collector entity receiving the ring.
	 * @param int $entity Removed ring entity ID, retained for caller diagnostics.
	 * @param Ring $ring Removed pickup component, retained for caller diagnostics.
	 * @return void Refreshes the nine-frame timeout only for an active airborne dash.
	 */
	public function collected(int $player, int $entity, Ring $ring): void
	{
		if(!$this->registry->has($player, LightDash::class) || !$this->registry->has($player, Body::class))
		{
			return;
		}

		$body = $this->registry->get($player, Body::class);

		if($body->grounded || $body->ability !== 'lightdash')
		{
			return;
		}

		$dash = $this->registry->get($player, LightDash::class);
		$dash->target = null;
		$dash->collected = true;
		$dash->remaining = self::TIMEOUT;
	}

	/**
	 * Test whether a ring remains available to acquire and collect.
	 *
	 * @param int $entity Candidate ring entity ID.
	 * @return bool False for removed rings, decorative scatter, and delayed scattered pickups.
	 */
	private function eligible(int $entity): bool
	{
		if(!$this->registry->has($entity, Ring::class))
		{
			return false;
		}

		if(!$this->registry->has($entity, ScatteredRing::class))
		{
			return true;
		}

		$scatter = $this->registry->get($entity, ScatteredRing::class);

		return $scatter->collectible && $scatter->age >= $scatter->pickupDelay;
	}

	/**
	 * Find the nearest eligible pickup, optionally restricting it to forward travel.
	 *
	 * @param float $x Search origin in world pixels.
	 * @param float $y Search origin in world pixels.
	 * @param float $radius Inclusive maximum distance in world pixels.
	 * @param int|null $exclude Ring to omit when finding a second trail member.
	 * @param Body|null $forward Body whose horizontal velocity excludes rings behind its center.
	 * @return int|null Nearest entity ID, with stable entity order breaking equal-distance ties.
	 */
	private function nearest(float $x, float $y, float $radius, ?int $exclude = null, ?Body $forward = null): ?int
	{
		$nearest = null;
		$distance = $radius;

		foreach($this->registry->query(Ring::class) as $entity)
		{
			if($entity === $exclude || !$this->eligible($entity))
			{
				continue;
			}

			$ring = $this->registry->get($entity, Ring::class);

			if($forward !== null && ($ring->x - $forward->x) * $forward->vx < 0)
			{
				continue;
			}

			$candidate = hypot($ring->x - $x, $ring->y - $y);

			if($candidate <= $radius && ($nearest === null || $candidate < $distance))
			{
				$nearest = $entity;
				$distance = $candidate;
			}
		}

		return $nearest;
	}

	/**
	 * Require an initial pair that continues within a right angle of the approach.
	 *
	 * @param Body $body Airborne runner searching near its world-space center.
	 * @return int|null First eligible ring, or null for isolated and reversing trail entrances.
	 */
	private function first(Body $body): ?int
	{
		$first = $this->nearest($body->x, $body->y, self::ACQUIRE_RADIUS, forward: $body);

		if($first === null)
		{
			return null;
		}

		$ring = $this->registry->get($first, Ring::class);
		$next = $this->nearest($ring->x, $ring->y, self::ACQUIRE_RADIUS, $first, $body);

		if($next === null)
		{
			return null;
		}

		$second = $this->registry->get($next, Ring::class);
		$dot = ($ring->x - $body->x) * ($second->x - $ring->x)
			+ ($ring->y - $body->y) * ($second->y - $ring->y);

		return $dot >= 0 ? $first : null;
	}

	/**
	 * Aim momentum at one ring using the reference distance/angle speed and 27.5 px/frame cap.
	 *
	 * @param int $player Player entity optionally carrying an active Combo.
	 * @param Body $body Airborne body receiving velocity and directional input ownership.
	 * @param LightDash $dash Component receiving the target, timer, and retained combo reward.
	 * @param int $entity Live eligible ring entity to approach.
	 * @return void Updates momentum in pixels per second without moving or teleporting the body.
	 */
	private function aim(int $player, Body $body, LightDash $dash, int $entity): void
	{
		$ring = $this->registry->get($entity, Ring::class);
		$angle = atan2($ring->y - $body->y, $ring->x - $body->x);
		$difference = abs(atan2(sin($angle - $body->angle), cos($angle - $body->angle)));
		$distance = hypot($ring->x - $body->x, $ring->y - $body->y);
		$speed = $difference < 1e-9 ? self::MAX_SPEED : min(self::MAX_SPEED, $distance * 120 * M_PI_2 / $difference);
		$body->vx = cos($angle) * $speed;
		$body->vy = sin($angle) * $speed;
		$body->ability = 'lightdash';
		$body->lightDashed = true;
		$body->charge = $body->dropCharge = $body->jumpBuffer = $body->coyote = 0;
		$body->crouching = $body->grinding = false;
		$dash->target = $entity;
		$dash->remaining = self::TIMEOUT;

		if(abs($body->vx) > 1e-8)
		{
			$body->facing = $body->direction = $body->vx <=> 0;
		}

		if($this->registry->has($player, Combo::class))
		{
			$combo = $this->registry->get($player, Combo::class);

			if($combo->chain)
			{
				if($dash->reward === null || !in_array($dash->reward, $combo->chain, true))
				{
					$dash->reward = $combo->add(new ComboReward('Light Dash', 10));
				}
				else
				{
					$dash->reward->points += 10;
				}
			}
		}
	}

	/**
	 * Release steering after a timeout, missing target, landing, or interruption.
	 *
	 * @param Body $body Body retaining its current momentum and other abilities.
	 * @param LightDash $dash Component whose current route must stop.
	 * @return void Clears the route while retaining cooldown and airborne combo accrual.
	 */
	private function cancel(Body $body, LightDash $dash): void
	{
		if($body->ability === 'lightdash')
		{
			$body->ability = '';
		}

		$dash->target = null;
		$dash->remaining = 0;
		$dash->collected = false;
	}
}

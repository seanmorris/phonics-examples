<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use Phonics\Ecs\Registry;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\TransientEffect;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Definition\ActionDispatcher;

/** Advances all DECORATE state machines at the original 35 Hz tic rate. */
final class ActorStateSystem
{
	/** @var array<int,ActorState> */
	private array $cache = [];

	public function __construct(public ActionDispatcher $actions) {}

	public function update(Registry $registry, float $dt): void
	{
		$ticks = max(0, $dt * 35);

		$seen = [];

		foreach($registry->query(ActorState::class) as $entity)
		{
			// Replicas must not dispatch explosion damage or advance beyond the host's state.
			if($registry->has($entity, RemoteProjectile::class)) continue;

			$seen[$entity] = true;
			$state = $this->cache[$entity] ??= $registry->get($entity, ActorState::class);

			if($registry->has($entity, TransientEffect::class)
				&& $registry->has($entity, Transform::class)
				&& $registry->has($entity, Velocity::class)
				&& !$registry->has($entity, Body::class)
			){
				$pose = $registry->get($entity, Transform::class);
				$velocity = $registry->get($entity, Velocity::class);
				$pose->x += $velocity->x * $dt;
				$pose->y += $velocity->y * $dt;
				$pose->z += $velocity->z * $dt;
			}

			$this->advance($entity, $state, $registry, $ticks);

			if($state->stopped)
			{
				if($registry->has($entity, TransientEffect::class))
				$this->retireEffect($entity, $registry);
				elseif($registry->has($entity, WeaponOverlay::class))
				$this->retireOverlay($entity, $registry);
			}
		}

		if(count($seen) !== count($this->cache))
		$this->cache = array_intersect_key($this->cache, $seen);
	}

	private function retireEffect(int $entity, Registry $registry): void
	{
		$effect = $registry->get($entity, TransientEffect::class);

		if($effect->retiring) return;

		$effect->retiring = true;

		$registry->defer(static function(Registry $registry) use ($entity): void {
			if($registry->alive($entity)) $registry->destroy($entity);
		});
	}

	private function retireOverlay(int $entity, Registry $registry): void
	{
		$overlay = $registry->get($entity, WeaponOverlay::class);
		$anotherFlashIsActive = false;

		foreach($registry->query(WeaponOverlay::class) as $other)
		{
			if($other === $entity) continue;

			$otherOverlay = $registry->get($other, WeaponOverlay::class);
			$otherState = $registry->get($other, ActorState::class);

			if($otherOverlay->playerEntity === $overlay->playerEntity && !$otherState->stopped)
			{
				$anotherFlashIsActive = true;
				break;
			}
		}

		if(!$anotherFlashIsActive && $registry->alive($overlay->playerEntity)
			&& $registry->has($overlay->playerEntity, ViewLighting::class)
		)
		$registry->get($overlay->playerEntity, ViewLighting::class)->clearWeaponFlash();

		$registry->defer(static function(Registry $registry) use ($entity): void {
			if($registry->alive($entity)) $registry->destroy($entity);
		});
	}

	private function advance(int $entity, ActorState $state, Registry $registry, float $ticks): void
	{
		for($guard = 0; $guard < 64 && !$state->stopped; $guard++)
		{
			$sequence = $state->definition->state($state->label);
			$frame = $state->current();

			if(!$sequence || !$frame)
			{
				$state->stopped = true;

				return;
			}

			if(!$state->entered)
			{
				$label = $state->label;
				$index = $state->frame;
				$state->entered = true;
				$state->ticks = $frame->ticks;
				$this->actions->dispatch($entity, $frame, $registry);

				if($state->label !== $label || $state->frame !== $index) continue;

				if($frame->ticks < 0) return;
			}

			if($state->ticks > $ticks)
			{
				$state->ticks -= $ticks;

				return;
			}

			$ticks = max(0, $ticks - max(0, $state->ticks));

			if($frame->flow !== null)
			{
				if($this->follow($state, $frame->flow, $frame->target)) continue;

				return;
			}

			$state->frame++;
			$state->entered = false;

			if(isset($sequence->frames[$state->frame]))
			{
				if($ticks <= 0 && $sequence->frames[$state->frame]->ticks !== 0) return;

				continue;
			}

			if($sequence->flow === 'wait') $state->frame = max(0, count($sequence->frames) - 1);

			if(!$this->follow($state, $sequence->flow, $sequence->target)) return;
		}
	}

	/** Apply one normalized DECORATE state edge; true means processing may continue. */
	private function follow(ActorState $state, string $flow, ?string $target): bool
	{
		if($flow === 'loop')
		{
			$state->frame = 0;
			$state->entered = false;

			return true;
		}

		if($flow === 'wait')
		{
			$state->entered = true;
			$state->ticks = -1;

			return false;
		}

		if($flow === 'goto' && $target !== null
			&& preg_match('/^(?:[^:]+::)?([^+]+)(?:\+(\d+))?$/', $target, $match)
		){
			$state->enter($match[1], isset($match[2]) ? (int)$match[2] : 0);

			return true;
		}

		$state->stopped = true;

		return false;
	}
}

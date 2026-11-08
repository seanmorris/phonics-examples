<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use Phonics\Ecs\Registry;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\RemoteActor;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\TransientEffect;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Component\ViewLighting;
use DoomPhonics\Component\WeaponOverlay;
use DoomPhonics\Definition\ActionDispatcher;

/**
 * Advances all DECORATE state machines at the original 35 Hz tic rate.
 *
 * A map holds hundreds of things, but in a given tic only a few dozen reach the end of a frame. The system therefore
 * keeps each actor's due time, the end of its current frame, and steps only the actors that are due, in entity order
 * as a walk over every actor would. A state changed from outside, through ActorState::enter(), jump(), or stop(), is
 * taken up in the tic that walk would have reached it. Transient effects with velocity still move every tic. With
 * `$scheduled` off the system walks every actor every tic; tests use that as the reference.
 */
final class ActorStateSystem
{
	/** @var array<int,ActorState> */
	private array $cache = [];
	/** End of the last completed update, in tics. */
	private float $now = 0;
	/** Start and end of the update in progress, and the actor being stepped; null outside an update. */
	private ?float $start = null, $end = null;
	private ?int $current = null;
	/** @var array<int,float> Tic each scheduled actor's current frame ends; INF waits for an outside change. */
	private array $due = [];
	/** @var array<int,float> Tic each scheduled actor was last stepped to. */
	private array $from = [];
	/** @var array<int,true> Transient effects that move every tic. */
	private array $moving = [];
	/** @var array<int,true> Replicas, which only the host steps. */
	private array $remote = [];
	/** @var list<int>|null Registry snapshot the schedule was built from. */
	private ?array $known = null;
	/** Pending due times, earliest first; entries whose time no longer matches $due are stale. */
	private \SplPriorityQueue $queue;
	/** Actors to step in the update in progress, lowest entity first. */
	private ?\SplMinHeap $batch = null;
	/** @var array<int,true> Actors already stepped in the update in progress. */
	private array $stepped = [];

	public function __construct(public ActionDispatcher $actions, private bool $scheduled = true)
	{
		$this->queue = new \SplPriorityQueue;
	}

	public function update(Registry $registry, float $dt): void
	{
		$ticks = max(0, $dt * 35);

		if(!$this->scheduled)
		{
			$this->walk($registry, $dt, $ticks);

			return;
		}

		$this->sync($registry);
		$this->start = $this->now;
		$this->end = $this->now + $ticks;
		$this->stepped = [];
		$this->batch = new \SplMinHeap;

		foreach(array_keys($this->moving) as $entity) $this->move($entity, $registry, $dt);

		while(!$this->queue->isEmpty())
		{
			[$entity, $due] = $this->queue->top();

			if($due > $this->end + 1e-9) break;

			$this->queue->extract();

			if(($this->due[$entity] ?? null) === $due) $this->batch->insert($entity);
		}

		while(!$this->batch->isEmpty())
		{
			$entity = $this->batch->extract();

			if(isset($this->stepped[$entity]) || !isset($this->cache[$entity])) continue;

			$this->stepped[$entity] = true;
			$this->current = $entity;
			$this->step($entity, $registry);
		}

		$this->current = null;
		$this->batch = null;
		$this->start = null;
		$this->now = $this->end;
		$this->end = null;
	}

	/** The reference: every actor, every tic. */
	private function walk(Registry $registry, float $dt, float $ticks): void
	{
		$seen = [];

		foreach($registry->query(ActorState::class) as $entity)
		{
			// Replicas must not dispatch explosion damage, AI, or attacks, or advance beyond the host's state.
			if($registry->has($entity, RemoteProjectile::class) || $registry->has($entity, RemoteActor::class)) continue;

			$seen[$entity] = true;
			$state = $this->cache[$entity] ??= $registry->get($entity, ActorState::class);

			if($this->movesEveryTic($entity, $registry)) $this->move($entity, $registry, $dt);

			$this->advance($entity, $state, $registry, $ticks);

			if($state->stopped) $this->retire($entity, $registry);
		}

		if(count($seen) !== count($this->cache))
		$this->cache = array_intersect_key($this->cache, $seen);
	}

	/** Step one due actor from its last step to the end of this update, then schedule its next frame end. */
	private function step(int $entity, Registry $registry): void
	{
		if(!$registry->alive($entity) || !$registry->has($entity, ActorState::class))
		{
			$this->forget($entity);

			return;
		}

		// Replication can mark an actor as a replica after it was first scheduled; only the host steps replicas.
		if($registry->has($entity, RemoteProjectile::class) || $registry->has($entity, RemoteActor::class))
		{
			$this->forget($entity);
			$this->remote[$entity] = true;

			return;
		}

		$state = $this->cache[$entity];
		$this->advance($entity, $state, $registry, $this->end - $this->from[$entity]);
		$this->from[$entity] = $this->end;

		if($state->stopped)
		{
			$this->retire($entity, $registry);
			$this->schedule($entity, INF);
		}
		elseif(!$state->entered) $this->schedule($entity, $this->end);
		else $this->schedule($entity, $state->ticks < 0 ? INF : $this->end + $state->ticks);
	}

	private function retire(int $entity, Registry $registry): void
	{
		if($registry->has($entity, TransientEffect::class)) $this->retireEffect($entity, $registry);
		elseif($registry->has($entity, WeaponOverlay::class)) $this->retireOverlay($entity, $registry);
	}

	private function movesEveryTic(int $entity, Registry $registry): bool
	{
		return $registry->has($entity, TransientEffect::class)
			&& $registry->has($entity, Transform::class)
			&& $registry->has($entity, Velocity::class)
			&& !$registry->has($entity, Body::class);
	}

	private function move(int $entity, Registry $registry, float $dt): void
	{
		if(!$registry->alive($entity) || !$registry->has($entity, Transform::class) || !$registry->has($entity, Velocity::class)
			|| ($this->scheduled && ($registry->has($entity, RemoteProjectile::class) || $registry->has($entity, RemoteActor::class)))
		)
		{
			unset($this->moving[$entity]);

			return;
		}

		$pose = $registry->get($entity, Transform::class);
		$velocity = $registry->get($entity, Velocity::class);
		$pose->x += $velocity->x * $dt;
		$pose->y += $velocity->y * $dt;
		$pose->z += $velocity->z * $dt;
	}

	/** Track actors added or removed since the last update. Only structural changes cost a pass over the snapshot. */
	private function sync(Registry $registry): void
	{
		$all = $registry->query(ActorState::class);

		if($all === $this->known) return;

		$this->known = $all;
		$present = [];

		foreach($all as $entity)
		{
			$present[$entity] = true;

			if(isset($this->cache[$entity]) || isset($this->remote[$entity])) continue;

			if($registry->has($entity, RemoteProjectile::class) || $registry->has($entity, RemoteActor::class))
			{
				$this->remote[$entity] = true;

				continue;
			}

			$state = $this->cache[$entity] = $registry->get($entity, ActorState::class);
			$state->changed = function() use ($entity): void { $this->wake($entity); };
			$this->from[$entity] = $this->now;

			if($this->movesEveryTic($entity, $registry)) $this->moving[$entity] = true;

			$this->schedule($entity, $state->stopped || !$state->entered ? $this->now : $this->now + max(0, $state->ticks));

			if($state->entered && $state->ticks < 0 && !$state->stopped) $this->schedule($entity, INF);
		}

		foreach(array_keys($this->cache) as $entity) if(!isset($present[$entity])) $this->forget($entity);

		foreach(array_keys($this->remote) as $entity) if(!isset($present[$entity])) unset($this->remote[$entity]);
	}

	/**
	 * Take up a state changed from outside in the tic a walk over every actor would: later in this update when the walk
	 * has not reached the actor yet, otherwise in the next one. Stepping the actor itself reschedules it afterwards.
	 */
	private function wake(int $entity): void
	{
		if($entity === $this->current || !isset($this->cache[$entity])) return;

		if($this->start === null)
		{
			$this->from[$entity] = $this->now;
			$this->schedule($entity, $this->now);

			return;
		}

		if($this->current !== null && $entity > $this->current && !isset($this->stepped[$entity]))
		{
			$this->from[$entity] = $this->start;
			$this->due[$entity] = $this->end;
			$this->batch?->insert($entity);

			return;
		}

		$this->from[$entity] = $this->end;
		$this->schedule($entity, $this->end);
	}

	private function schedule(int $entity, float $due): void
	{
		$this->due[$entity] = $due;

		if($due !== INF) $this->queue->insert([$entity, $due], [-$due, -$entity]);
	}

	private function forget(int $entity): void
	{
		if(isset($this->cache[$entity]) && $this->cache[$entity]->changed !== null) $this->cache[$entity]->changed = null;

		unset($this->cache[$entity], $this->due[$entity], $this->from[$entity], $this->moving[$entity]);
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

			// Like P_SetMobjState from P_MobjThinker, the next frame starts and runs its action in the tic the previous
			// one ran out, as Loop and Goto already did. Deferring it a tic made each frame after the first in a state
			// run a tic late and short: idle monsters looked 9 then 11 tics apart, and their looks bunched up.
			if(isset($sequence->frames[$state->frame])) continue;

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

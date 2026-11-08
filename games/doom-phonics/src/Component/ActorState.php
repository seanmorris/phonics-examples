<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

use DoomPhonics\Definition\ActorDefinition;
use DoomPhonics\Definition\StateFrame;

/** Live cursor through one immutable DECORATE actor definition. */
final class ActorState
{
	public int $frame = 0;
	public float $ticks = 0;
	public bool $entered = false;
	public bool $stopped = false;
	/**
	 * Called when code outside the state system changes this state through enter(), jump(), or stop(), so a system
	 * that steps only due actors takes it up in time. ActorStateSystem sets it for the states it schedules. While the
	 * system is not stepping an actor, its `ticks` reflect its last step rather than this tic.
	 */
	public ?\Closure $changed = null;

	public function __construct(public ActorDefinition $definition, public string $label = 'Spawn') {}

	public function enter(string $label, int $offset = 0): void
	{
		$this->label = $label;
		$this->frame = max(0, $offset);
		$this->ticks = 0;
		$this->entered = false;
		$this->stopped = false;

		if($this->changed) ($this->changed)();
	}

	/** Restart at a frame offset within the current state, as the A_JumpIf actions do. */
	public function jump(int $offset): void
	{
		$this->frame = max(0, $this->frame + $offset);
		$this->ticks = 0;
		$this->entered = false;

		if($this->changed) ($this->changed)();
	}

	/** Stop advancing, so the state system retires the effect or weapon layer. */
	public function stop(): void
	{
		$this->stopped = true;

		if($this->changed) ($this->changed)();
	}

	public function current(): ?StateFrame
	{
		return $this->definition->state($this->label)?->frames[$this->frame] ?? null;
	}
}

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

	public function __construct(public ActorDefinition $definition, public string $label = 'Spawn') {}

	public function enter(string $label, int $offset = 0): void
	{
		$this->label = $label;
		$this->frame = max(0, $offset);
		$this->ticks = 0;
		$this->entered = false;
		$this->stopped = false;
	}

	public function current(): ?StateFrame
	{
		return $this->definition->state($this->label)?->frames[$this->frame] ?? null;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Frames and terminal flow control for one named DECORATE state. */
final class StateSequence
{
	/** @var list<StateFrame> */
	public array $frames = [];
	/** loop, stop, wait, or goto. */
	public string $flow = 'stop';
	/** Destination for goto, including an optional +offset suffix. */
	public ?string $target = null;
}

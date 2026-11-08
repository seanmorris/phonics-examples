<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** One DECORATE state frame after compact frame-letter sequences are expanded. */
final class StateFrame
{

	/**
	 * @param string $sprite Four-character sprite name, or a DECORATE sentinel.
	 * @param string $frame Single frame letter.
	 * @param int $ticks Duration in 35 Hz tics; -1 holds indefinitely.
	 * @param bool $bright Whether full-bright rendering is requested.
	 * @param string|null $action Native action-function name.
	 * @param list<string|int|float|Expression> $arguments Parsed action arguments; an Expression is computed on
	 * each dispatch.
	 * @param string|null $flow Flow directive reached after this frame, when one appears inline.
	 * @param string|null $target Goto destination associated with an inline flow directive.
	 */
	public function __construct(
		public string $sprite
		, public string $frame
		, public int $ticks
		, public bool $bright = false
		, public ?string $action = null
		, public array $arguments = []
		, public ?string $flow = null
		, public ?string $target = null
	){
		foreach($arguments as $argument) if($argument instanceof Expression) { $this->dynamic = true; break; }
	}

	/** Whether any argument must be computed when the action runs. */
	public bool $dynamic = false;

	/**
	 * This frame with its expressions computed, for one dispatch.
	 *
	 * @param null|callable(string):(int|float|null) $variables Named values the expressions may read.
	 */
	public function evaluated(?callable $variables = null): self
	{
		$arguments = [];

		foreach($this->arguments as $argument)
		$arguments[] = $argument instanceof Expression ? $argument->evaluate($variables) : $argument;

		return new self($this->sprite, $this->frame, $this->ticks, $this->bright, $this->action, $arguments, $this->flow, $this->target);
	}
}

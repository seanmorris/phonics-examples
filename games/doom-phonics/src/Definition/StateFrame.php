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
	 * @param list<string|int|float> $arguments Parsed action arguments.
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
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Definition;

/** Immutable semantic definition for a Doom linedef special. */
final class LineAction
{
	public const USE = 'use', WALK = 'walk', SHOOT = 'shoot', AUTOMATIC = 'automatic';
	public const LOCAL_DOOR = 'local-door', DOOR = 'door', FLOOR = 'floor', LIFT = 'lift', STAIRS = 'stairs';
	public const DONUT = 'donut', LIGHT = 'light', TELEPORT = 'teleport', EXIT = 'exit', SECRET_EXIT = 'secret-exit'
		, SCROLL =
		'scroll';
	public const OPEN = 'open', OPEN_CLOSE = 'open-close', CLOSE_OPEN = 'close-open';
	public const RAISE_CEILING = 'raise-ceiling', RAISE_NEXT = 'raise-next', RAISE_NEXT_CHANGE = 'raise-next-change';
	public const LOWER_LOWEST = 'lower-lowest', LOWER_HIGHEST = 'lower-highest', LOWER_HIGHEST_FAST =
		'lower-highest-fast';

	public function __construct(
		public int $number
		, public string $trigger
		, public bool $repeatable
		, public string $operation
		, public ?string $mode = null
		, public ?string $key = null
	){
		if(!in_array($trigger, [self::USE,self::WALK,self::SHOOT,self::AUTOMATIC], true))
		throw new \InvalidArgumentException("Unknown linedef trigger $trigger");

		if(!in_array(
			$operation
			, [
				self::LOCAL_DOOR
				, self::DOOR
				, self::FLOOR
				, self::LIFT
				, self::STAIRS
				, self::DONUT
				, self::LIGHT
				, self::TELEPORT
				, self::EXIT,self::SECRET_EXIT,self::SCROLL
			]
			, true
		))
		throw new \InvalidArgumentException("Unknown linedef operation $operation");
	}

	/** @param array{trigger:string,repeat:bool,type:string,key?:string,mode?:string} $value */
	public static function fromArray(int $number, array $value): self
	{
		return new self(
			$number
			, $value['trigger']
			, $value['repeat']
			, $value['type']
			, $value['mode'] ?? null
			, $value['key'] ?? null
		);
	}

	public function isSwitch(): bool
	{
		return in_array($this->number, [7,9,11,18,20,23,46,51,62,63,70,102,103], true);
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** A parsed classic cheat, separated from the keyboard sequence that produced it. */
final class CheatCommand
{
	public const GOD = 'god';
	public const ARSENAL = 'arsenal';
	public const ARSENAL_AND_KEYS = 'arsenal-and-keys';
	public const NO_CLIP = 'no-clip';
	public const POWER = 'power';
	public const BEHOLD = 'behold';
	public const CHOPPERS = 'choppers';
	public const POSITION = 'position';
	public const WARP = 'warp';
	public const OOPS_ALL_GIBS = 'oops-all-gibs';

	private function __construct(public string $type, public ?string $argument = null) {}

	public static function named(string $type): self { return new self($type); }

	public static function power(string $name): self { return new self(self::POWER, $name); }

	public static function warp(string $map): self { return new self(self::WARP, strtoupper($map)); }
}

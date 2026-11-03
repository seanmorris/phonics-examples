<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Result of applying a cheat; map changes remain owned by the game lifecycle. */
final class CheatResult
{

	private function __construct(public ?string $warpMap = null) {}

	public static function applied(): self { return new self; }

	public static function warp(string $map): self { return new self(strtoupper($map)); }
}

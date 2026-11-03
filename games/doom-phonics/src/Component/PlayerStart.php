<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Marks the player entity and its numbered map start. */
final class PlayerStart
{

	public function __construct(public int $number = 1) {}
}

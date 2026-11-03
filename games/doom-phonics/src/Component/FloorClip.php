<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Lowers the visible bottom of an actor into a floor surface without changing its body. */
final class FloorClip
{

	public function __construct(public float $amount = 10) {}
}

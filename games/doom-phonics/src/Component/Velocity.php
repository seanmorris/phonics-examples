<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Map-space velocity in units per second. */
final class Velocity
{

	public function __construct(public float $x = 0, public float $y = 0, public float $z = 0) {}
}

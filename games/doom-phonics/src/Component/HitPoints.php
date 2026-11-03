<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Damageable actor health. */
final class HitPoints
{

	public function __construct(public int $current, public int $maximum, public int $painChance = 0) {}
}

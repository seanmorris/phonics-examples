<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** DECORATE gravity participation and per-actor acceleration scale. */
final class Gravity
{

	public function __construct(public float $scale = 1) {}
}

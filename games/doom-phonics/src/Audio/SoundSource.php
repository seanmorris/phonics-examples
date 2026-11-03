<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

/** A sound's location in WAD map space. */
final class SoundSource
{

	public function __construct(public float $x, public float $y) {}
}

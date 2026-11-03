<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

/** The map-space pose used for sound attenuation and stereo positioning. */
final class SoundListener
{

	public function __construct(public float $x, public float $y, public float $angle) {}
}

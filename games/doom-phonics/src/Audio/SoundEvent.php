<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

/** One positional playback request. */
final class SoundEvent
{

	public function __construct(
		public SoundCue $cue
		, public SoundSource $source
		, public ?SoundListener $listener = null
	){
	}
}

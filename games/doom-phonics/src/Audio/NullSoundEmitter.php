<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

use DoomPhonics\Archive\Stack;

/** Native-test and headless fallback for optional SDL_mixer playback. */
final class NullSoundEmitter implements SoundEmitter
{

	public function beginScope(Stack $wad): void {}

	public function play(SoundEvent $event): void {}

	public function playMusic(MusicCue $cue): void {}

	public function stopScope(): void {}

	public function close(): void {}
}

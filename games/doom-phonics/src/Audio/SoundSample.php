<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** Normalize native Doom DMX and add-on RIFF/WAVE lumps for the audio backends. */
final class SoundSample
{

	/**
	 * Return a validated WAV container for a WAD sound lump.
	 *
	 * @param Lump|string $source Native DMX bytes or an add-on RIFF/WAVE, Ogg Vorbis, or FLAC lump.
	 * @param string $identity Diagnostic name used for raw byte strings.
	 * @return string Bytes both backends decode: a WAV container, or an Ogg or FLAC stream as it stands, since Web
	 * Audio's decodeAudioData and SDL_mixer's Mix_LoadWAV take those too. Add-on packs ship most of their effects as
	 * Ogg, and some as FLAC; treated as DMX they failed to parse and played nothing.
	 */
	public static function wav(Lump|string $source, string $identity = 'WAD sound'): string
	{
		$bytes = $source instanceof Lump ? $source->bytes() : $source;
		$identity = $source instanceof Lump ? $source->identity() : $identity;
		$magic = substr($bytes, 0, 4);

		if($magic === 'OggS' || $magic === 'fLaC') return $bytes;

		if($magic !== 'RIFF') return DmxSound::decode($bytes, $identity)->wav();

		Binary::slice($bytes, 0, 12, $identity);

		if(substr($bytes, 8, 4) !== 'WAVE')
		throw new \UnexpectedValueException("$identity: RIFF sound is not WAVE data");

		$length = Binary::u32($bytes, 4, $identity) + 8;

		if($length < 12) throw new \UnexpectedValueException("$identity: invalid RIFF length");

		return Binary::slice($bytes, 0, $length, $identity);
	}
}

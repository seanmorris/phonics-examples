<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** Parsed Doom DMX unsigned 8-bit mono sample. */
final class DmxSound
{

	public function __construct(public int $sampleRate, public string $samples, public string $source = 'DMX')
	{
		if($sampleRate < 1000 || $sampleRate > 192000)
		throw new \UnexpectedValueException("$source: invalid DMX sample rate");
	}

	public static function decode(Lump|string $source, string $identity = 'DMX'): self
	{
		$bytes = $source instanceof Lump ? $source->bytes() : $source;
		$identity = $source instanceof Lump ? $source->identity() : $identity;

		if(Binary::u16($bytes, 0, $identity) !== 3)
		throw new \UnexpectedValueException("$identity: unsupported DMX format");

		$rate = Binary::u16($bytes, 2, $identity);
		$length = Binary::u32($bytes, 4, $identity);
		$samples = Binary::slice($bytes, 8, $length, $identity);

		return new self($rate, $samples, $identity);
	}

	/** Little-endian signed PCM16 suitable for Web Audio decoding or SDL queuing. */
	public function pcm16(): string
	{
		$result = '';

		for($i = 0, $n = strlen($this->samples); $i < $n; $i++)
		$result .= pack('v', (ord($this->samples[$i]) - 128) << 8 & 0xffff);

		return $result;
	}

	/** PCM WAV container accepted by SDL_mixer's in-memory loader. */
	public function wav(): string
	{
		$data = $this->pcm16();
		$length = strlen($data);
		$byteRate = $this->sampleRate * 2;

		return 'RIFF' . pack('V', 36 + $length) . 'WAVEfmt '
			. pack('VvvVVvv', 16, 1, 1, $this->sampleRate, $byteRate, 2, 16)
			. 'data' . pack('V', $length) . $data;
	}
}

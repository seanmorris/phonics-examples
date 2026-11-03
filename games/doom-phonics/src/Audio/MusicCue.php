<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

/** Selects one canonical Doom music lump without depending on port-specific aliases. */
final class MusicCue
{

	private function __construct(private string $lump) {}

	public static function title(): self { return new self('D_INTRO'); }

	public static function level(string $map): self
	{
		$map = strtoupper($map);

		if(!preg_match('/^E[1-9]M[1-9]$/', $map))
		throw new \InvalidArgumentException("Unsupported episode map music cue $map");

		return new self('D_' . $map);
	}

	public static function intermission(): self { return new self('D_INTER'); }

	public static function intermissionForMap(string $map): self
	{
		return new self(preg_match('/^MAP\d\d$/i', $map) ? 'D_DM2INT' : 'D_INTER');
	}

	public static function victory(): self { return new self('D_VICTOR'); }

	public function lump(): string { return $this->lump; }
}

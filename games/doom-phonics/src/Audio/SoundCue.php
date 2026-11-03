<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

/** A named world-sound cue, kept distinct from arbitrary WAD lump strings. */
final class SoundCue
{

	private function __construct(private string $lump) {}

	/** Construct a checked archive cue after SNDINFO has resolved a logical actor sound. */
	public static function fromLump(string $lump): self
	{
		$lump = strtoupper($lump);

		if(!preg_match('/^[A-Z0-9_]{1,8}$/', $lump))
		throw new \InvalidArgumentException("Invalid WAD sound lump $lump");

		return new self($lump);
	}

	public static function doorOpen(): self { return new self('DSDOROPN'); }

	public static function doorClose(): self { return new self('DSDORCLS'); }

	public static function platformStart(): self { return new self('DSPSTART'); }

	public static function platformStop(): self { return new self('DSPSTOP'); }

	public static function stoneMove(): self { return new self('DSSTNMOV'); }

	public static function switch(): self { return new self('DSSWTCHN'); }

	public static function exitSwitch(): self { return new self('DSSWTCHX'); }

	public static function teleport(): self { return new self('DSTELEPT'); }

	public static function locked(): self { return new self('DSOOF'); }

	public static function menuOpen(): self { return new self('DSSWTCHN'); }

	public static function menuBack(): self { return new self('DSSWTCHX'); }

	public static function menuMove(): self { return new self('DSPSTOP'); }

	public static function menuSelect(): self { return new self('DSPISTOL'); }

	public static function intermissionTick(): self { return new self('DSPISTOL'); }

	public static function intermissionStage(): self { return new self('DSBAREXP'); }

	public static function intermissionDone(): self { return new self('DSSGCOCK'); }

	public function lump(): string { return $this->lump; }
}

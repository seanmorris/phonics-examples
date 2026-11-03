<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Projection applied when the player looks above or below the horizon. */
enum PitchMode: string
{
	case Perspective = 'perspective';
	case ClassicShear = 'classic';

	public function label(): string
	{
		return match($this)
		{
			self::Perspective => 'Perspective',
			self::ClassicShear => 'Classic',
		};
	}

	/** Integer representation passed to GLES uniforms without leaking enum cases into shaders. */
	public function shaderValue(): int { return $this === self::ClassicShear ? 1 : 0; }
}

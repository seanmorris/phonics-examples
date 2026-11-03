<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** User-selectable raster resolution for the three-dimensional scene. */
enum RenderResolution: string
{
	case Native = 'native';
	case Classic = 'classic';

	public function label(): string
	{
		return match($this)
		{
			self::Native => 'Native',
			self::Classic => 'Classic',
		};
	}

	/** Resolve the active scale while retaining the world's classic-style preference. */
	public function scale(float $classicScale): float
	{
		return $this === self::Native ? 1.0 : $classicScale;
	}
}

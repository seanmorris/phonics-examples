<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

/** Selected PLAYPAL page and optional fixed COLORMAP row for one rendered view. */
final class ViewColorState
{
	public const NORMAL_PALETTE = 0;
	public const FIRST_DAMAGE_PALETTE = 1;
	public const DAMAGE_PALETTE_COUNT = 8;
	public const FIRST_BONUS_PALETTE = 9;
	public const BONUS_PALETTE_COUNT = 4;
	public const RADIATION_PALETTE = 13;
	public const INVULNERABILITY_COLORMAP = 32;

	public function __construct(
		public int $palettePage = self::NORMAL_PALETTE
		, public ?int $fixedColormap = null
	){
		if($palettePage < 0) throw new \InvalidArgumentException('PLAYPAL page cannot be negative');

		if($fixedColormap !== null && $fixedColormap < 0)
		throw new \InvalidArgumentException('Fixed COLORMAP row cannot be negative');
	}
}

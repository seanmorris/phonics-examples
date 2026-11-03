<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Graphics\IndexedImage;

/** Place a first-person WAD sprite in Doom's classic 320 by 200 screen space. */
final class ViewWeaponPlacement
{
	/** Doom's psprite Y origin leaves the conventional 32-pixel status-bar overlap. */
	public const READY_ORIGIN_Y = 32.0;

	/**
	 * Store the resolved upper-left patch coordinates.
	 *
	 * @param float $x Horizontal classic-screen coordinate.
	 * @param float $y Vertical classic-screen coordinate.
	 */
	public function __construct(public float $x, public float $y) {}

	/**
	 * Resolve WAD offsets while retaining the full-screen weapon origin.
	 *
	 * Common ready sprites consequently end at y=200 and are occluded by the
	 * status bar drawn afterward, matching the software renderer.
	 *
	 * @param IndexedImage $image Decoded first-person sprite patch.
	 * @param float $loweringOffset Additional positive offset from raise/lower animation.
	 * @return self Resolved upper-left placement in classic screen coordinates.
	 */
	public static function fromImage(IndexedImage $image, float $loweringOffset = 0): self
	{
		return new self(-$image->leftOffset, self::READY_ORIGIN_Y - $image->topOffset + $loweringOffset);
	}
}

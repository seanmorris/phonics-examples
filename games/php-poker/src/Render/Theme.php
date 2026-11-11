<?php
declare(strict_types=1);
namespace PhpPoker\Render;

/**
 * Palette, type scale and radii for the two visual modes.
 *
 * Colours are straight RGBA floats. `classic` is the flat DOS field: square corners, no shadows or gradients.
 * `modern` keeps the same layout and type and adds rounded corners, a gentle vignette and light shadows.
 */
final class Theme
{
	public const CLASSIC = 'classic';
	public const MODERN = 'modern';

	public const INK = [.07,.07,.07,1];
	public const PLATE = [.957,.945,.91,1];
	public const PLATE_FOLD = [.949,.718,.718,1];
	public const PLATE_HAND = [.949,.886,.416,1];
	public const MENU = [.07,.07,.07,1];
	public const MENU_TEXT = [.957,.945,.91,1];
	public const BUTTON = [.812,.812,.812,1];
	public const BRASS = [.851,.643,.255,1];
	/** Brightness of a card that does not count: a folded hand's, or one that only fills out an example hand. */
	public const OUT_OF_PLAY = .55;
	public const DANGER_INK = [.553,.114,.114,1];
	public const CHIP_GRAY = [.812,.812,.812,1];
	public const CHIP_RED = [.847,.251,.239,1];
	public const CHIP_BLUE = [.227,.373,.847,1];
	public const CHIP_INK = [.957,.945,.91,1];
	public const SHADOW = [0,0,0,.22];
	public const DIALOG = [.957,.945,.91,1];
	public const HISTORY_BLUE = [.169,.247,.549,1];
	public const EMBLEM = [.957,.945,.91,1];

	public const CAPTION = 12.0;
	public const BODY = 14.0;
	public const LABEL = 15.0;
	public const HEADING = 18.0;
	public const DISPLAY = 54.0;

	/**
	 * Felt colour of a mode.
	 *
	 * @param string $mode `classic` or `modern`.
	 * @return array{float,float,float,float}
	 */
	public static function felt(string $mode): array
	{
		return $mode === self::MODERN ? [.129,.478,.282,1] : [.122,.561,.271,1];
	}

	/**
	 * Lighter felt at the centre of the modern vignette; classic has no gradient.
	 *
	 * @param string $mode `classic` or `modern`.
	 * @return array{float,float,float,float}
	 */
	public static function feltCentre(string $mode): array
	{
		return $mode === self::MODERN ? [.145,.533,.31,1] : self::felt($mode);
	}

	/**
	 * Darker felt at the edge of the modern vignette; classic has no gradient.
	 *
	 * @param string $mode `classic` or `modern`.
	 * @return array{float,float,float,float}
	 */
	public static function feltEdge(string $mode): array
	{
		return $mode === self::MODERN ? [.102,.373,.227,1] : self::felt($mode);
	}

	/**
	 * Corner radius for a mode; classic corners are square.
	 *
	 * @param string $mode `classic` or `modern`.
	 * @param float $radius Radius used by the modern mode.
	 */
	public static function radius(string $mode, float $radius): float
	{
		return $mode === self::MODERN ? $radius : 0.0;
	}

	/**
	 * Multiply a colour's alpha.
	 *
	 * @param array{float,float,float,float} $color Straight RGBA colour.
	 * @param float $alpha Opacity multiplier, clamped to [0,1].
	 * @return array{float,float,float,float}
	 */
	public static function alpha(array $color, float $alpha): array
	{
		$color[3] *= max(0, min(1, $alpha));
		return $color;
	}

	/**
	 * Linear blend between two colours.
	 *
	 * @param array{float,float,float,float} $from Colour at zero.
	 * @param array{float,float,float,float} $to Colour at one.
	 * @param float $amount Blend fraction, clamped to [0,1].
	 * @return array{float,float,float,float}
	 */
	public static function mix(array $from, array $to, float $amount): array
	{
		$amount = max(0, min(1, $amount));
		return array_map(static fn(float $a, float $b): float => $a + ($b - $a) * $amount, $from, $to);
	}
}

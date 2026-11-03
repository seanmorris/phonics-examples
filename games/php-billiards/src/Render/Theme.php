<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

/**
 * Shared HUD palette, type scale, and radii.
 *
 * Colours are straight RGBA floats. Text colours keep at least 4.5:1 contrast against `PANEL` composited over the
 * darkest table tones, including disabled states, which change emphasis through opacity of fills rather than text.
 */
final class Theme
{
	public const PANEL = [.043,.071,.063,.84];
	public const PANEL_RAISED = [.075,.11,.098,.92];
	public const HAIRLINE = [.91,.83,.63,.18];
	public const SHADOW = [0,0,0,.42];
	public const DIM = [.008,.012,.01,.58];
	public const BRASS = [.85,.64,.255,1];
	public const BRASS_BRIGHT = [.96,.78,.42,1];
	public const BRASS_DEEP = [.42,.29,.09,1];
	public const TEXT = [.949,.937,.902,1];
	public const TEXT_SECONDARY = [.663,.71,.678,1];
	public const TEXT_ON_BRASS = [.09,.067,.027,1];
	public const DANGER = [.878,.325,.239,1];
	public const SUCCESS = [.42,.78,.55,1];
	public const SOLID = [.94,.74,.25,1];
	public const STRIPE = [.48,.74,1,1];
	public const KEYCAP = [.16,.2,.18,1];
	public const KEYCAP_EDGE = [.94,.93,.88,.22];

	public const CAPTION = 13.0;
	public const BODY = 15.0;
	public const LABEL = 16.0;
	public const HEADING = 21.0;
	public const DISPLAY = 46.0;

	public const RADIUS_PANEL = 12.0;
	public const RADIUS_BUTTON = 10.0;
	public const GAP = 8.0;
	public const MARGIN = 16.0;

	/** Gamepad face colours for keycap glyphs, matching common controller legends at reduced saturation. */
	public const FACE = [
		'A' => [.36,.7,.39,1],'B' => [.84,.35,.3,1],'X' => [.33,.55,.88,1],'Y' => [.9,.75,.28,1]
	];

	/**
	 * Multiply a colour's alpha.
	 *
	 * @param array{float,float,float,float} $color Straight RGBA colour.
	 * @param float $alpha Opacity multiplier, clamped to [0,1].
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
	 */
	public static function mix(array $from, array $to, float $amount): array
	{
		$amount = max(0, min(1, $amount));
		return array_map(static fn(float $a, float $b): float => $a + ($b - $a) * $amount, $from, $to);
	}
}

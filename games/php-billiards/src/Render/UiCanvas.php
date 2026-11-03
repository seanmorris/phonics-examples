<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

/**
 * Immediate-mode draw list for the native HUD.
 *
 * Each primitive appends one 20-float instance consumed by `UiRenderer`: rect, fill, radius/border/mode/softness,
 * border colour, and either glyph UVs or a gradient end colour. Building the list is pure PHP so layouts can be
 * measured and tested without a GL context.
 */
final class UiCanvas
{
	private const SOLID = 0.0;
	private const GLYPH = 1.0;
	private const CIRCLE = 2.0;
	private const GRADIENT = 3.0;

	/** @var list<list<float>> */
	private array $instances = [];
	/** Global opacity applied to every subsequent primitive, used for fades. */
	public float $opacity = 1.0;

	/**
	 * @param Text $text Font layout used for labels and measurement.
	 * @param float $unit Device pixels per design pixel.
	 */
	public function __construct(
		/** Font layout used for labels and measurement. */
		public readonly Text $text
		/** Device pixels per design pixel. */
		, public readonly float $unit
	) {}

	/** @return list<list<float>> */
	public function instances(): array { return $this->instances; }

	private function push(array $rect, array $fill, float $radius, float $border, float $mode, float $softness, array $edge, array $extra): void
	{
		$fill[3] *= $this->opacity;
		$edge[3] *= $this->opacity;
		if($mode === self::GRADIENT) $extra[3] *= $this->opacity;
		if($fill[3] <= .001 && ($border <= 0 || $edge[3] <= .001) && ($mode !== self::GRADIENT || $extra[3] <= .001)) return;
		$this->instances[] = [...$rect, ...$fill, $radius, $border, $mode, $softness, ...$edge, ...$extra];
	}

	/**
	 * Rounded rectangle with an optional inside border.
	 *
	 * @param float $x Left edge in pixels.
	 * @param float $y Top edge in pixels.
	 * @param float $width Width in pixels.
	 * @param float $height Height in pixels.
	 * @param float $radius Corner radius in pixels.
	 * @param array{float,float,float,float} $fill Straight RGBA fill.
	 * @param array{float,float,float,float}|null $border Straight RGBA border, or null for none.
	 * @param float $borderWidth Border width in pixels.
	 */
	public function box(float $x, float $y, float $width, float $height, float $radius, array $fill
		, ?array $border = null, float $borderWidth = 0
	): void {
		$this->push([$x,$y,$width,$height], $fill, $radius, $border ? $borderWidth : 0, self::SOLID, 0
			, $border ?? [0,0,0,0], [0,0,0,0]);
	}

	/**
	 * Soft drop shadow beneath a rounded rectangle.
	 *
	 * @param float $x Left edge in pixels.
	 * @param float $y Top edge in pixels.
	 * @param float $width Width in pixels.
	 * @param float $height Height in pixels.
	 * @param float $radius Corner radius in pixels.
	 * @param float $blur Blur distance in pixels.
	 * @param array{float,float,float,float} $color Straight RGBA shadow colour.
	 */
	public function shadow(float $x, float $y, float $width, float $height, float $radius, float $blur, array $color = Theme::SHADOW): void
	{
		$this->push([$x,$y,$width,$height], $color, $radius, 0, self::SOLID, max(.5, $blur), [0,0,0,0], [0,0,0,0]);
	}

	/**
	 * Vertical gradient inside a rounded rectangle.
	 *
	 * @param float $x Left edge in pixels.
	 * @param float $y Top edge in pixels.
	 * @param float $width Width in pixels.
	 * @param float $height Height in pixels.
	 * @param float $radius Corner radius in pixels.
	 * @param array{float,float,float,float} $top Straight RGBA colour at the top edge.
	 * @param array{float,float,float,float} $bottom Straight RGBA colour at the bottom edge.
	 */
	public function gradient(float $x, float $y, float $width, float $height, float $radius, array $top, array $bottom): void
	{
		$this->push([$x,$y,$width,$height], $top, $radius, 0, self::GRADIENT, 0, [0,0,0,0], $bottom);
	}

	/**
	 * Filled disc with an optional ring, or a bare ring when the fill is transparent.
	 *
	 * @param float $centerX Centre X in pixels.
	 * @param float $centerY Centre Y in pixels.
	 * @param float $radius Outer radius in pixels.
	 * @param array{float,float,float,float} $fill Straight RGBA fill.
	 * @param array{float,float,float,float}|null $ring Straight RGBA ring, or null for none.
	 * @param float $ringWidth Ring width in pixels.
	 */
	public function circle(float $centerX, float $centerY, float $radius, array $fill, ?array $ring = null, float $ringWidth = 0): void
	{
		$this->push([$centerX - $radius,$centerY - $radius,$radius * 2,$radius * 2], $fill, 0, $ring ? $ringWidth : 0
			, self::CIRCLE, 0, $ring ?? [0,0,0,0], [0,0,0,0]);
	}

	/**
	 * Draw one line of text with its caps centred on `$middle`, returning the drawn width.
	 *
	 * @param string $text UTF-8 text on one line.
	 * @param float $x Anchor X in pixels.
	 * @param float $middle Vertical centre of the capitals in pixels.
	 * @param float $size Design em size, scaled by the canvas unit.
	 * @param array{float,float,float,float} $color Straight RGBA text colour.
	 * @param string $face Face name, body or bold.
	 * @param string $align left, center, or right relative to `$x`.
	 * @param float|null $maxWidth Width in pixels beyond which text is ellipsized.
	 * @param float $tracking Extra spacing between characters in em units.
	 * @param bool $shadow Whether to draw a soft shadow beneath the text.
	 */
	public function label(string $text, float $x, float $middle, float $size, array $color, string $face = 'body'
		, string $align = 'left', ?float $maxWidth = null, float $tracking = 0, bool $shadow = false
	): float {
		$size *= $this->unit;
		if($maxWidth !== null) $text = $this->text->fit($text, $size, $maxWidth, $face, $tracking);
		$width = $this->text->measure($text, $size, $face, $tracking);
		$left = match($align) {'center' => $x - $width / 2, 'right' => $x - $width, default => $x};

		if($shadow) foreach($this->text->glyphs($text, $left, $middle + $size * .06, $size, $face, $tracking) as $quad)
		$this->push(array_slice($quad, 0, 4), [0,0,0,.55 * $color[3]], 0, 0, self::GLYPH, .16, [0,0,0,0], array_slice($quad, 4));

		foreach($this->text->glyphs($text, $left, $middle, $size, $face, $tracking) as $quad)
		$this->push(array_slice($quad, 0, 4), $color, 0, 0, self::GLYPH, 0, [0,0,0,0], array_slice($quad, 4));

		return $width;
	}

	/**
	 * Measure text at a design size scaled by the canvas unit.
	 *
	 * @param string $text UTF-8 text on one line.
	 * @param float $size Design em size.
	 * @param string $face Face name, body or bold.
	 * @param float $tracking Extra spacing between characters in em units.
	 */
	public function measure(string $text, float $size, string $face = 'body', float $tracking = 0): float
	{
		return $this->text->measure($text, $size * $this->unit, $face, $tracking);
	}

	/**
	 * Keyboard key or gamepad face button legend, returning its width.
	 *
	 * Single gamepad face letters become coloured discs; everything else becomes a rounded keycap.
	 *
	 * @param string $legend Key or button legend.
	 * @param float $x Left edge in pixels.
	 * @param float $middle Vertical centre in pixels.
	 * @param float $size Design caption size.
	 * @param bool $pad Whether the legend names a controller button rather than a key.
	 */
	public function keycap(string $legend, float $x, float $middle, float $size = Theme::CAPTION, bool $pad = false): float
	{
		$unit = $this->unit;
		$height = ($size + 7) * $unit;

		if($pad && isset(Theme::FACE[$legend]))
		{
			$radius = $height / 2;
			$this->circle($x + $radius, $middle, $radius, Theme::alpha(Theme::FACE[$legend], .9), [1,1,1,.25], 1 * $unit);
			$this->label($legend, $x + $radius, $middle, $size * .92, [1,1,1,1], 'bold', 'center');

			return $height;
		}

		$width = max($height, $this->measure($legend, $size * .9, 'bold') + 12 * $unit);
		$this->box($x, $middle - $height / 2, $width, $height, 5 * $unit, Theme::KEYCAP, Theme::KEYCAP_EDGE, 1 * $unit);
		$this->label($legend, $x + $width / 2, $middle, $size * .9, Theme::TEXT, 'bold', 'center');

		return $width;
	}

	/**
	 * Width a keycap would occupy without drawing it.
	 *
	 * @param string $legend Key or button legend.
	 * @param float $size Design caption size.
	 * @param bool $pad Whether the legend names a controller button rather than a key.
	 */
	public function keycapWidth(string $legend, float $size = Theme::CAPTION, bool $pad = false): float
	{
		$height = ($size + 7) * $this->unit;
		return $pad && isset(Theme::FACE[$legend]) ? $height : max($height, $this->measure($legend, $size * .9, 'bold') + 12 * $this->unit);
	}
}

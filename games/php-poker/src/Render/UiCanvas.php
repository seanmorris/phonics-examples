<?php
declare(strict_types=1);
namespace PhpPoker\Render;

/**
 * Immediate-mode draw list for the table.
 *
 * Each primitive appends one 20-float instance consumed by `UiRenderer`: rect, fill, radius/border/mode/softness,
 * border colour, and either atlas UVs or a gradient end colour. Building the list is pure PHP so layouts can be
 * measured and tested without a GL context.
 */
final class UiCanvas
{
	private const SOLID = 0.0;
	private const GLYPH = 1.0;
	private const CIRCLE = 2.0;
	private const GRADIENT = 3.0;
	private const IMAGE = 4.0;

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
	 * @param bool $horizontal Whether the ramp runs left to right instead of top to bottom.
	 */
	public function gradient(float $x, float $y, float $width, float $height, float $radius, array $top, array $bottom, bool $horizontal = false): void
	{
		$this->push([$x,$y,$width,$height], $top, $radius, 0, self::GRADIENT, $horizontal ? 1 : 0, [0,0,0,0], $bottom);
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
	 * Axis-aligned ellipse, drawn as a circle stretched by the renderer's local coordinates.
	 *
	 * @param float $centerX Centre X in pixels.
	 * @param float $centerY Centre Y in pixels.
	 * @param float $radiusX Horizontal radius in pixels.
	 * @param float $radiusY Vertical radius in pixels.
	 * @param array{float,float,float,float} $fill Straight RGBA fill.
	 * @param array{float,float,float,float}|null $ring Straight RGBA ring, or null for none.
	 * @param float $ringWidth Ring width in pixels.
	 */
	public function ellipse(float $centerX, float $centerY, float $radiusX, float $radiusY, array $fill, ?array $ring = null, float $ringWidth = 0): void
	{
		$this->push([$centerX - $radiusX,$centerY - $radiusY,$radiusX * 2,$radiusY * 2], $fill, 0, $ring ? $ringWidth : 0
			, self::CIRCLE, 1, $ring ?? [0,0,0,0], [0,0,0,0]);
	}

	/**
	 * Textured quad from the card atlas, clipped to rounded corners and tinted by `$tint`.
	 *
	 * @param float $x Left edge in pixels.
	 * @param float $y Top edge in pixels.
	 * @param float $width Width in pixels.
	 * @param float $height Height in pixels.
	 * @param float $radius Corner radius in pixels.
	 * @param array{float,float,float,float} $uv Atlas rectangle as u0, v0, u1, v1 in texture space.
	 * @param array{float,float,float,float} $tint Straight RGBA multiplier; white draws the texel as stored.
	 */
	public function image(float $x, float $y, float $width, float $height, float $radius, array $uv, array $tint = [1,1,1,1]): void
	{
		$this->push([$x,$y,$width,$height], $tint, $radius, 0, self::IMAGE, 0, [0,0,0,0], $uv);
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
}

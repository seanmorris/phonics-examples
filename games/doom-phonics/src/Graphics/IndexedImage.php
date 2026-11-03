<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

/** Compact palette-indexed image with a separate alpha byte for transparent patch pixels. */
final class IndexedImage
{

	/**
	 * Store validated row-major indexed pixels.
	 *
	 * @param int $width Pixel width.
	 * @param int $height Pixel height.
	 * @param string $pixels One palette-index byte per pixel.
	 * @param string $alpha One alpha byte per pixel.
	 * @param int $leftOffset Patch origin X offset.
	 * @param int $topOffset Patch origin Y offset.
	 * @return void Validates buffer sizes.
	 */
	public function __construct(
		public int $width
		, public int $height
		, public string $pixels
		, public string $alpha
		, public int $leftOffset = 0
		, public int $topOffset = 0
	){
		$length = $width * $height;

		if($width <= 0 || $height <= 0 || strlen($pixels) !== $length || strlen($alpha) !== $length)
		throw new \InvalidArgumentException('Indexed image buffer dimensions do not match');
	}

	/** @return string Interleaved palette-index/alpha bytes for a GLES RG8 texture. */
	public function indexAlphaBytes(): string
	{
		static $indices = [], $opacity = [];

		if(!$indices)
		for($value = 0; $value < 256; $value++)
		{
			$byte = chr($value);
			$indices[$byte] = $byte . "\0";
			$opacity[$byte] = "\0" . $byte;
		}

		// Expand the two byte planes in native string operations. Their nonzero
		// bytes occupy alternating positions, so OR interleaves without a PHP pixel loop.
		return strtr($this->pixels, $indices) | strtr($this->alpha, $opacity);
	}

	/** @param int $x Pixel X. @param int $y Pixel Y. @return int Palette index. */
	public function index(int $x, int $y): int
	{
		$x = (($x % $this->width) + $this->width) % $this->width;
		$y = (($y % $this->height) + $this->height) % $this->height;

		return ord($this->pixels[$y * $this->width + $x]);
	}

	/** @param int $x Pixel X. @param int $y Pixel Y. @return bool Whether the pixel is opaque. */
	public function opaque(int $x, int $y): bool
	{
		if($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) return false;

		return ord($this->alpha[$y * $this->width + $x]) !== 0;
	}

	/**
	 * Place a patch vertically from its WAD origin, optionally keeping its visible
	 * rectangle above a supporting plane.
	 *
	 * WAD sprite origins commonly put a few transparent/foot pixels below an
	 * actor's physical Z. The software renderer clipped those pixels against the
	 * floor. A billboard needs the same constraint explicitly or it intersects
	 * the floor plane and appears sunken.
	 *
	 * @return array{bottom:float,top:float}
	 */
	public function verticalBounds(float $origin, ?float $minimumBottom = null): array
	{
		$top = $origin + $this->topOffset;
		$bottom = $top - $this->height;

		if($minimumBottom !== null && $bottom < $minimumBottom)
		{
			$top += $minimumBottom - $bottom;
			$bottom = $minimumBottom;
		}

		return ['bottom' => $bottom, 'top' => $top];
	}
}

<?php
declare(strict_types=1);
namespace PhpPoker\Render;

/**
 * Proportional text layout over the generated signed-distance UI font.
 *
 * Measurement, kerning, ellipsis fitting, wrapping, and glyph placement are pure PHP so layout can be tested without
 * a GL context; every character outside the baked set maps to a defined fallback rather than a replacement glyph.
 */
final class Text
{
	/** Accented Latin letters fold to their base glyph; the baked faces cover printable ASCII plus typographic marks. */
	private const FOLD = [
		'À' => 'A','Á' => 'A','Â' => 'A','Ã' => 'A','Ä' => 'A','Å' => 'A','Æ' => 'AE','Ç' => 'C','È' => 'E','É' => 'E'
		, 'Ê' => 'E','Ë' => 'E','Ì' => 'I','Í' => 'I','Î' => 'I','Ï' => 'I','Ð' => 'D','Ñ' => 'N','Ò' => 'O','Ó' => 'O'
		, 'Ô' => 'O','Õ' => 'O','Ö' => 'O','Ø' => 'O','Ù' => 'U','Ú' => 'U','Û' => 'U','Ü' => 'U','Ý' => 'Y','ß' => 'ss'
		, 'à' => 'a','á' => 'a','â' => 'a','ã' => 'a','ä' => 'a','å' => 'a','æ' => 'ae','ç' => 'c','è' => 'e','é' => 'e'
		, 'ê' => 'e','ë' => 'e','ì' => 'i','í' => 'i','î' => 'i','ï' => 'i','ð' => 'd','ñ' => 'n','ò' => 'o','ó' => 'o'
		, 'ô' => 'o','õ' => 'o','ö' => 'o','ø' => 'o','ù' => 'u','ú' => 'u','û' => 'u','ü' => 'u','ý' => 'y','ÿ' => 'y'
		, '‘' => '’','„' => '“','‐' => '-','‑' => '-','−' => '-',"\u{00A0}" => ' ','⋯' => '…'
	];
	private const FALLBACK = '·';

	/**
	 * Faces drawn from a bitmap font, by the font pixels in one em. The Classic face is Fixedsys Excelsior, an
	 * 8×16 cell: drawn smaller than 16 device pixels a font pixel is smaller than a screen pixel, and whole rows
	 * and columns of it vanish (a B reads as a D).
	 */
	private const PIXEL_GRID = ['dos' => 16];

	/** Below this many device pixels per font pixel a bitmap face is drawn at its own size instead. */
	private const PIXEL_FLOOR = 1.25;

	/**
	 * @param array<string,mixed> $metrics Decoded `ui-font.json` from the texture generator.
	 */
	public function __construct(private array $metrics) {}

	/**
	 * Load metrics from the generated asset directory, verifying the manifest checksum.
	 *
	 * @param string $root Absolute root directory of the Poker package.
	 * @param array<string,mixed> $descriptor `uiFont` entry from `textures.json`.
	 */
	public static function load(string $root, array $descriptor): self
	{
		$bytes = file_get_contents($root . '/assets/generated/' . $descriptor['metrics']);
		if(isset($descriptor['metricsSha256']) && !hash_equals($descriptor['metricsSha256'], hash('sha256', (string)$bytes)))
		throw new \RuntimeException('Poker font metrics checksum mismatch');

		return new self(json_decode((string)$bytes, true, flags: JSON_THROW_ON_ERROR));
	}

	/**
	 * Baked faces by name, with their metrics.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function faces(): array { return $this->metrics['faces']; }

	/** Atlas width and height in texels. */
	public function atlasSize(): array { return [(int)$this->metrics['width'],(int)$this->metrics['height']]; }

	/**
	 * Cap height of a face as a fraction of its em size.
	 *
	 * @param string $face Face name, body or bold.
	 */
	public function capHeight(string $face = 'body'): float { return (float)$this->metrics['faces'][$face]['capHeight']; }

	/**
	 * The em size a face is really drawn and measured at.
	 *
	 * A bitmap face asked for less than `PIXEL_FLOOR` device pixels per font pixel is drawn at one device pixel per
	 * font pixel, its own size, so every row and column of it stays on screen and lands on one pixel. Larger sizes
	 * have room for every font pixel and are drawn as asked. Vector faces are always drawn as asked.
	 *
	 * A caller that has no room for that size asks for `$exact` and gets the size it asked for, drawn as before.
	 *
	 * @param string $face Face name.
	 * @param float $size Em size in device pixels.
	 * @param bool $exact Whether to draw at exactly this size even where that loses font pixels.
	 */
	public function size(string $face, float $size, bool $exact = false): float
	{
		$grid = self::PIXEL_GRID[$face] ?? null;
		return !$exact && $grid !== null && $size < $grid * self::PIXEL_FLOOR ? (float)$grid : $size;
	}

	/**
	 * Whether a face at this em size draws every font pixel as a whole number of device pixels, so its glyphs can be
	 * drawn with hard edges.
	 *
	 * @param string $face Face name.
	 * @param float $size Em size in device pixels, before `size()`.
	 * @param bool $exact Whether the face is drawn at exactly this size; see `size()`.
	 */
	public function crisp(string $face, float $size, bool $exact = false): bool
	{
		$grid = self::PIXEL_GRID[$face] ?? null;
		if($grid === null) return false;
		$scale = $this->size($face, $size, $exact) / $grid;
		return $scale >= 1 - 1e-6 && abs($scale - round($scale)) < 1e-6;
	}

	/**
	 * Split UTF-8 into characters the atlas can draw.
	 *
	 * @param string $text UTF-8 text.
	 * @param string $face Face name, body or bold.
	 * @return list<string>
	 */
	public function characters(string $text, string $face = 'body'): array
	{
		$glyphs = $this->metrics['faces'][$face]['glyphs'];
		$split = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if($split === false) $split = str_split(preg_replace('/[^\x20-\x7e]/', self::FALLBACK, $text) ?? '');
		$characters = [];

		foreach($split as $character)
		{
			if($character === "\t" || $character === "\n") $character = ' ';
			foreach(isset($glyphs[$character]) ? [$character] : self::fold($character) as $part)
			$characters[] = isset($glyphs[$part]) ? $part : self::FALLBACK;
		}

		return $characters;
	}

	/** @return list<string> */
	private static function fold(string $character): array
	{
		$folded = self::FOLD[$character] ?? null;
		if($folded === null) return [self::FALLBACK];

		return preg_split('//u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [self::FALLBACK];
	}

	/**
	 * Measure a single line in pixels.
	 *
	 * @param string $text UTF-8 text on one line.
	 * @param float $size Em size in pixels.
	 * @param string $face Face name, body or bold.
	 * @param float $tracking Extra spacing between characters in em units.
	 * @param bool $exact Whether to measure at exactly this size; see `size()`.
	 */
	public function measure(string $text, float $size, string $face = 'body', float $tracking = 0, bool $exact = false): float
	{
		$size = $this->size($face, $size, $exact);
		$width = 0.0;
		$previous = null;
		$characters = $this->characters($text, $face);
		$face = $this->metrics['faces'][$face];

		foreach($characters as $character)
		{
			if($previous !== null) $width += ($face['kerning'][$previous . $character] ?? 0) + $tracking;
			$width += $face['glyphs'][$character]['advance'];
			$previous = $character;
		}

		return $width * $size;
	}

	/**
	 * Shorten a line with an ellipsis until it fits the requested width.
	 *
	 * @param string $text UTF-8 text on one line.
	 * @param float $size Em size in pixels.
	 * @param float $maxWidth Available width in pixels.
	 * @param string $face Face name, body or bold.
	 * @param float $tracking Extra spacing between characters in em units.
	 */
	public function fit(string $text, float $size, float $maxWidth, string $face = 'body', float $tracking = 0, bool $exact = false): string
	{
		if($this->measure($text, $size, $face, $tracking, $exact) <= $maxWidth) return $text;
		$characters = $this->characters($text, $face);

		while($characters)
		{
			array_pop($characters);
			$candidate = rtrim(implode('', $characters)) . '…';
			if($this->measure($candidate, $size, $face, $tracking, $exact) <= $maxWidth) return $candidate;
		}

		return '';
	}

	/**
	 * Greedily wrap words into at most `maxLines` lines, ellipsizing the last line when text remains.
	 *
	 * @param string $text UTF-8 text.
	 * @param float $size Em size in pixels.
	 * @param float $maxWidth Available line width in pixels.
	 * @param int $maxLines Largest number of lines to return.
	 * @param string $face Face name, body or bold.
	 * @return list<string>
	 */
	public function wrap(string $text, float $size, float $maxWidth, int $maxLines = 2, string $face = 'body', bool $exact = false): array
	{
		$words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$lines = [];
		$line = '';

		foreach($words as $index => $word)
		{
			$candidate = $line === '' ? $word : $line . ' ' . $word;

			if($line === '' || $this->measure($candidate, $size, $face, 0, $exact) <= $maxWidth)
			{
				$line = $candidate;
				continue;
			}

			$lines[] = $line;
			$line = $word;

			if(count($lines) === $maxLines - 1)
			{
				$line = implode(' ', array_slice($words, $index));
				break;
			}
		}

		if($line !== '') $lines[] = $line;

		return array_map(fn(string $line): string => $this->fit($line, $size, $maxWidth, $face, 0, $exact), $lines);
	}

	/**
	 * Wrap as `wrap()` does, at the size the face is really drawn at where the whole text fits in that many lines,
	 * and otherwise at exactly the size asked for, so a bitmap face loses no words to being drawn crisp.
	 *
	 * @return array{lines:list<string>,exact:bool} The lines, and whether they must be drawn at exactly `$size`.
	 */
	public function wrapWhole(string $text, float $size, float $maxWidth, int $maxLines = 2, string $face = 'body'): array
	{
		if($this->size($face, $size) === $size) return ['lines' => $this->wrap($text, $size, $maxWidth, $maxLines, $face), 'exact' => false];
		// Wrapped with no limit on lines, the text either fits the lines allowed, every word whole, or it does not.
		$lines = $this->wrap($text, $size, $maxWidth, PHP_INT_MAX, $face);
		$whole = count($lines) <= $maxLines;
		foreach($lines as $line) $whole = $whole && $this->measure($line, $size, $face) <= $maxWidth;
		return $whole ? ['lines' => $lines, 'exact' => false] : ['lines' => $this->wrap($text, $size, $maxWidth, $maxLines, $face, true), 'exact' => true];
	}

	/**
	 * Place glyph quads for one line whose caps are vertically centred on `$middle`.
	 *
	 * @param string $text UTF-8 text on one line.
	 * @param float $x Left edge of the pen in pixels.
	 * @param float $middle Vertical centre of the capitals in pixels.
	 * @param float $size Em size in pixels.
	 * @param string $face Face name, body or bold.
	 * @param float $tracking Extra spacing between characters in em units.
	 * @param bool $exact Whether to place the glyphs at exactly this size; see `size()`.
	 * @return list<array{float,float,float,float,float,float,float,float,bool}> Screen rect, atlas UV rectangle, and
	 *   whether the glyph's outline lies on its face's pixel grid (the arrows a bitmap face borrows do not).
	 */
	public function glyphs(string $text, float $x, float $middle, float $size, string $face = 'body', float $tracking = 0, bool $exact = false): array
	{
		[$atlasWidth,$atlasHeight] = $this->atlasSize();
		$metrics = $this->metrics['faces'][$face];
		$size = $this->size($face, $size, $exact);
		$baseline = $middle + $metrics['capHeight'] * $size / 2;
		// A bitmap face starts on a whole device pixel, so at its own size every font pixel is one screen pixel.
		if(isset(self::PIXEL_GRID[$face])) [$x, $baseline] = [round($x), round($baseline)];
		$quads = [];
		$pen = 0.0;
		$previous = null;

		foreach($this->characters($text, $face) as $character)
		{
			if($previous !== null) $pen += ($metrics['kerning'][$previous . $character] ?? 0) + $tracking;
			$glyph = $metrics['glyphs'][$character];

			if(isset($glyph['atlas']))
			{
				[$atlasX,$atlasY,$cellWidth,$cellHeight] = $glyph['atlas'];
				[$left,$top,$width,$height] = $glyph['plane'];
				$grid = self::PIXEL_GRID[$face] ?? 0;
				$onGrid = $grid > 0;
				foreach([$left, $top, $width, $height, $glyph['advance']] as $edge) $onGrid = $onGrid && abs($edge * $grid - round($edge * $grid)) < 1e-6;
				$quads[] = [
					$x + ($pen + $left) * $size, $baseline + $top * $size, $width * $size, $height * $size
					, $atlasX / $atlasWidth, $atlasY / $atlasHeight
					, ($atlasX + $cellWidth) / $atlasWidth, ($atlasY + $cellHeight) / $atlasHeight
					, $onGrid
				];
			}

			$pen += $glyph['advance'];
			$previous = $character;
		}

		return $quads;
	}
}

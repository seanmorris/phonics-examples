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
	 */
	public function measure(string $text, float $size, string $face = 'body', float $tracking = 0): float
	{
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
	public function fit(string $text, float $size, float $maxWidth, string $face = 'body', float $tracking = 0): string
	{
		if($this->measure($text, $size, $face, $tracking) <= $maxWidth) return $text;
		$characters = $this->characters($text, $face);

		while($characters)
		{
			array_pop($characters);
			$candidate = rtrim(implode('', $characters)) . '…';
			if($this->measure($candidate, $size, $face, $tracking) <= $maxWidth) return $candidate;
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
	public function wrap(string $text, float $size, float $maxWidth, int $maxLines = 2, string $face = 'body'): array
	{
		$words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$lines = [];
		$line = '';

		foreach($words as $index => $word)
		{
			$candidate = $line === '' ? $word : $line . ' ' . $word;

			if($line === '' || $this->measure($candidate, $size, $face) <= $maxWidth)
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

		return array_map(fn(string $line): string => $this->fit($line, $size, $maxWidth, $face), $lines);
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
	 * @return list<array{float,float,float,float,float,float,float,float}> Screen rect and atlas UV rectangles.
	 */
	public function glyphs(string $text, float $x, float $middle, float $size, string $face = 'body', float $tracking = 0): array
	{
		[$atlasWidth,$atlasHeight] = $this->atlasSize();
		$metrics = $this->metrics['faces'][$face];
		$baseline = $middle + $metrics['capHeight'] * $size / 2;
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
				$quads[] = [
					$x + ($pen + $left) * $size, $baseline + $top * $size, $width * $size, $height * $size
					, $atlasX / $atlasWidth, $atlasY / $atlasHeight
					, ($atlasX + $cellWidth) / $atlasWidth, ($atlasY + $cellHeight) / $atlasHeight
				];
			}

			$pen += $glyph['advance'];
			$previous = $character;
		}

		return $quads;
	}
}

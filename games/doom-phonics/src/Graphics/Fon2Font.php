<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** One decoded variable-width FON2 font and its palette-indexed glyph pictures. */
final class Fon2Font
{
	public const PICTURE_PREFIX = 'DBIGF';

	/** @var array<int,IndexedImage> */
	private array $glyphs = [];
	/** @var array<int,int> */
	private array $widths = [];

	private function __construct(
		public int $height
		, public int $firstCharacter
		, public int $lastCharacter
		, public int $kerning
		, public int $spaceWidth
	){
	}

	/** Decode a standard FON2 lump into menu-red pictures in the active PLAYPAL. */
	public static function decode(Lump $lump, Palette $palette): self
	{
		$bytes = $lump->bytes();
		$source = $lump->identity();
		Binary::slice($bytes, 0, 12, $source . ' FON2 header');

		if(substr($bytes, 0, 4) !== 'FON2')
		throw new \UnexpectedValueException("$source: expected a FON2 font");

		$height = Binary::u16($bytes, 4, $source);
		$first = Binary::u8($bytes, 6, $source);
		$last = Binary::u8($bytes, 7, $source);
		$constantWidth = Binary::u8($bytes, 8, $source) !== 0;
		$paletteSize = Binary::u8($bytes, 10, $source) + 1;
		$flags = Binary::u8($bytes, 11, $source);

		if($height <= 0 || $height > 4096 || $last < $first)
		throw new \UnexpectedValueException("$source: invalid FON2 character range or height");

		$count = $last - $first + 1;
		$offset = 12;
		$kerning = 0;

		if($flags & 1)
		{
			$kerning = Binary::s16($bytes, $offset, $source);
			$offset += 2;
		}

		$widths = [];

		if($constantWidth)
		{
			$width = Binary::u16($bytes, $offset, $source);
			$offset += 2;
			$widths = array_fill(0, $count, $width);
		}
		else
		{
			Binary::slice($bytes, $offset, $count * 2, $source . ' FON2 widths');

			for($index = 0; $index < $count; $index++)
			$widths[] = Binary::u16($bytes, $offset + $index * 2, $source);

			$offset += $count * 2;
		}

		foreach($widths as $width)
		if($width > 4096) throw new \UnexpectedValueException("$source: invalid FON2 glyph width $width");

		$fontPalette = Binary::slice($bytes, $offset, $paletteSize * 3, $source . ' FON2 palette');
		$offset += $paletteSize * 3;
		$translation = [0];

		// FON2 color zero is transparent. The remaining entries are a brightness-sorted
		// ramp; menu text uses Doom's red range just like STCFN and the bitmap labels.
		for($index = 1; $index < $paletteSize; $index++)
		{
			$color = $index * 3;

			$luminance = (int)round(
				Binary::u8($fontPalette, $color, $source) * .2126
				+ Binary::u8($fontPalette, $color + 1, $source) * .7152
				+ Binary::u8($fontPalette, $color + 2, $source) * .0722
			);

			$translation[$index] = $palette->closestIndex($luminance, 0, 0);
		}

		$spaceIndex = 32 >= $first && 32 <= $last ? 32 - $first : null;
		$spaceWidth = $spaceIndex === null
			? max(1, (int)round(array_sum($widths) * 2 / (3 * $count)))
			: $widths[$spaceIndex];

		$self = new self($height, $first, $last, $kerning, $spaceWidth);

		foreach($widths as $index => $width)
		{
			$code = $first + $index;
			$self->widths[$code] = $width;
			$size = $width * $height;

			if($size === 0) continue;

			$decoded = '';

			while(strlen($decoded) < $size)
			{
				$value = Binary::u8($bytes, $offset, $source);
				$offset++;
				$command = $value < 128 ? $value : $value - 256;

				if($command >= 0)
				{
					$length = $command + 1;
					$decoded .= Binary::slice($bytes, $offset, $length, $source . " FON2 glyph $code");
					$offset += $length;
				}
				elseif($command !== -128)
				{
					$length = -$command + 1;
					$decoded .= str_repeat(Binary::slice($bytes, $offset, 1, $source . " FON2 glyph $code"), $length);
					$offset++;
				}
			}

			if(strlen($decoded) !== $size)
			throw new \UnexpectedValueException("$source: FON2 glyph $code overflows its {$width}x{$height} cell");

			$pixels = '';
			$alpha = '';

			for($pixel = 0; $pixel < $size; $pixel++)
			{
				$fontIndex = min(ord($decoded[$pixel]), $paletteSize - 1);
				$pixels .= chr($translation[$fontIndex] ?? 0);
				$alpha .= $fontIndex === 0 ? "\0" : "\xff";
			}

			$self->glyphs[$code] = new IndexedImage($width, $height, $pixels, $alpha);
		}

		return $self;
	}

	/** Stable synthetic atlas picture name for one FON2 character. */
	public static function pictureName(int $code): string
	{
		if($code < 0 || $code > 255) throw new \OutOfBoundsException("FON2 character $code is outside the byte range");

		return self::PICTURE_PREFIX . str_pad((string)$code, 3, '0', STR_PAD_LEFT);
	}

	/** Recover a character code from a synthetic atlas picture name. */
	public static function pictureCode(string $name): ?int
	{
		return preg_match('/^' . self::PICTURE_PREFIX . '(\d{3})$/D', strtoupper($name), $match)
			? (int)$match[1]
			: null;
	}

	/** Decoded picture for a character, or null when the font does not define it. */
	public function glyph(int $code): ?IndexedImage { return $this->glyphs[$code] ?? null; }

	/** @return list<int> Character codes with nonempty glyph cells. */
	public function glyphCodes(): array { return array_keys($this->glyphs); }

	/** Character advance including the font-wide kerning adjustment. */
	public function advance(int $code): int
	{
		return max(1, ($this->widths[$code] ?? $this->spaceWidth) + $this->kerning);
	}
}

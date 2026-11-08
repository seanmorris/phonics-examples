<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/**
 * Non-interlaced PNG pictures, including ZDoom's signed grAb sprite origin: palette images at 1, 2, 4, or 8 bits,
 * and greyscale, greyscale with alpha, truecolour, and truecolour with alpha at 8 or 16 bits. Colours map to the
 * nearest PLAYPAL entry; a 16-bit sample keeps its high byte.
 */
final class PngDecoder
{
	public const SIGNATURE = "\x89PNG\r\n\x1a\n";

	public static function decode(Lump $lump, Palette $palette): IndexedImage
	{
		$bytes = $lump->bytes();
		$source = $lump->identity();

		if(!str_starts_with($bytes, self::SIGNATURE))
		throw new \UnexpectedValueException("$source: invalid PNG signature");

		if(!function_exists('gzuncompress'))
		throw new \RuntimeException('PNG pictures require the PHP zlib extension');

		$offset = 8;
		$data = $colors = $transparency = '';
		$width = $height = $channels = $type = $depth = 0;
		$left = $top = 0;
		$ended = false;

		while($offset < strlen($bytes))
		{
			$header = Binary::slice($bytes, $offset, 8, $source);
			$size = unpack('N', $header)[1];
			$name = substr($header, 4);
			$chunk = Binary::slice($bytes, $offset + 8, $size, $source);
			$crc = Binary::slice($bytes, $offset + 8 + $size, 4, $source);

			// A bad CRC condemns a critical chunk. An ancillary one is read anyway, as ZDoom reads every chunk: the
			// Omega Weapon Pack's shotgun frames carry grAb chunks whose CRC was taken over the data alone.
			if(hash('crc32b', $name . $chunk, true) !== $crc && ctype_upper($name[0]))
			throw new \UnexpectedValueException("$source: corrupt PNG $name chunk");

			$offset += $size + 12;

			if($name === 'IHDR')
			{
				if($width || $size !== 13) throw new \UnexpectedValueException("$source: invalid PNG header");

				$h = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunk);
				$width = $h['width'];
				$height = $h['height'];
				$type = $h['type'];
				$depth = $h['depth'];
				$channels = match($type) { 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4, default => 0 };
				$depths = $type === 3 ? [1, 2, 4, 8] : [8, 16];

				if($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || !$channels
					|| !in_array($depth, $depths, true) || $h['compression'] || $h['filter'] || $h['interlace']
				)
				throw new \UnexpectedValueException("$source: unsupported PNG dimensions or encoding");
			}
			elseif($name === 'PLTE') $colors = $chunk;
			elseif($name === 'tRNS') $transparency = $chunk;
			elseif($name === 'IDAT') $data .= $chunk;
			elseif($name === 'grAb' && $size === 8)
			{
				// Reversing into Binary::s32 also works in the 32-bit browser runtime.
				$left = Binary::s32(strrev(substr($chunk, 0, 4)), 0, $source);
				$top = Binary::s32(strrev(substr($chunk, 4, 4)), 0, $source);
			}
			elseif($name === 'IEND')
			{
				$ended = true;
				break;
			}
		}

		if(!$width || !$ended || ($type === 3 && strlen($colors) < 3)
			|| strlen($colors) % 3 || strlen($colors) > 768
		)
		throw new \UnexpectedValueException("$source: incomplete PNG");

		// Bytes per row and, for the filters, per pixel; a sub-byte pixel still filters by whole bytes.
		$stride = intdiv($width * $channels * $depth + 7, 8);
		$step = max(1, intdiv($channels * $depth, 8));
		$raw = @gzuncompress($data, ($stride + 1) * $height);

		if(!is_string($raw) || strlen($raw) !== ($stride + 1) * $height)
		throw new \UnexpectedValueException("$source: invalid PNG image data");

		$indices = [];

		for($i = 0; $i < strlen($colors); $i += 3)
		$indices[] = chr($palette->closestIndex(ord($colors[$i]), ord($colors[$i + 1]), ord($colors[$i + 2])));
		$pixels = $alpha = '';
		$previous = str_repeat("\0", $stride);
		$mask = (1 << $depth) - 1;
		$wide = $depth === 16;

		for($y = 0; $y < $height; $y++)
		{
			$filter = ord($raw[$y * ($stride + 1)]);

			if($filter > 4) throw new \UnexpectedValueException("$source: invalid PNG row filter");

			$row = substr($raw, $y * ($stride + 1) + 1, $stride);

			if($filter)
			for($x = 0; $x < $stride; $x++)
			{
				$a = $x >= $step ? ord($row[$x - $step]) : 0;
				$b = ord($previous[$x]);
				$c = $x >= $step ? ord($previous[$x - $step]) : 0;
				$predict = match($filter)
				{
					1 => $a, 2 => $b, 3 => intdiv($a + $b, 2), 4 => self::paeth($a, $b, $c)
				};

				$row[$x] = chr((ord($row[$x]) + $predict) & 255);
			}

			if($type === 3)
			{
				for($x = 0; $x < $width; $x++)
				{
					$bit = $x * $depth;
					$index = (ord($row[$bit >> 3]) >> (8 - $depth - ($bit & 7))) & $mask;

					if(!isset($indices[$index]))
					throw new \UnexpectedValueException("$source: invalid PNG palette index");

					$pixels .= $indices[$index];
					$alpha .= $transparency[$index] ?? "\xff";
				}
			}
			else
			{
				for($x = 0; $x < $stride; $x += $step)
				{
					// Full-precision samples for the colour key, their high bytes for the palette.
					$samples = [];

					for($i = 0; $i < $channels; $i++)
					$samples[] = $wide ? (ord($row[$x + 2 * $i]) << 8) | ord($row[$x + 2 * $i + 1]) : ord($row[$x + $i]);

					$shift = $wide ? 8 : 0;

					if($type === 0 || $type === 4)
					{
						$grey = $samples[0] >> $shift;
						$pixels .= chr($palette->closestIndex($grey, $grey, $grey));
						$alpha .= $type === 4
							? chr($samples[1] >> $shift)
							: ($transparency === pack('n', $samples[0]) ? "\0" : "\xff");
					}
					else
					{
						$pixels .= chr($palette->closestIndex($samples[0] >> $shift, $samples[1] >> $shift, $samples[2] >> $shift));
						$alpha .= $type === 6
							? chr($samples[3] >> $shift)
							: ($transparency === pack('n3', $samples[0], $samples[1], $samples[2]) ? "\0" : "\xff");
					}
				}
			}

			$previous = $row;
		}

		return new IndexedImage($width, $height, $pixels, $alpha, $left, $top);
	}

	private static function paeth(int $a, int $b, int $c): int
	{
		$p = $a + $b - $c;
		$pa = abs($p - $a);
		$pb = abs($p - $b);
		$pc = abs($p - $c);

		return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
	}
}

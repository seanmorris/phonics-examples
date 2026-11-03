<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** Non-interlaced 8-bit PNG pictures, including ZDoom's signed grAb sprite origin. */
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
		$width = $height = $channels = $type = 0;
		$left = $top = 0;
		$ended = false;

		while($offset < strlen($bytes))
		{
			$header = Binary::slice($bytes, $offset, 8, $source);
			$size = unpack('N', $header)[1];
			$name = substr($header, 4);
			$chunk = Binary::slice($bytes, $offset + 8, $size, $source);
			$crc = Binary::slice($bytes, $offset + 8 + $size, 4, $source);

			if(hash('crc32b', $name . $chunk, true) !== $crc)
			throw new \UnexpectedValueException("$source: corrupt PNG $name chunk");

			$offset += $size + 12;

			if($name === 'IHDR')
			{
				if($width || $size !== 13) throw new \UnexpectedValueException("$source: invalid PNG header");

				$h = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunk);
				$width = $h['width'];
				$height = $h['height'];
				$type = $h['type'];
				$channels = match($type) { 2 => 3, 3 => 1, 6 => 4, default => 0 };

				if($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || !$channels
					|| $h['depth'] !== 8 || $h['compression'] || $h['filter'] || $h['interlace']
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

		$stride = $width * $channels;
		$raw = @gzuncompress($data, ($stride + 1) * $height);

		if(!is_string($raw) || strlen($raw) !== ($stride + 1) * $height)
		throw new \UnexpectedValueException("$source: invalid PNG image data");

		$indices = [];

		for($i = 0; $i < strlen($colors); $i += 3)
		$indices[] = chr($palette->closestIndex(ord($colors[$i]), ord($colors[$i + 1]), ord($colors[$i + 2])));
		$pixels = $alpha = '';
		$previous = str_repeat("\0", $stride);

		for($y = 0; $y < $height; $y++)
		{
			$filter = ord($raw[$y * ($stride + 1)]);

			if($filter > 4) throw new \UnexpectedValueException("$source: invalid PNG row filter");

			$row = substr($raw, $y * ($stride + 1) + 1, $stride);

			for($x = 0; $x < $stride; $x++)
			{
				$a = $x >= $channels ? ord($row[$x - $channels]) : 0;
				$b = ord($previous[$x]);
				$c = $x >= $channels ? ord($previous[$x - $channels]) : 0;
				$predict = match($filter)
				{
					0 => 0, 1 => $a, 2 => $b, 3 => intdiv($a + $b, 2), 4 => self::paeth($a, $b, $c)
				};

				$row[$x] = chr((ord($row[$x]) + $predict) & 255);
			}

			for($x = 0; $x < $stride; $x += $channels)
			{
				if($type === 3)
				{
					$index = ord($row[$x]);

					if(!isset($indices[$index]))
					throw new \UnexpectedValueException("$source: invalid PNG palette index");

					$pixels .= $indices[$index];
					$alpha .= $transparency[$index] ?? "\xff";
				}
				else
				{
					$r = ord($row[$x]);
					$g = ord($row[$x + 1]);
					$b = ord($row[$x + 2]);
					$pixels .= chr($palette->closestIndex($r, $g, $b));
					$alpha .= $type === 6 ? $row[$x + 3] : ($transparency === pack('n3', $r, $g, $b) ? "\0" : "\xff");
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

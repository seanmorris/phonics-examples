<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** Decode Doom column/post pictures used for patches, sprites, HUD art, and title images. */
final class PatchDecoder
{

	/** @param Lump $lump Picture lump. @return IndexedImage Decoded transparent image. */
	public static function decode(Lump $lump): IndexedImage
	{
		$bytes = $lump->bytes();
		$source = $lump->identity();
		Binary::slice($bytes, 0, 8, $source);
		$width = Binary::u16($bytes, 0, $source);
		$height = Binary::u16($bytes, 2, $source);
		$left = Binary::s16($bytes, 4, $source);
		$top = Binary::s16($bytes, 6, $source);

		if($width <= 0 || $height <= 0 || $width > 4096 || $height > 4096)
		throw new \UnexpectedValueException("$source: invalid patch dimensions {$width}x{$height}");

		Binary::slice($bytes, 8, $width * 4, $source . ' column table');
		$pixels = str_repeat("\0", $width * $height);
		$alpha = str_repeat("\0", $width * $height);

		for($x = 0; $x < $width; $x++)
		{
			$offset = Binary::u32($bytes, 8 + $x * 4, $source);
			$previousTop = -1;
			$guard = 0;

			while(true)
			{
				if(++$guard > $height + 256)
				throw new \UnexpectedValueException("$source: unterminated patch column $x");

				$topDelta = Binary::u8($bytes, $offset, $source);
				$offset++;

				if($topDelta === 255) break;

				$length = Binary::u8($bytes, $offset, $source);
				$offset += 2;
				$row = $topDelta <= $previousTop ? $previousTop + $topDelta : $topDelta;
				$post = Binary::slice($bytes, $offset, $length, $source);

				for($y = 0; $y < $length; $y++)
				if($row + $y >= 0 && $row + $y < $height)
				{
					$target = ($row + $y) * $width + $x;
					$pixels[$target] = $post[$y];
					$alpha[$target] = "\xff";
				}

				$previousTop = $row;
				$offset += $length + 1;
			}
		}

		return new IndexedImage($width, $height, $pixels, $alpha, $left, $top);
	}
}

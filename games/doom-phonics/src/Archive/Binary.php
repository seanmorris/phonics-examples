<?php
declare(strict_types=1);
namespace DoomPhonics\Archive;

/** Bounds-checked little-endian reads used by every WAD structure parser. */
final class Binary
{

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param int $length Byte length.
	 * @param string $source Diagnostic source.
	 * @return string Exact byte slice.
	 */
	public static function slice(string $bytes, int $offset, int $length, string $source = 'binary data'): string
	{
		if($offset < 0 || $length < 0 || $offset > strlen($bytes) - $length)
		throw new \UnexpectedValueException("Truncated $source at byte $offset for $length bytes");

		return substr($bytes, $offset, $length);
	}

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param string $source Diagnostic source.
	 * @return int Unsigned byte.
	 */
	public static function u8(string $bytes, int $offset, string $source = 'binary data'): int
	{
		return ord(self::slice($bytes, $offset, 1, $source));
	}

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param string $source Diagnostic source.
	 * @return int Unsigned 16-bit value.
	 */
	public static function u16(string $bytes, int $offset, string $source = 'binary data'): int
	{
		return unpack('v', self::slice($bytes, $offset, 2, $source))[1];
	}

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param string $source Diagnostic source.
	 * @return int Signed 16-bit value.
	 */
	public static function s16(string $bytes, int $offset, string $source = 'binary data'): int
	{
		$value = self::u16($bytes, $offset, $source);

		return $value >= 0x8000 ? $value - 0x10000 : $value;
	}

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param string $source Diagnostic source.
	 * @return int Unsigned 32-bit value.
	 */
	public static function u32(string $bytes, int $offset, string $source = 'binary data'): int
	{
		return unpack('V', self::slice($bytes, $offset, 4, $source))[1];
	}

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param string $source Diagnostic source.
	 * @return int Signed 32-bit value.
	 */
	public static function s32(string $bytes, int $offset, string $source = 'binary data'): int
	{
		$value = self::u32($bytes, $offset, $source);

		return $value >= 0x80000000 ? $value - 0x100000000 : $value;
	}

	/**
	 * @param string $bytes Source bytes.
	 * @param int $offset Byte offset.
	 * @param int $length Fixed field width.
	 * @param string $source Diagnostic source.
	 * @return string Uppercase NUL-trimmed identifier.
	 */
	public static function name(string $bytes, int $offset, int $length = 8, string $source = 'binary data'): string
	{
		return strtoupper(rtrim(self::slice($bytes, $offset, $length, $source), "\0 \t\r\n"));
	}
}

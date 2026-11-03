<?php
declare(strict_types=1);
namespace PhpBilliards\Online;

/**
 * Versioned canonical binary encoding for deterministic game checkpoints.
 *
 * Values use explicit type tags, sorted map keys, length-prefixed UTF-8 strings, big-endian integers, and
 * big-endian IEEE-754 doubles. The byte stream is independent of JSON formatting and host byte order.
 */
final class BinaryState
{
	private const MAGIC = "PBLD\x01";

	/**
	 * Encode a supported PHP value as one versioned checkpoint byte stream.
	 *
	 * @param mixed $value Scalar, list, or string-keyed map to encode.
	 * @return string Canonical binary bytes including the format prefix.
	 */
	public static function encode(mixed $value): string
	{
		return self::MAGIC . self::write($value);
	}

	/**
	 * Decode and validate a complete checkpoint byte stream.
	 *
	 * @param string $bytes Complete canonical checkpoint bytes.
	 * @return mixed Restored scalar, list, or map value.
	 */
	public static function decode(string $bytes): mixed
	{
		if(!str_starts_with($bytes, self::MAGIC)) throw new \UnexpectedValueException('Unsupported checkpoint version');
		$offset = strlen(self::MAGIC);
		$value = self::read($bytes, $offset);
		if($offset !== strlen($bytes)) throw new \UnexpectedValueException('Trailing checkpoint bytes');
		return $value;
	}

	/**
	 * Encode a checkpoint for URL-safe room-event transport.
	 *
	 * @param mixed $value State value to encode and wrap in unpadded base64url.
	 * @return string Portable checkpoint text.
	 */
	public static function export(mixed $value): string
	{
		return rtrim(strtr(base64_encode(self::encode($value)), '+/', '-_'), '=');
	}

	/**
	 * Decode a URL-safe room-event checkpoint.
	 *
	 * @param string $value Unpadded base64url checkpoint text.
	 * @return mixed Restored state value.
	 */
	public static function import(string $value): mixed
	{
		if(!preg_match('/^[A-Za-z0-9_-]+$/', $value)) throw new \UnexpectedValueException('Invalid checkpoint encoding');
		$padding = str_repeat('=', (4 - strlen($value) % 4) % 4);
		$bytes = base64_decode(strtr($value, '-_', '+/') . $padding, true);
		if($bytes === false) throw new \UnexpectedValueException('Invalid checkpoint encoding');
		return self::decode($bytes);
	}

	/**
	 * Hash the exact canonical checkpoint bytes.
	 *
	 * @param mixed $value State value to encode canonically.
	 * @return string Lowercase SHA-256 digest.
	 */
	public static function hash(mixed $value): string
	{
		return hash('sha256', self::encode($value));
	}

	private static function write(mixed $value): string
	{
		if($value === null) return 'n';
		if($value === false) return 'f';
		if($value === true) return 't';
		if(is_int($value)) return 'i' . ($value < 0 ? '-' : '+') . pack('N', abs($value));
		if(is_float($value))
		{
			if(!is_finite($value)) throw new \UnexpectedValueException('Checkpoint contains a non-finite number');
			return 'd' . pack('E', $value);
		}
		if(is_string($value)) return 's' . pack('N', strlen($value)) . $value;
		if(!is_array($value)) throw new \UnexpectedValueException('Unsupported checkpoint value');

		if(array_is_list($value))
		{
			$result = 'l' . pack('N', count($value));
			foreach($value as $item) $result .= self::write($item);
			return $result;
		}

		$keys = array_keys($value);
		foreach($keys as $key) if(!is_string($key)) throw new \UnexpectedValueException('Checkpoint map keys must be strings');
		sort($keys, SORT_STRING);
		$result = 'm' . pack('N', count($keys));
		foreach($keys as $key) $result .= self::write($key) . self::write($value[$key]);
		return $result;
	}

	private static function read(string $bytes, int &$offset): mixed
	{
		$type = self::take($bytes, $offset, 1);
		return match($type) {
			'n' => null,
			'f' => false,
			't' => true,
			'i' => self::readInteger($bytes, $offset),
			'd' => unpack('Evalue', self::take($bytes, $offset, 8))['value'],
			's' => self::take($bytes, $offset, self::length($bytes, $offset)),
			'l' => self::readList($bytes, $offset),
			'm' => self::readMap($bytes, $offset),
			default => throw new \UnexpectedValueException('Invalid checkpoint type')
		};
	}

	private static function readInteger(string $bytes, int &$offset): int
	{
		$sign = self::take($bytes, $offset, 1);
		$value = self::length($bytes, $offset);
		if($sign !== '+' && $sign !== '-') throw new \UnexpectedValueException('Invalid checkpoint integer');
		return $sign === '-' ? -$value : $value;
	}

	private static function readList(string $bytes, int &$offset): array
	{
		$count = self::length($bytes, $offset);
		if($count > 100000) throw new \UnexpectedValueException('Checkpoint list is too large');
		$result = [];
		for($index = 0; $index < $count; $index++) $result[] = self::read($bytes, $offset);
		return $result;
	}

	private static function readMap(string $bytes, int &$offset): array
	{
		$count = self::length($bytes, $offset);
		if($count > 100000) throw new \UnexpectedValueException('Checkpoint map is too large');
		$result = [];
		for($index = 0; $index < $count; $index++)
		{
			$key = self::read($bytes, $offset);
			if(!is_string($key) || array_key_exists($key, $result))
			throw new \UnexpectedValueException('Invalid checkpoint map key');
			$result[$key] = self::read($bytes, $offset);
		}
		return $result;
	}

	private static function length(string $bytes, int &$offset): int
	{
		return unpack('Nvalue', self::take($bytes, $offset, 4))['value'];
	}

	private static function take(string $bytes, int &$offset, int $length): string
	{
		if($length < 0 || $offset + $length > strlen($bytes)) throw new \UnexpectedValueException('Truncated checkpoint');
		$value = substr($bytes, $offset, $length);
		$offset += $length;
		return $value;
	}
}

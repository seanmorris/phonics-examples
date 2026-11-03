<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

/**
 * Supply a reproducible, local random stream for versioned map generation.
 *
 * Hashing the seed and counter avoids PHP's global RNG and platform-specific
 * random engines. This is an authoring primitive, not a security token source.
 */
final class SeedSequence
{
	private int $counter = 0;

	/** Preserve the seed exactly, including zeroes, spaces and Unicode. */
	public function __construct(private string $seed) {}

	/** Select an integer in a bounded inclusive range without modulo bias. */
	public function integer(int $min, int $max): int
	{
		$size = $max - $min + 1;
		if($size < 1 || $size > 0x10000000) throw new \InvalidArgumentException('Invalid seed range');
		$limit = intdiv(0x10000000,$size) * $size;
		do { $value = (int)hexdec(substr(hash('sha256',$this->seed . ':' . $this->counter++),0,7)); }
		while($value >= $limit);
		return $min + $value % $size;
	}

	/** Shuffle a copy using this stream only. */
	public function shuffle(array $values): array
	{
		$values = array_values($values);
		for($i = count($values) - 1; $i > 0; $i--)
		{
			$j = $this->integer(0,$i);
			[$values[$i],$values[$j]] = [$values[$j],$values[$i]];
		}
		return $values;
	}
}

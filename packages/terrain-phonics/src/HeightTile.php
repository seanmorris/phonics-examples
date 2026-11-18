<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Small sparse-filled tile with bounded packed double storage, never a PHP array of samples. */
final class HeightTile
{
	/** Fixed-size little-endian doubles and validity flags. */
	private string $data, $valid;
	/** Access serial and active residency leases used by the owner cache. */
	public int $touched = 0, $pins = 0;
	/** Number of populated nodes, independent of reserved storage. */
	public int $samples = 0;

	/**
	 * Allocate storage only; no terrain generation runs in the constructor.
	 * @param int $size Number of lattice nodes along each tile edge.
	 * @param int $revision Committed landscape revision owning these nodes.
	 * @return void Allocates packed heights and empty validity flags.
	 */
	public function __construct(public readonly int $size, public readonly int $revision)
	{
		if($size < 1 || $size > 32) throw new \InvalidArgumentException('Invalid height tile size');

		$this->data = str_repeat("\0", $size * $size * 8);
		$this->valid = str_repeat("\0", $size * $size);
	}

	/**
	 * Read a node if populated. Local coordinates must belong to this tile.
	 * @param int $x Local node coordinate within this tile.
	 * @param int $z Local node coordinate within this tile.
	 * @return ?float Stored height, or null when the node has not been populated.
	 */
	public function get(int $x, int $z): ?float
	{
		$index = $this->index($x, $z);

		return $this->valid[$index] === "\0" ? null : unpack('e', $this->data, $index * 8)[1];
	}

	/**
	 * Store one computed finite node in the fixed-size packed buffer.
	 * @param int $x Local node coordinate within this tile.
	 * @param int $z Local node coordinate within this tile.
	 * @param float $height Previous height in metres.
	 * @return void Stores a finite node and marks it valid.
	 */
	public function set(int $x, int $z, float $height): void
	{
		if(!is_finite($height)) throw new \InvalidArgumentException('Height must be finite');

		$index = $this->index($x, $z);

		if($this->valid[$index] === "\0") $this->samples++;

		$this->valid[$index] = "\1";
		$this->data = substr_replace($this->data, pack('e', $height), $index * 8, 8);
	}

	/**
	 * Return packed payload bytes, excluding PHP object/hash-table overhead.
	 * @return int Packed height and validity payload size, excluding object overhead.
	 */
	public function bytes(): int { return strlen($this->data) + strlen($this->valid); }

	/**
	 * Validate addressing rather than permitting cross-tile accidental writes.
	 * @param int $x Local node coordinate within this tile.
	 * @param int $z Local node coordinate within this tile.
	 * @return int Validated row-major node offset.
	 */
	private function index(int $x, int $z): int
	{
		if($x < 0 || $z < 0 || $x >= $this->size || $z >= $this->size)
		throw new \OutOfBoundsException('Height tile coordinate outside tile');

		return $z * $this->size + $x;
	}
}

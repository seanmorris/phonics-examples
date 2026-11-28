<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Small sparse-filled tile with bounded packed double storage, never a PHP array of samples. */
final class HeightTile
{
	/** Bytes of a quiet NaN. A populated node is finite, so NaN marks a hole that block() can report unaided. */
	private const HOLE = "\x00\x00\x00\x00\x00\x00\xF8\x7F";
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

		$this->data = str_repeat(self::HOLE, $size * $size);
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
		if($x < 0 || $z < 0 || $x >= $this->size || $z >= $this->size)
		throw new \OutOfBoundsException('Height tile coordinate outside tile');

		$index = $z * $this->size + $x;

		return $this->valid[$index] === "\0" ? null : unpack('e', $this->data, $index * 8)[1];
	}

	/**
	 * Store one computed finite node in the fixed-size packed buffer.
	 * @param int $x Local node coordinate within this tile.
	 * @param int $z Local node coordinate within this tile.
	 * @param float $height Previous height in meters.
	 * @return void Stores a finite node and marks it valid.
	 */
	public function set(int $x, int $z, float $height): void
	{
		// Only a finite value minus itself is zero. Infinity and NaN both give NaN.
		if($height - $height !== 0.0) throw new \InvalidArgumentException('Height must be finite');

		if($x < 0 || $z < 0 || $x >= $this->size || $z >= $this->size)
		throw new \OutOfBoundsException('Height tile coordinate outside tile');

		$index = $z * $this->size + $x;

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
	 * Write the unpack format for the part of a four-by-four stencil that one tile stores. The stencil's sixteen
	 * nodes are named 'a' to 'p' in row-major order, so the parts read from neighboring tiles merge by array union.
	 * @param int $size Number of lattice nodes along each tile edge.
	 * @param int $column First stencil column that the tile stores.
	 * @param int $columns Number of stencil columns that the tile stores.
	 * @param int $row First stencil row that the tile stores.
	 * @param int $rows Number of stencil rows that the tile stores.
	 * @return string Format for block(), measured from the part's first node.
	 */
	public static function layout(int $size, int $column, int $columns, int $row, int $rows): string
	{
		if($size < 1 || $column < 0 || $columns < 1 || $column + $columns > 4 || $columns > $size
			|| $row < 0 || $rows < 1 || $row + $rows > 4 || $rows > $size
		) throw new \OutOfBoundsException('Height tile stencil part outside stencil');

		$fields = [];

		for($j = 0; $j < $rows; $j++)
		{
			// An absolute seek reaches the next tile row in one step, whatever the tile size.
			if($j) $fields[] = '@' . $j * $size * 8;

			for($i = 0; $i < $columns; $i++) $fields[] = 'e' . chr(97 + ($row + $j) * 4 + $column + $i);
		}

		return implode('/', $fields);
	}

	/**
	 * Read one stencil part with a single unpack call. A node that has not been populated reads as NaN. The
	 * arguments are not checked: the caller keeps the part described by the layout inside this tile.
	 * @param string $layout Format from layout() for a tile of this size.
	 * @param int $x Local column of the part's first node.
	 * @param int $z Local row of the part's first node.
	 * @return array Heights keyed by stencil node name.
	 */
	public function block(string $layout, int $x, int $z): array
	{
		return unpack($layout, $this->data, ($z * $this->size + $x) * 8);
	}
}

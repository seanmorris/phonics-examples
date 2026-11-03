<?php
declare(strict_types=1);
namespace PhpBilliards\Geometry;

/**
 * Packed position/normal/UV vertices and unsigned 32-bit triangle indices.
 *
 * This mutable build-time value collects one interleaved vertex stream and its indexed triangles, providing
 * the small append and merge operations used to assemble the table, cue, and reusable primitive meshes.
 */
final class MeshData
{
	/** Packed interleaved position, normal, and texture-coordinate bytes. */
	public string $vertices = '';
	/** Packed unsigned triangle-index bytes. */
	public string $indices = '';
	/** Number of vertices currently stored in the packed stream. */
	public int $vertexCount = 0;
	/** Number of indices currently stored in the packed stream. */
	public int $indexCount = 0;

	/**
	 * @param bool $doubleSided Whether the renderer disables back-face culling for this mesh.
	 */
	public function __construct(public bool $doubleSided = false) {}

	/**
	 * @param array{float,float,float,float,float,float,float,float} $values Position, normal, and texture coordinates for one vertex.
	 */
	public function vertex(array $values): int
	{
		if(count($values) !== 8 || count(array_filter($values, 'is_finite')) !== 8)
		throw new \InvalidArgumentException('Mesh vertices require eight finite values');

		$index = $this->vertexCount++;
		$this->vertices .= pack('g*', ...$values);

		return $index;
	}

	/**
	 * @param int $a First vertex index.
	 * @param int $b Second vertex index.
	 * @param int $c Third vertex index.
	 */
	public function triangle(int $a, int $b, int $c): void
	{
		if(min($a, $b, $c) < 0 || max($a, $b, $c) >= $this->vertexCount)
		throw new \OutOfBoundsException('Mesh index exceeds its vertex buffer');

		$this->indices .= pack('V3', $a, $b, $c);
		$this->indexCount += 3;
	}

	/**
	 * @param int $a First perimeter vertex index.
	 * @param int $b Second perimeter vertex index.
	 * @param int $c Third perimeter vertex index.
	 * @param int $d Fourth perimeter vertex index.
	 */
	public function quad(int $a, int $b, int $c, int $d): void
	{
		$this->triangle($a, $b, $c);
		$this->triangle($a, $c, $d);
	}

	/**
	 * Validate the packed mesh before it becomes a generated or GPU resource.
	 *
	 * Byte counts, triangle cardinality, finite vertex channels, and every unsigned index are checked together so a
	 * malformed constructive operation cannot silently reach the renderer.
	 */
	public function validate(): void
	{
		if($this->vertexCount < 3 || $this->indexCount < 3 || $this->indexCount % 3 !== 0
			|| strlen($this->vertices) !== $this->vertexCount * 32
			|| strlen($this->indices) !== $this->indexCount * 4
		){
			throw new \RuntimeException('Invalid packed mesh');
		}
	}
}

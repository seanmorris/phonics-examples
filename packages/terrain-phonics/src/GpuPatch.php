<?php
declare(strict_types=1);
namespace TerrainPhonics;

/**
 * Indexed grid geometry retained across graphics-context restoration.
 *
 * The packed stream holds local position, mesh-derived normal, parent height and
 * material identifier. Terrain generation owns those bytes independently of GL names.
 */
final class GpuPatch
{
	private array $vao = [], $buffers = [];
	private int $count;

	/**
	 * Retain and upload one patch with matching parent-height and normal attributes.
	 * @param string $vertices Packed eleven-float vertex records, including parent normal.
	 * @param int|null $sharedIndex Optional scene-owned element buffer.
	 * @param string $indices Packed unsigned-short triangle indices.
	 * @return void Uploads the retained mesh data.
	 */
	public function __construct(private string $vertices, private string $indices, ?int $sharedIndex = null)
	{
		$this->count = intdiv(strlen($indices), 2);
		$this->restore($sharedIndex);
	}

	/**
	 * Recreate context-local names from retained bytes.
	 * @param int|null $sharedIndex Optional restored scene-owned element buffer.
	 * @return void Uploads vertex and index buffers for the current context.
	 */
	public function restore(?int $sharedIndex = null): void
	{
		$this->close();
		glGenVertexArrays(1, $this->vao);
		glBindVertexArray($this->vao[0]);
		glGenBuffers($sharedIndex === null ? 2 : 1, $this->buffers);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($this->vertices), $this->vertices, GL_STATIC_DRAW);
		glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $sharedIndex ?? $this->buffers[1]);
		if($sharedIndex === null) glBufferData(GL_ELEMENT_ARRAY_BUFFER, strlen($this->indices), $this->indices, GL_STATIC_DRAW);

		foreach([[0,3,0],[1,3,12],[2,1,24],[3,1,28],[4,3,32]] as [$location,$size,$offset])
		{
			glEnableVertexAttribArray($location);
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 44, $offset);
		}

		glBindVertexArray(0);
	}

	/**
	 * Draw the indexed triangle stream for this patch.
	 * @return void Submits the retained vertices with index validation.
	 */
	public function draw(): void
	{
		glBindVertexArray($this->vao[0]);
		glDrawElements(GL_TRIANGLES, $this->count, GL_UNSIGNED_SHORT, 0);
	}

	/**
	 * Release names without discarding the retained restoration data.
	 * @return void Clears all currently owned graphics handles.
	 */
	public function close(): void
	{
		if($this->buffers !== []) glDeleteBuffers(count($this->buffers), $this->buffers);

		if($this->vao !== []) glDeleteVertexArrays(1, $this->vao);

		$this->buffers = $this->vao = [];
	}

	/**
	 * Report packed CPU mesh bytes.
	 * @param bool $includeIndices Include index data when the caller owns this topology.
	 * @return int Retained payload length in bytes.
	 */
	public function bytes(bool $includeIndices = true): int
	{
		return strlen($this->vertices) + ($includeIndices ? strlen($this->indices) : 0);
	}

	/**
	 * Construct a consistently wound grid index buffer.
	 * @param int $cells Number of cells along an edge.
	 * @return string Packed little-endian unsigned short indices.
	 */
	public static function indices(int $cells): string
	{
		$bytes = '';

		for($z = 0; $z < $cells; $z++)
		for($x = 0; $x < $cells; $x++)
		{
			$a = $z * ($cells + 1) + $x;
			$b = $a + $cells + 1;
			$bytes .= pack('v*', $a, $b, $a + 1, $a + 1, $b, $b + 1);
		}

		return $bytes;
	}
}

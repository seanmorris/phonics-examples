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
	 *
	 * @param string $vertices Packed eleven-float vertex records, including parent normal.
	 * @param string $indices Packed unsigned-short triangle indices.
	 * @param int|null $sharedIndex Optional scene-owned element buffer.
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

		if($sharedIndex === null)
		glBufferData(GL_ELEMENT_ARRAY_BUFFER, strlen($this->indices), $this->indices, GL_STATIC_DRAW);

		foreach([[0,3,0],[1,3,12],[2,1,24],[3,1,28],[4,3,32]] as [$location,$size,$offset])
		{
			glEnableVertexAttribArray($location);
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 44, $offset);
		}

		glBindVertexArray(0);
	}

	/**
	 * Draw the indexed triangle stream for this patch.
	 * @param int $stitchMask Shared-grid edge pattern, or zero for ordinary meshes.
	 * @return void Submits the retained vertices with index validation.
	 */
	public function draw(int $stitchMask = 0): void
	{
		glBindVertexArray($this->vao[0]);
		glDrawElements(GL_TRIANGLES, $this->count, GL_UNSIGNED_SHORT, $stitchMask * $this->count * 2);
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
	 * Pack all sixteen shared edge patterns consecutively for single-draw stitched grids.
	 * @param int $cells Even number of cells along a patch edge.
	 * @param string $regular The ordinary grid index stream from indices().
	 * @return string Sixteen equal-sized uint16 streams indexed by left/right/top/bottom bits.
	 */
	public static function stitchPatterns(int $cells, string $regular): string
	{
		$indices = unpack('v*', $regular);
		$changes = [[], [], [], []];
		$stride = $cells + 1;

		foreach($indices as $i => $vertex)
		{
			$x = $vertex % $stride;
			$z = intdiv($vertex, $stride);

			// Snap alternate boundary vertices onto an existing coarse endpoint. These
			// directions preserve triangle winding even when two stitched edges meet.
			if(($z & 1) && $x === 0) $changes[0][$i] = $vertex - $stride;

			if(($z & 1) && $x === $cells) $changes[1][$i] = $vertex + $stride;

			if(($x & 1) && $z === 0) $changes[2][$i] = $vertex - 1;

			if(($x & 1) && $z === $cells) $changes[3][$i] = $vertex + 1;
		}

		$stream = $regular;

		for($mask = 1; $mask < 16; $mask++)
		{
			$pattern = $indices;

			foreach($changes as $edge => $entries)
			if($mask & (1 << $edge)) $pattern = array_replace($pattern, $entries);
			$stream .= pack('v*', ...$pattern);
		}

		return $stream;
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

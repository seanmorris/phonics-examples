<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

/**
 * Restorable GLES vertex/index buffers for one packed procedural mesh.
 *
 * The resource uploads an interleaved slice, configures the billiards shader attributes, and can recreate or release
 * its GL objects across browser context changes. Small meshes are expanded to triangle lists and drawn non-indexed;
 * only the very large rail mesh keeps an index buffer.
 */
final class GpuMesh
{
	/**
	 * Meshes up to this many indices are expanded into plain triangle lists at load. The PHP GL binding validates
	 * every glDrawElements() with two synchronous WebGL queries, stalling the pipeline once per draw call.
	 */
	private const EXPAND_LIMIT = 65536;
	private array $vao = [];
	private array $buffers = [];
	private bool $closed = false;
	/** Whether this mesh keeps an index buffer and draws with glDrawElements(). */
	private bool $indexed;
	/** Separate vertex array and per-instance buffer for instanced draws, created on first use. */
	private array $instanceVao = [], $instanceBuffer = [];
	/** Floats per instance: a column-major model matrix, RGBA tint, and texture-array layer. */
	public const INSTANCE_FLOATS = 21;

	/**
	 * Upload one validated interleaved vertex stream and index stream.
	 *
	 * The constructor rejects inconsistent packed lengths before allocating the VAO and buffers used by the fixed
	 * position, normal, and texture-coordinate attribute layout.
	 *
	 * @param string $vertices Packed interleaved vertex bytes.
	 * @param string $indices Packed unsigned-index bytes.
	 * @param int $indexCount Number of indices submitted for drawing.
	 * @param bool $doubleSided Whether rendering disables back-face culling.
	 * @param bool $expand Whether a small mesh may drop its indices and draw as a plain triangle list.
	 */
	public function __construct(
		private string $vertices
		, private string $indices
		, public int $indexCount
		, public bool $doubleSided = false
		, bool $expand = true
	){
		if(strlen($vertices) % 32 !== 0 || strlen($indices) !== $indexCount * 4)
		throw new \InvalidArgumentException('Invalid packed GPU mesh');

		$this->indexed = !$expand || $indexCount > self::EXPAND_LIMIT;
		if(!$this->indexed)
		{
			$vertex = str_split($vertices, 32);
			$expanded = '';
			foreach(unpack('V*', $indices) as $index)
			{
				if(!isset($vertex[$index])) throw new \InvalidArgumentException('GPU mesh index is out of range');
				$expanded .= $vertex[$index];
			}
			$this->vertices = $expanded;
			$this->indices = '';
		}

		$this->create();
	}

	private function create(): void
	{
		glGenVertexArrays(1, $this->vao);
		glGenBuffers($this->indexed ? 2 : 1, $this->buffers);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($this->vertices), $this->vertices, GL_STATIC_DRAW);
		if($this->indexed)
		{
			glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);
			glBufferData(GL_ELEMENT_ARRAY_BUFFER, strlen($this->indices), $this->indices, GL_STATIC_DRAW);
		}

		foreach([[0,3,0],[1,3,12],[2,2,24]] as [$location,$size,$offset])
		{
			glEnableVertexAttribArray($location);
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 32, $offset);
		}

		glBindVertexArray(0);
	}

	public function draw(): void
	{
		glBindVertexArray($this->vao[0]);
		if($this->indexed) glDrawElements(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_INT, 0);
		else glDrawArrays(GL_TRIANGLES, 0, $this->indexCount);
	}

	/**
	 * Draw many copies in one call from packed per-instance data.
	 *
	 * Instances feed attributes 3-8 with divisor one, so a whole rack of balls costs one draw and at most one index
	 * validation instead of one of each per ball.
	 *
	 * @param string $instances Packed little-endian floats, `INSTANCE_FLOATS` per instance.
	 * @param int $count Number of instances in `$instances`.
	 */
	public function drawInstanced(string $instances, int $count): void
	{
		if($count < 1 || strlen($instances) !== $count * self::INSTANCE_FLOATS * 4) return;
		if(!$this->instanceVao) $this->createInstancing();

		glBindVertexArray($this->instanceVao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->instanceBuffer[0]);
		glBufferData(GL_ARRAY_BUFFER, strlen($instances), $instances, GL_DYNAMIC_DRAW);
		if($this->indexed) glDrawElementsInstanced(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_INT, 0, $count);
		else glDrawArraysInstanced(GL_TRIANGLES, 0, $this->indexCount, $count);
		glBindVertexArray(0);
	}

	private function createInstancing(): void
	{
		glGenVertexArrays(1, $this->instanceVao);
		glGenBuffers(1, $this->instanceBuffer);
		glBindVertexArray($this->instanceVao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		foreach([[0,3,0],[1,3,12],[2,2,24]] as [$location,$size,$offset])
		{
			glEnableVertexAttribArray($location);
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 32, $offset);
		}
		if($this->indexed) glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);

		glBindBuffer(GL_ARRAY_BUFFER, $this->instanceBuffer[0]);
		foreach([[3,4,0],[4,4,16],[5,4,32],[6,4,48],[7,4,64],[8,1,80]] as [$location,$size,$offset])
		{
			glEnableVertexAttribArray($location);
			glVertexAttribPointer($location, $size, GL_FLOAT, false, self::INSTANCE_FLOATS * 4, $offset);
			glVertexAttribDivisor($location, 1);
		}
		glBindVertexArray(0);
	}

	private function release(): void
	{
		if($this->instanceVao) glDeleteVertexArrays(count($this->instanceVao), $this->instanceVao);
		if($this->instanceBuffer) glDeleteBuffers(count($this->instanceBuffer), $this->instanceBuffer);
		if($this->vao) glDeleteVertexArrays(count($this->vao), $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);
		$this->vao = $this->buffers = $this->instanceVao = $this->instanceBuffer = [];
	}

	public function restore(): void
	{
		if($this->closed) return;
		$this->release();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;
		$this->closed = true;
		$this->release();
	}
}

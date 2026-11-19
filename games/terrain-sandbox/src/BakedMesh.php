<?php
declare(strict_types=1);

namespace TerrainSandbox;

/**
 * One mesh in the sandbox's baked format, uploaded to the current GL context with its texture atlas.
 *
 * The file is gzip-compressed: a header of four little-endian uint32 (vertex count, index count, atlas width and
 * height), 28-byte vertices, uint16 triangle indices, then the RGBA atlas. scripts/import_terrain_vehicle.py and
 * scripts/import_terrain_gates.py write it, and model.vert reads the vertex layout.
 */
final class BakedMesh
{
	private const STRIDE = 28;
	/** Largest decompressed file the sandbox reads, in bytes. */
	public const MAX_BYTES = 4194304;
	public readonly int $indexCount;
	private array $vao = [], $buffers = [], $texture = [];

	/**
	 * Read and upload one baked mesh.
	 * @param string $file Path of the .mesh.bin.gz file.
	 * @throws \RuntimeException When the file is unreadable or its sizes disagree with its header.
	 */
	public function __construct(string $file)
	{
		$data = gzdecode(file_get_contents($file), self::MAX_BYTES);
		if($data === false || strlen($data) < 16) throw new \RuntimeException('Cannot read baked mesh');
		$header = unpack('Vvertices/Vindices/Vwidth/Vheight', $data);
		$vertexBytes = $header['vertices'] * self::STRIDE;
		$this->indexCount = $header['indices'];
		$indexBytes = $this->indexCount * 2;
		$pixelOffset = 16 + $vertexBytes + $indexBytes;
		if($header['vertices'] > 65536 || !$this->indexCount || $this->indexCount % 3
			|| strlen($data) !== $pixelOffset + $header['width'] * $header['height'] * 4)
			throw new \RuntimeException('Invalid baked mesh dimensions');
		try
		{
			glGenVertexArrays(1, $this->vao);
			glBindVertexArray($this->vao[0]);
			glGenBuffers(2, $this->buffers);
			glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
			glBufferData(GL_ARRAY_BUFFER, $vertexBytes, substr($data, 16, $vertexBytes), GL_STATIC_DRAW);
			glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[1]);
			glBufferData(GL_ELEMENT_ARRAY_BUFFER, $indexBytes, substr($data, 16 + $vertexBytes, $indexBytes), GL_STATIC_DRAW);
			foreach([
				[3, GL_FLOAT, false, 0], [3, GL_SHORT, true, 12], [2, GL_UNSIGNED_SHORT, true, 18],
				[4, GL_UNSIGNED_BYTE, true, 22], [2, GL_UNSIGNED_BYTE, true, 26]
			] as $location => [$size, $type, $normalized, $offset])
			{
				glEnableVertexAttribArray($location);
				glVertexAttribPointer($location, $size, $type, $normalized, self::STRIDE, $offset);
			}
			glBindVertexArray(0);

			glGenTextures(1, $this->texture);
			glActiveTexture(GL_TEXTURE0);
			glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
			glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $header['width'], $header['height'], 0,
				GL_RGBA, GL_UNSIGNED_BYTE, substr($data, $pixelOffset));
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR_MIPMAP_LINEAR);
			glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
			glGenerateMipmap(GL_TEXTURE_2D);
		}
		catch(\Throwable $error)
		{
			$this->close();
			throw $error;
		}
	}

	/**
	 * Bind the atlas to texture unit 0 and the vertex array, for one or more draw() calls.
	 * @return void Leaves the mesh's vertex array bound.
	 */
	public function bind(): void
	{
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->texture[0]);
		glBindVertexArray($this->vao[0]);
	}

	/** Draw every triangle of the bound mesh with the current program and uniforms. */
	public function draw(): void { glDrawElements(GL_TRIANGLES, $this->indexCount, GL_UNSIGNED_SHORT, 0); }

	/** Release the context-local handles. */
	public function close(): void
	{
		if($this->vao) glDeleteVertexArrays(1, $this->vao);
		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);
		if($this->texture) glDeleteTextures(1, $this->texture);
		$this->vao = $this->buffers = $this->texture = [];
	}
}

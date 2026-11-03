<?php
declare(strict_types=1);
namespace PhpBilliards\Render;

/**
 * Load the checked build-time mesh bundle and own all resulting GPU resources.
 *
 * The loader validates manifest sizes and hashes, expands the compressed binary once, slices each declared
 * mesh into GPU buffers, and coordinates context restoration and deterministic cleanup for the complete set.
 */
final class MeshBundle
{
	/** @var array<string,GpuMesh> */
	private array $meshes = [];

	/**
	 * Load, authenticate, and upload the generated mesh bundle.
	 *
	 * Compressed and expanded hashes, declared byte lengths, stride, and per-mesh slices are checked before any named
	 * GPU mesh is exposed to the scene, keeping generated data failures deterministic and local.
	 *
	 * @param string $manifestPath Path to the generated mesh manifest.
	 * @param string $binaryPath Path to the compressed mesh payload.
	 * @param list<string> $keepIndexed Meshes drawn instanced, which keep indices for vertex reuse.
	 */
	public function __construct(string $manifestPath, string $binaryPath, array $keepIndexed = [])
	{
		$manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
		$compressed = file_get_contents($binaryPath);
		$binary = gzdecode($compressed);

		if(($manifest['schemaVersion'] ?? null) !== 1 || ($manifest['vertexStride'] ?? null) !== 32
			|| strlen($compressed) !== ($manifest['compressedByteLength'] ?? -1)
			|| !hash_equals((string)($manifest['compressedSha256'] ?? ''), hash('sha256', $compressed))
			|| strlen($binary) !== ($manifest['byteLength'] ?? -1)
			|| !hash_equals((string)($manifest['sha256'] ?? ''), hash('sha256', $binary))
		){
			throw new \RuntimeException('Invalid billiards mesh bundle');
		}

		foreach($manifest['meshes'] as $name => $mesh)
		{
			$vertices = substr($binary, $mesh['vertexOffset'], $mesh['vertexLength']);
			$indices = substr($binary, $mesh['indexOffset'], $mesh['indexLength']);
			$this->meshes[$name] = new GpuMesh(
				$vertices, $indices, (int)$mesh['indexCount'], (bool)$mesh['doubleSided'], !in_array($name, $keepIndexed, true)
			);
		}
	}

	/**
	 * @param string $name Manifest name of the requested mesh.
	 */
	public function get(string $name): GpuMesh
	{
		return $this->meshes[$name] ?? throw new \OutOfBoundsException("Unknown mesh $name");
	}

	public function restore(): void
	{
		foreach($this->meshes as $mesh) $mesh->restore();
	}

	public function close(): void
	{
		foreach($this->meshes as $mesh) $mesh->close();
		$this->meshes = [];
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\CameraTextureBinding;
use DoomPhonics\Game\DomTextureBinding;

/** Map-format vertices redrawn with live camera texture targets. */
final class CameraTextureMesh
{
	/** @var list<float> */
	public array $vertices = [];
	/**
	 * @var list<array{first:int,count:int,binding:CameraTextureBinding|DomTextureBinding,side:int}> side 0=front,
	 * 1=back, 2=either.
	 */
	public array $spans = [];

	public function vertexCount(): int { return intdiv(count($this->vertices), MapMesh::FLOATS); }

	public function bytes(): string { return $this->vertices ? pack('f*', ...$this->vertices) : ''; }

	/** Copy a span immediately after MapMeshBuilder appended it. */
	public function surface(
		array $mapVertices
		, int $first
		, int $count
		, CameraTextureBinding|DomTextureBinding $binding
		, int $side
	): void {
		$targetFirst = $this->vertexCount();
		array_push($this->vertices, ...array_slice($mapVertices, $first * MapMesh::FLOATS, $count * MapMesh::FLOATS));
		$this->spans[] = ['first' => $targetFirst,'count' => $count,'binding' => $binding,'side' => $side];
	}
}

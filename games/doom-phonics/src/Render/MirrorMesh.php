<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\MirrorView;

/** One-sided Line_Mirror geometry rendered from reflected scene targets. */
final class MirrorMesh
{
	public const FLOATS = 5;
	/** @var list<float> */
	public array $vertices = [];
	/** @var list<array{first:int,count:int,mirror:MirrorView}> */
	public array $spans = [];

	public function vertexCount(): int { return intdiv(count($this->vertices), self::FLOATS); }

	public function bytes(): string { return $this->vertices ? pack('f*', ...$this->vertices) : ''; }

	/** @param array{x:int,y:int} $a @param array{x:int,y:int} $b */
	public function line(array $a, array $b, int $sector, MirrorView $mirror): void
	{
		$first = $this->vertexCount();

		foreach([
			[$a['x'],$a['y'],MapMesh::FLOOR]
			, [$b['x'],$b['y'],MapMesh::FLOOR]
			, [$b['x'],$b['y'],MapMesh::CEILING]
			, [$a['x'],$a['y'],MapMesh::FLOOR]
			, [$b['x'],$b['y'],MapMesh::CEILING]
			, [$a['x'],$a['y'],MapMesh::CEILING]
		] as [$x,$y,$plane])
		array_push($this->vertices, (float)$x, (float)$y, (float)$sector, (float)$plane, (float)$sector);
		$this->spans[] = ['first' => $first,'count' => 6,'mirror' => $mirror];
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** Depth-only caps above terminal walls whose sectors open onto the sky. */
final class SkyOcclusionMesh
{
	public const FLOATS = 4;
	/** @var list<float> Interleaved world X, world Y, ceiling-sector index, and upper-extent selector. */
	public array $vertices = [];
	/** @var list<array{first:int,count:int,line:int,sector:int}> */
	public array $boundarySpans = [];

	public function vertexCount(): int { return intdiv(count($this->vertices), self::FLOATS); }

	/** @param array{x:int,y:int} $a @param array{x:int,y:int} $b */
	public function boundary(array $a, array $b, int $sector, int $line): void
	{
		$first = $this->vertexCount();

		foreach([
			[$a,0],[$b,0],[$b,1]
			, [$a,0],[$b,1],[$a,1]
		] as [$point,$upper])
		array_push($this->vertices, $point['x'], $point['y'], (float)$sector, (float)$upper);

		$this->boundarySpans[] = compact('first', 'line', 'sector') + ['count' => 6];
	}

	public function bytes(): string
	{
		$bytes = '';

		foreach(array_chunk($this->vertices, 4096) as $chunk) $bytes .= pack('f*', ...$chunk);

		return $bytes;
	}
}

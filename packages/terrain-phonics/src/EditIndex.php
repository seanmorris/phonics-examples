<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Rectangle quadtree of committed edit footprints, including the interpolation border. */
final class EditIndex
{
	private array $root = [];

	public function __construct(private float $extent, array $regions)
	{
		foreach($regions as $box)
		{
			$node = &$this->root;
			$x = $z = -$extent / 2;
			$size = $extent;

			for($depth = 0; $depth < 12; $depth++)
			{
				$size /= 2;
				$mx = $x + $size;
				$mz = $z + $size;

				if(($box[0] < $mx && $box[2] >= $mx) || ($box[1] < $mz && $box[3] >= $mz)) break;

				$east = $box[0] >= $mx;
				$north = $box[1] >= $mz;
				$node = &$node[(int)$east + 2 * (int)$north];

				if($east) $x = $mx;

				if($north) $z = $mz;
			}

			$node['boxes'][] = $box;
			unset($node);
		}
	}

	public function isEmpty(): bool { return !$this->root; }

	/** Return only overlaps in the requested tile. */
	public function intersect(array $tile): array
	{
		$hits = [];

		if($this->root) $this->visit($this->root, -$this->extent / 2, -$this->extent / 2, $this->extent, $tile, $hits);

		return $hits;
	}

	private function visit(array $node, float $x, float $z, float $size, array $tile, array &$hits): void
	{
		if($x > $tile[2] || $z > $tile[3] || $x + $size < $tile[0] || $z + $size < $tile[1]) return;

		foreach($node['boxes'] ?? [] as $box)
		{
			$overlap = [max($box[0], $tile[0]),max($box[1], $tile[1]),min($box[2], $tile[2]),min($box[3], $tile[3])];

			if($overlap[0] <= $overlap[2] && $overlap[1] <= $overlap[3]) $hits[] = $overlap;
		}

		$size /= 2;

		for($i = 0; $i < 4; $i++)
		if(isset($node[$i]))
		$this->visit($node[$i], $x + ($i % 2) * $size, $z + intdiv($i, 2) * $size, $size, $tile, $hits);
	}
}

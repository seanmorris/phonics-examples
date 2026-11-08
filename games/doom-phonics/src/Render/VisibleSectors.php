<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\Level;

/**
 * The sectors a view can reach, found the way Doom's renderer finds them (r_bsp.c).
 *
 * R_RenderBSPNode walks the BSP front to back. Each subsector it reaches marks its sector, which is where Doom adds
 * the sector's sprites. Its one-sided segs, and two-sided segs whose opening is closed, are clipped into a list of
 * solid screen-column ranges (the solidsegs). A node whose bounding box lies outside the view, or whose columns are
 * all solid already, is not entered (R_CheckBBox), and the walk ends once the whole view is solid. Two-sided segs with
 * an opening never occlude, so anything seen through a window or over a ledge is reached.
 *
 * The result is conservative: a sector is included whenever any of its subsectors is not wholly hidden, so sprites
 * behind a wall of the same sector still draw. Occlusion ignores heights the way Doom's does, so this suits classic
 * geometry; callers decide when to use it. Pitch is ignored, as it is in Doom.
 */
final class VisibleSectors
{
	/** Virtual screen columns the solid ranges are measured in; a seg narrower than one column does not occlude. */
	private const COLUMNS = 1024;
	private float $viewX = 0, $viewY = 0, $viewAngle = 0, $halfFov = 0, $tanHalfFov = 1;
	/** @var list<array{int,int}> Sorted, merged inclusive column ranges hidden by solid segs. */
	private array $solid = [];
	/** @var array<int,true> */
	private array $sectors = [];
	private bool $full = false;
	/** @var array<int,array{int,int,int,int}|null> Each subsector's bounds as top, bottom, left, right; null without segs. */
	private array $subsectorBounds = [];

	public function __construct(private Level $level)
	{
		$map = $level->map;

		foreach($map->subsectors as $index => $subsector)
		{
			$bounds = null;

			for($i = 0; $i < $subsector['count']; $i++)
			{
				$seg = $map->segs[$subsector['first'] + $i] ?? null;

				if($seg === null) continue;

				foreach([$map->vertices[$seg['start']] ?? null, $map->vertices[$seg['end']] ?? null] as $vertex)
				{
					if($vertex === null) continue;

					$bounds ??= [(int)$vertex['y'], (int)$vertex['y'], (int)$vertex['x'], (int)$vertex['x']];
					$bounds[0] = max($bounds[0], (int)$vertex['y']);
					$bounds[1] = min($bounds[1], (int)$vertex['y']);
					$bounds[2] = min($bounds[2], (int)$vertex['x']);
					$bounds[3] = max($bounds[3], (int)$vertex['x']);
				}
			}

			$this->subsectorBounds[$index] = $bounds;
		}
	}

	/** Whether the map has the BSP this walk needs. */
	public function available(): bool
	{
		$map = $this->level->map;

		return $map->nodes && $map->subsectors && $map->segs;
	}

	/**
	 * @param float $fov Horizontal field of view in degrees.
	 * @return array<int,true> Sector indexes keyed by index.
	 */
	public function from(float $x, float $y, float $angle, float $fov): array
	{
		$this->viewX = $x;
		$this->viewY = $y;
		$this->viewAngle = $angle;
		$this->halfFov = deg2rad(min(179.0, max(1.0, $fov)) / 2);
		$this->tanHalfFov = tan($this->halfFov);
		$this->solid = [];
		$this->sectors = [];
		$this->full = false;
		$this->node(count($this->level->map->nodes) - 1);

		return $this->sectors;
	}

	/** R_RenderBSPNode. */
	private function node(int $child): void
	{
		if($this->full) return;

		if($child & 0x8000)
		{
			$this->subsector($child & 0x7fff);

			return;
		}

		$node = $this->level->map->nodes[$child];
		$front = ($this->viewX - $node['x']) * $node['dy'] - ($this->viewY - $node['y']) * $node['dx'] > 0;
		$this->node($front ? $node['right'] : $node['left']);

		if($this->full) return;

		if($this->boxVisible($front ? $node['leftBounds'] : $node['rightBounds']))
		$this->node($front ? $node['left'] : $node['right']);
	}

	/** R_Subsector: mark the sector, then clip the subsector's solid segs. */
	private function subsector(int $index): void
	{
		$map = $this->level->map;
		$subsector = $map->subsectors[$index] ?? null;

		if($subsector === null) return;

		// Doom enters a node's front child without testing its bounds and marks whatever leaf it reaches. Testing the
		// leaf's own bounds here only drops subsectors nothing in the view could show.
		$bounds = $this->subsectorBounds[$index] ?? null;

		if($bounds !== null && !$this->boxVisible($bounds)) return;

		$marked = false;

		for($i = 0; $i < $subsector['count']; $i++)
		{
			$seg = $map->segs[$subsector['first'] + $i] ?? null;

			if($seg === null || $seg['linedef'] === 0xffff) continue;

			$line = $map->linedefs[$seg['linedef']];
			$frontSide = $seg['direction'] ? $line['left'] : $line['right'];
			$backSide = $seg['direction'] ? $line['right'] : $line['left'];

			if($frontSide < 0) continue;

			$frontSector = (int)$map->sidedefs[$frontSide]['sector'];

			if(!$marked)
			{
				$this->sectors[$frontSector] = true;
				$marked = true;
			}

			$a = $map->vertices[$seg['start']];
			$b = $map->vertices[$seg['end']];
			$angle1 = $this->angleTo((float)$a['x'], (float)$a['y']);
			$angle2 = $this->angleTo((float)$b['x'], (float)$b['y']);
			$span = self::unsigned($angle1 - $angle2);

			// R_AddLine: a seg whose vertices sweep clockwise from the view faces away.
			if($span <= 0 || $span >= M_PI) continue;

			// Only a wall without an opening occludes.
			if($backSide >= 0)
			{
				$frontState = $this->level->sectors[$frontSector];
				$backState = $this->level->sectors[(int)$map->sidedefs[$backSide]['sector']];

				if($backState->ceiling > $frontState->floor && $backState->floor < $frontState->ceiling
					&& $backState->ceiling > $backState->floor
				) continue;
			}

			$columns = $this->columns($angle1, $angle2, $span);

			if($columns !== null) $this->occlude($columns[0], $columns[1]);
		}
	}

	/** View-relative angle of a point as Doom holds it, in [0, 2π): counter-clockwise, 0 straight ahead. */
	private function angleTo(float $x, float $y): float
	{
		return self::unsigned(atan2($y - $this->viewY, $x - $this->viewX) - $this->viewAngle);
	}

	private static function unsigned(float $angle): float
	{
		$angle = fmod($angle, 2 * M_PI);

		return $angle < 0 ? $angle + 2 * M_PI : $angle;
	}

	/**
	 * R_AddLine's and R_CheckBBox's clip to the view: the inclusive column range of an angular span from `$angle1`
	 * counter-clockwise round to `$angle2`, or null when it lies wholly outside the view or is narrower than a column.
	 *
	 * @return array{int,int}|null
	 */
	private function columns(float $angle1, float $angle2, float $span): ?array
	{
		$clip = $this->halfFov;
		$outside = self::unsigned($angle1 + $clip);

		if($outside > 2 * $clip)
		{
			if($outside - 2 * $clip >= $span) return null;

			$angle1 = $clip;
		}

		$outside = self::unsigned($clip - $angle2);

		if($outside > 2 * $clip)
		{
			if($outside - 2 * $clip >= $span) return null;

			$angle2 = -$clip;
		}

		$x1 = $this->column($angle1 > M_PI ? $angle1 - 2 * M_PI : $angle1);
		$x2 = $this->column($angle2 > M_PI ? $angle2 - 2 * M_PI : $angle2);

		return $x1 < $x2 ? [$x1, $x2 - 1] : null;
	}

	/** Screen column of a view-relative angle, 0 at the left edge. */
	private function column(float $angle): int
	{
		$t = tan($angle) / $this->tanHalfFov;

		return (int)floor((1 - max(-1.0, min(1.0, $t))) * self::COLUMNS / 2);
	}

	/** R_ClipSolidWallSegment's bookkeeping: merge an inclusive column range into the solid list. */
	private function occlude(int $first, int $last): void
	{
		$merged = [];
		$placed = false;

		foreach($this->solid as [$a, $b])
		{
			if($b < $first - 1)
			{
				$merged[] = [$a, $b];

				continue;
			}

			if($a > $last + 1)
			{
				if(!$placed)
				{
					$merged[] = [$first, $last];
					$placed = true;
				}

				$merged[] = [$a, $b];

				continue;
			}

			$first = min($first, $a);
			$last = max($last, $b);
		}

		if(!$placed) $merged[] = [$first, $last];

		$this->solid = $merged;
		$this->full = count($merged) === 1 && $merged[0][0] <= 0 && $merged[0][1] >= self::COLUMNS - 1;
	}

	/**
	 * Which two box corners bound its view, by the viewer's position relative to the box: Doom's checkcoord table,
	 * indexed by the viewer's row and column around the box, giving the corners' x and y as box edge indexes.
	 */
	private const CHECK = [
		[3,0,2,1], [3,0,2,0], [3,1,2,0], null,
		[2,0,2,1], null, [3,1,3,0], null,
		[2,0,3,1], [2,1,3,1], [2,1,3,0]
	];

	/**
	 * R_CheckBBox: whether any of a node's bounding box can still be seen.
	 *
	 * @param array{int,int,int,int} $bounds Top, bottom, left, right as the WAD stores them.
	 */
	private function boxVisible(array $bounds): bool
	{
		[$top, $bottom, $left, $right] = $bounds;
		$column = $this->viewX <= $left ? 0 : ($this->viewX < $right ? 1 : 2);
		$row = $this->viewY >= $top ? 0 : ($this->viewY > $bottom ? 1 : 2);
		$check = self::CHECK[$row * 4 + $column];

		// Inside the box every direction is possible; let the children decide.
		if($check === null) return true;

		$angle1 = $this->angleTo((float)$bounds[$check[0]], (float)$bounds[$check[1]]);
		$angle2 = $this->angleTo((float)$bounds[$check[2]], (float)$bounds[$check[3]]);
		$span = self::unsigned($angle1 - $angle2);

		// Sitting on a line of the box.
		if($span >= M_PI) return true;

		$columns = $this->columns($angle1, $angle2, $span);

		if($columns === null) return false;

		foreach($this->solid as [$a, $b])
		if($a <= $columns[0] && $b >= $columns[1]) return false;

		return true;
	}
}

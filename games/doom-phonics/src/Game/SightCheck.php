<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/**
 * Doom's P_CheckSight, from p_sight.c: walk the BSP along the sight line (P_CrossBSPNode) and test the lines in each
 * subsector it passes (P_CrossSubsector). A one-sided line, or a two-sided line whose opening closes, blocks sight.
 * Every two-sided line whose floor or ceiling heights differ narrows the window of slopes from the looker's eyes to the
 * target, from its feet to its head, so a ledge can hide part of a target while the rest stays visible. Heights are
 * the sectors' live heights, so moving doors and lifts count.
 *
 * Only classic geometry is described here: flat planes, no 3D floors, height transfers, or camera portals. Callers keep
 * their own trace for other maps.
 */
final class SightCheck
{
	/** @var array<int,int> Lines already tested in the current check, by stamp: P_CrossSubsector's validcount. */
	private array $visited = [];
	private int $stamp = 0;
	private float $startX = 0, $startY = 0, $deltaX = 0, $deltaY = 0, $endX = 0, $endY = 0;
	private float $eyeZ = 0, $topSlope = 0, $bottomSlope = 0;

	public function __construct(private Level $level) {}

	/** Whether Doom's sight rules can describe this level: flat planes, no 3D floors, height transfers, or portals. */
	public function classic(): bool
	{
		if($this->level->cameraPortalSurfaces()) return false;

		foreach($this->level->sectors as $sector)
		if($sector->volumes || $sector->heightTransfer !== null || $sector->floorSlope !== [0.0,0.0,0.0]
			|| $sector->ceilingSlope !== [0.0,0.0,0.0]
		) return false;

		return true;
	}

	/**
	 * Whether a looker standing at (x1, y1) with eyes at `$eyeZ` can see any part of a target at (x2, y2) between
	 * `$bottomZ` and `$topZ`. REJECT is the caller's business, as in P_CheckSight before it walks the BSP.
	 */
	public function sees(float $x1, float $y1, float $eyeZ, float $x2, float $y2, float $bottomZ, float $topZ): bool
	{
		$this->stamp++;
		$this->startX = $x1;
		$this->startY = $y1;
		$this->endX = $x2;
		$this->endY = $y2;
		$this->deltaX = $x2 - $x1;
		$this->deltaY = $y2 - $y1;
		$this->eyeZ = $eyeZ;
		$this->topSlope = $topZ - $eyeZ;
		$this->bottomSlope = $bottomZ - $eyeZ;
		$nodes = count($this->level->map->nodes);

		return $nodes ? $this->crossNode($nodes - 1) : $this->crossSubsector(0);
	}

	/** P_DivlineSide: 0 in front of the directed line, 1 behind, 2 on it. */
	private static function side(float $x, float $y, float $lineX, float $lineY, float $lineDX, float $lineDY): int
	{
		$cross = ($x - $lineX) * $lineDY - ($y - $lineY) * $lineDX;

		return $cross > 0 ? 0 : ($cross < 0 ? 1 : 2);
	}

	/** P_CrossBSPNode: cross the start point's side, then the other side if the sight line reaches it. */
	private function crossNode(int $child): bool
	{
		if($child & 0x8000) return $this->crossSubsector($child & 0x7fff);

		$node = $this->level->map->nodes[$child];
		$side = self::side($this->startX, $this->startY, (float)$node['x'], (float)$node['y'], (float)$node['dx'], (float)$node['dy']);

		// An "on" start should cross both sides.
		if($side === 2) $side = 0;

		if(!$this->crossNode($side === 0 ? $node['right'] : $node['left'])) return false;

		if($side === self::side($this->endX, $this->endY, (float)$node['x'], (float)$node['y'], (float)$node['dx'], (float)$node['dy']))
		return true;

		return $this->crossNode($side === 0 ? $node['left'] : $node['right']);
	}

	/** P_CrossSubsector: test each line of the subsector once; true when sight passes it. */
	private function crossSubsector(int $index): bool
	{
		$map = $this->level->map;
		$subsector = $map->subsectors[$index] ?? null;

		if($subsector === null) return true;

		for($i = 0; $i < $subsector['count']; $i++)
		{
			$seg = $map->segs[$subsector['first'] + $i] ?? null;

			if($seg === null || $seg['linedef'] === 0xffff) continue;

			$lineId = $seg['linedef'];

			if(($this->visited[$lineId] ?? 0) === $this->stamp) continue;

			$this->visited[$lineId] = $this->stamp;
			$line = $map->linedefs[$lineId];
			$a = $map->vertices[$line['from']];
			$b = $map->vertices[$line['to']];
			$ax = (float)$a['x'];
			$ay = (float)$a['y'];
			$lineDX = (float)$b['x'] - $ax;
			$lineDY = (float)$b['y'] - $ay;

			// The sight line must separate the line's vertices, and the line its endpoints.
			if(self::side($ax, $ay, $this->startX, $this->startY, $this->deltaX, $this->deltaY)
				=== self::side((float)$b['x'], (float)$b['y'], $this->startX, $this->startY, $this->deltaX, $this->deltaY)
			) continue;

			if(self::side($this->startX, $this->startY, $ax, $ay, $lineDX, $lineDY)
				=== self::side($this->endX, $this->endY, $ax, $ay, $lineDX, $lineDY)
			) continue;

			// A line without the two-sided flag, or without a back, always blocks sight.
			if(!($line['flags'] & 4) || $line['right'] < 0 || $line['left'] < 0) return false;

			$frontSide = $seg['direction'] ? $line['left'] : $line['right'];
			$backSide = $seg['direction'] ? $line['right'] : $line['left'];
			$front = $this->level->sectors[(int)$map->sidedefs[$frontSide]['sector']];
			$back = $this->level->sectors[(int)$map->sidedefs[$backSide]['sector']];

			if($front->floor === $back->floor && $front->ceiling === $back->ceiling) continue;

			$openTop = min($front->ceiling, $back->ceiling);
			$openBottom = max($front->floor, $back->floor);

			// Closed doors.
			if($openBottom >= $openTop) return false;

			// P_InterceptVector2: fraction along the sight line where it crosses this line.
			$denominator = $lineDY * $this->deltaX - $lineDX * $this->deltaY;
			$fraction = $denominator == 0 ? 0.0
				: (($ax - $this->startX) * $lineDY + ($this->startY - $ay) * $lineDX) / $denominator;

			if($front->floor !== $back->floor)
			{
				$slope = $fraction > 0 ? ($openBottom - $this->eyeZ) / $fraction : ($openBottom - $this->eyeZ >= 0 ? INF : -INF);

				if($slope > $this->bottomSlope) $this->bottomSlope = $slope;
			}

			if($front->ceiling !== $back->ceiling)
			{
				$slope = $fraction > 0 ? ($openTop - $this->eyeZ) / $fraction : ($openTop - $this->eyeZ >= 0 ? INF : -INF);

				if($slope < $this->topSlope) $this->topSlope = $slope;
			}

			if($this->topSlope <= $this->bottomSlope) return false;
		}

		return true;
	}
}

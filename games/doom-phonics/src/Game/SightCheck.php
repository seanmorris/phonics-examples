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
 *
 * The map is flattened once: nodes to numeric lists and each subsector to its segs' endpoints and sectors, so a check
 * reads plain arrays and does no lookups through linedefs, vertices, or sidedefs. Idle monsters run this seven or so
 * times a tic on E1M3, and the browser's PHP is slow enough that each lookup counted.
 */
final class SightCheck
{
	/** @var list<array{0:float,1:float,2:float,3:float,4:int,5:int}> Node x, y, dx, dy, right child, left child. */
	private array $nodes = [];
	/**
	 * @var list<list<array{0:int,1:float,2:float,3:float,4:float,5:int,6:int}>> Per subsector: each seg's line, start
	 * x and y, end x and y, front sector, and back sector or -1 for a one-sided line.
	 */
	private array $segs = [];
	/** @var array<int,SectorState> */
	private array $sectors;
	/** @var array<int,int> Lines already tested in the current check, by stamp: P_CrossSubsector's validcount. */
	private array $visited = [];
	private int $stamp = 0;
	private float $startX = 0, $startY = 0, $deltaX = 0, $deltaY = 0, $endX = 0, $endY = 0;
	private float $eyeZ = 0, $topSlope = 0, $bottomSlope = 0;

	public function __construct(private Level $level)
	{
		$map = $level->map;
		$this->sectors = $level->sectors;

		foreach($map->nodes as $node)
		$this->nodes[] = [(float)$node['x'], (float)$node['y'], (float)$node['dx'], (float)$node['dy'], $node['right'], $node['left']];

		foreach($map->subsectors as $index => $subsector)
		{
			$segs = [];

			for($i = 0; $i < $subsector['count']; $i++)
			{
				$seg = $map->segs[$subsector['first'] + $i] ?? null;

				if($seg === null || $seg['linedef'] === 0xffff) continue;

				$line = $map->linedefs[$seg['linedef']];
				$frontSide = $seg['direction'] ? $line['left'] : $line['right'];
				$backSide = $seg['direction'] ? $line['right'] : $line['left'];

				if($frontSide < 0) continue;

				$a = $map->vertices[$line['from']];
				$b = $map->vertices[$line['to']];
				$segs[] = [
					$seg['linedef']
					, (float)$a['x']
					, (float)$a['y']
					, (float)$b['x']
					, (float)$b['y']
					, (int)$map->sidedefs[$frontSide]['sector']
					, ($line['flags'] & 4) && $backSide >= 0 ? (int)$map->sidedefs[$backSide]['sector'] : -1
				];
			}

			$this->segs[$index] = $segs;
		}
	}

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
		$count = count($this->nodes);

		return $count ? $this->crossNode($count - 1) : $this->crossSubsector(0);
	}

	/** P_CrossBSPNode: cross the start point's side, then the other side if the sight line reaches it. */
	private function crossNode(int $child): bool
	{
		if($child & 0x8000) return $this->crossSubsector($child & 0x7fff);

		[$x, $y, $dx, $dy, $right, $left] = $this->nodes[$child];
		// P_DivlineSide for the start: in front (0) or behind (1); a start on the line crosses both sides.
		$startBehind = ($this->startX - $x) * $dy - ($this->startY - $y) * $dx < 0;

		if(!$this->crossNode($startBehind ? $left : $right)) return false;

		$endCross = ($this->endX - $x) * $dy - ($this->endY - $y) * $dx;

		if(($endCross < 0) === $startBehind && $endCross != 0) return true;

		if($endCross == 0 && !$startBehind) return true;

		return $this->crossNode($startBehind ? $right : $left);
	}

	/** P_CrossSubsector: test each line of the subsector once; true when sight passes it. */
	private function crossSubsector(int $index): bool
	{
		$segs = $this->segs[$index] ?? [];

		if(!$segs) return true;

		$stamp = $this->stamp;
		$sx = $this->startX;
		$sy = $this->startY;
		$ex = $this->endX;
		$ey = $this->endY;
		$ddx = $this->deltaX;
		$ddy = $this->deltaY;

		foreach($segs as [$lineId, $ax, $ay, $bx, $by, $frontSector, $backSector])
		{
			if(($this->visited[$lineId] ?? 0) === $stamp) continue;

			$this->visited[$lineId] = $stamp;

			// The sight line must separate the line's vertices, and the line its endpoints.
			$crossA = ($ax - $sx) * $ddy - ($ay - $sy) * $ddx;
			$crossB = ($bx - $sx) * $ddy - ($by - $sy) * $ddx;

			if(($crossA > 0) === ($crossB > 0) && ($crossA < 0) === ($crossB < 0)) continue;

			$lineDX = $bx - $ax;
			$lineDY = $by - $ay;
			$crossS = ($sx - $ax) * $lineDY - ($sy - $ay) * $lineDX;
			$crossE = ($ex - $ax) * $lineDY - ($ey - $ay) * $lineDX;

			if(($crossS > 0) === ($crossE > 0) && ($crossS < 0) === ($crossE < 0)) continue;

			// A one-sided line always blocks sight.
			if($backSector < 0) return false;

			$front = $this->sectors[$frontSector];
			$back = $this->sectors[$backSector];
			$frontFloor = $front->floor;
			$backFloor = $back->floor;
			$frontCeiling = $front->ceiling;
			$backCeiling = $back->ceiling;

			if($frontFloor === $backFloor && $frontCeiling === $backCeiling) continue;

			$openTop = $frontCeiling < $backCeiling ? $frontCeiling : $backCeiling;
			$openBottom = $frontFloor > $backFloor ? $frontFloor : $backFloor;

			// Closed doors.
			if($openBottom >= $openTop) return false;

			// P_InterceptVector2: fraction along the sight line where it crosses this line.
			$denominator = $lineDY * $ddx - $lineDX * $ddy;
			$fraction = $denominator == 0 ? 0.0 : (($ax - $sx) * $lineDY + ($sy - $ay) * $lineDX) / $denominator;

			if($frontFloor !== $backFloor)
			{
				$slope = $fraction > 0 ? ($openBottom - $this->eyeZ) / $fraction : ($openBottom - $this->eyeZ >= 0 ? INF : -INF);

				if($slope > $this->bottomSlope) $this->bottomSlope = $slope;
			}

			if($frontCeiling !== $backCeiling)
			{
				$slope = $fraction > 0 ? ($openTop - $this->eyeZ) / $fraction : ($openTop - $this->eyeZ >= 0 ? INF : -INF);

				if($slope < $this->topSlope) $this->topSlope = $slope;
			}

			if($this->topSlope <= $this->bottomSlope) return false;
		}

		return true;
	}
}

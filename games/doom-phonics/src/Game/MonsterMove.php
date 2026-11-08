<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\MonsterBrain;
use DoomPhonics\Component\Transform;

/**
 * Doom's monster movement, from p_enemy.c and p_map.c.
 *
 * A monster walks in one of eight directions (`movedir`) for a counted number of steps (`movecount`), then picks a
 * direction again (P_NewChaseDir). Each step is a single position test at the destination (P_TryMove over
 * P_CheckPosition): the lines whose box the destination straddles must have an opening the monster fits, a step no
 * higher than 24, and, unless the monster floats or may drop off, no ledge deeper than 24 below; no solid body may
 * occupy the destination. A blocked step fails outright, and the monster turns. There is no sliding, so a monster
 * never grinds along a wall, and a blocked step costs one test rather than a sweep and a slide. Doors a monster bumps
 * open through its special lines, as in P_Move.
 *
 * Players and projectiles keep their swept movement in Collision; this is only how monsters chase.
 *
 * The map is flattened once, as SightCheck does, so a test reads plain arrays: nodes to find the destination's sector
 * and each line's endpoints, bounds, flags, sectors, and special.
 */
final class MonsterMove
{
	public const NO_DIRECTION = 8;
	/** Doom's step size for a floating monster adjusting its height around an obstacle. */
	public const FLOAT_SPEED = 4;
	private const DIAGONAL = 47000 / 65536;
	/** Direction 0 is east and they run counter-clockwise, as Doom's dirtype_t does. */
	private const STEP_X = [1.0, self::DIAGONAL, 0.0, -self::DIAGONAL, -1.0, -self::DIAGONAL, 0.0, self::DIAGONAL];
	private const STEP_Y = [0.0, self::DIAGONAL, 1.0, self::DIAGONAL, 0.0, -self::DIAGONAL, -1.0, -self::DIAGONAL];
	private const OPPOSITE = [4, 5, 6, 7, 0, 1, 2, 3, self::NO_DIRECTION];
	/** Diagonal toward the target, indexed by (target south) * 2 + (target east). */
	private const DIAGONALS = [3, 1, 5, 7];
	private const EAST = 0, NORTH = 2, WEST = 4, SOUTH = 6, SOUTHEAST = 7;
	private const FLAT = [0.0, 0.0, 0.0];

	/** @var list<array{0:float,1:float,2:float,3:float,4:int,5:int}> Node x, y, dx, dy, right child, left child. */
	private array $nodes = [];
	/** @var array<int,int> Sector of each subsector. */
	private array $subsectorSectors = [];
	/**
	 * @var array<int,array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float,8:int,9:int,10:int,11:int}>
	 * Per line: start x and y, end x and y, min x, min y, max x, max y, flags, front sector, back sector or -1,
	 * special.
	 */
	private array $lines = [];
	/** @var array<int,SectorState> */
	private array $sectors;
	/** Results of the last tryMove(). */
	public float $floor = 0;
	/** Whether the last blocked tryMove() found an opening the monster could fit at another height. */
	public bool $floatOk = false;
	/** @var list<int> Special lines the last tryMove() crossed, whether or not the move went through. */
	public array $specials = [];
	/** Sectors the last tryMove() started in and would end in. */
	private int $from = 0, $sector = 0;

	/**
	 * @param ?\Closure(int,int,Transform):bool $use Opens the special line a blocked monster bumped, as
	 * P_UseSpecialLine does for a monster; given the line, the monster's sector, and its pose.
	 */
	public function __construct(
		private Level $level
		, private ?ActorObstacles $actors = null
		, private ?\Closure $use = null
		, private float $maxStep = 24
	){
		$map = $level->map;
		$this->sectors = $level->sectors;

		foreach($map->nodes as $node)
		$this->nodes[] = [(float)$node['x'], (float)$node['y'], (float)$node['dx'], (float)$node['dy'], $node['right'], $node['left']];

		foreach($map->subsectors as $index => $subsector)
		{
			$this->subsectorSectors[$index] = 0;

			for($i = 0; $i < $subsector['count']; $i++)
			{
				$seg = $map->segs[$subsector['first'] + $i] ?? null;

				if($seg === null || $seg['linedef'] === 0xffff) continue;

				$line = $map->linedefs[$seg['linedef']];
				$side = $seg['direction'] ? $line['left'] : $line['right'];

				if($side < 0) continue;

				$this->subsectorSectors[$index] = (int)$map->sidedefs[$side]['sector'];

				break;
			}
		}

		foreach($map->linedefs as $id => $line)
		{
			$a = $map->vertices[$line['from']];
			$b = $map->vertices[$line['to']];
			$this->lines[$id] = [
				(float)$a['x'], (float)$a['y'], (float)$b['x'], (float)$b['y']
				, (float)min($a['x'], $b['x']), (float)min($a['y'], $b['y'])
				, (float)max($a['x'], $b['x']), (float)max($a['y'], $b['y'])
				, (int)$line['flags']
				, $line['right'] >= 0 ? (int)$map->sidedefs[$line['right']]['sector'] : -1
				, ($line['flags'] & 4) && $line['left'] >= 0 ? (int)$map->sidedefs[$line['left']]['sector'] : -1
				, (int)($line['action'] ?? 0)
			];
		}
	}

	/** Whether Doom's monster movement can describe this level: a BSP to find sectors with, and no camera portals. */
	public function available(): bool
	{
		return $this->nodes && $this->subsectorSectors && !$this->level->cameraPortalSurfaces();
	}

	/**
	 * A_Chase's movement: step in the current direction, or choose a new one when the count runs out or the step is
	 * blocked.
	 */
	public function chase(int $entity, Transform $pose, Body $body, MonsterBrain $brain, Transform $target, float $speed, bool $floats, bool $dropOff): void
	{
		if(--$brain->moveCount < 0 || !$this->move($entity, $pose, $body, $brain, $speed, $floats, $dropOff))
		$this->newChaseDirection($entity, $pose, $body, $brain, $target, $speed, $floats, $dropOff);
	}

	/** A_Chase's turn: an eighth of a turn toward the movement direction, the shorter way round. */
	public function face(MonsterBrain $brain, Transform $pose): void
	{
		if($brain->moveDir >= self::NO_DIRECTION) return;

		$eighth = M_PI / 4;
		$current = ((int)floor($pose->angle / $eighth) % 8 + 8) % 8;
		$difference = ($brain->moveDir - $current + 8) % 8;

		if($difference === 0)
		{
			$pose->angle = $current * $eighth;

			return;
		}

		$pose->angle = (($difference <= 4 ? $current + 1 : $current - 1) + 8) % 8 * $eighth;
	}

	/** P_Move: one step in the movement direction; a blocked step opens the doors it bumped. */
	public function move(int $entity, Transform $pose, Body $body, MonsterBrain $brain, float $speed, bool $floats, bool $dropOff): bool
	{
		$direction = $brain->moveDir;

		if($direction >= self::NO_DIRECTION) return false;

		$x = $pose->x + $speed * self::STEP_X[$direction];
		$y = $pose->y + $speed * self::STEP_Y[$direction];

		if(!$this->tryMove($entity, $pose, $body, $x, $y, $floats || $dropOff))
		{
			if($floats && $this->floatOk)
			{
				$pose->z += $pose->z < $this->floor ? self::FLOAT_SPEED : -self::FLOAT_SPEED;
				$this->actors?->moved($entity, $pose);

				return true;
			}

			if(!$this->specials || $this->use === null) return false;

			$brain->moveDir = self::NO_DIRECTION;
			$opened = false;
			$sector = $this->sectorIndexAt($pose->x, $pose->y);

			foreach($this->specials as $lineId)
			if(($this->use)($lineId, $sector, $pose)) $opened = true;

			return $opened;
		}

		// A flat step within one sector leaves nothing for physics to settle; any other move wakes it.
		$wake = $floats || $this->sector !== $this->from || $pose->z !== $this->floor;
		$pose->x = $x;
		$pose->y = $y;

		if(!$floats) $pose->z = $this->floor;

		$this->actors?->moved($entity, $pose, $this->sector, $wake);

		return true;
	}

	/**
	 * P_TryMove over P_CheckPosition: whether a body can stand at the destination. Afterwards `$floor` is the
	 * destination's floor, `$specials` the special lines the destination straddles, and `$floatOk` whether only the
	 * monster's height blocked it.
	 */
	public function tryMove(int $entity, Transform $pose, Body $body, float $x, float $y, bool $dropOff): bool
	{
		$this->floatOk = false;
		$this->specials = [];
		$radius = $body->radius;
		$z = $pose->z;
		$height = $body->height;
		$step = $this->maxStep;
		$fromX = $pose->x;
		$fromY = $pose->y;
		$from = $this->from = $this->actors ? $this->actors->sectorOf($entity, $pose) : $this->sectorIndexAt($fromX, $fromY);
		// The destination's sector follows from the line the step crosses, when the step fits inside the destination
		// box so every line it could cross is tested below; otherwise, or on an ambiguous crossing, the BSP decides.
		$destination = abs($x - $fromX) <= $radius && abs($y - $fromY) <= $radius ? $from : -1;
		$crossings = 0;
		$floor = $ceiling = $lowest = 0.0;
		$minX = $x - $radius;
		$minY = $y - $radius;
		$maxX = $x + $radius;
		$maxY = $y + $radius;
		$straddled = [];

		foreach($this->level->spatial->near($minX, $minY, $maxX, $maxY) as $lineId)
		{
			$line = $this->lines[$lineId];

			if($maxX <= $line[4] || $minX >= $line[6] || $maxY <= $line[5] || $minY >= $line[7]) continue;

			[$ax, $ay, $bx, $by] = $line;

			// P_BoxOnLineSide: the box straddles the line when the two corners furthest across it lie on opposite sides.
			$dx = $bx - $ax;
			$dy = $by - $ay;
			$sideA = ($dy > 0 ? $minX : $maxX);
			$sideB = ($dx > 0 ? $maxY : $minY);
			$crossA = ($sideB - $ay) * $dx - ($sideA - $ax) * $dy;
			$crossB = (($dx > 0 ? $minY : $maxY) - $ay) * $dx - (($dy > 0 ? $maxX : $minX) - $ax) * $dy;

			if(($crossA > 0 && $crossB > 0) || ($crossA < 0 && $crossB < 0)) continue;

			$front = $line[9];
			$back = $line[10];

			// One-sided lines, blocking lines, and monster-blocking lines stop a monster outright.
			if($back < 0 || ($line[8] & 3)) return false;

			if($front !== $back)
			{
				$straddled[] = $line;

				if($destination >= 0)
				{
					// The step crosses the line when each segment separates the other's ends; a touch at an end is
					// left to the BSP. A step ending on the line's right side is in its front sector.
					$fromRight = ($fromY - $ay) * $dx - ($fromX - $ax) * $dy;
					$toRight = ($y - $ay) * $dx - ($x - $ax) * $dy;
					$stepX = $x - $fromX;
					$stepY = $y - $fromY;
					$sideA = ($ay - $fromY) * $stepX - ($ax - $fromX) * $stepY;
					$sideB = ($by - $fromY) * $stepX - ($bx - $fromX) * $stepY;

					if($toRight == 0 || $fromRight == 0 || $sideA == 0 || $sideB == 0) $destination = -1;
					elseif(($fromRight < 0) !== ($toRight < 0) && ($sideA < 0) !== ($sideB < 0))
					{
						$crossings++;
						$destination = $toRight < 0 ? $front : $back;
					}
				}
			}

			if($line[11]) $this->specials[] = $lineId;
		}

		if($crossings > 1 || $destination < 0) $destination = $this->sectorIndexAt($x, $y);

		$sector = $this->sectors[$destination];

		// A plain sector's planes are its heights; slopes and 3D floors go through the full support rules.
		if(!$sector->volumes && $sector->floorSlope === self::FLAT && $sector->ceilingSlope === self::FLAT)
		{
			$floor = $sector->floor;
			$ceiling = $sector->ceiling;
		}
		else
		{
			$floor = $sector->supportZ($x, $y, $z, $step);
			$ceiling = $sector->headroomZ($x, $y, max($z, $floor));
		}

		$lowest = $floor;

		// P_LineOpening for each straddled line, narrowing the opening and finding the lowest floor beside the step.
		foreach($straddled as $line)
		{
			$frontState = $this->sectors[$line[9]];
			$backState = $this->sectors[$line[10]];

			if(!$frontState->volumes && !$backState->volumes && $frontState->floorSlope === self::FLAT
				&& $backState->floorSlope === self::FLAT && $frontState->ceilingSlope === self::FLAT
				&& $backState->ceilingSlope === self::FLAT
			){
				$frontFloor = $frontState->floor;
				$backFloor = $backState->floor;
				$openBottom = $frontFloor > $backFloor ? $frontFloor : $backFloor;
				$openTop = $frontState->ceiling < $backState->ceiling ? $frontState->ceiling : $backState->ceiling;
			}
			else
			{
				$frontFloor = $frontState->supportZ($x, $y, $z, $step);
				$backFloor = $backState->supportZ($x, $y, $z, $step);
				$openBottom = $frontFloor > $backFloor ? $frontFloor : $backFloor;
				$openTop = min(
					$frontState->headroomZ($x, $y, max($z, $openBottom))
					, $backState->headroomZ($x, $y, max($z, $openBottom))
				);
			}

			if($openTop < $ceiling) $ceiling = $openTop;

			if($openBottom > $floor) $floor = $openBottom;

			$lowFloor = $frontFloor < $backFloor ? $frontFloor : $backFloor;

			if($lowFloor < $lowest) $lowest = $lowFloor;
		}

		$this->floor = $floor;

		if($ceiling - $floor < $height) return false;

		$this->floatOk = true;

		if($ceiling - $z < $height) return false;

		if($floor - $z > $step) return false;

		if(!$dropOff && $floor - $lowest > $step) return false;

		if($this->actors && $this->actors->blockedAt($entity, $pose, $body, $x, $y)) return false;

		$this->sector = $destination;

		return true;
	}

	/** P_NewChaseDir: pick the direction toward the target that is open, or any open direction, or none. */
	public function newChaseDirection(int $entity, Transform $pose, Body $body, MonsterBrain $brain, Transform $target, float $speed, bool $floats, bool $dropOff): void
	{
		$old = $brain->moveDir;
		$turnaround = self::OPPOSITE[$old];
		$deltaX = $target->x - $pose->x;
		$deltaY = $target->y - $pose->y;
		$first = $deltaX > 10 ? self::EAST : ($deltaX < -10 ? self::WEST : self::NO_DIRECTION);
		$second = $deltaY < -10 ? self::SOUTH : ($deltaY > 10 ? self::NORTH : self::NO_DIRECTION);

		// Try the direct route.
		if($first !== self::NO_DIRECTION && $second !== self::NO_DIRECTION)
		{
			$brain->moveDir = self::DIAGONALS[(($deltaY < 0) ? 2 : 0) + ($deltaX > 0 ? 1 : 0)];

			if($brain->moveDir !== $turnaround && $this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
		}

		// Try the other directions. P_Random is drawn before the distance test, whatever its outcome.
		$random = mt_rand(0, 255);

		if($random > 200 || abs($deltaY) > abs($deltaX)) [$first, $second] = [$second, $first];

		if($first === $turnaround) $first = self::NO_DIRECTION;

		if($second === $turnaround) $second = self::NO_DIRECTION;

		if($first !== self::NO_DIRECTION)
		{
			$brain->moveDir = $first;

			if($this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
		}

		if($second !== self::NO_DIRECTION)
		{
			$brain->moveDir = $second;

			if($this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
		}

		// No direct path to the target: keep going the old way if it is open.
		if($old !== self::NO_DIRECTION)
		{
			$brain->moveDir = $old;

			if($this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
		}

		// Search the other directions, randomly clockwise or counter-clockwise.
		if(mt_rand(0, 255) & 1)
		{
			for($direction = self::EAST; $direction <= self::SOUTHEAST; $direction++)
			{
				if($direction === $turnaround) continue;

				$brain->moveDir = $direction;

				if($this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
			}
		}
		else
		{
			for($direction = self::SOUTHEAST; $direction >= self::EAST; $direction--)
			{
				if($direction === $turnaround) continue;

				$brain->moveDir = $direction;

				if($this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
			}
		}

		if($turnaround !== self::NO_DIRECTION)
		{
			$brain->moveDir = $turnaround;

			if($this->tryWalk($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return;
		}

		$brain->moveDir = self::NO_DIRECTION;
	}

	/** P_TryWalk: a step that succeeds starts a new count of steps before the next choice. */
	private function tryWalk(int $entity, Transform $pose, Body $body, MonsterBrain $brain, float $speed, bool $floats, bool $dropOff): bool
	{
		if(!$this->move($entity, $pose, $body, $brain, $speed, $floats, $dropOff)) return false;

		$brain->moveCount = mt_rand(0, 255) & 15;

		return true;
	}

	/** R_PointInSubsector over the flattened nodes. */
	public function sectorIndexAt(float $x, float $y): int
	{
		$count = count($this->nodes);

		if(!$count) return 0;

		$child = $count - 1;

		while(!($child & 0x8000))
		{
			[$nx, $ny, $dx, $dy, $right, $left] = $this->nodes[$child];
			$child = ($x - $nx) * $dy - ($y - $ny) * $dx > 0 ? $right : $left;
		}

		return $this->subsectorSectors[$child & 0x7fff] ?? 0;
	}
}

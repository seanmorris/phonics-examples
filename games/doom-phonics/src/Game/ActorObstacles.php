<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/**
 * Dynamic square actor bounds used by Doom-style Minkowski movement sweeps.
 *
 * Without a grid every sweep tests every body in the registry, so moving N actors costs N^2 tests per tic. With
 * `$grid`, bodies are binned by centre in 128-unit cells, the size of Doom's blockmap blocks, and a sweep tests only
 * the cells its path covers, widened by the largest binned radius. The grid persists between steps: the owner calls
 * beginStep() at the start of each simulation step, after which the first query re-bins the bodies whose cell changed
 * since the last step, and moved() after changing a body's position within the step, which Collision does for every
 * move it makes. A change to the set of bodies rebuilds the grid.
 */
final class ActorObstacles
{
	private const CELL = 128.0;
	/**
	 * Cells are clamped to +/-16384 per axis, about two million map units, so packed keys stay below 2^31. The browser
	 * runs 32-bit PHP (wasm32), where a larger key becomes a float and the int return type throws.
	 */
	private const CELL_LIMIT = 16384;
	private Query $solids;
	/** @var array<int,array{x:float,y:float,sector:int}> */
	private array $sectors = [];
	/** @var array<int,array<int,true>> Body entities by cell key. */
	private array $cells = [];
	/** @var array<int,int> Cell key each binned entity occupies. */
	private array $placed = [];
	/** @var array<int,Transform> Each binned entity's pose, re-read at each step to catch moves made without moved(). */
	private array $poses = [];
	/** Whether a step began since the last query, so bodies moved without moved() must be re-binned. */
	private bool $stepped = false;
	/** Largest radius among binned bodies; queries widen by it because bodies are binned by centre. */
	private float $reach = 0;
	/** @var list<int>|null Registry snapshot the grid was built from, or null when it must be rebuilt. */
	private ?array $snapshot = null;

	public function __construct(public Registry $registry, public ?Level $level = null, public bool $grid = false)
	{
		$this->solids = Query::all(Transform::class, Body::class);
	}

	/** Mark a new step: the next query re-bins bodies whose cell changed since the last one. */
	public function beginStep(): void { $this->stepped = true; }

	/** Re-bin one body after its position changed within the step. */
	public function moved(int $entity, Transform $pose): void
	{
		if(!$this->grid || $this->snapshot === null || !isset($this->placed[$entity])) return;

		$key = self::key($pose->x, $pose->y);
		$old = $this->placed[$entity];

		if($key === $old) return;

		unset($this->cells[$old][$entity]);
		$this->cells[$key][$entity] = true;
		$this->placed[$entity] = $key;
	}

	/**
	 * Bodies that may touch a box, in entity order. Every body, solid or not, is binned, so a corpse raised during the
	 * step is found without a rebuild; callers still apply their own solid and overlap tests.
	 *
	 * @return list<int>
	 */
	private function near(float $minX, float $minY, float $maxX, float $maxY): array
	{
		if(!$this->grid) return $this->registry->query($this->solids);

		$solids = $this->registry->query($this->solids);

		if($this->snapshot === null || $solids !== $this->snapshot) $this->index($solids);
		elseif($this->stepped) $this->refresh();

		$this->stepped = false;

		$reach = $this->reach;
		$fromX = self::cell($minX - $reach);
		$fromY = self::cell($minY - $reach);
		$toX = self::cell($maxX + $reach);
		$toY = self::cell($maxY + $reach);
		$found = [];

		// A box spanning more cells than there are bodies is cheaper to answer from the bodies themselves.
		if(($toX - $fromX + 1) * ($toY - $fromY + 1) > count($this->placed))
		{
			$found = array_keys($this->placed);
			sort($found);

			return $found;
		}

		for($x = $fromX; $x <= $toX; $x++)
		for($y = $fromY; $y <= $toY; $y++)
		foreach($this->cells[self::pack($x, $y)] ?? [] as $entity => $unused) $found[] = $entity;

		sort($found);

		return $found;
	}

	/** @param list<int> $solids */
	private function index(array $solids): void
	{
		$this->cells = $this->placed = $this->poses = [];
		$this->reach = 0;

		foreach($solids as $entity)
		{
			$pose = $this->registry->get($entity, Transform::class);
			$key = self::key($pose->x, $pose->y);
			$this->cells[$key][$entity] = true;
			$this->placed[$entity] = $key;
			$this->poses[$entity] = $pose;
			$this->reach = max($this->reach, $this->registry->get($entity, Body::class)->radius);
		}

		$this->snapshot = $solids;
	}

	/** Re-bin bodies that moved between cells since the last step without reporting it, such as replicas. */
	private function refresh(): void
	{
		foreach($this->poses as $entity => $pose)
		{
			$key = self::key($pose->x, $pose->y);
			$old = $this->placed[$entity];

			if($key === $old) continue;

			unset($this->cells[$old][$entity]);
			$this->cells[$key][$entity] = true;
			$this->placed[$entity] = $key;
		}
	}

	/** Positions beyond the clamped range share its edge cells; a non-finite position uses the origin cell. */
	private static function cell(float $coordinate): int
	{
		$cell = floor($coordinate / self::CELL);

		if(is_nan($cell)) return 0;

		return (int)max(-self::CELL_LIMIT, min(self::CELL_LIMIT - 1, $cell));
	}

	/** Distinct keys for every clamped cell, at most 2^30 - 1, so they stay integers in 32-bit PHP. */
	private static function pack(int $x, int $y): int
	{
		return ($x + self::CELL_LIMIT) * 2 * self::CELL_LIMIT + $y + self::CELL_LIMIT;
	}

	private static function key(float $x, float $y): int { return self::pack(self::cell($x), self::cell($y)); }

	public function sweep(
		int $mover
		, Transform $pose
		, Body $body
		, float $dx
		, float $dy
	): ?MovementContact {
		if(!$body->solid) return null;

		$nearest = null;
		$connected = $this->connectedSectors($mover, $pose);

		foreach($this->near(
			min($pose->x, $pose->x + $dx) - $body->radius
			, min($pose->y, $pose->y + $dy) - $body->radius
			, max($pose->x, $pose->x + $dx) + $body->radius
			, max($pose->y, $pose->y + $dy) + $body->radius
		) as $entity)
		{
			if($entity === $mover) continue;

			$otherBody = $this->registry->get($entity, Body::class);

			if(!$otherBody->solid) continue;

			$other = $this->registry->get($entity, Transform::class);

			if(!$this->sharesSpace($entity, $other, $connected)) continue;

			if(!$this->verticalOverlap($pose, $body, $other, $otherBody)) continue;

			$contact = $this->expandedBoxHit(
				$pose->x
				, $pose->y
				, $dx
				, $dy
				, $other->x
				, $other->y
				, $body->radius + $otherBody->radius
				, $entity
			);

			if($contact && (!$nearest || $contact->time < $nearest->time)) $nearest = $contact;
		}

		return $nearest;
	}

	/** @return array{float,float,bool} */
	public function resolve(int $mover, Transform $pose, Body $body, float $x, float $y): array
	{
		if(!$body->solid) return [$x,$y,false];

		$hit = false;
		$connected = $this->connectedSectors($mover, $pose);

		for($iteration = 0; $iteration < 8; $iteration++)
		{
			$changed = false;

			foreach($this->near($x - $body->radius, $y - $body->radius, $x + $body->radius, $y + $body->radius) as $entity)
			{
				if($entity === $mover) continue;

				$otherBody = $this->registry->get($entity, Body::class);

				if(!$otherBody->solid) continue;

				$other = $this->registry->get($entity, Transform::class);

				if(!$this->sharesSpace($entity, $other, $connected)) continue;

				if(!$this->verticalOverlap($pose, $body, $other, $otherBody)) continue;

				$extent = $body->radius + $otherBody->radius;
				$offsetX = $x - $other->x;
				$offsetY = $y - $other->y;
				$overlapX = $extent - abs($offsetX);
				$overlapY = $extent - abs($offsetY);

				if($overlapX <= 0 || $overlapY <= 0) continue;

				if($overlapX <= $overlapY)
				$x += ($offsetX < 0 ? -1 : 1) * ($overlapX + .001);
				else
				$y += ($offsetY < 0 ? -1 : 1) * ($overlapY + .001);

				$changed = $hit = true;
			}

			if(!$changed) break;
		}

		return [$x,$y,$hit];
	}

	/** @return array<int,true>|null */
	private function connectedSectors(int $entity, Transform $pose): ?array
	{
		if(!$this->level) return null;

		return $this->level->map->connectedSectors($this->sector($entity, $pose));
	}

	/** @param array<int,true>|null $connected */
	private function sharesSpace(int $entity, Transform $other, ?array $connected): bool
	{
		if($connected === null || !$this->level) return true;

		return isset($connected[$this->sector($entity, $other)]);
	}

	private function sector(int $entity, Transform $pose): int
	{
		$cached = $this->sectors[$entity] ?? null;

		if($cached && $cached['x'] === $pose->x && $cached['y'] === $pose->y)
		return $cached['sector'];

		$sector = $this->level?->map->sectorAt($pose->x, $pose->y) ?? 0;
		$this->sectors[$entity] = ['x' => $pose->x,'y' => $pose->y,'sector' => $sector];

		return $sector;
	}

	private function verticalOverlap(Transform $a, Body $aBody, Transform $b, Body $bBody): bool
	{
		return $a->z < $b->z + $bBody->height - .001
			&& $a->z + $aBody->height > $b->z + .001;
	}

	private function expandedBoxHit(
		float $x
		, float $y
		, float $dx
		, float $dy
		, float $centerX
		, float $centerY
		, float $extent
		, int $entity
	): ?MovementContact {
		$entryX = -INF;
		$exitX = INF;
		$entryY = -INF;
		$exitY = INF;

		if(abs($dx) < 1e-12)
		{
			if($x <= $centerX - $extent || $x >= $centerX + $extent) return null;
		}
		else
		{
			$a = ($centerX - $extent - $x) / $dx;
			$b = ($centerX + $extent - $x) / $dx;
			$entryX = min($a, $b);
			$exitX = max($a, $b);
		}

		if(abs($dy) < 1e-12)
		{
			if($y <= $centerY - $extent || $y >= $centerY + $extent) return null;
		}
		else
		{
			$a = ($centerY - $extent - $y) / $dy;
			$b = ($centerY + $extent - $y) / $dy;
			$entryY = min($a, $b);
			$exitY = max($a, $b);
		}

		$entry = max($entryX, $entryY);
		$exit = min($exitX, $exitY);

		if($entry > $exit || $exit < 0 || $entry < -1e-7 || $entry > 1.0000001) return null;

		if($entryX > $entryY)
		return new MovementContact(max(0, $entry), $dx > 0 ? -1 : 1, 0, $entity);

		return new MovementContact(max(0, $entry), 0, $dy > 0 ? -1 : 1, $entity);
	}
}

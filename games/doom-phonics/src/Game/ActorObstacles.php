<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Dynamic square actor bounds used by Doom-style Minkowski movement sweeps. */
final class ActorObstacles
{
	private Query $solids;
	/** @var array<int,array{x:float,y:float,sector:int}> */
	private array $sectors = [];

	public function __construct(public Registry $registry, public ?Level $level = null)
	{
		$this->solids = Query::all(Transform::class, Body::class);
	}

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

		foreach($this->registry->query($this->solids) as $entity)
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

			foreach($this->registry->query($this->solids) as $entity)
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

<?php
declare(strict_types=1);
namespace DoomPhonics\System;

use DoomPhonics\Audio\SoundEmitter;
use DoomPhonics\Audio\SoundCue;
use DoomPhonics\Audio\SoundEvent;
use DoomPhonics\Audio\SoundListener;
use DoomPhonics\Audio\SoundSource;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\SectorMover;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\Level;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;

/** Runs classic 35 Hz sector thinkers represented by ordinary ECS entities. */
final class SectorMotionSystem
{
	private float $ticRemainder = 0;
	private Query $actors;

	public function __construct(public Level $level, public SoundEmitter $sound)
	{
		$this->actors = Query::all(Transform::class, ActorState::class);
	}

	public function update(Registry $registry, float $dt): void
	{
		$this->ticRemainder += max(0, $dt) * 35;
		$tics = min(8, (int)floor($this->ticRemainder + 1e-9));

		if($tics < 1) return;

		$this->ticRemainder -= $tics;

		for($tic = 0; $tic < $tics; $tic++)
		foreach($registry->query(SectorMover::class) as $entity)
		{
			if(!$registry->alive($entity)) continue;

			$this->tick($registry, $entity, $registry->get($entity, SectorMover::class));
		}
	}

	private function tick(Registry $registry, int $entity, SectorMover $mover): void
	{
		$sector = $this->level->sectors[$mover->sector] ?? null;

		if(!$sector)
		{
			$registry->destroy($entity);

			return;
		}

		if($mover->isWaiting())
		{
			if(!$mover->advanceWait()) return;

			if($mover->returnTarget === null)
			{
				$this->finish($registry, $entity, $mover);

				return;
			}

			$mover->target = $mover->returnTarget;
			$mover->returnTarget = null;
			$mover->resume();
			$current = $mover->movesFloor()?$sector->floor:$sector->ceiling;
			$cue = $mover->isLift()
				? SoundCue::platformStart()
				: ($mover->target > $current?SoundCue::doorOpen():SoundCue::doorClose());

			$this->play($registry, $cue, $mover->sector);

			return;
		}

		$current = $mover->movesFloor() ? $sector->floor : $sector->ceiling;
		$step = $mover->speed / 35;
		$next = self::approach($current, $mover->target, $step);

		if($next !== $current && !$this->movePlane($registry, $mover, $current, $next))
		{
			if($mover->reversesWhenBlocked() && $mover->movesCeiling())
			{
				$mover->target = max($sector->ceiling, $this->level->lowestNeighborCeiling($mover->sector) - 4);
				$mover->returnTarget = $mover->isNormalDoor()?$sector->originalCeiling:null;
				$mover->waitTics = $mover->isNormalDoor()?150:0;
				$mover->resume();
				$this->play($registry, SoundCue::doorOpen(), $mover->sector);
			}

			return;
		}

		if($next !== $mover->target) return;

		if($mover->returnTarget !== null)
		{
			if($mover->waitTics > 0)
			{
				$mover->beginWaiting();

				if($mover->isLift()) $this->play($registry, SoundCue::platformStop(), $mover->sector);

				return;
			}

			$mover->target = $mover->returnTarget;
			$mover->returnTarget = null;

			return;
		}

		$this->finish($registry, $entity, $mover);
	}

	private function movePlane(Registry $registry, SectorMover $mover, float $from, float $to): bool
	{
		$sector = $this->level->sectors[$mover->sector];
		$delta = $to - $from;
		$carried = [];

		foreach($registry->query($this->actors) as $entity)
		{
			if(!$registry->alive($entity)) continue;

			$pose = $registry->get($entity, Transform::class);

			$occupied = $this->level->sectorAt($pose->x, $pose->y);
			if($occupied->index !== $mover->sector)
			{
				// Model sectors live elsewhere in the map. Their solid tops carry actors
				// in each target sector; liquid surfaces never push or crush swimmers.
				foreach($occupied->volumes as $volume)
				{
					if($volume->model !== $sector || !$volume->solid()) continue;
					$top = $volume->top($pose->x, $pose->y);
					$plane = $mover->movesFloor() ? 0 : 1;
					$topDelta = $volume->topPlane() === $plane ? $delta : 0;
					$bottom = $volume->bottom($pose->x, $pose->y) + ($volume->bottomPlane() === $plane ? $delta : 0);
					$canCarry = !$registry->has($entity, Projectile::class)
						&& !$registry->has($entity, RemoteProjectile::class)
						&& !$registry->get($entity, ActorState::class)->definition->hasFlag('SPAWNCEILING');
					if($canCarry && (abs($pose->z - $top) <= .1
						|| ($topDelta > 0 && $pose->z >= $top && $pose->z < $top + $topDelta)))
					{
						$carried[$entity] ??= $pose->z;
						$pose->z = $top + $topDelta;
					}
					if(!$registry->has($entity, Body::class)) continue;
					$height = $registry->get($entity, Body::class)->height;
					$blocked = $pose->z + $height > $occupied->headroomZ($pose->x, $pose->y, max($pose->z, $top)) + .01
						|| ($pose->z < $top + $topDelta - .01 && $pose->z + $height > $bottom + .01);
					if($blocked)
					{
						foreach($carried as $carriedEntity => $oldZ)
						$registry->get($carriedEntity, Transform::class)->z = $oldZ;
						return false;
					}
				}
				continue;
			}

			$floor = $sector->floorZ($pose->x, $pose->y);
			$ceiling = $sector->ceilingZ($pose->x, $pose->y);

			if($mover->movesFloor() && $delta !== 0.0 && abs($pose->z - $floor) <= .1
				&& !$registry->has($entity, Projectile::class)
				&& !$registry->has($entity, RemoteProjectile::class)
				&& !$registry->get($entity, ActorState::class)->definition->hasFlag('SPAWNCEILING')
			){
				$carried[$entity] = $pose->z;
				$pose->z += $delta;
			}

			if(!$registry->has($entity, Body::class)) continue;

			$constricting = ($mover->movesFloor() && $delta > 0)
				|| ($mover->movesCeiling() && $delta < 0);

			if(!$constricting)continue;

			$body = $registry->get($entity, Body::class);
			$newFloor = $floor + ($mover->movesFloor() ? $delta : 0);
			$newCeiling = $ceiling + ($mover->movesCeiling() ? $delta : 0);

			if($pose->z < $newFloor - .01 || $pose->z + $body->height > $newCeiling + .01)
			{
				foreach($carried as $carriedEntity => $oldZ)
				if($registry->alive($carriedEntity)) $registry->get($carriedEntity, Transform::class)->z = $oldZ;

				return false;
			}
		}

		if($mover->movesFloor()) $sector->floor = $to;
		else $sector->ceiling = $to;

		return true;
	}

	private function finish(Registry $registry, int $entity, SectorMover $mover): void
	{
		$sector = $this->level->sectors[$mover->sector];

		if($mover->completionTexture !== null) $sector->setFloorTexture($mover->completionTexture);

		if($mover->completionSpecial !== null) $sector->setSpecial($mover->completionSpecial);

		$sector->unlockMover($mover, $entity);

		if($mover->movesFloor()) $this->play($registry, SoundCue::platformStop(), $mover->sector);

		if($registry->alive($entity)) $registry->destroy($entity);
	}

	private function play(Registry $registry, SoundCue $cue, int $sectorId): void
	{
		[$x,$y] = $this->level->sectorCenter($sectorId);
		$listener = null;

		foreach($registry->query(Query::all(PlayerStart::class, Transform::class)) as $id)
		{
			$listener = $registry->get($id, Transform::class);
			break;
		}

		$listenerPose = $listener?new SoundListener($listener->x, $listener->y, $listener->angle):null;
		$this->sound->play(new SoundEvent($cue, new SoundSource($x, $y), $listenerPose));
	}

	private static function approach(float $value, float $target, float $amount): float
	{
		return $value < $target ? min($target, $value + $amount) : max($target, $value - $amount);
	}
}

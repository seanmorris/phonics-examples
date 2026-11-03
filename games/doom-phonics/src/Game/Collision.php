<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;

/** Swept-circle movement over Minkowski-expanded linedefs with Doom step and opening rules. */
final class Collision
{

	public function __construct(
		public Level $level
		, public float $maxStep = 24
		, public ?ActorObstacles $actors = null
	){
	}

	/** Move and slide a body, returning whether any blocking surface was encountered. */
	public function move(Transform $transform, Body $body, float $dx, float $dy, ?int $entity = null): bool
	{
		$result = $this->moveAndSlide($transform, $body, $dx, $dy, $entity);

		$sector = $this->level->sectorAt($transform->x, $transform->y);
		$floor = $sector->supportZ($transform->x, $transform->y, $transform->z, $body->grounded ? $this->maxStep : 0);
		$transform->z = max($transform->z, $floor);

		if($body->grounded && $transform->z - $floor <= $this->maxStep + .01)
		$transform->z = $floor;

		return $result['collided'];
	}

	/**
	 * Sweep a body through the map and preserve the tangential remainder after each impact.
	 *
	 * Expanding every wall segment by the body's radius turns circle-versus-segment movement
	 * into a point-versus-capsule sweep. This prevents tunnelling and removes the sticky
	 * push-out response at corners while retaining exact round endpoints.
	 *
	 * @return array{collided:bool,dx:float,dy:float,normals:list<array{0:float,1:float}>,actors:list<int>}
	 */
	public function moveAndSlide(
		Transform $transform
		, Body $body
		, float $dx
		, float $dy
		, ?int $entity = null
	): array {
		$startX = $transform->x;
		$startY = $transform->y;

		[$transform->x,$transform->y,$overlap] = $this->resolve(
			$transform
			, $body
			, $transform->x
			, $transform->y
			, $entity
		);

		$normals = [];
		$actors = [];
		$swept = $this->sweep($transform, $body, $dx, $dy, $normals, $actors, $entity);
		$collided = $overlap || $swept;

		return [
			'collided' => $collided
			, 'dx' => $transform->x - $startX
			, 'dy' => $transform->y - $startY
			, 'normals' => $normals
			, 'actors' => array_values(array_unique($actors))
		];
	}

	/** @param list<array{0:float,1:float}> $normals @param list<int> $actors */
	private function sweep(
		Transform $transform
		, Body $body
		, float $dx
		, float $dy
		, array &$normals
		, array &$actors
		, ?int $entity = null
		, int $depth = 0
	): bool {
		$distance = hypot($dx, $dy);

		if($distance < 1e-7 || $depth >= 6) return false;

		$current = $this->level->sectorAt($transform->x, $transform->y)->index;
		$nearest = null;

		foreach($this->level->spatial->near(
			min($transform->x, $transform->x + $dx) - $body->radius
			, min($transform->y, $transform->y + $dy) - $body->radius
			, max($transform->x, $transform->x + $dx) + $body->radius
			, max($transform->y, $transform->y + $dy) + $body->radius
		) as $lineId
		){
			if(!$this->blocked(
				$lineId
				, $current
				, $transform->z
				, $body->height
				, $transform->x + $dx
				, $transform->y + $dy
			))
			continue;

			$line = $this->level->map->linedefs[$lineId];
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];

			$hit = $this->capsuleHit(
				$transform->x
				, $transform->y
				, $dx
				, $dy
				, (float)$a['x']
				, (float)$a['y']
				, (float)$b['x']
				, (float)$b['y']
				, $body->radius
			);

			if($hit && (!$nearest || $hit->time < $nearest->time)) $nearest = $hit;
		}

		if($entity !== null && $this->actors)
		{
			$actor = $this->actors->sweep($entity, $transform, $body, $dx, $dy);

			if($actor && (!$nearest || $actor->time < $nearest->time)) $nearest = $actor;
		}

		if(!$nearest)
		{
			$transform->x += $dx;
			$transform->y += $dy;

			return false;
		}

		$padding = min($nearest->time, .01 / $distance);
		$travel = max(0, $nearest->time - $padding);
		$transform->x += $dx * $travel;
		$transform->y += $dy * $travel;
		$normal = [$nearest->normalX, $nearest->normalY];
		$normals[] = $normal;

		if($nearest->entity !== null) $actors[] = $nearest->entity;

		$remaining = max(0, 1 - $nearest->time);
		$slideX = $dx * $remaining;
		$slideY = $dy * $remaining;
		$into = $slideX * $normal[0] + $slideY * $normal[1];

		if($into < 0)
		{
			$slideX -= $normal[0] * $into;
			$slideY -= $normal[1] * $into;
		}

		$this->sweep($transform, $body, $slideX, $slideY, $normals, $actors, $entity, $depth + 1);

		return true;
	}

	/** Return the first contact with a linedef expanded by the moving circle. */
	private function capsuleHit(
		float $px
		, float $py
		, float $dx
		, float $dy
		, float $ax
		, float $ay
		, float $bx
		, float $by
		, float $radius
	): ?MovementContact {
		$vx = $bx - $ax;
		$vy = $by - $ay;
		$length = hypot($vx, $vy);

		if($length < 1e-9) return $this->circleHit($px, $py, $dx, $dy, $ax, $ay, $radius);

		$tx = $vx / $length;
		$ty = $vy / $length;
		$nx = -$ty;
		$ny = $tx;
		$nearest = null;

		foreach([-1.0, 1.0] as $sign)
		{
			$outX = $nx * $sign;
			$outY = $ny * $sign;
			$start = ($px - $ax) * $outX + ($py - $ay) * $outY;
			$toward = $dx * $outX + $dy * $outY;

			if($start < $radius - 1e-6 || $toward >= -1e-9) continue;

			$time = ($radius - $start) / $toward;

			if($time < -1e-7 || $time > 1.0000001) continue;

			$along = ($px + $dx * $time - $ax) * $tx + ($py + $dy * $time - $ay) * $ty;

			if($along < 0 || $along > $length) continue;

			$nearest = new MovementContact(max(0, $time), $outX, $outY);
		}

		foreach([[$ax,$ay],[$bx,$by]] as [$cx,$cy])
		{
			$hit = $this->circleHit($px, $py, $dx, $dy, $cx, $cy, $radius);

			if($hit && (!$nearest || $hit->time < $nearest->time)) $nearest = $hit;
		}

		return $nearest;
	}

	private function circleHit(
		float $px
		, float $py
		, float $dx
		, float $dy
		, float $cx
		, float $cy
		, float $radius
	): ?MovementContact {
		$mx = $px - $cx;
		$my = $py - $cy;
		$a = $dx * $dx + $dy * $dy;

		if($a < 1e-12) return null;

		$c = $mx * $mx + $my * $my - $radius * $radius;

		if($c < -1e-6) return null;

		$b = 2 * ($mx * $dx + $my * $dy);
		$discriminant = $b * $b - 4 * $a * $c;

		if($discriminant < 0) return null;

		$time = (-$b - sqrt($discriminant)) / (2 * $a);

		if($time < -1e-7 || $time > 1.0000001) return null;

		$ix = $px + $dx * $time - $cx;
		$iy = $py + $dy * $time - $cy;
		$length = hypot($ix, $iy);

		if($length < 1e-9) return null;

		$normalX = $ix / $length;
		$normalY = $iy / $length;

		if($dx * $normalX + $dy * $normalY >= -1e-9) return null;

		return new MovementContact(max(0, $time), $normalX, $normalY);
	}

	/** @return array{float,float,bool} */
	private function resolve(
		Transform $from
		, Body $body
		, float $x
		, float $y
		, ?int $entity
	): array {
		$hit = false;

		for($iteration = 0; $iteration < 5; $iteration++)
		{
			[$x,$y,$mapHit] = $this->resolveMap($from, $body, $x, $y);
			$actorHit = false;

			if($entity !== null && $this->actors)
			[$x,$y,$actorHit] = $this->actors->resolve($entity, $from, $body, $x, $y);

			$hit = $hit || $mapHit || $actorHit;

			if(!$mapHit && !$actorHit) break;
		}

		return [$x,$y,$hit];
	}

	/** @return array{float,float,bool} */
	private function resolveMap(Transform $from, Body $body, float $x, float $y): array
	{
		$hit = false;
		$current = $this->level->sectorAt($from->x, $from->y)->index;

		for($iteration = 0; $iteration < 5; $iteration++)
		{
			$changed = false;

			foreach($this->level->spatial->near(
				$x - $body->radius
				, $y - $body->radius
				, $x + $body->radius
				, $y + $body->radius
			) as $lineId
			){
				$line = $this->level->map->linedefs[$lineId];
				$a = $this->level->map->vertices[$line['from']];
				$b = $this->level->map->vertices[$line['to']];
				$vx = $b['x'] - $a['x'];
				$vy = $b['y'] - $a['y'];
				$lengthSquared = $vx * $vx + $vy * $vy;
				$t = $lengthSquared > 0
					? max(0, min(1, (($x - $a['x']) * $vx + ($y - $a['y']) * $vy) / $lengthSquared))
					: 0;

				$nearestX = $a['x'] + $vx * $t;
				$nearestY = $a['y'] + $vy * $t;

				if(!$this->blocked($lineId, $current, $from->z, $body->height, $nearestX, $nearestY)) continue;

				$nx = $x - $nearestX;
				$ny = $y - $nearestY;
				$distance = hypot($nx, $ny);

				if($distance >= $body->radius) continue;

				if($distance < 1e-6)
				{
					$side = (($from->x - $a['x']) * $vy - ($from->y - $a['y']) * $vx) < 0 ? -1 : 1;
					$lineLength = max(1e-6, hypot($vx, $vy));
					$nx = $side * $vy / $lineLength;
					$ny = -$side * $vx / $lineLength;
					$distance = 1;
				}

				$push = $body->radius - $distance + .001;
				$x += $nx / $distance * $push;
				$y += $ny / $distance * $push;
				$changed = $hit = true;
			}

			if(!$changed) break;
		}

		return [$x,$y,$hit];
	}

	public function blocked(int $lineId, int $sector, float $z, float $height, ?float $x = null, ?float $y = null): bool
	{
		$line = $this->level->map->linedefs[$lineId];

		if($line['flags'] & 1) return true;

		$sides = $this->level->sides($lineId, $sector);

		if($sides['back'] === null) return true;
		// Self-referencing control lines do not divide physical spaces. Testing
		// their own ceiling would push a swimmer sideways while crossing a portal.
		if($sides['front'] === $sides['back']) return false;

		$front = $this->level->sectors[$sides['front']];
		$back = $this->level->sectors[$sides['back']];
		$a = $this->level->map->vertices[$line['from']];
		$b = $this->level->map->vertices[$line['to']];
		$x ??= ($a['x'] + $b['x']) / 2;
		$y ??= ($a['y'] + $b['y']) / 2;
		$dx = $b['x'] - $a['x'];
		$dy = $b['y'] - $a['y'];
		$t = max(0, min(1, (($x - $a['x']) * $dx + ($y - $a['y']) * $dy) / max(1, $dx * $dx + $dy * $dy)));
		$x = $a['x'] + $dx * $t;
		$y = $a['y'] + $dy * $t;
		$floor = max($front->supportZ($x, $y, $z, $this->maxStep), $back->supportZ($x, $y, $z, $this->maxStep));
		$ceiling = min($front->headroomZ($x, $y, max($z, $floor)), $back->headroomZ($x, $y, max($z, $floor)));

		return $floor - $z > $this->maxStep || $ceiling - max($z, $floor) < $height;
	}
}

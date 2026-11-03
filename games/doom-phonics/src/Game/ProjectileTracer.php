<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;

/** Sweeps projectile height through portals and mutable sector planes. */
final class ProjectileTracer
{

	public function __construct(public Level $level, private bool $sight = false) {}

	public function trace(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
	): ?ProjectileImpact {
		return $this->tracePath($pose, $body, $dx, $dy, $dz)['impact'];
	}

	/**
	 * Trace through passable camera portals while retaining each map-space segment.
	 *
	 * Fractions remain relative to the original displacement, so callers can compare
	 * transformed actor contacts with the final static impact.
	 *
	 * @return array{
	 *   segments:list<array{x:float,y:float,z:float,dx:float,dy:float,dz:float,from:float,to:float,portalCount:int}>,
	 *   impact:?ProjectileImpact,
	 *   portals:list<CameraPortalLink>
	 * }
	 */
	public function tracePath(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
	): array {
		$segments = [];
		$portals = [];
		$x = $pose->x;
		$y = $pose->y;
		$z = $pose->z;
		$remainingX = $dx;
		$remainingY = $dy;
		$remainingZ = $dz;
		$from = 0.0;

		for($guard = 0; $guard < 8; $guard++)
		{
			$ray = new Transform($x, $y, $z, $pose->angle);
			$impact = $this->traceStraight($ray, $body, $remainingX, $remainingY, $remainingZ);

			$portalHit = $this->cameraPortalHit(
				$x
				, $y
				, $z
				, $body
				, $remainingX
				, $remainingY
				, $remainingZ
				, $impact?->fraction ?? 1.0
			);

			if($portalHit === null)
			{
				$local = $impact?->fraction ?? 1.0;
				$to = $from + (1.0 - $from) * $local;
				$segments[] = [
					'x' => $x
					, 'y' => $y
					, 'z' => $z
					, 'dx' => $remainingX * $local
					, 'dy' => $remainingY * $local
					, 'dz' => $remainingZ * $local
					, 'from' => $from
					, 'to' => $to
					, 'portalCount' => count($portals)
				];

				if($impact) $impact->fraction = $to;

				return ['segments' => $segments,'impact' => $impact,'portals' => $portals];
			}

			$local = $portalHit['fraction'];
			$to = $from + (1.0 - $from) * $local;
			$segments[] = [
				'x' => $x
				, 'y' => $y
				, 'z' => $z
				, 'dx' => $remainingX * $local
				, 'dy' => $remainingY * $local
				, 'dz' => $remainingZ * $local
				, 'from' => $from
				, 'to' => $to
				, 'portalCount' => count($portals)
			];

			$link = $portalHit['portal'];
			$crossX = $x + $remainingX * $local;
			$crossY = $y + $remainingY * $local;
			$crossZ = $z + $remainingZ * $local;
			[$x,$y] = $link->point($crossX, $crossY);
			$z = $link->bodyZ($crossZ, $body->height, $this->level);
			[$remainingX,$remainingY] = $link->vector($remainingX * (1.0 - $local), $remainingY * (1.0 - $local));
			$remainingZ *= 1.0 - $local;
			$from = $to;
			$portals[] = $link;

			if(1.0 - $from <= 1e-9)
			return ['segments' => $segments,'impact' => null,'portals' => $portals];
		}

		// Malformed cyclic portals are bounded. Finish the remaining displacement in
		// the last coordinate space instead of hanging a simulation step.
		$ray = new Transform($x, $y, $z, $pose->angle);
		$impact = $this->traceStraight($ray, $body, $remainingX, $remainingY, $remainingZ);
		$local = $impact?->fraction ?? 1.0;
		$to = $from + (1.0 - $from) * $local;
		$segments[] = [
			'x' => $x
			, 'y' => $y
			, 'z' => $z
			, 'dx' => $remainingX * $local
			, 'dy' => $remainingY * $local
			, 'dz' => $remainingZ * $local
			, 'from' => $from
			, 'to' => $to
			, 'portalCount' => count($portals)
		];

		if($impact) $impact->fraction = $to;

		return ['segments' => $segments,'impact' => $impact,'portals' => $portals];
	}

	/**
	 * @return array{fraction:float,portal:CameraPortalLink}|null
	 */
	private function cameraPortalHit(
		float $x
		, float $y
		, float $z
		, Body $body
		, float $dx
		, float $dy
		, float $dz
		, float $limit
	): ?array {
		$line = $this->lineCameraPortalHit($x, $y, $z, $body, $dx, $dy, $dz, $limit);
		$plane = $this->planeCameraPortalHit($x, $y, $z, $body, $dx, $dy, $dz, $limit);

		if($line === null) return $plane;

		if($plane === null) return $line;

		return $line['fraction'] <= $plane['fraction'] ? $line : $plane;
	}

	/** @return array{fraction:float,portal:CameraPortalLink}|null */
	private function lineCameraPortalHit(
		float $x
		, float $y
		, float $z
		, Body $body
		, float $dx
		, float $dy
		, float $dz
		, float $limit
	): ?array {
		$distance = hypot($dx, $dy);

		if($distance <= 1e-9) return null;

		foreach($this->level->spatial->cast($x, $y, $dx, $dy, $distance) as $hit)
		{
			$fraction = $hit['distance'] / $distance;

			if($fraction > $limit + 1e-7) break;

			$surface = $this->level->cameraPortalForLine($hit['line']);

			if(!$surface?->link->passable()) continue;

			$line = $this->level->map->linedefs[$hit['line']];
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];
			$side = ((float)$b['x'] - $a['x']) * ($y - $a['y'])
				- ((float)$b['y'] - $a['y']) * ($x - $a['x']);

			// Line portals only traverse from their authored front side, matching actor
			// movement. The physical opening still decides whether a solid wall wins.
			if($side > 1e-7) continue;

			$physical = $this->level->portal($hit['line']);
			$crossZ = $z + $dz * $fraction;

			if(!$physical?->admitsProjectile($crossZ, $body->height, .001, $hit['x'], $hit['y'])) continue;

			return ['fraction' => $fraction,'portal' => $surface->link];
		}

		return null;
	}

	/**
	 * Find the first passable floor or ceiling portal before the solid impact.
	 * Sector intervals follow the center ray so sloped planes and portals reached
	 * after an ordinary two-sided line use the correct live sector geometry.
	 *
	 * @return array{fraction:float,portal:CameraPortalLink}|null
	 */
	private function planeCameraPortalHit(
		float $x
		, float $y
		, float $z
		, Body $body
		, float $dx
		, float $dy
		, float $dz
		, float $limit
	): ?array {
		$current = $this->level->sectorAt($x, $y)->index;
		$distance = hypot($dx, $dy);
		$from = 0.0;
		$hits = $distance > 1e-9
			? $this->level->spatial->cast($x, $y, $dx, $dy, $distance)
			: [];

		foreach($hits as $hit)
		{
			$fraction = min(1.0, max(0.0, $hit['distance'] / $distance));

			if($fraction > $limit + 1e-7) break;

			if($fraction < $from) continue;

			$plane = $this->planeCameraPortalInSector($x, $y, $z, $body, $dx, $dy, $dz, $current, $from, $fraction);

			if($plane !== null) return $plane;

			$portal = $this->level->portal($hit['line']);
			$back = $portal?->opposite($current);
			$bottom = $z + $dz * $fraction;

			$surface = $this->lineSurface(
				$hit['line']
				, $bottom
				, $body->height
				, $x + $dx * $fraction
				, $y + $dy * $fraction
			);

			if($portal && $back === null) $surface = ProjectileImpact::WALL;

			if($surface !== null || $back === null) break;

			$current = $back;
			$from = $fraction;
		}

		return $this->planeCameraPortalInSector($x, $y, $z, $body, $dx, $dy, $dz, $current, $from, $limit);
	}

	/** @return array{fraction:float,portal:CameraPortalLink}|null */
	private function planeCameraPortalInSector(
		float $x
		, float $y
		, float $z
		, Body $body
		, float $dx
		, float $dy
		, float $dz
		, int $sectorId
		, float $from
		, float $to
	): ?array {
		$sector = $this->level->sectors[$sectorId];
		$center = $z + $body->height * .5;
		$floor = $center - $sector->floorZ($x, $y);
		$ceiling = $center - $sector->ceilingZ($x, $y);
		$floorRate = $dz - $sector->floorSlope[0] * $dx - $sector->floorSlope[1] * $dy;
		$ceilingRate = $dz - $sector->ceilingSlope[0] * $dx - $sector->ceilingSlope[1] * $dy;
		$candidates = [];

		if($floorRate < -1e-9)
		{
			$fraction = -$floor / $floorRate;

			if($fraction >= $from - 1e-7 && $fraction <= $to + 1e-7)
			$candidates[] = [0,$fraction];
		}

		if($ceilingRate > 1e-9)
		{
			$fraction = -$ceiling / $ceilingRate;

			if($fraction >= $from - 1e-7 && $fraction <= $to + 1e-7)
			$candidates[] = [1,$fraction];
		}

		usort($candidates, static fn(array $left, array $right): int => $left[1] <=> $right[1]);

		foreach($candidates as [$plane,$fraction])
		{
			$fraction = min($to, max($from, $fraction));
			$crossX = $x + $dx * $fraction;
			$crossY = $y + $dy * $fraction;

			if(!$this->level->boundsWithinCameraPortalPlane(
				$crossX
				, $crossY
				, $body->radius
				, $sectorId
				, $plane
			)) continue;

			$link = $this->level->cameraPortalForPlane($sectorId, $plane)?->link;

			if($link?->passable()) return ['fraction' => $fraction,'portal' => $link];
		}

		return null;
	}

	/** Trace one displacement without applying camera-portal transforms. */
	private function traceStraight(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
	): ?ProjectileImpact {
		$distance = hypot($dx, $dy);
		$current = $this->level->sectorAt($pose->x, $pose->y)->index;
		$from = 0.0;
		$wall = $this->wall($pose, $body, $dx, $dy, $dz);
		$limit = $wall?->fraction ?? 1.0;

		$hits = $distance > 1e-9
			? $this->level->spatial->cast($pose->x, $pose->y, $dx, $dy, $distance)
			: [];

		foreach($hits as $hit)
		{
			$fraction = min(1.0, max(0.0, $hit['distance'] / $distance));

			if($fraction > $limit + 1e-7) break;

			if($fraction < $from) continue;

			$plane = $this->plane($pose, $body, $dx, $dy, $dz, $current, $from, $fraction);

			if($plane) return $plane;

			$z = $pose->z + $dz * $fraction;
			$portal = $this->level->portal($hit['line']);
			$back = $portal?->opposite($current);

			$surface = $this->lineSurface(
				$hit['line']
				, $z
				, $body->height
				, $pose->x + $dx * $fraction
				, $pose->y + $dy * $fraction
			);

			if($portal && $back === null) $surface = ProjectileImpact::WALL;

			if($surface !== null)
			return new ProjectileImpact(
				$surface
				, $fraction
				, $pose->x + $dx * $fraction
				, $pose->y + $dy * $fraction
				, $z
				, $current
				, $hit['line']
			);

			$current = $back;
			$from = $fraction;
		}

		$plane = $this->plane($pose, $body, $dx, $dy, $dz, $current, $from, $limit);

		return $plane ?? $wall;
	}

	private function wall(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
	): ?ProjectileImpact {
		if(hypot($dx, $dy) < 1e-9) return null;

		$nearest = null;

		$lines = $this->level->spatial->near(
			min($pose->x, $pose->x + $dx) - $body->radius
			, min($pose->y, $pose->y + $dy) - $body->radius
			, max($pose->x, $pose->x + $dx) + $body->radius
			, max($pose->y, $pose->y + $dy) + $body->radius
		);

		foreach($lines as $lineId)
		{
			$line = $this->level->map->linedefs[$lineId];
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];

			$hit = $this->capsuleHit(
				$pose->x
				, $pose->y
				, $dx
				, $dy
				, (float)$a['x']
				, (float)$a['y']
				, (float)$b['x']
				, (float)$b['y']
				, $body->radius
			);

			if($hit === null || ($nearest && $hit >= $nearest->fraction)) continue;

			$z = $pose->z + $dz * $hit;
			$surface = $this->lineSurface($lineId, $z, $body->height, $pose->x + $dx * $hit, $pose->y + $dy * $hit);

			// A capsule reaches a wall before its center ray. Solid walls stop at that
			// earlier contact; sky escapes are classified when the center crosses the
			// terminal boundary so they retain an exact map-exit position.
			if($surface === null || $surface === ProjectileImpact::SKY) continue;

			$nearest = new ProjectileImpact(
				ProjectileImpact::WALL
				, $hit
				, $pose->x + $dx * $hit
				, $pose->y + $dy * $hit
				, $z
				, $this->level->sectorAt($pose->x + $dx * $hit, $pose->y + $dy * $hit)->index
				, $lineId
			);
		}

		return $nearest;
	}

	/** Return the physical response for crossing a linedef at this vertical interval. */
	private function lineSurface(int $lineId, float $bottom, float $height, float $x, float $y): ?string
	{
		$portal = $this->level->portal($lineId);

		if($portal)
		return $portal->admitsProjectile($bottom, $height, .001, $x, $y, $this->sight) ? null : ProjectileImpact::WALL;

		$terminal = $this->level->terminalSector($lineId);

		return $terminal && $terminal->hasSkyCeiling() && $bottom >= $terminal->ceilingZ($x, $y) - .001
			? ProjectileImpact::SKY
			: ProjectileImpact::WALL;
	}

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
	): ?float {
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

			$time = max(0, $time);

			if($nearest === null || $time < $nearest) $nearest = $time;
		}

		foreach([[$ax,$ay],[$bx,$by]] as [$cx,$cy])
		{
			$hit = $this->circleHit($px, $py, $dx, $dy, $cx, $cy, $radius);

			if($hit !== null && ($nearest === null || $hit < $nearest)) $nearest = $hit;
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
	): ?float {
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

		return $time >= -1e-7 && $time <= 1.0000001 ? max(0, $time) : null;
	}

	private function plane(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
		, int $sectorId
		, float $from
		, float $to
	): ?ProjectileImpact {
		$sector = $this->level->sectors[$sectorId];
		$volumeHit = $this->volumePlane($pose, $body, $dx, $dy, $dz, $sector, $from, $to);
		$floor = $pose->z - $sector->floorZ($pose->x, $pose->y);
		$ceiling = $pose->z + $body->height - $sector->ceilingZ($pose->x, $pose->y);
		$floorRate = $dz - $sector->floorSlope[0] * $dx - $sector->floorSlope[1] * $dy;
		$ceilingRate = $dz - $sector->ceilingSlope[0] * $dx - $sector->ceilingSlope[1] * $dy;
		$fraction = null;
		$surface = null;

		if($floorRate <= 0 && $floor + $floorRate * $from < -.001)
		{
			$fraction = $from;
			$surface = ProjectileImpact::FLOOR;
		}
		elseif($floorRate < 0 && $floor + $floorRate * $to <= 0)
		{
			$fraction = -$floor / $floorRate;
			$surface = ProjectileImpact::FLOOR;
		}
		elseif(!$sector->hasSkyCeiling() && $ceilingRate >= 0
			&& $ceiling + $ceilingRate * $from > .001
		){
			$fraction = $from;
			$surface = ProjectileImpact::CEILING;
		}
		elseif(!$sector->hasSkyCeiling() && $ceilingRate > 0 && $ceiling + $ceilingRate * $to >= 0)
		{
			$fraction = -$ceiling / $ceilingRate;
			$surface = ProjectileImpact::CEILING;
		}

		if($fraction === null || $fraction < $from - 1e-7 || $fraction > $to + 1e-7) return $volumeHit;
		if($volumeHit && $volumeHit->fraction < $fraction) return $volumeHit;

		$fraction = min($to, max($from, $fraction));
		$plane = $surface === ProjectileImpact::FLOOR ? 0 : 1;
		$crossX = $pose->x + $dx * $fraction;
		$crossY = $pose->y + $dy * $fraction;
		$portal = $this->level->cameraPortalForPlane($sectorId, $plane)?->link;

		// A body starts intersecting a portal before its center changes map space.
		// Keep that aperture non-solid while the complete horizontal footprint fits;
		// planeCameraPortalInSector() performs the actual center-anchor traversal.
		if($portal?->passable() && $this->level->boundsWithinCameraPortalPlane(
			$crossX
			, $crossY
			, $body->radius
			, $sectorId
			, $plane
		)) return $volumeHit;

		return new ProjectileImpact($surface, $fraction, $crossX, $crossY, $pose->z + $dz * $fraction, $sectorId);
	}

	/** Clip a swept body against each blocking slab, including vertical rays and sloped models. */
	private function volumePlane(
		Transform $pose, Body $body, float $dx, float $dy, float $dz,
		SectorState $sector, float $from, float $to
	): ?ProjectileImpact {
		$nearest = null;
		foreach($sector->volumes as $volume)
		{
			if(!$volume->blocks($this->sight)) continue;
			$enter = $from;
			$exit = $to;
			$surface = ProjectileImpact::WALL;
			$top = $volume->top($pose->x, $pose->y);
			$bottom = $volume->bottom($pose->x, $pose->y);
			// Both inequalities must be positive inside the expanded volume.
			$planes = [
				[$top - $pose->z, $volume->top($pose->x + $dx, $pose->y + $dy) - $top - $dz, ProjectileImpact::FLOOR],
				[$pose->z + $body->height - $bottom, $dz - $volume->bottom($pose->x + $dx, $pose->y + $dy) + $bottom, ProjectileImpact::CEILING]
			];
			foreach($planes as [$distance,$rate,$kind])
			{
				if(abs($rate) < 1e-9)
				{
					if($distance < .001) continue 2;
					continue;
				}
				$cross = -$distance / $rate;
				if($rate > 0 && $cross >= $enter) { $enter = $cross; $surface = $kind; }
				elseif($rate < 0) $exit = min($exit, $cross);
			}
			if($enter > $exit + 1e-7 || $exit <= $from || $enter > $to || ($nearest && $enter >= $nearest->fraction)) continue;
			$nearest = new ProjectileImpact($surface, $enter, $pose->x + $dx * $enter,
				$pose->y + $dy * $enter, $pose->z + $dz * $enter, $sector->index);
		}
		return $nearest;
	}
}

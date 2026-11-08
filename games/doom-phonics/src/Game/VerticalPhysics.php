<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;

/** Shared floor, ceiling, and gravity integration for grounded DECORATE actors. */
final class VerticalPhysics
{
	/** Keep a freshly warped eye beyond the destination portal plane. */
	private const PORTAL_EXIT_INSET = .02;
	/**
	 * Bodies their last step left standing on a plain sector floor: position, sector, its floor and ceiling heights,
	 * and the body's height. Keyed weakly by pose, so entries leave with their entities.
	 *
	 * @var \WeakMap<Transform,array{float,float,float,SectorState,float,float,float}>
	 */
	private \WeakMap $resting;

	public function __construct(
		public Level $level
		, public float $maxStep = 24
		, public float $acceleration = DoomGravity::ACCELERATION
	){
		$this->resting = new \WeakMap;
	}

	/**
	 * Integrate one body's vertical motion for one step.
	 *
	 * Most of a map's things stand still on their floors every step. While such a body, its sector's planes, and its
	 * height are unchanged, the step cannot change anything, so it returns before locating the sector.
	 */
	public function step(
		Transform $pose
		, Velocity $velocity
		, Body $body
		, float $dt
		, float $gravityScale = 1
		, ?float $portalAnchorHeight = null
	): void {
		$rest = $this->resting[$pose] ?? null;

		if($rest !== null && $velocity->z == 0 && $body->grounded
			&& $rest[0] === $pose->x && $rest[1] === $pose->y && $rest[2] === $pose->z
			&& $rest[3]->floor === $rest[4] && $rest[3]->ceiling === $rest[5] && $rest[6] === $body->height
		) return;

		unset($this->resting[$pose]);
		$this->integrate($pose, $velocity, $body, $dt, $gravityScale, $portalAnchorHeight);
	}

	private function integrate(
		Transform $pose
		, Velocity $velocity
		, Body $body
		, float $dt
		, float $gravityScale
		, ?float $portalAnchorHeight
	): void {
		$portalAnchorHeight = max(0.0, min($body->height, $portalAnchorHeight ?? $body->height * .5));
		$sector = $this->level->sectorAt($pose->x, $pose->y);
		$floor = $sector->supportZ($pose->x, $pose->y, $pose->z, $body->grounded ? $this->maxStep : 0);
		$ceiling = $sector->headroomZ($pose->x, $pose->y, max($floor, $pose->z));
		$water = $this->level->waterSurface($pose->x, $pose->y, $pose->z, $body->height);
		if($water !== null)
		{
			// Buoyancy and drag replace the dry initial-fall impulse. Movement supplies
			// swimming thrust; actors without input slowly settle through the liquid.
			$gravityScale *= .08;
			$velocity->z *= pow(.8, max(0, $dt) * 35);
		}
		$floorPortal = $this->level->cameraPortalForPlane($sector->index, 0)?->link;
		$ceilingPortal = $this->level->cameraPortalForPlane($sector->index, 1)?->link;
		// A portal replaces only the sector's own plane. A solid 3D floor may
		// supply a nearer support or headroom plane and must still stop the body.
		$passableFloor = ($floorPortal?->passable() ?? false)
			&& abs($floor - $sector->floorZ($pose->x, $pose->y)) < .001
			&& $this->level->boundsWithinCameraPortalPlane($pose->x, $pose->y, $body->radius, $sector->index, 0);

		$passableCeiling = ($ceilingPortal?->passable() ?? false)
			&& abs($ceiling - $sector->ceilingZ($pose->x, $pose->y)) < .001
			&& $this->level->boundsWithinCameraPortalPlane($pose->x, $pose->y, $body->radius, $sector->index, 1);

		if($floorPortal && !$passableFloor)
		{
			foreach($this->footprintSectors($pose, $body) as $support)
			$floor = max($floor, $support->floorZ($pose->x, $pose->y));
		}

		if($ceilingPortal && !$passableCeiling)
		{
			foreach($this->footprintSectors($pose, $body) as $boundary)
			$ceiling = min($ceiling, $boundary->ceilingZ($pose->x, $pose->y));
		}

		if(!$passableFloor && $body->grounded && $velocity->z <= 0
			&& ($floor > $pose->z || $sector->floorSlope !== [0.0,0.0,0.0])
			&& abs($floor - $pose->z) <= $this->maxStep
			&& $ceiling - $floor >= $body->height
		){
			$pose->z = $floor;
			$velocity->z = 0;
		}

		if(!$passableFloor && $pose->z <= $floor + .01 && $velocity->z <= 0)
		{
			$pose->z = $floor;
			$velocity->z = 0;
			$body->grounded = true;
		}
		else
		{
			$elapsed = max(0, $dt);

			if($water === null && abs($velocity->z) < 1e-9 && $gravityScale > 0)
			$velocity->z = DoomGravity::initialVelocity($this->acceleration, $gravityScale);
			else $velocity->z -= $this->acceleration * $gravityScale * $elapsed;

			$pose->z += $velocity->z * $elapsed;

			$portalAnchorZ = $pose->z + $portalAnchorHeight;

			if($passableFloor && $portalAnchorZ < $floor)
			{
				$this->crossFloorPortal(
					$pose
					, $velocity
					, $body
					, $floorPortal
					, $portalAnchorHeight
					, $floor - $portalAnchorZ
				);

				return;
			}

			if(!$passableFloor && $pose->z <= $floor)
			{
				$pose->z = $floor;
				$velocity->z = 0;
				$body->grounded = true;
			}
			else $body->grounded = false;
		}

		$highest = $ceiling - $body->height;
		$portalAnchorZ = $pose->z + $portalAnchorHeight;

		if($passableCeiling && $portalAnchorZ > $ceiling)
		{
			$this->crossCeilingPortal(
				$pose
				, $velocity
				, $body
				, $ceilingPortal
				, $portalAnchorHeight
				, $portalAnchorZ - $ceiling
			);

			return;
		}

		if(!$passableCeiling && $pose->z > $highest)
		{
			$pose->z = max($floor, $highest);

			if($velocity->z > 0) $velocity->z = 0;
		}

		// Remember a body left at rest on a plain floor: flat planes, no 3D floors, height transfer, liquid, or portals.
		if($body->grounded && $velocity->z == 0 && $pose->z === $floor && $water === null
			&& !$sector->volumes && $sector->heightTransfer === null && !$this->level->cameraPortalSurfaces()
			&& $sector->floorSlope === [0.0,0.0,0.0] && $sector->ceilingSlope === [0.0,0.0,0.0]
		)
		$this->resting[$pose] = [$pose->x, $pose->y, $pose->z, $sector, $sector->floor, $sector->ceiling, $body->height];
	}

	/** @return list<SectorState> Sectors beneath the corners of the square body. */
	private function footprintSectors(Transform $pose, Body $body): array
	{
		$sectors = [];

		foreach([[-1,-1],[-1,1],[1,-1],[1,1]] as [$dx,$dy])
		{
			$sector = $this->level->sectorAt($pose->x + $dx * $body->radius, $pose->y + $dy * $body->radius);
			$sectors[$sector->index] = $sector;
		}

		return array_values($sectors);
	}

	/** Continue falling below a linked floor at the matching remote ceiling. */
	private function crossFloorPortal(
		Transform $pose
		, Velocity $velocity
		, Body $body
		, CameraPortalLink $portal
		, float $anchorHeight
		, float $depth
	): void {
		[$x,$y] = $portal->point($pose->x, $pose->y);
		$destination = $this->level->sectors[$portal->destinationSector];
		$ceiling = $destination->ceilingZ($x, $y);
		$pose->previousX = $pose->x = $x;
		$pose->previousY = $pose->y = $y;
		$seam = $ceiling - $anchorHeight;
		$pose->previousZ = $seam - self::PORTAL_EXIT_INSET;
		$pose->z = $seam - max(self::PORTAL_EXIT_INSET, $depth);
		$pose->angle = $portal->angle($pose->angle);
		[$velocity->x,$velocity->y] = $portal->vector($velocity->x, $velocity->y);
		$body->grounded = false;
	}

	/** Continue rising above a linked ceiling at the matching remote floor. */
	private function crossCeilingPortal(
		Transform $pose
		, Velocity $velocity
		, Body $body
		, CameraPortalLink $portal
		, float $anchorHeight
		, float $depth
	): void {
		[$x,$y] = $portal->point($pose->x, $pose->y);
		$destination = $this->level->sectors[$portal->destinationSector];
		$floor = $destination->floorZ($x, $y);
		$pose->previousX = $pose->x = $x;
		$pose->previousY = $pose->y = $y;
		$seam = $floor - $anchorHeight;
		$pose->previousZ = $seam + self::PORTAL_EXIT_INSET;
		$pose->z = $seam + max(self::PORTAL_EXIT_INSET, $depth);
		$pose->angle = $portal->angle($pose->angle);
		[$velocity->x,$velocity->y] = $portal->vector($velocity->x, $velocity->y);
		$body->grounded = false;
	}
}

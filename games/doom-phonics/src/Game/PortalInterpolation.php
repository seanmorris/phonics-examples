<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Transform;

/** Rebase replicated render history across disconnected portal map space. */
final class PortalInterpolation
{

	public function __construct(private Level $level) {}

	/**
	 * Move only the previous render endpoint through the portal that best explains
	 * an authoritative position jump. The current pose already came from the host.
	 */
	public function restore(Transform $pose, float $height): ?CameraPortalLink
	{
		$rawError = self::distanceSquared(
			$pose->previousX
			, $pose->previousY
			, $pose->previousZ
			, $pose->x
			, $pose->y
			, $pose->z
		);

		if($rawError < 64 * 64) return null;

		$previousSector = $this->level->map->sectorAt($pose->previousX, $pose->previousY);
		$currentSector = $this->level->map->sectorAt($pose->x, $pose->y);
		$best = null;
		$bestPoint = null;
		$bestError = $rawError;

		foreach($this->level->cameraPortalLinks() as $link)
		{
			if(!$link->passable()
				|| !isset($this->level->map->connectedSectors($link->originSector)[$previousSector])
				|| !isset($this->level->map->connectedSectors($link->destinationSector)[$currentSector])
			) continue;

			[$x,$y] = $link->point($pose->previousX, $pose->previousY);
			$z = $link->bodyZ($pose->previousZ, $height, $this->level);
			$error = self::distanceSquared($x, $y, $z, $pose->x, $pose->y, $pose->z);

			if($error >= $bestError) continue;

			$best = $link;
			$bestPoint = [$x,$y,$z];
			$bestError = $error;
		}

		// A normal correction can happen near portal-connected sectors. Rebase only
		// when the portal transform explains at least three quarters of the jump.
		if(!$best || !$bestPoint || $bestError * 4 >= $rawError) return null;

		[$pose->previousX,$pose->previousY,$pose->previousZ] = $bestPoint;

		return $best;
	}

	private static function distanceSquared(
		float $ax
		, float $ay
		, float $az
		, float $bx
		, float $by
		, float $bz
	): float {
		return ($bx - $ax) ** 2 + ($by - $ay) ** 2 + ($bz - $az) ** 2;
	}
}

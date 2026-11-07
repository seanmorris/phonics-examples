<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;

/**
 * Doom's vertical autoaim (`P_AimLineAttack` and `PTR_AimTraverse`).
 *
 * The aim ray starts with a window of reachable slopes. Every two-sided line it crosses narrows that window to the
 * line's opening wherever the two sides' floors or ceilings differ, so a ledge or lintel clips which part of a target
 * can still be aimed at. The first shootable body left inside the window supplies the slope through the middle of its
 * reachable part. A solid wall, a closed opening, or an empty window ends the search.
 */
final class AutoAim
{
	/** The reference window spans 100 of the 160 pixels above or below the view centre. */
	public const HALF_WINDOW = 100 / 160;
	/** `P_BulletSlope` retries 1 << 26 binary-angle units, 5.625 degrees, to either side. */
	public const SIDE_SEARCH = M_PI / 32;
	/** `P_BulletSlope` and `P_SpawnPlayerMissile` search 16 * 64 map units. */
	public const RANGE = 1024.0;

	public function __construct(private Level $level) {}

	/**
	 * @param float $pitch Freelook pitch the window is centred on; 0 reproduces the reference window exactly.
	 * @param array<int,array{0:Transform,1:Body}> $targets Shootable bodies by entity, without the shooter.
	 * @return array{target:int,slope:float}|null
	 */
	public function aim(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $range
		, float $pitch
		, array $targets
	): ?array {
		$dx = cos($angle);
		$dy = sin($angle);
		$top = tan($pitch + atan(self::HALF_WINDOW));
		$bottom = tan($pitch - atan(self::HALF_WINDOW));
		$crossings = [];

		foreach($this->level->spatial->cast($x, $y, $dx, $dy, $range) as $hit)
		$crossings[] = [$hit['distance'], 0, $hit];

		foreach($targets as $entity => [$pose, $body])
		{
			$offsetX = $pose->x - $x;
			$offsetY = $pose->y - $y;
			$along = $offsetX * $dx + $offsetY * $dy;

			// The reference intercepts a thing on its cross-section through the centre.
			if($along <= 0 || $along > $range || abs($offsetX * $dy - $offsetY * $dx) > $body->radius) continue;

			$crossings[] = [$along, 1, $entity];
		}

		// Lines at the same distance as a body restrict the window before the body is tested.
		usort($crossings, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

		foreach($crossings as [$distance, $kind, $item])
		{
			if($kind === 0)
			{
				$line = $item['line'];
				$portal = $this->level->portal($line);

				// Camera portals transform the ray; leave those shots to freelook instead of aiming across them.
				if($portal === null || $this->level->cameraPortalForLine($line) !== null) return null;

				$right = $this->level->sectors[$portal->rightSector];
				$left = $this->level->sectors[$portal->leftSector];
				$floor = $portal->floor($item['x'], $item['y']);
				$ceiling = $portal->ceiling($item['x'], $item['y']);

				if($floor >= $ceiling) return null;

				if(abs($right->floorZ($item['x'], $item['y']) - $left->floorZ($item['x'], $item['y'])) > 1e-6)
				$bottom = max($bottom, ($floor - $z) / $distance);

				if(abs($right->ceilingZ($item['x'], $item['y']) - $left->ceilingZ($item['x'], $item['y'])) > 1e-6)
				$top = min($top, ($ceiling - $z) / $distance);

				if($top <= $bottom) return null;

				continue;
			}

			[$pose, $body] = $targets[$item];
			$targetTop = ($pose->z + $body->height - $z) / $distance;
			$targetBottom = ($pose->z - $z) / $distance;

			if($targetTop < $bottom || $targetBottom > $top) continue;

			return ['target' => $item, 'slope' => (min($targetTop, $top) + max($targetBottom, $bottom)) / 2];
		}

		return null;
	}
}

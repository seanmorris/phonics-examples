<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Register ZDoom camera things as structural map objects. */
final class HexenCameraObjectSetup
{

	public static function structuralThing(int $type): bool
	{
		return in_array($type, [CameraViewpoint::SECURITY,CameraViewpoint::MOVING,CameraViewpoint::AIMING], true);
	}

	public static function apply(Level $level): void
	{
		foreach($level->map->things as $thing)
		{
			$type = (int)$thing['type'];

			if(!self::structuralThing($type)) continue;

			$x = (float)$thing['x'];
			$y = (float)$thing['y'];
			$sector = $level->sectorAt($x, $y);
			$pitchByte = (int)($thing['args'][0] ?? 0);
			$pitch = deg2rad($pitchByte > 127 ? $pitchByte - 256 : $pitchByte);

			$level->addCamera(
				new CameraViewpoint(
					(int)($thing['id'] ?? 0)
					, $type
					, $x
					, $y
					, $sector->floorZ($x, $y) + (float)($thing['z'] ?? 0)
					, deg2rad((float)$thing['angle'])
					, $pitch
					, $sector->index
					, $thing['args'] ?? []
				)
			);
		}
	}
}

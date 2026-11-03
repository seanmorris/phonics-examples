<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/**
 * Load Hexen 160/209 and their classic-format equivalents into live sector state.
 *
 * Control lines use their front sector. These are startup definitions, never
 * repeatable player actions; a volume and transferred heights cannot share a target.
 */
final class SectorVolumeSetup
{
	/** Attach model references after slopes and other structural specials are ready. */
	public static function apply(Level $level): void
	{
		foreach($level->map->linedefs as $line)
		{
			$action = $line['action'];
			$args = $line['args'] ?? [0,0,0,0,0];
			if($level->map->format !== 'HEXEN')
			{
				$tag = $line['tag'];
				$converted = match($action) {
					242 => [209,[$tag,0]], 280 => [209,[$tag,12]],
					350 => [209,[$tag,2]], 351 => [209,[$tag,6]],
					281,400 => [160,[$tag,1,0,255]], 289 => [160,[$tag,1,1,255]],
					300 => [160,[$tag,1,1,127]], 301 => [160,[$tag,2,2,127]],
					302 => [160,[$tag,3,6,127]], 303 => [160,[$tag,3,0,0]],
					304,403 => [160,[$tag,2,2,255]], 305 => [160,[$tag,3,2,0]],
					306 => [160,[$tag,1,0,0]], 332 => [160,[$tag,4,0,0]],
					401 => [160,[$tag,1,16,255]], 402 => [160,[$tag,1,32,255]],
					404,405,406,407,408 => [160,[$tag,2,2,(408 - $action) * 51]],
					413,414,415,416,417 => [160,[$tag,1,8,(418 - $action) * 51]],
					default => null
				};
				if(!$converted) continue;
				[$action,$args] = $converted;
			}
			if(!in_array($action, [160,209], true) || $line['right'] < 0) continue;
			$side = $level->sideTextures[$line['right']];
			$model = $level->sectors[$side['sector']];
			$tag = $args[0];
			if($action === 160 && ($args[1] & 8) && ($args[4] ?? 0)) $level->addLineId($args[4], $line['index']);
			if($action === 160 && !($args[1] & 8)) $tag |= ($args[4] ?? 0) << 8;
			foreach($level->tags[$tag] ?? [] as $id)
			{
				$sector = $level->sectors[$id];
				if($sector === $model) continue;
				if($action === 209) $sector->heightTransfer = new HeightTransfer($model, $args[1], $side);
				else $sector->volumes[] = new SectorVolume($model, $line['index'], $args[1], $args[2], $args[3], $side['middle']);
			}
		}
		foreach($level->sectors as $sector)
		if($sector->volumes) $sector->heightTransfer = null;
		foreach($level->map->things as $thing)
		if($thing['type'] === 9045) $level->sectorAt($thing['x'], $thing['y'])->waterZone = true;
	}
}

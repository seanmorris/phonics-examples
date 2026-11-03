<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Static argument-based map specials. Never dispatch these as classic Doom numbers. */
final class HexenMapSetup
{

	public static function apply(Level $level): void
	{
		HexenCameraPortalSetup::apply($level);
		HexenCameraObjectSetup::apply($level);

		foreach($level->map->linedefs as $line)
		{
			$args = $line['args'];
			$sides = $level->sides($line['index']);

			if($line['action'] === 182 && ($sides['front'] !== null) !== ($sides['back'] !== null))
			{
				$level->addMirror(new MirrorView($line['index'], $sides['front'] ?? $sides['back']));
				continue;
			}

			if($line['action'] === 181 && $sides['front'] !== null && $sides['back'] !== null)
			{
				foreach(['floor','ceiling'] as $plane => $name)
				{
					$selection = $args[$plane];

					if($selection !== 1 && $selection !== 2) continue;

					$target = $sides[$selection === 1 ? 'front' : 'back'];
					$neighbor = $sides[$selection === 1 ? 'back' : 'front'];
					self::align($level, $line, $target, $neighbor, $name);
				}
			}
			elseif(in_array($line['action'], [210,211], true) && $sides['front'] !== null)
			{
				$field = $line['action'] === 210 ? 'floorLightSource' : 'ceilingLightSource';

				foreach($level->tags[$args[0]] ?? [] as $id) $level->sectors[$id]->$field = $sides['front'];
			}
		}
	}

	public static function structuralThing(int $type): bool
	{
		return $type === 9045 || HexenCameraPortalSetup::structuralThing($type) || HexenCameraObjectSetup::structuralThing($type);
	}

	/** Anchor the shared edge to the neighbor and retain the farthest target vertex's height. */
	private static function align(Level $level, array $line, int $target, int $neighbor, string $plane): void
	{
		$a = $level->map->vertices[$line['from']];
		$b = $level->map->vertices[$line['to']];
		$nx = $a['y'] - $b['y'];
		$ny = $b['x'] - $a['x'];
		$distance = 0.0;

		foreach($level->map->sectorLines[$target] ?? [] as $id)
		foreach(['from','to'] as $end)
		{
			$v = $level->map->vertices[$level->map->linedefs[$id][$end]];
			$d = $nx * ($v['x'] - $a['x']) + $ny * ($v['y'] - $a['y']);

			if(abs($d) > abs($distance)) $distance = $d;
		}

		if(abs($distance) < .001) return;

		$base = $level->sectors[$target]->$plane;
		$edge = $level->sectors[$neighbor]->$plane;
		$scale = ($base - $edge) / $distance;
		$dx = $nx * $scale;
		$dy = $ny * $scale;
		$field = $plane . 'Slope';
		$level->sectors[$target]->$field = [$dx,$dy,$edge - $base - $dx * $a['x'] - $dy * $a['y']];
	}
}

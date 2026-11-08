<?php
declare(strict_types=1);

use DoomPhonics\Game\Level;
use DoomPhonics\Game\SightCheck;
use DoomPhonics\Render\VisibleSectors;

// The sprite pass draws actors only from sectors Doom's BSP walk reaches. That walk must reach every sector holding
// an actor the player can see, and on a real map it should reach few sectors from most viewpoints.
(static function() use ($stack): void {
	mt_srand(7);

	foreach(['E1M1', 'E1M3'] as $name)
	{
		$level = new Level($stack->map($name));
		$walk = new VisibleSectors($level);
		$sight = new SightCheck($level);
		verifyWad($walk->available(), "$name has the BSP the visibility walk needs");

		$things = array_values(array_filter(
			$level->map->things
			, static fn(array $thing): bool => in_array((int)$thing['type'], [1, 9, 3001, 3002, 3004, 2035, 2014, 2007], true)
		));

		$sizes = [];
		$missed = $checked = $ownMissing = 0;
		$halfFov = deg2rad(45);

		foreach(range(1, 200) as $probe)
		{
			$camera = $things[mt_rand(0, count($things) - 1)];
			$angle = mt_rand(0, 628) / 100;
			[$cx, $cy] = [(float)$camera['x'], (float)$camera['y']];
			$set = $walk->from($cx, $cy, $angle, 90);
			$sizes[] = count($set);
			$eye = $level->sectorAt($cx, $cy)->floor + 41;

			if(!isset($set[$level->map->sectorAt($cx, $cy)])) $ownMissing++;

			foreach($things as $other)
			{
				$dx = $other['x'] - $cx;
				$dy = $other['y'] - $cy;
				$depth = $dx * cos($angle) + $dy * sin($angle);
				$side = $dx * sin($angle) - $dy * cos($angle);

				if($depth <= 1 || abs($side) > $depth * tan($halfFov)) continue;

				$floor = $level->sectorAt((float)$other['x'], (float)$other['y'])->floor;

				if(!$sight->sees($cx, $cy, $eye, (float)$other['x'], (float)$other['y'], $floor, $floor + 56)) continue;

				$checked++;

				if(!isset($set[$level->map->sectorAt((float)$other['x'], (float)$other['y'])])) $missed++;
			}
		}

		sort($sizes);
		verifyWad($checked > 200 && $missed === 0, "$name: the walk reaches every sector with a visible actor ($checked checked)");
		verifyWad($ownMissing === 0, "$name: the walk always reaches the camera's own sector");
		verifyWad(
			$sizes[100] <= count($level->sectors) / 5
			, "$name: the median viewpoint reaches at most a fifth of the sectors ({$sizes[100]} of " . count($level->sectors) . ')'
		);
	}

	// A closed door hides what is behind it; opening it reveals the room. E1M3's alcove looks through door 116.
	$level = new Level($stack->map('E1M3'));
	$walk = new VisibleSectors($level);
	$level->sectors[116]->ceiling = 136.0;
	verifyWad(!isset($walk->from(-1760.0, -1864.0, 0.0, 90)[32]), 'a closed door hides the room behind it');
	$level->sectors[116]->ceiling = 172.0;
	verifyWad(isset($walk->from(-1760.0, -1864.0, 0.0, 90)[32]), 'an open door reveals the room behind it');
	verifyWad(!isset($walk->from(-1760.0, -1864.0, M_PI, 90)[32]), 'facing away from the door hides the room again');
})();

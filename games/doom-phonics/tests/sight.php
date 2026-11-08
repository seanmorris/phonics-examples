<?php
declare(strict_types=1);

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\ProjectileTracer;

// Sight and hitscan traces once tested every linedef in their path's bounding box. On E1M3 that cost about 500 us
// per monster A_Look. The cheaper candidate set and the side test that skips lines must never drop a line the
// capsule can touch, so traces report exactly the same impacts.
(static function() use ($stack): void {
	$segmentDistance = static function(array $p, array $q, array $a, array $b): float {
		$point = static function(array $p, array $a, array $b): float {
			[$vx, $vy] = [$b[0] - $a[0], $b[1] - $a[1]];
			$length = $vx * $vx + $vy * $vy;
			$t = $length > 0 ? max(0, min(1, (($p[0] - $a[0]) * $vx + ($p[1] - $a[1]) * $vy) / $length)) : 0;

			return hypot($p[0] - $a[0] - $t * $vx, $p[1] - $a[1] - $t * $vy);
		};
		$cross = static fn(array $o, array $a, array $b): float => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);

		if($cross($p, $q, $a) * $cross($p, $q, $b) < 0 && $cross($a, $b, $p) * $cross($a, $b, $q) < 0) return 0.0;

		return min($point($p, $a, $b), $point($q, $a, $b), $point($a, $p, $q), $point($b, $p, $q));
	};

	mt_srand(1993);
	$missing = $checked = $saved = 0;
	$capsule = new ReflectionMethod(ProjectileTracer::class, 'capsuleHit');

	foreach(['E1M1', 'E1M3'] as $name)
	{
		$level = new Level($stack->map($name));
		$tracer = new ProjectileTracer($level);
		[$minX, $minY, $maxX, $maxY] = [$level->map->bounds['minX'] ?? -4096, $level->map->bounds['minY'] ?? -4096, $level->map->bounds['maxX'] ?? 4096, $level->map->bounds['maxY'] ?? 4096];

		foreach(range(1, 300) as $probe)
		{
			$p = [mt_rand((int)$minX, (int)$maxX) + .5, mt_rand((int)$minY, (int)$maxY) + .25];
			$d = [mt_rand(-2500, 2500) + .125, mt_rand(-2500, 2500)];
			$q = [$p[0] + $d[0], $p[1] + $d[1]];
			$radius = [0, 0, 8, 16, 20, 64][mt_rand(0, 5)];
			$swept = array_flip($level->spatial->swept($p[0], $p[1], $d[0], $d[1], $radius));

			foreach($level->map->linedefs as $index => $line)
			{
				$a = $level->map->vertices[$line['from']];
				$b = $level->map->vertices[$line['to']];

				if($segmentDistance($p, $q, [$a['x'], $a['y']], [$b['x'], $b['y']]) > $radius) continue;

				$checked++;

				if(!isset($swept[$index])) $missing++;
			}

			$saved += count($level->spatial->near(min($p[0], $q[0]) - $radius, min($p[1], $q[1]) - $radius, max($p[0], $q[0]) + $radius, max($p[1], $q[1]) + $radius)) - count($swept);
		}
	}

	verifyWad($checked > 500 && $missing === 0, "swept cells hold every line a capsule can touch ($checked contacts checked)");
	verifyWad($saved > 0, 'swept cells test fewer lines than the path bounding box');

	// The side test in wall() may skip a line only when capsuleHit could not report a contact.
	$level = new Level($stack->map('E1M3'));
	$tracer = new ProjectileTracer($level);
	$false = 0;

	foreach(range(1, 4000) as $probe)
	{
		$px = mt_rand(-200, 200) + .5;
		$py = mt_rand(-200, 200) + .25;
		$dx = mt_rand(-300, 300) + .125;
		$dy = mt_rand(-300, 300) + .5;
		$radius = [0, 16, 20][mt_rand(0, 2)];
		[$ax, $ay, $bx, $by] = [mt_rand(-400, 400), mt_rand(-400, 400), mt_rand(-400, 400), mt_rand(-400, 400)];
		$length = hypot($dx, $dy);
		[$ux, $uy] = [$dx / $length, $dy / $length];
		$margin = $radius + .001;
		$sideA = ($ax - $px) * $uy - ($ay - $py) * $ux;
		$sideB = ($bx - $px) * $uy - ($by - $py) * $ux;
		$alongA = ($ax - $px) * $ux + ($ay - $py) * $uy;
		$alongB = ($bx - $px) * $ux + ($by - $py) * $uy;
		$skipped = ($sideA > $margin && $sideB > $margin) || ($sideA < -$margin && $sideB < -$margin)
			|| ($alongA < -$margin && $alongB < -$margin) || ($alongA > $length + $margin && $alongB > $length + $margin);

		if($skipped && $capsule->invoke($tracer, $px, $py, $dx, $dy, (float)$ax, (float)$ay, (float)$bx, (float)$by, (float)$radius) !== null)
		$false++;
	}

	verifyWad($false === 0, 'the wall side test never skips a line the capsule touches');

	// P_CheckSight port: a BSP walk from the looker's eyes to any part of the target, reading live sector heights.
	$level = new Level($stack->map('E1M3'));
	$sight = new DoomPhonics\Game\SightCheck($level);
	verifyWad($sight->classic(), 'E1M3 has the classic geometry P_CheckSight describes');

	// The E1M3 alcove (floor 104) looks through door 116 (floor 136) into room 32 (floor 64).
	$looks = static fn(): bool => $sight->sees(-1760.0, -1864.0, 104 + 42, -1450.0, -1864.0, 64, 120);
	$level->sectors[116]->ceiling = 136.0;
	verifyWad(!$looks(), 'a closed door blocks sight');
	$level->sectors[116]->ceiling = 172.0;
	verifyWad($looks(), 'the open door passes sight over its ledge to the top of a target below');
	verifyWad(
		!$sight->sees(-1760.0, -1864.0, 104 + 42, -1700.0, -1864.0, 64, 120)
		, 'a target wholly below the ledge from the looker is hidden'
	);

	// Whenever the tracer finds a clear line from the eyes to part of the target, the port must see it too.
	mt_srand(666);
	$tracer = new ProjectileTracer($level, true);
	$things = array_values(array_filter($level->map->things, static fn(array $thing): bool => in_array((int)$thing['type'], [1, 9, 3001, 3002, 3004, 2035], true)));
	$missed = $compared = $clearCount = 0;

	foreach(range(1, 1500) as $probe)
	{
		$a = $things[mt_rand(0, count($things) - 1)];
		$b = $things[mt_rand(0, count($things) - 1)];

		if($a === $b) continue;

		$az = $level->sectorAt((float)$a['x'], (float)$a['y'])->floor;
		$bz = $level->sectorAt((float)$b['x'], (float)$b['y'])->floor;
		$eye = $az + 42;
		$clear = false;

		foreach([.05, .5, .95] as $part)
		{
			$hit = $tracer->trace(new Transform((float)$a['x'], (float)$a['y'], $eye), new Body(0, 0), $b['x'] - $a['x'], $b['y'] - $a['y'], $bz + 56 * $part - $eye);
			$clear = $clear || $hit === null || $hit->fraction >= .999;
		}

		$compared++;
		$clearCount += (int)$clear;

		if($clear && !$sight->sees((float)$a['x'], (float)$a['y'], $eye, (float)$b['x'], (float)$b['y'], $bz, $bz + 56)) $missed++;
	}

	verifyWad(
		$compared > 1200 && $clearCount >= 40 && $missed === 0
		, "P_CheckSight sees every line the tracer finds clear ($clearCount clear of $compared pairs)"
	);

	// E1M3 ships a full REJECT table. P_CheckSight trusts its bits before tracing; a map without one, or with one too
	// short for its sectors, never hides anything.
	$map = $stack->map('E1M3');
	$count = count($map->sectors);
	$pair = null;

	for($from = 0; $from < $count && $pair === null; $from++)
	for($to = 0; $to < $count; $to++)
	if($map->rejects($from, $to)) { $pair = [$from, $to]; break; }

	verifyWad($pair !== null, 'E1M3 rejects some sector pairs');
	$full = $map->reject;
	$map->reject = substr($full, 0, -1);
	verifyWad(!$map->rejects(...$pair), 'a REJECT too short for the map is ignored');
	$map->reject = '';
	verifyWad(!$map->rejects(...$pair), 'a map without REJECT rejects nothing');
	$map->reject = $full;
})();

<?php
declare(strict_types=1);

// Fairness sweep: the built-in player, which never taps faster than five times a second, must survive every seeded
// course. Run as `php games/phlappy-phant/tests/fairness.php [seeds] [seconds]`; the defaults take about two minutes.
require dirname(__DIR__) . '/autoload.php';

use PhlappyPhant\Simulation;

$seeds = max(1, (int)($argv[1] ?? 40));
$steps = (int)round(max(1, (float)($argv[2] ?? 90)) / Simulation::STEP);
$failures = 0;
$tightest = INF;
$quickest = PHP_INT_MAX;
$lowest = PHP_INT_MAX;

for($seed = 1; $seed <= $seeds; $seed++)
{
	$game = new Simulation($seed);
	$game->autopilot = true;
	$lastFlap = null;

	for($step = 1; $step <= $steps && $game->phase !== 'dead'; $step++)
	{
		$game->step();

		if(in_array('flap', $game->events, true))
		{
			if($lastFlap !== null) $quickest = min($quickest, $step - $lastFlap);

			$lastFlap = $step;
		}

		// Clearance to the nearer edge of any gap the hitbox is inside.
		[$left, $top, $width, $height] = $game->hitbox();

		foreach($game->pillars as $pillar)
		{
			if($pillar->x >= $left + $width || $pillar->x + Simulation::PILLAR_W <= $left) continue;

			$tightest = min($tightest, $top - $pillar->gapTop, $pillar->gapTop + $pillar->gap - $top - $height);
		}
	}

	if($game->phase === 'dead')
	{
		$failures++;
		fwrite(STDERR, "Seed $seed bonked at step $step with {$game->score} points.\n");

		continue;
	}

	$lowest = min($lowest, $game->score);
}

$quickestMs = $quickest === PHP_INT_MAX ? 0 : (int)round($quickest * Simulation::STEP * 1000);

if($failures || $quickest < Simulation::TAS_FLAP_GAP)
{
	fwrite(STDERR, "Phlappy Phant fairness: $failures of $seeds courses failed; closest taps $quickestMs ms apart.\n");

	exit(1);
}

printf(
	"Phlappy Phant fairness: %d courses flown for %d seconds each; lowest score %d, closest taps %d ms apart, tightest clearance %.1f units.\n"
	, $seeds
	, (int)round($steps * Simulation::STEP)
	, $lowest
	, $quickestMs
	, $tightest
);

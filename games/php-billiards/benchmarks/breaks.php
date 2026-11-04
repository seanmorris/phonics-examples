<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\ContactModel;
use PhpBilliards\Physics\Simulation;

// Diagnostic distributions, never a target pot count. --quick repeats the original 24-seed investigation.
$quick = in_array('--quick', $argv, true);
$rows = [];
$histogram = [];
$start = hrtime(true);
for($seedIndex = 0; $seedIndex < ($quick ? 24 : 64); $seedIndex++)
foreach($quick ? [0] : [-.5,-.1,0,.1,.5] as $angle)
foreach($quick ? [1.0] : [.58,.8,1.0] as $power)
{
	$seed = hash('sha256', 'billiards-break-diagnosis-' . $seedIndex);
	$s = new Simulation($seed);
	$s->shoot(deg2rad($angle), $power);
	$initial = $s->snapshot();
	$energy = static function(Simulation $s): float {
		$total = ContactModel::elasticEnergy($s->balls);
		foreach($s->balls as $b) if($b->active)
		$total += Ball::MASS * (.5 * ($b->vx ** 2 + $b->vy ** 2 + $b->vDrop ** 2)
			+ .2 * Ball::RADIUS ** 2 * ($b->spinX ** 2 + $b->spinY ** 2 + $b->spinZ ** 2)
			+ ContactModel::GRAVITY * (.115 - $b->drop));
		return $total;
	};
	$launch = $energy($s); $peak = $launch; $overlap = 0.0;
	for($tick = 0; $tick < 2400 && $s->moving(); $tick++)
	{
		$s->step(); $s->consumeEvents(); $peak = max($peak, $energy($s));
		foreach($s->balls as $i => $a) if($a->active)
		{
			if(!is_finite($a->x + $a->y + $a->drop + $a->vx + $a->vy + $a->vDrop)) throw new RuntimeException('Nonfinite ball state');
			foreach(array_slice($s->balls, $i + 1) as $b) if($b->active)
			$overlap = max($overlap, 2 * Ball::RADIUS - sqrt(($a->x - $b->x) ** 2 + ($a->y - $b->y) ** 2 + ($a->drop - $b->drop) ** 2));
		}
	}
	if($s->moving() || $overlap > .002 || $peak > $launch * 1.002)
	throw new RuntimeException("Invalid break seed=$seedIndex aim=$angle power=$power ticks=$tick overlap=$overlap energy=" . $peak / $launch);
	$sunk = array_values(array_map(static fn(Ball $b): int => $b->number, array_filter($s->balls, static fn(Ball $b): bool => !$b->active && $b->number !== 0)));
	$histogram[count($sunk)] = ($histogram[count($sunk)] ?? 0) + 1;
	$rows[] = ['seedIndex' => $seedIndex,'angle' => $angle,'power' => $power,'sunk' => $sunk,'scratch' => $s->scratched,
		'seconds' => $tick / 60,'maxCompression' => $overlap,'energyRatio' => $peak / $launch,'hash' => $s->exactHash()];
	if($seedIndex === 0 && $angle == 0 && $power == 1)
	{
		$replay = new Simulation; $replay->restore($initial);
		while($replay->moving()) {$replay->step(); $replay->consumeEvents();}
		if($s->exactHash() !== $replay->exactHash()) throw new RuntimeException('Recorded seed did not replay exactly');
	}
}
ksort($histogram);
echo json_encode(['cases' => count($rows),'potHistogram' => $histogram,'elapsedSeconds' => (hrtime(true) - $start) / 1e9,'results' => $rows], JSON_PRETTY_PRINT), "\n";

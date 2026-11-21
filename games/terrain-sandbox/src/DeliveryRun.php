<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** One box in transit at a time. Each leg begins outside the driver's view. */
final class DeliveryRun
{
	public const HEIGHT = 108.0;
	public const RADIUS = 18.0;
	public const MAX_LEG = 4500.0;
	public const COLORS = ['pickup'=>'#caff18', 'drop'=>'#ff3528', 'checkpoint'=>'#ffcc54'];

	public bool $carrying = false;
	public int $delivered = 0;
	public ?array $target = null;
	private ?\Generator $search = null;
	private int $attempt = 0;
	private float $phase;
	private ?array $waitingAt = null;

	public function __construct()
	{
		$this->phase = random_int(0, 65535) * (2*M_PI/65536);
	}

	/** An edit can invalidate either an established site or a search still sampling the old terrain. */
	public function relocate(): void
	{
		$this->search = null;
		$this->target = null;
		$this->waitingAt = null;
		$this->attempt = 0;
	}

	public static function visible(array $site, Camera $camera, float $aspect): bool
	{
		$r = self::RADIUS;
		return Camera::inView($camera->clipPlanes(max(1,(int)ceil(4096*$aspect)),4096), $camera->position,
			['x'=>$site['x']-$r,'z'=>$site['z']-$r,'size'=>2*$r], $site['y']-2, $site['y']+self::HEIGHT+$r);
	}

	public function step(Landscape $land, float $water, Camera $camera, float $aspect, array $from, array $to): ?string
	{
		if($this->target === null)
		{
			if($this->waitingAt !== null)
			{
				[$x,$z,$yaw,$oldAspect] = $this->waitingAt;
				if(hypot($to['x']-$x,$to['z']-$z) < 8 && cos($camera->yaw-$yaw) > .98 && $oldAspect === $aspect)
					return null;
				$this->waitingAt = null;
			}
			// Limit cold height samples per tick, even if an entire shoreline rejects every candidate.
			if($this->search === null) $this->search = $this->candidate($land,$water,$to,$camera,$aspect);
			if($this->search->valid()) { $this->search->next(); return null; }
			$site = $this->search->getReturn();
			$this->search = null;
			if($site === null || self::visible($site,$camera,$aspect)
				|| hypot($site['x']-$to['x'],$site['z']-$to['z']) > self::MAX_LEG)
			{
				if($this->attempt % 96 === 0)
				{
					$this->waitingAt = [$to['x'],$to['z'],$camera->yaw,$aspect];
					return 'No dry site out of view here. Drive on or turn the camera to search again.';
				}
				return null;
			}
			$kind = $this->carrying ? 'drop' : 'pickup';
			$this->target = $site + ['kind'=>$kind,'color'=>self::COLORS[$kind]];
			$this->attempt = 0;
			return $this->carrying ? 'Deliver the box to the red smoke.' : 'Drive into the box under the lime smoke.';
		}

		if($from['recoveries'] !== $to['recoveries']) return null;
		$x = $this->target['x']-$from['x']; $z = $this->target['z']-$from['z'];
		$dx = $to['x']-$from['x']; $dz = $to['z']-$from['z'];
		$t = max(0., min(1., ($x*$dx+$z*$dz)/max(1e-9,$dx*$dx+$dz*$dz)));
		if(hypot($x-$t*$dx,$z-$t*$dz) > ($this->carrying ? 6.0 : 3.0)
			|| abs($from['y']+$t*($to['y']-$from['y'])-$this->target['y']) > 3.0) return null;
		if($this->carrying) $this->delivered++;
		$this->carrying = !$this->carrying;
		$this->phase += 1.79;
		$this->relocate();
		return $this->carrying ? 'Box collected. Finding a drop site…' : 'Delivered! Finding the next box…';
	}

	private function candidate(Landscape $land, float $water, array $car, Camera $camera, float $aspect): \Generator
	{
		$recipe = $land->recipe();
		$half = $recipe['worldSize']/2;
		$limit = $half - 6;
		$spacing = $recipe['spacing'];
		$i = ++$this->attempt;
		// Sample all bearings, and shorten the search radius in a tight valley or on a small island.
		$angle = $this->phase + 2.39996323*$i;
		$range = min(self::MAX_LEG, $limit*.85) / (1 + intdiv(($i-1)%96,12));
		$distance = max(12., $range*(.33+.67*fmod($i*.75487767,1.)));
		$x = round(($car['x']+sin($angle)*$distance)/$spacing)*$spacing;
		$z = round(($car['z']+cos($angle)*$distance)/$spacing)*$spacing;
		if(abs($x)>$limit || abs($z)>$limit || hypot($x-$car['x'],$z-$car['z']) > self::MAX_LEG) return null;
		$y = $land->height($x,$z);
		$site = ['x'=>$x,'y'=>$y,'z'=>$z];
		if($y < $water+1 || abs($y-$car['y']) > max(20.,$distance*.12) || self::visible($site,$camera,$aspect))
			return null;
		yield;
		$surface = $land->sample($x,$z);
		if(hypot($surface['dx'],$surface['dz']) > .25) return null;
		// Screen the approach for water and cliff steps. Lattice samples avoid reconstructing cold patches.
		$steps = max(1,(int)ceil($distance / max(64.,$spacing*2)));
		$last = $car['y'];
		for($j=1; $j<=$steps; $j++)
		{
			yield;
			$t = $j/$steps;
			$px = round(($car['x']+($x-$car['x'])*$t)/$spacing)*$spacing;
			$pz = round(($car['z']+($z-$car['z'])*$t)/$spacing)*$spacing;
			$h = $land->height(max(-$half,min($half,$px)),max(-$half,min($half,$pz)));
			if($h < $water+1 || abs($h-$last) > .5*($distance/$steps+$spacing)) return null;
			$last = $h;
		}
		return $site;
	}
}

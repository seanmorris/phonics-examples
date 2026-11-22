<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Out-and-back deliveries from a single depot. Terrain screens each new box from the driver. */
final class DeliveryRun
{
	public const HEIGHT = 108.0;
	public const RADIUS = 18.0;
	public const MIN_LEG = 1609.344; // One statute mile, measured from the depot.
	public const MAX_LEG = 5600.;
	public const PUFF_COUNT = 36, PUFFS_PER_SECOND = 4;
	private const JUMP_CLEARANCE = 8.0;
	public const COLORS = ['pickup'=>'#caff18', 'drop'=>'#ff3528', 'checkpoint'=>'#ffcc54'];

	public bool $carrying = false;
	public int $delivered = 0;
	public ?array $depot = null;
	private ?array $pickup = null;
	private array $collectedSmoke = [];
	private ?PickupSearch $planner = null;
	private ?\Generator $search = null;
	private bool $parked = true;
	private float $phase;
	private ?array $waitingAt = null;

	public function __construct()
	{
		$this->phase = random_int(0, 65535) * (2*M_PI/65536);
	}

	public function target(): ?array { return $this->carrying ? $this->depot : $this->pickup; }

	public function searchPending(): bool { return $this->search !== null; }

	/** Run a bounded slice. Only the simulation may publish the result against the current car position. */
	public function advanceSearch(float $budgetMs): bool
	{
		if($this->search === null) return false;
		$deadline = hrtime(true) + 1e6*($this->parked ? $budgetMs : min(.9,$budgetMs));
		for($i=0; $i<128 && $this->search->valid() && hrtime(true)<$deadline; $i++) $this->search->next();
		return $this->search->valid();
	}

	/** Active delivery sites shown on the map. */
	public function sites(): array
	{
		if($this->depot === null) return [];
		return $this->pickup === null ? [$this->depot] : [$this->depot,$this->pickup];
	}

	/** Airborne smoke stays at each collection site until its last puff reaches the top. */
	public function beacons(float $time): array
	{
		$beacons = $this->sites();
		$cycle = (int)floor($time * self::PUFFS_PER_SECOND);
		foreach($this->collectedSmoke as $i => [$site, $lastEmission])
		{
			$count = self::PUFF_COUNT - ($cycle - $lastEmission);
			if($count > 0) $beacons[] = ['kind'=>'smoke','puffs'=>$count] + $site;
			else unset($this->collectedSmoke[$i]);
		}
		return $beacons;
	}

	public function refit(Landscape $land, float $water): ?string
	{
		$this->search = null;
		$this->pickup = null;
		$this->waitingAt = null;
		if($this->depot === null) return null;
		$this->depot['y'] = $land->height($this->depot['x'],$this->depot['z']);
		$this->planner = $this->planner?->refitted($land,$water,$this->depot);
		if($this->depot['y'] < $water+.12) return 'The depot is flooded. Lower the water to resume deliveries.';
		return $this->carrying ? 'Return to the red depot.' : 'Finding a package site…';
	}

	/** Frustum culling for rendering the box and its smoke column. */
	public static function beaconInView(array $site, Camera $camera, float $aspect): bool
	{
		$r = self::RADIUS + ($site['kind'] === 'drop' ? 4. : 0.);
		return Camera::inView($camera->clipPlanes(max(1,(int)ceil(4096*$aspect)),4096), $camera->position,
			['x'=>$site['x']-$r,'z'=>$site['z']-$r,'size'=>2*$r], $site['y']-2, $site['y']+self::HEIGHT+$r);
	}

	public function step(Landscape $land, float $water, array $from, array $to, float $time): ?string
	{
		if($this->depot === null)
		{
			if($to['motion'] !== 'driving') return null;
			$this->depot = ['x'=>$to['x'],'z'=>$to['z'],'y'=>$land->height($to['x'],$to['z']),
				'kind'=>'drop','color'=>self::COLORS['drop']];
		}
		if($this->depot['y'] < $water+.12) return null;
		$target = $this->target();
		if($target === null)
		{
			$this->parked = abs($to['speed']) < .25;
			if($this->search === null)
			{
				$retry = $this->waitingAt !== null;
				if($retry)
				{
					[$x,$y,$z] = $this->waitingAt;
					if(hypot($to['x']-$x,$to['z']-$z) < 4 && abs($to['y']-$y)<1) return null;
				}
				$this->waitingAt = [$to['x'],$to['y'],$to['z']];
				$this->planner ??= new PickupSearch($land,$water,$this->depot);
				$this->search = $this->planner->find($to,$this->phase);
				if($retry) return 'Searching for a reachable pickup…';
			}
			if($this->advanceSearch(2.5)) return null;
			$site = $this->search->getReturn();
			$this->search = null;
			$this->phase += 1.79;
			if($site === null) return 'No reachable pickup behind terrain at least one mile from the depot. Drive on or reshape the terrain to search again.';
			$this->waitingAt = null;
			// The driver may have crested the ridge while the route was being checked.
			if(!PickupCover::blocks($land,$to,$site,$site['cover'])
				|| (isset($site['plumeCover']) && !PickupCover::hidesPlume($land,$to,$site,$site['plumeCover']))
				|| hypot($site['x']-$to['x'],$site['z']-$to['z']) > self::MAX_LEG)
				return null;
			unset($site['cover'],$site['plumeCover']);
			$this->planner->placed($site);
			$this->pickup = $site;
			return 'Drive into the box under the lime smoke.';
		}

		if($from['recoveries'] !== $to['recoveries']
			|| !self::crossesSite($from,$to,$target,$this->carrying ? 6.0 : 3.0)) return null;
		if($this->carrying) $this->delivered++;
		else $this->collectedSmoke[] = [$this->pickup, (int)floor($time * self::PUFFS_PER_SECOND)];
		$this->carrying = !$this->carrying;
		$this->pickup = null;
		return $this->carrying ? 'Box collected. Return to the red depot.' : 'Delivered! Finding the next box…';
	}

	/** Clip this step to the site's height range, then find the closest point within that part of the jump. */
	private static function crossesSite(array $from, array $to, array $site, float $radius): bool
	{
		$bottom = $site['y']-3.0;
		$top = $site['y']+self::JUMP_CLEARANCE;
		if(min($from['y'],$to['y']) > $top || max($from['y'],$to['y']) < $bottom) return false;
		$enter = 0.; $exit = 1.;
		$dy = $to['y']-$from['y'];
		if($dy != 0.)
		{
			$atBottom = ($bottom-$from['y'])/$dy; $atTop = ($top-$from['y'])/$dy;
			$enter = max(0.,min($atBottom,$atTop));
			$exit = min(1.,max($atBottom,$atTop));
		}
		$x = $site['x']-$from['x']; $z = $site['z']-$from['z'];
		$dx = $to['x']-$from['x']; $dz = $to['z']-$from['z'];
		$distanceSquared = $dx*$dx+$dz*$dz;
		$t = $distanceSquared > 0. ? max($enter,min($exit,($x*$dx+$z*$dz)/$distanceSquared)) : $enter;
		return hypot($x-$t*$dx,$z-$t*$dz) <= $radius;
	}
}

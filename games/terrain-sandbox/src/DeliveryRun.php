<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Out-and-back deliveries from a single depot. Each new box appears outside the driver's view. */
final class DeliveryRun
{
	public const HEIGHT = 108.0;
	public const RADIUS = 18.0;
	public const MAX_LEG = 5600.;
	private const JUMP_CLEARANCE = 8.0;
	public const COLORS = ['pickup'=>'#caff18', 'drop'=>'#ff3528', 'checkpoint'=>'#ffcc54'];

	public bool $carrying = false;
	public int $delivered = 0;
	public ?array $depot = null;
	private ?array $pickup = null;
	private ?PickupSearch $planner = null;
	private ?\Generator $search = null;
	private float $phase;
	private ?array $waitingAt = null;

	public function __construct()
	{
		$this->phase = random_int(0, 65535) * (2*M_PI/65536);
	}

	public function target(): ?array { return $this->carrying ? $this->depot : $this->pickup; }

	/** Both smoke emitters and chart markers read these same world positions. */
	public function sites(): array
	{
		if($this->depot === null) return [];
		return $this->pickup === null ? [$this->depot] : [$this->depot,$this->pickup];
	}

	public function refit(Landscape $land, float $water): ?string
	{
		$this->search = null;
		$this->planner = null;
		$this->pickup = null;
		$this->waitingAt = null;
		if($this->depot === null) return null;
		$this->depot['y'] = $land->height($this->depot['x'],$this->depot['z']);
		if($this->depot['y'] < $water+.12) return 'The depot is flooded. Lower the water to resume deliveries.';
		return $this->carrying ? 'Return to the red depot.' : 'Finding a package site…';
	}

	public static function visible(array $site, Camera $camera, float $aspect): bool
	{
		$r = self::RADIUS + ($site['kind'] === 'drop' ? 4. : 0.);
		return Camera::inView($camera->clipPlanes(max(1,(int)ceil(4096*$aspect)),4096), $camera->position,
			['x'=>$site['x']-$r,'z'=>$site['z']-$r,'size'=>2*$r], $site['y']-2, $site['y']+self::HEIGHT+$r);
	}

	public function step(Landscape $land, float $water, Camera $camera, float $aspect, array $from, array $to): ?string
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
			if($this->search === null)
			{
				$retry = $this->waitingAt !== null;
				if($retry)
				{
					[$x,$z,$yaw,$pitch,$oldAspect] = $this->waitingAt;
					if(hypot($to['x']-$x,$to['z']-$z) < 4 && cos($camera->yaw-$yaw) > .999
						&& abs($camera->pitch-$pitch)<.04 && $oldAspect === $aspect) return null;
				}
				// Retry when the view changes during a search, even if the driver is parked by the time it finishes.
				$this->waitingAt = [$to['x'],$to['z'],$camera->yaw,$camera->pitch,$aspect];
				$this->planner ??= new PickupSearch($land,$water,$this->depot);
				$this->search = $this->planner->find($to,$camera,$aspect,$this->phase);
				if($retry) return 'Searching for a reachable pickup…';
			}
			// Spend more of a parked frame checking the first route. Keep moving-car slices under a millisecond.
			$parked = abs($to['speed']) < .25;
			$deadline = hrtime(true)+($parked ? 2500000 : 900000);
			for($i=0; $i<128 && $this->search->valid() && hrtime(true)<$deadline; $i++) $this->search->next();
			if($this->search->valid()) return null;
			$site = $this->search->getReturn();
			$this->search = null;
			$this->phase += 1.79;
			if($site === null) return 'No hidden pickup found on reachable ground. Turn the camera or drive on to search again.';
			$this->waitingAt = null;
			// A driver can move or look around while the route is being sampled.
			if(self::visible($site,$camera,$aspect) || hypot($site['x']-$to['x'],$site['z']-$to['z']) > self::MAX_LEG)
				return null;
			$this->pickup = $site;
			return 'Drive into the box under the lime smoke.';
		}

		if($from['recoveries'] !== $to['recoveries']
			|| !self::crossesSite($from,$to,$target,$this->carrying ? 6.0 : 3.0)) return null;
		if($this->carrying) $this->delivered++;
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

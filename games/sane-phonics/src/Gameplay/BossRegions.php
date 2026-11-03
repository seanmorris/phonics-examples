<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;
use Phonics\Render\Camera;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Activates Tiled boss arenas, confines players, and clamps their camera until target defeat. */
final class BossRegions
{
	/** @var list<BossRegion> Regions in Tiled painter order. */
	public array $regions;
	/** @var array<int,BossRegion> Active region keyed by body object ID. */
	private array $active = [];
	/** @var array<int,array{float,float}> Previous body center keyed by body object ID. */
	private array $previous = [];
	private \Closure $bossActive;

	/**
	 * Bind explicit regions to an application-defined boss-life resolver.
	 *
	 * @param callable(int):bool $bossActive Callback receiving a referenced Tiled object ID and
	 * returning whether that target still keeps its arena locked.
	 * @param BossRegion ...$regions Arena rectangles retained in supplied precedence order.
	 * @return void Initializes empty per-body entry and lock state.
	 */
	public function __construct(callable $bossActive, BossRegion ...$regions)
	{
		$this->bossActive = \Closure::fromCallable($bossActive);
		$this->regions = $regions;
	}

	/**
	 * Load BossRegion and boss-region rectangles from Tiled maps.
	 *
	 * The editor-visible name `boss-region` is accepted when old maps leave class and type empty.
	 * Each rectangle requires a `boss` object-reference property. Map placement, layer offsets, and
	 * Tiled's bottom anchor for tile objects are applied.
	 *
	 * @param callable(int):bool $bossActive Callback resolving a Tiled boss object ID to live state.
	 * @param TileMap ...$maps Loaded maps whose object layers contain arena rectangles.
	 * @return self Query and constraint manager containing every recognized boss region.
	 */
	public static function fromMaps(callable $bossActive, TileMap ...$maps): self
	{
		$regions = [];

		foreach($maps as $map)
		{
			foreach($map->layers as $layer)
			{
				foreach($layer->objects as $object)
				{
					$class = strtolower(
						(string)(($object['class'] ?? '') ?: ($object['type'] ?? '') ?: ($object['name'] ?? ''))
					);

					if(!in_array($class, ['bossregion', 'boss-region', 'boss region'], true))
					{
						continue;
					}

					if(!empty($object['polygon']) || !empty($object['polyline']) || !empty($object['ellipse'])
						|| (float)($object['rotation'] ?? 0) !== 0.0
					){
						throw new \UnexpectedValueException('Boss regions must be unrotated rectangles');
					}

					$properties = Loader::properties($object['properties'] ?? []);
					$boss = (int)($properties['boss'] ?? 0);
					$x = (float)($object['x'] ?? 0) + $map->x + $layer->offsetX;
					$y = (float)($object['y'] ?? 0) + $map->y + $layer->offsetY;
					$width = (float)($object['width'] ?? 0);
					$height = (float)($object['height'] ?? 0);

					if(isset($object['gid']))
					{
						$y -= $height;
					}

					$regions[] = new BossRegion(
						$x
						, $y
						, $width
						, $height
						, $boss
						, isset($object['id']) ? (int)$object['id'] : null
						, (float)($properties['cameraAnchorY'] ?? .72)
					);
				}
			}
		}

		return new self($bossActive, ...$regions);
	}

	/**
	 * Record a teleport or spawn without treating its path as an arena crossing.
	 *
	 * @param Body $body Actor whose current center becomes the next sweep origin.
	 * @param bool $release Whether to clear an existing lock for this body, as on respawn.
	 * @return void Retains global completion state and other bodies' locks.
	 */
	public function sync(Body $body, bool $release = true): void
	{
		$key = spl_object_id($body);
		$this->previous[$key] = [$body->x, $body->y];

		if($release)
		{
			unset($this->active[$key]);
		}
	}

	/**
	 * Detect grounded entry with a swept segment and apply an existing horizontal lock.
	 *
	 * A newly entered arena remains active when the actor jumps or leaves its vertical rectangle.
	 * The lock completes and releases when the referenced target callback becomes false.
	 *
	 * @param Body $body Actor to test and constrain after its movement step.
	 * @return BossRegion|null Active region after target release and entry detection.
	 */
	public function update(Body $body): ?BossRegion
	{
		$key = spl_object_id($body);
		$from = $this->previous[$key] ?? [$body->x, $body->y];
		$region = $this->liveRegion($key);

		if($region === null && $body->grounded)
		{
			foreach($this->regions as $candidate)
			{
				if(!$candidate->completed && ($this->bossActive)($candidate->bossSourceId)
					&& $this->crosses($candidate, $from[0], $from[1], $body->x, $body->y)
				){
					$region = $candidate;
				}
			}

			if($region !== null)
			{
				$this->active[$key] = $region;
				$this->stopPassThrough($body, $region, $from[0]);
			}
		}

		if($region !== null)
		{
			$this->constrainBody($body, $region);
		}

		$this->previous[$key] = [$body->x, $body->y];

		return $region;
	}

	/**
	 * Return this body's live arena, releasing it immediately after target defeat.
	 *
	 * @param Body $body Actor whose current lock is requested.
	 * @return BossRegion|null Active live region or null when outside, completed, or released.
	 */
	public function active(Body $body): ?BossRegion
	{
		return $this->liveRegion(spl_object_id($body));
	}

	/**
	 * Clamp a camera's horizontal world span to this body's active arena.
	 *
	 * Regions narrower than the visible world are centered instead of producing inverted bounds.
	 *
	 * @param Body $body Actor selecting the active lock.
	 * @param Camera $camera Camera already advanced toward its follow target for this frame.
	 * @return bool True when an active region changed or confirmed the camera constraint.
	 */
	public function constrainCamera(Body $body, Camera $camera): bool
	{
		$region = $this->active($body);

		if($region === null)
		{
			return false;
		}

		$viewWidth = $camera->width / $camera->zoom;
		$minimum = $region->x;
		$maximum = $region->x + $region->width - $viewWidth;
		$camera->x = $minimum <= $maximum
			? max($minimum, min($maximum, $camera->x))
			: $region->x + ($region->width - $viewWidth) / 2;

		return true;
	}

	/**
	 * Describe this body's active arena for browser diagnostics and scripted tests.
	 *
	 * @param Body $body Actor whose lock state is inspected.
	 * @return array<string,bool|int|float|null> Stable lock state and bounds, using null values while
	 * inactive.
	 */
	public function snapshot(Body $body): array
	{
		$region = $this->active($body);

		return [
			'active' => $region !== null
			, 'sourceId' => $region?->sourceId
			, 'bossSourceId' => $region?->bossSourceId
			, 'x' => $region?->x
			, 'y' => $region?->y
			, 'width' => $region?->width
			, 'height' => $region?->height
		];
	}

	/**
	 * Release completed targets while resolving an active region by body key.
	 *
	 * @param int $key Body object ID used by the per-actor state tables.
	 * @return BossRegion|null Still-live region, or null after release.
	 */
	private function liveRegion(int $key): ?BossRegion
	{
		$region = $this->active[$key] ?? null;

		if($region !== null && !($this->bossActive)($region->bossSourceId))
		{
			$region->completed = true;
			unset($this->active[$key]);

			return null;
		}

		return $region;
	}

	/**
	 * Test a complete movement segment against one axis-aligned activation rectangle.
	 *
	 * @param BossRegion $region Candidate arena.
	 * @param float $x1 Segment starting X coordinate.
	 * @param float $y1 Segment starting Y coordinate.
	 * @param float $x2 Segment ending X coordinate.
	 * @param float $y2 Segment ending Y coordinate.
	 * @return bool True when any fraction from zero through one lies inside the rectangle.
	 */
	private function crosses(BossRegion $region, float $x1, float $y1, float $x2, float $y2): bool
	{
		$near = 0.0;
		$far = 1.0;

		foreach([
			[$x1, $x2 - $x1, $region->x, $region->x + $region->width]
			, [$y1, $y2 - $y1, $region->y, $region->y + $region->height]
		] as [$origin, $delta, $minimum, $maximum])
		{
			if(abs($delta) < 1e-12)
			{
				if($origin < $minimum || $origin > $maximum)
				{
					return false;
				}

				continue;
			}

			$a = ($minimum - $origin) / $delta;
			$b = ($maximum - $origin) / $delta;
			$near = max($near, min($a, $b));
			$far = min($far, max($a, $b));

			if($near > $far)
			{
				return false;
			}
		}

		return $far >= 0 && $near <= 1;
	}

	/**
	 * Put a full-width high-speed crossing back on the edge where it entered.
	 *
	 * @param Body $body Actor whose endpoint crossed both arena walls in one update.
	 * @param BossRegion $region Newly activated arena.
	 * @param float $fromX Horizontal position at the start of the movement step.
	 * @return void Leaves ordinary endpoints within the arena unchanged.
	 */
	private function stopPassThrough(Body $body, BossRegion $region, float $fromX): void
	{
		$right = $region->x + $region->width;

		if($fromX < $region->x && $body->x > $right)
		{
			$body->x = $region->x;
			$body->vx = $body->groundSpeed = 0;
		}
		elseif($fromX > $right && $body->x < $region->x)
		{
			$body->x = $right;
			$body->vx = $body->groundSpeed = 0;
		}
	}

	/**
	 * Keep a body center within horizontal lock bounds and remove outward momentum.
	 *
	 * @param Body $body Actor whose position and velocity may be corrected.
	 * @param BossRegion $region Active arena supplying the left and right walls.
	 * @return void Preserves inward and vertical movement.
	 */
	private function constrainBody(Body $body, BossRegion $region): void
	{
		$right = $region->x + $region->width;
		$leftContact = $body->x <= $region->x;
		$rightContact = $body->x >= $right;
		$body->x = max($region->x, min($right, $body->x));

		if(($leftContact && $body->vx < 0) || ($rightContact && $body->vx > 0))
		{
			$body->vx = 0;
		}

		$groundX = cos($body->angle) * $body->groundSpeed;

		if(($leftContact && $groundX < 0) || ($rightContact && $groundX > 0))
		{
			$body->groundSpeed = 0;
		}
	}
}

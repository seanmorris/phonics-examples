<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\SectorMover;

/** Mutable floor, ceiling, light, and timed-special state for one immutable WAD sector. */
final class SectorState
{
	private const SKY_CEILING = 'F_SKY1';
	public float $floor;
	public float $ceiling;
	public float $previousFloor;
	public float $previousCeiling;
	public int $light;
	public int $special;
	public string $floorTexture;
	public string $ceilingTexture;
	public ?int $floorMover = null;
	public ?int $ceilingMover = null;
	public int $appearanceRevision = 0;
	/** @var array{float,float,float} Plane offsets: dx*x + dy*y + constant. */
	public array $floorSlope = [0.0,0.0,0.0], $ceilingSlope = [0.0,0.0,0.0];
	public ?int $floorLightSource = null, $ceilingLightSource = null;
	/** @var list<SectorVolume> Live solid, decorative and swimmable model volumes. */
	public array $volumes = [];
	/** Optional Boom fake-plane definition; never changes physical boundaries. */
	public ?HeightTransfer $heightTransfer = null;
	/** WaterZone enables swimming below a transferred floor in classic maps. */
	public bool $waterZone = false;

	/** Find the support below a body, optionally allowing an ordinary step onto a slab. */
	public function supportZ(float $x, float $y, float $z, float $step = 0): float
	{
		$floor = $this->floorZ($x, $y);
		foreach($this->volumes as $volume)
		if($volume->solid() && ($top = $volume->top($x, $y)) <= $z + $step + .01)
		$floor = max($floor, $top);
		return $floor;
	}

	/** Find the nearest solid underside above a body's feet. */
	public function headroomZ(float $x, float $y, float $z): float
	{
		$ceiling = $this->ceilingZ($x, $y);
		foreach($this->volumes as $volume)
		if($volume->solid() && $volume->top($x, $y) > $z + .01)
		$ceiling = min($ceiling, $volume->bottom($x, $y));
		return $ceiling;
	}

	/** Return the highest liquid surface overlapping the body's vertical interval. */
	public function waterSurface(float $x, float $y, float $z, float $height): ?float
	{
		$surface = null;
		foreach($this->volumes as $volume)
		if($volume->swimmable() && $volume->overlaps($x, $y, $z, $height))
		$surface = max($surface ?? -INF, $volume->top($x, $y));
		$transfer = $this->heightTransfer;
		if($transfer && (($transfer->flags & 8) || $this->waterZone)
			&& $z < $transfer->model->floorZ($x, $y) && $z + $height > $this->floorZ($x, $y))
		$surface = max($surface ?? -INF, $transfer->model->floorZ($x, $y));
		return $surface;
	}

	/** Test whether a shot or sight interval overlaps a blocking 3D volume. */
	public function blocksVolume(float $x, float $y, float $z, float $height, bool $sight = false): bool
	{
		foreach($this->volumes as $volume)
		if($volume->blocks($sight) && $volume->overlaps($x, $y, $z, $height)) return true;
		return false;
	}

	/* Legacy motion fields remain public until the pre-1.0 compatibility window ends. */
	public float $targetFloor;
	public float $targetCeiling;
	public float $floorSpeed = 0;
	public float $ceilingSpeed = 0;
	public float $wait = 0;
	public float $originalFloor;
	public float $originalCeiling;
	public ?float $floorReturn = null;
	public ?float $ceilingReturn = null;
	public float $floorReturnDelay = 0;
	public float $ceilingReturnDelay = 0;
	private bool $floorArrived = false;
	private bool $ceilingArrived = false;

	/** @param array<string,mixed> $source */
	public function __construct(public int $index, public array $source)
	{
		$this->floor = $this->previousFloor = $this->targetFloor = $this->originalFloor = (float)$source['floor'];
		$this->ceiling = $this->previousCeiling = $this->targetCeiling = $this->originalCeiling = (float)$source[
			'ceiling'
		];

		$this->light = (int)$source['light'];
		$this->special = (int)$source['special'];
		$this->floorTexture = strtoupper((string)$source['floorTexture']);
		$this->ceilingTexture = strtoupper((string)$source['ceilingTexture']);
	}

	/** Whether the ceiling is an open sky portal rather than a solid plane. */
	public function hasSkyCeiling(): bool { return $this->ceilingTexture === self::SKY_CEILING; }

	/** Whether a body's lower edge has cleared this sector's terminal sky wall. */
	public function opensToSkyAt(float $bottom, float $tolerance = .001): bool
	{
		return $this->hasSkyCeiling() && $bottom >= $this->ceiling - $tolerance;
	}

	/** Retain the fixed-step plane positions used by render interpolation. */
	public function beginStep(): void
	{
		$this->previousFloor = $this->floor;
		$this->previousCeiling = $this->ceiling;
	}

	public function floorAt(float $alpha): float
	{
		$alpha = max(0, min(1, $alpha));

		return $this->previousFloor + ($this->floor - $this->previousFloor) * $alpha;
	}

	public function ceilingAt(float $alpha): float
	{
		$alpha = max(0, min(1, $alpha));

		return $this->previousCeiling + ($this->ceiling - $this->previousCeiling) * $alpha;
	}

	public function update(float $dt): void
	{
		$this->floor = self::approach($this->floor, $this->targetFloor, $this->floorSpeed * $dt);
		$this->ceiling = self::approach($this->ceiling, $this->targetCeiling, $this->ceilingSpeed * $dt);

		if($this->floor === $this->targetFloor && $this->floorReturn !== null)
		{
			if(!$this->floorArrived)
			{
				$this->floorArrived = true;
				$this->wait = max($this->wait, $this->floorReturnDelay);
			}
			elseif(($this->wait = max(0, $this->wait - $dt)) === 0.0)
			{
				$this->targetFloor = $this->floorReturn;
				$this->floorReturn = null;
				$this->floorArrived = false;
			}
		}

		if($this->ceiling === $this->targetCeiling && $this->ceilingReturn !== null)
		{
			if(!$this->ceilingArrived)
			{
				$this->ceilingArrived = true;
				$this->wait = max($this->wait, $this->ceilingReturnDelay);
			}
			elseif(($this->wait = max(0, $this->wait - $dt)) === 0.0)
			{
				$this->targetCeiling = $this->ceilingReturn;
				$this->ceilingReturn = null;
				$this->ceilingArrived = false;
			}
		}
	}

	/** Height at a world position, including moving-plane interpolation. */
	public function floorZ(float $x, float $y, float $alpha = 1): float
	{
		return $this->floorAt($alpha) + $this->floorSlope[0] * $x + $this->floorSlope[1] * $y + $this->floorSlope[2];
	}

	public function ceilingZ(float $x, float $y, float $alpha = 1): float
	{
		return $this->ceilingAt(
			$alpha
		) + $this->ceilingSlope[0] * $x + $this->ceilingSlope[1] * $y + $this->ceilingSlope[2];
	}

	public function moveFloor(float $target, float $speed, ?float $return = null, float $delay = 0): void
	{
		$this->targetFloor = $target;
		$this->floorSpeed = $speed;
		$this->floorReturn = $return;
		$this->floorReturnDelay = $delay;
		$this->floorArrived = false;
	}

	public function moveCeiling(float $target, float $speed, ?float $return = null, float $delay = 0): void
	{
		$this->targetCeiling = $target;
		$this->ceilingSpeed = $speed;
		$this->ceilingReturn = $return;
		$this->ceilingReturnDelay = $delay;
		$this->ceilingArrived = false;
	}

	public function setLight(int $light): void
	{
		$light = max(0, min(255, $light));

		if($this->light === $light) return;

		$this->light = $light;
		$this->source['light'] = $light;
		$this->appearanceRevision++;
	}

	public function setSpecial(int $special): void
	{
		$this->special = $special;
		$this->source['special'] = $special;
	}

	public function setFloorTexture(string $texture): void
	{
		$texture = strtoupper($texture);

		if($this->floorTexture === $texture) return;

		$this->floorTexture = $texture;
		$this->source['floorTexture'] = $texture;
		$this->appearanceRevision++;
	}

	public function activeMoverFor(SectorMover $mover): ?int
	{
		return $mover->movesFloor()?$this->floorMover:$this->ceilingMover;
	}

	public function lockMover(SectorMover $mover, int $entity): void
	{
		if($mover->movesFloor())$this->floorMover = $entity;
		else$this->ceilingMover = $entity;
	}

	public function unlockMover(SectorMover $mover, int $entity): void
	{
		if($this->activeMoverFor($mover) !== $entity)return;

		if($mover->movesFloor())$this->floorMover = null;
		else$this->ceilingMover = null;
	}

	private static function approach(float $value, float $target, float $amount): float
	{
		if($amount <= 0 || $value === $target) return $value;

		if($value < $target) return min($target, $value + $amount);

		return max($target, $value - $amount);
	}
}

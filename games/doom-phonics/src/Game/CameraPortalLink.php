<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Camera and traversal transform shared by one or more portal surfaces. */
final class CameraPortalLink
{
	public const ALIGN_ABSOLUTE = 0;
	public const ALIGN_FLOOR = 1;
	public const ALIGN_CEILING = 2;

	public bool $active = true;
	/** Plane used at the remote end; plane portals cross floor-to-ceiling or ceiling-to-floor. */
	public int $destinationAlignment;

	public function __construct(
		public int $id
		, public int $type
		, public int $alignment
		, public int $originSector
		, public int $destinationSector
		, public float $originX
		, public float $originY
		, public float $destinationX
		, public float $destinationY
		, public float $angleDelta = 0.0
		, public ?int $originLine = null
		, public ?int $destinationLine = null
		, public ?int $reciprocalId = null
		, ?int $destinationAlignment = null
	){
		if(!CameraPortalType::valid($type)) throw new \InvalidArgumentException("Unknown camera portal type $type");

		$this->destinationAlignment = $destinationAlignment ?? $alignment;

		foreach([$alignment,$this->destinationAlignment] as $portalAlignment)
		if($portalAlignment < self::ALIGN_ABSOLUTE || $portalAlignment > self::ALIGN_CEILING)
		throw new \InvalidArgumentException("Unknown camera portal alignment $portalAlignment");
	}

	public function passable(): bool { return $this->active && $this->type !== CameraPortalType::VISUAL; }

	public function interactive(): bool
	{
		return $this->active && $this->type >= CameraPortalType::INTERACTIVE;
	}

	/** Opposite horizontal planes reverse signed distance during physical traversal. */
	public function flipsVertical(): bool
	{
		return $this->alignment !== self::ALIGN_ABSOLUTE
			&& $this->alignment !== $this->destinationAlignment;
	}

	/** @return array{0:float,1:float} */
	public function point(float $x, float $y): array
	{
		$relativeX = $x - $this->originX;
		$relativeY = $y - $this->originY;
		$cos = cos($this->angleDelta);
		$sin = sin($this->angleDelta);

		return [
			$this->destinationX + $relativeX * $cos - $relativeY * $sin
			, $this->destinationY + $relativeY * $cos + $relativeX * $sin
		];
	}

	/** @return array{0:float,1:float} */
	public function vector(float $x, float $y): array
	{
		$cos = cos($this->angleDelta);
		$sin = sin($this->angleDelta);

		return [$x * $cos - $y * $sin, $y * $cos + $x * $sin];
	}

	public function angle(float $angle): float
	{
		$angle = fmod($angle + $this->angleDelta, 2 * M_PI);

		return $angle < 0 ? $angle + 2 * M_PI : $angle;
	}

	public function z(float $z, Level $level): float
	{
		if($this->alignment === self::ALIGN_ABSOLUTE) return $z;

		$originPlane = $this->alignment === self::ALIGN_FLOOR ? 'floorZ' : 'ceilingZ';
		$destinationPlane = $this->destinationAlignment === self::ALIGN_FLOOR ? 'floorZ' : 'ceilingZ';
		$origin = $level->sectors[$this->originSector]->$originPlane($this->originX, $this->originY);

		$destination = $level->sectors[$this->destinationSector]->$destinationPlane(
			$this->destinationX
			, $this->destinationY
		);

		$offset = $z - $origin;

		if($this->flipsVertical()) $offset = -$offset;

		return $destination + $offset;
	}

	/**
	 * Transform an actor base through a horizontal plane portal.
	 *
	 * Plane traversal changes map-space when the actor center crosses the seam.
	 * Transforming that same center through the portal keeps sprites continuous
	 * with the camera view while the body briefly straddles the remote plane.
	 * Line portals retain the ordinary base-height transform.
	 */
	public function bodyZ(float $z, float $height, Level $level): float
	{
		if($this->originLine !== null || $this->alignment === self::ALIGN_ABSOLUTE)
		return $this->z($z, $level);

		$center = $z + $height * .5;

		return $this->cameraZ($center, $level) - $height * .5;
	}

	/**
	 * Place a portal camera on the same side of the remote plane as the real
	 * camera is on the source plane. The destination portal plane is left open
	 * while rendering the capture, so rays cross it with the correct parallax.
	 */
	public function cameraZ(float $z, Level $level): float
	{
		if($this->alignment === self::ALIGN_ABSOLUTE) return $z;

		$originPlane = $this->alignment === self::ALIGN_FLOOR ? 'floorZ' : 'ceilingZ';
		$destinationPlane = $this->destinationAlignment === self::ALIGN_FLOOR ? 'floorZ' : 'ceilingZ';
		$origin = $level->sectors[$this->originSector]->$originPlane($this->originX, $this->originY);

		$destination = $level->sectors[$this->destinationSector]->$destinationPlane(
			$this->destinationX
			, $this->destinationY
		);

		return $destination + ($z - $origin);
	}

	/** @return array{x:float,y:float,z:float,angle:float} */
	public function camera(
		float $x
		, float $y
		, float $z
		, float $angle
		, Level $level
		, float $viewHeight = 0.0
	): array {
		[$x,$y] = $this->point($x, $y);
		// Transform the eye rather than the actor origin, then return the base height
		// expected by the renderer. Opposite plane portals retain the eye on the
		// approach side so the open destination plane supplies the aperture.
		$z = $this->cameraZ($z + $viewHeight, $level) - $viewHeight;

		return ['x' => $x,'y' => $y,'z' => $z,'angle' => $this->angle($angle)];
	}

	/** Reassign a non-linked line portal without replacing references held by its surfaces. */
	public function retarget(Level $level, ?int $lineId): bool
	{
		if($this->originLine === null || $this->type === CameraPortalType::LINKED) return false;

		if($lineId === null)
		{
			$this->destinationLine = null;
			$this->active = false;

			return true;
		}

		$line = $level->map->linedefs[$lineId] ?? null;
		$origin = $level->map->linedefs[$this->originLine] ?? null;

		if(!$line || !$origin) return false;

		$a = $level->map->vertices[$origin['from']];
		$b = $level->map->vertices[$origin['to']];
		$c = $level->map->vertices[$line['from']];
		$d = $level->map->vertices[$line['to']];
		$this->destinationLine = $lineId;
		$this->destinationSector = $level->sides($lineId)['front'] ?? $this->destinationSector;
		$this->originX = (float)$a['x'];
		$this->originY = (float)$a['y'];
		$this->destinationX = (float)$d['x'];
		$this->destinationY = (float)$d['y'];
		$this->angleDelta = atan2($d['y'] - $c['y'], $d['x'] - $c['x'])
			- atan2($b['y'] - $a['y'], $b['x'] - $a['x']) + M_PI;

		$this->active = true;

		return true;
	}
}

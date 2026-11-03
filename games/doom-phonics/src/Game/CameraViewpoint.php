<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Structural ZDoom camera thing used by SetCameraToTexture. */
final class CameraViewpoint
{
	public const SECURITY = 9025, MOVING = 9072, AIMING = 9073;
	public float $angle;
	public float $pitch;
	private float $accumulator = 0.0;
	private float $baseAngle;

	/** @param list<int> $args */
	public function __construct(
		public int $tid
		, public int $type
		, public float $x
		, public float $y
		, public float $z
		, float $angle
		, float $pitch
		, public int $sector
		, public array $args = [0,0,0,0,0]
	){
		$this->angle = $this->baseAngle = $angle;
		$this->pitch = max(deg2rad(-89), min(deg2rad(89), $pitch));
	}

	public function update(float $dt, Level $level): void
	{
		if($dt <= 0) return;

		if($this->type === self::SECURITY && ($this->args[2] ?? 0) > 0)
		{
			$range = deg2rad((float)($this->args[1] ?? 0));
			// ZDoom stores the cycle in octics. A sweeping camera halves the
			// rotation rate because one sine period visits both endpoints.
			$speed = deg2rad((($range === 0.0 ? 2880.0 : 1440.0) / max(1, (int)$this->args[2])));
			$this->accumulator = fmod($this->accumulator + $speed * $dt, M_PI * 2);
			$this->angle = $range > 0
				? $this->baseAngle + $range * sin($this->accumulator)
				: $this->accumulator;
		}

		if($this->type !== self::AIMING || !($target = $level->thingWithTid((int)($this->args[3] ?? 0)))) return;

		$dx = (float)$target['x'] - $this->x;
		$dy = (float)$target['y'] - $this->y;
		$targetSector = $level->sectorAt((float)$target['x'], (float)$target['y']);
		$targetZ = $targetSector->floorZ((float)$target['x'], (float)$target['y']) + (float)($target['z'] ?? 0);
		$yaw = atan2($dy, $dx);
		$pitch = atan2($targetZ - $this->z, max(.001, hypot($dx, $dy)));
		$this->angle = self::approachAngle($this->angle, $yaw, deg2rad((float)($this->args[1] ?? 0)) * $dt);
		$this->pitch = self::approach($this->pitch, $pitch, deg2rad((float)($this->args[2] ?? 0)) * $dt);
	}

	private static function approachAngle(float $value, float $target, float $step): float
	{
		$delta = fmod($target - $value + M_PI * 3, M_PI * 2) - M_PI;

		return $value + max(-$step, min($step, $delta));
	}

	private static function approach(float $value, float $target, float $step): float
	{
		return $value + max(-$step, min($step, $target - $value));
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\PitchMode;

/** Shared perspective math for camera rendering and world-space aim rays. */
final class ViewProjection
{
	public readonly float $horizontalScale;
	public readonly float $verticalScale;

	public function __construct(public int $width, public int $height, public float $horizontalFov)
	{
		if($width <= 0 || $height <= 0)
		throw new \InvalidArgumentException('View dimensions must be positive');

		if(!is_finite($horizontalFov) || $horizontalFov <= 0 || $horizontalFov >= 180)
		throw new \InvalidArgumentException('Horizontal field of view must be between 0 and 180 degrees');

		$this->horizontalScale = 1 / tan(deg2rad($horizontalFov / 2));
		$this->verticalScale = $this->horizontalScale * $width / $height;
	}

	/** Rotate horizontal distance and world height into depth along the pitched camera axis. */
	public function viewDepth(
		float $horizontalDepth
		, float $verticalOffset
		, float $pitch
		, PitchMode $mode = PitchMode::Perspective
	): float {
		if($mode === PitchMode::ClassicShear) return $horizontalDepth;

		return $horizontalDepth * cos($pitch) + $verticalOffset * sin($pitch);
	}

	/** Rotate horizontal distance and world height into height above the pitched camera axis. */
	public function viewHeight(
		float $horizontalDepth
		, float $verticalOffset
		, float $pitch
		, PitchMode $mode = PitchMode::Perspective
	): float {
		if($mode === PitchMode::ClassicShear)
		return $verticalOffset - tan($pitch) * $horizontalDepth;

		return $verticalOffset * cos($pitch) - $horizontalDepth * sin($pitch);
	}

	/** Project a world point to normalized screen Y using the same rotation as the GLES shaders. */
	public function screenY(
		float $horizontalDepth
		, float $verticalOffset
		, float $pitch
		, PitchMode $mode = PitchMode::Perspective
	): float {
		$depth = $this->viewDepth($horizontalDepth, $verticalOffset, $pitch, $mode);

		if(abs($depth) < 1e-9)
		return $this->viewHeight($horizontalDepth, $verticalOffset, $pitch, $mode) < 0
			? -INF
			: INF;

		return $this->viewHeight($horizontalDepth, $verticalOffset, $pitch, $mode) * $this->verticalScale / $depth;
	}
}

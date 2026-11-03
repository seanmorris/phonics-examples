<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;

/** A rectangular liquid volume with movement and shader displacement parameters. */
final class WaterRegion
{

	/**
	 * Define one world-space water volume.
	 *
	 * @param float $x Left edge in world pixels.
	 * @param float $y Surface position in world pixels.
	 * @param float $width Positive region width in world pixels.
	 * @param float $height Positive depth in world pixels.
	 * @param float $gravity Gravity multiplier applied to submerged bodies.
	 * @param float $drag Acceleration and jump-strength multiplier applied underwater.
	 * @param float $density Liquid density used for optional buoyancy.
	 * @param float $skimSpeed Minimum horizontal speed in pixels per second that treats the surface
	 * as temporary ground.
	 * @param float $currentX Horizontal current acceleration in pixels per second squared.
	 * @param float $currentY Vertical current acceleration in pixels per second squared.
	 * @param array{0:float,1:float,2:float,3:float} $tint Normalized underwater RGBA tint.
	 * @param float $displacement Maximum texture displacement in screen pixels.
	 * @param float $displacementScale World pixels covered by one displacement-map repeat.
	 * @param float $displacementSpeed Vertical displacement-map scroll in pixels per second.
	 * @param int|null $sourceId Tiled object ID used by water switches and buoyant objects.
	 * @return void Stores validated physics and rendering settings.
	 */
	public function __construct(
		public float $x
		, public float $y
		, public float $width
		, public float $height
		, public float $gravity = .4
		, public float $drag = .85
		, public float $density = 1
		, public float $skimSpeed = 900
		, public float $currentX = 0
		, public float $currentY = 0
		, public array $tint = [.08,.48,.72,.3]
		, public float $displacement = 4
		, public float $displacementScale = 64
		, public float $displacementSpeed = 42.6666667
		, public ?int $sourceId = null
	){
		foreach([
			$x,$y,$width,$height,$gravity,$drag,$density,$skimSpeed,$currentX,$currentY
			, $displacement,$displacementScale,$displacementSpeed
		] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Water properties must be finite');
			}
		}

		if($width <= 0 || $height <= 0 || $gravity < 0 || $drag < 0 || $drag > 1 || $density <= 0
			|| $skimSpeed < 0 || $displacement < 0 || $displacementScale <= 0
		){
			throw new \InvalidArgumentException('Invalid water region');
		}

		if(count($tint) !== 4)
		{
			throw new \InvalidArgumentException('Water tint must contain four channels');
		}

		foreach($tint as $channel)
		{
			if(!is_numeric($channel) || !is_finite((float)$channel) || $channel < 0 || $channel > 1)
			{
				throw new \InvalidArgumentException('Water tint channels must be normalized');
			}
		}

		$this->tint = array_map('floatval', $tint);
	}

	/**
	 * Move the liquid surface while retaining the fixed bottom of the volume.
	 *
	 * @param float $y New world-space surface position above the current bottom.
	 * @return void Updates y and height atomically; an empty or inverted volume is rejected.
	 */
	public function setSurface(float $y): void
	{
		$bottom = $this->y + $this->height;

		if(!is_finite($y) || $y >= $bottom)
		{
			throw new \InvalidArgumentException('Water surface must remain above its bottom');
		}

		$this->y = $y;
		$this->height = $bottom - $y;
	}

	/**
	 * Test whether a world point lies inside the liquid volume.
	 *
	 * @param float $x World X coordinate.
	 * @param float $y World Y coordinate.
	 * @return bool True for points on or inside the rectangular bounds.
	 */
	public function contains(float $x, float $y): bool
	{
		return $x >= $this->x && $x <= $this->x + $this->width
			&& $y >= $this->y && $y <= $this->y + $this->height;
	}

	/**
	 * Measure how much of an upright body overlaps the water vertically.
	 *
	 * @param Body $body Body whose current standing or rolling bounds are sampled.
	 * @return float Overlap fraction in [0,1].
	 */
	public function depth(Body $body): float
	{
		$top = $body->y - $body->halfHeight;
		$bottom = $body->y + $body->halfHeight;
		$overlap = min($bottom, $this->y + $this->height) - max($top, $this->y);

		return max(0, min(1, $overlap / ($body->halfHeight * 2)));
	}
}

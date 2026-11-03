<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;

/** A pressure switch that selects one target surface for a Tiled water object. */
final class WaterSwitch
{
	public bool $active = false;
	public int $entity = 0;
	public ?int $shape = null;

	/**
	 * Define a body-triggered water-level switch.
	 *
	 * @param int $sourceId Tiled switch object ID retained for scripts and diagnostics.
	 * @param int $waterId Referenced Tiled water object ID.
	 * @param float $targetY Referenced set-point Y coordinate in world pixels.
	 * @param float $x Left edge of the trigger rectangle in world pixels.
	 * @param float $y Bottom edge of the trigger rectangle in world pixels.
	 * @param float $width Positive trigger width in world pixels.
	 * @param float $height Positive trigger height in world pixels.
	 * @param float $fillSpeed Positive upward water speed in pixels per second.
	 * @param float $drainSpeed Positive downward water speed in pixels per second.
	 * @param int $collisionLayer Nonnegative terrain plane; zero collides with every body.
	 * @return void Initializes an inactive switch.
	 */
	public function __construct(
		public int $sourceId
		, public int $waterId
		, public float $targetY
		, public float $x
		, public float $y
		, public float $width
		, public float $height
		, public float $fillSpeed = 240
		, public float $drainSpeed = 480
		, public int $collisionLayer = 0
	){
		foreach([$targetY, $x, $y, $width, $height, $fillSpeed, $drainSpeed] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Water switch values must be finite');
			}
		}

		if($sourceId < 1 || $waterId < 1 || $width <= 0 || $height <= 0 || $collisionLayer < 0
			|| $fillSpeed <= 0 || $drainSpeed <= 0
		){
			throw new \InvalidArgumentException('Invalid water switch');
		}
	}

	/**
	 * Resolve the top collision surface of the pressure plate.
	 *
	 * @return float World Y coordinate where a descending body lands.
	 */
	public function top(): float
	{
		return $this->y - $this->height;
	}

	/**
	 * Build the directed one-way edge used by Terrain.
	 *
	 * @return list<array{float,float}> Left-to-right top edge with an upward collision normal.
	 */
	public function points(): array
	{
		return [[$this->x, $this->top()],[$this->x + $this->width, $this->top()]];
	}

	/**
	 * Test whether a grounded body's support point depresses this switch.
	 *
	 * @param Body $body Candidate actor using center position and collision radii.
	 * @return bool True only while the body is standing on this switch's retained top edge.
	 */
	public function pressedBy(Body $body): bool
	{
		return $body->grounded
			&& $body->vy >= -1e-6
			&& ($this->collisionLayer === 0 || $body->collisionLayer === $this->collisionLayer)
			&& $body->x + $body->radius >= $this->x
			&& $body->x - $body->radius <= $this->x + $this->width
			&& abs($body->supportY() - $this->top()) <= .1;
	}
}

<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;

/** An oriented pressure plate that selects a cardinal gravity direction per body. */
final class GravitySwitch
{
	public bool $active = false;
	public float $activeTime = 0;
	public int $entity = 0;
	public ?int $shape = null;

	/**
	 * Define an oriented gravity pressure plate.
	 *
	 * @param int $sourceId Tiled object ID retained for scripts and diagnostics.
	 * @param float $x World X coordinate at the center of the contact edge.
	 * @param float $y World Y coordinate at the center of the contact edge.
	 * @param float $normalX Horizontal outward unit normal of the plate face.
	 * @param float $normalY Vertical outward unit normal of the plate face.
	 * @param float $length Positive contact-edge length in world pixels.
	 * @param int $gravityX Target cardinal horizontal gravity component.
	 * @param int $gravityY Target cardinal vertical gravity component.
	 * @param int $collisionLayer Nonnegative terrain plane; zero collides with every body.
	 * @return void Initializes an inactive pressure plate.
	 */
	public function __construct(
		public int $sourceId
		, public float $x
		, public float $y
		, public float $normalX
		, public float $normalY
		, public float $length
		, public int $gravityX
		, public int $gravityY
		, public int $collisionLayer = 0
	){
		foreach([$x, $y, $normalX, $normalY, $length] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Gravity switch values must be finite');
			}
		}

		if($sourceId < 1 || $length <= 0 || $collisionLayer < 0 || abs($gravityX) + abs($gravityY) !== 1)
		{
			throw new \InvalidArgumentException('Invalid gravity switch');
		}

		$normalLength = hypot($normalX, $normalY);

		if($normalLength < 1e-9)
		{
			throw new \InvalidArgumentException('Gravity switch normal cannot be zero');
		}

		$this->normalX /= $normalLength;
		$this->normalY /= $normalLength;
	}

	/**
	 * Build the directed one-way contact edge for this plate.
	 *
	 * @return list<array{float,float}> Endpoints ordered to produce the authored outward normal.
	 */
	public function points(): array
	{
		$tx = -$this->normalY;
		$ty = $this->normalX;
		$half = $this->length / 2;

		return [
			[$this->x - $tx * $half, $this->y - $ty * $half]
			, [$this->x + $tx * $half, $this->y + $ty * $half]
		];
	}

	/**
	 * Test whether a grounded body is supported by this plate's front face.
	 *
	 * @param Body $body Candidate body carrying a support point and selected collision plane.
	 * @return bool True while the body's sole overlaps and aligns with this pressure plate.
	 */
	public function pressedBy(Body $body): bool
	{
		if(!$body->grounded || ($this->collisionLayer !== 0 && $body->collisionLayer !== $this->collisionLayer))
		{
			return false;
		}

		$supportNormalX = sin($body->angle);
		$supportNormalY = -cos($body->angle);

		if($supportNormalX * $this->normalX + $supportNormalY * $this->normalY < .98)
		{
			return false;
		}

		$dx = $body->supportX() - $this->x;
		$dy = $body->supportY() - $this->y;
		$distance = $dx * $this->normalX + $dy * $this->normalY;
		$along = $dx * -$this->normalY + $dy * $this->normalX;

		return abs($distance) <= .1 && abs($along) <= $this->length / 2 + $body->radius;
	}

	/**
	 * Apply the target gravity direction and release the body from its previous support.
	 *
	 * @param Body $body Body currently pressing this plate.
	 * @return bool True when gravity changed; false when it already matched the target.
	 */
	public function activate(Body $body): bool
	{
		if($body->gravityX === $this->gravityX && $body->gravityY === $this->gravityY)
		{
			return false;
		}

		$body->setGravityDirection($this->gravityX, $this->gravityY);
		$body->grounded = false;
		$body->grinding = false;
		$body->coyote = 0;
		$body->angle = $body->uprightAngle();
		$this->active = true;
		$this->activeTime = .15;

		return true;
	}
}

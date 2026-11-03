<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Body;

/** An oriented, collision-backed spring that launches supported bodies along its face normal. */
final class Spring
{
	public bool $active = false;
	public float $activeTime = 0;
	public int $entity = 0;
	public ?int $shape = null;

	/**
	 * Define a spring contact edge and launch impulse.
	 *
	 * @param int $sourceId Tiled object ID retained for scripts and diagnostics.
	 * @param float $x World X coordinate at the center of the contact edge.
	 * @param float $y World Y coordinate at the center of the contact edge.
	 * @param float $normalX Horizontal launch direction before normalization.
	 * @param float $normalY Vertical launch direction before normalization.
	 * @param float $length Positive contact-edge length in world pixels.
	 * @param float $power Positive launch speed in pixels per second.
	 * @param int $collisionLayer Nonnegative terrain plane; zero collides with every body.
	 * @return void Initializes an idle spring.
	 */
	public function __construct(
		public int $sourceId
		, public float $x
		, public float $y
		, public float $normalX
		, public float $normalY
		, public float $length
		, public float $power = 960
		, public int $collisionLayer = 0
	){
		foreach([$x, $y, $normalX, $normalY, $length, $power] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Spring values must be finite');
			}
		}

		$normalLength = hypot($normalX, $normalY);

		if($sourceId < 1 || $length <= 0 || $power <= 0 || $collisionLayer < 0 || $normalLength < 1e-9)
		{
			throw new \InvalidArgumentException('Invalid spring');
		}

		$this->normalX /= $normalLength;
		$this->normalY /= $normalLength;
	}

	/**
	 * Build the directed one-way contact edge for this spring.
	 *
	 * @return list<array{float,float}> Endpoints ordered to produce the launch normal.
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
	 * Test whether a grounded body is supported by the spring face.
	 *
	 * @param Body $body Candidate body carrying a support point and selected collision plane.
	 * @return bool True while the body's sole overlaps and aligns with this spring.
	 */
	public function pressedBy(Body $body): bool
	{
		if(!$body->grounded || ($this->collisionLayer !== 0 && $body->collisionLayer !== $this->collisionLayer))
		{
			return false;
		}

		$supportNormalX = sin($body->angle);
		$supportNormalY = -cos($body->angle);
		$dx = $body->supportX() - $this->x;
		$dy = $body->supportY() - $this->y;
		$distance = $dx * $this->normalX + $dy * $this->normalY;
		$along = $dx * -$this->normalY + $dy * $this->normalX;

		return $supportNormalX * $this->normalX + $supportNormalY * $this->normalY >= .98
			&& abs($distance) <= .1
			&& abs($along) <= $this->length / 2 + $body->radius;
	}

	/**
	 * Launch a supported body without marking an ordinary spring bounce as a jump.
	 *
	 * @param Body $body Body whose world momentum and support state are replaced.
	 * @return void Applies authored power along the surface normal and starts visual activation.
	 */
	public function launch(Body $body): void
	{
		$body->x += $this->normalX * .05;
		$body->y += $this->normalY * .05;
		$body->vx = $this->normalX * $this->power;
		$body->vy = $this->normalY * $this->power;
		$body->groundSpeed = 0;
		$body->grounded = false;
		$body->grinding = false;
		$body->jumping = false;
		$body->crouching = false;
		$body->charge = 0;
		$body->dropCharge = 0;
		$body->coyote = 0;
		$body->jumpBuffer = 0;
		$body->ability = 'spring';
		$body->angle = $body->uprightAngle();
		$this->active = true;
		$this->activeTime = .1;
	}
}

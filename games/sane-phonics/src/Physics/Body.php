<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;

/** Mutable platformer pose with movement, gravity, ability, and terrain-contact state. */
final class Body extends \Phonics\Physics\Pose
{
	public float $vx = 0,$vy = 0,$groundSpeed = 0,$angle = 0;
	public float $radius = 8,$halfHeight = 18,$standingHeight = 18,$rollingHeight = 12;
	public bool $grounded = false,$rolling = false,$jumping = false,$crouching = false,$grinding = false;
	public bool $underwater = false,$waterEntered = false,$waterExited = false,$skimming = false;
	/** Horizontal look direction used by sprite rendering and facing-relative controls. */
	public int $facing = 1;
	/** Retained movement orientation used by surface travel and facing-relative actions. */
	public int $direction = 1;
	/** Requested glide steering, retained through neutral input independently of current travel. */
	public int $glideDirection = 0;
	public int $wall = 0;
	/** Zero is shared terrain; positive numbers select an additional collision plane. */
	public int $collisionLayer = 1;
	public string $ability = '';
	/** Retains light-dash jump-release suppression until landing or an explicit player reset. */
	public bool $lightDashed = false;
	public float $charge = 0,$crouchTime = 0,$dropCharge = 0,$flightRemaining = 5.8,$dropTimer = 0
		,$coyote = 0,$jumpBuffer = 0;
	public float $acceleration = 432,$braking = 1440,$friction = 360,$airAcceleration = 1080
		,$gravity = 1800,$jumpSpeed = 660,$maxSpeed = 1080,$terminalSpeed = 1800;
	/** Cardinal unit vector defining the direction in which gravity accelerates this body. */
	public int $gravityX = 0,$gravityY = 1;
	public float $minGrindSpeed = 360,$maxGrindSpeed = 2400,$grindAcceleration = 360;
	public float $density = 0,$waterDepth = 0;
	public ?WaterRegion $water = null;

	/**
	 * Create a mutable platformer body with default dimensions and movement tuning.
	 *
	 * @param float $x Body-center world X coordinate in pixels.
	 * @param float $y Body-center world Y coordinate in pixels.
	 * @param string $profile Movement profile: runner, glider, or flyer; unknown names are
	 * rejected.
	 * @return void Initializes an airborne actor on collision layer 1.
	 */
	public function __construct(float $x = 0, float $y = 0, public string $profile = 'runner')
	{
		$this->x = $x;
		$this->y = $y;

		if(!in_array($profile, ['runner','glider','flyer'], true))
		{
			throw new \InvalidArgumentException('Unknown movement profile');
		}

		if($profile === 'glider')
		{
			$this->jumpSpeed = 570;
		}
	}

	/**
	 * Resolve the world X coordinate where the body meets its current support plane.
	 *
	 * @return float Foot or surface-contact X coordinate after applying body angle and height.
	 */
	public function supportX(): float
	{
		return $this->x - sin($this->angle) * $this->halfHeight;
	}

	/**
	 * Resolve the world Y coordinate where the body meets its current support plane.
	 *
	 * @return float Foot or surface-contact Y coordinate after applying body angle and height.
	 */
	public function supportY(): float
	{
		return $this->y + cos($this->angle) * $this->halfHeight;
	}

	/**
	 * Select one of the four cardinal gravity directions.
	 *
	 * @param int $x Horizontal gravity component: negative one, zero, or positive one.
	 * @param int $y Vertical gravity component: negative one, zero, or positive one.
	 * @return void Replaces the active direction while retaining gravity magnitude and momentum.
	 */
	public function setGravityDirection(int $x, int $y): void
	{
		if(abs($x) + abs($y) !== 1)
		{
			throw new \InvalidArgumentException('Gravity direction must be cardinal');
		}

		$this->gravityX = $x;
		$this->gravityY = $y;
	}

	/**
	 * Resolve the world X component of the body's input-relative right tangent.
	 *
	 * @return int Horizontal component perpendicular to the active gravity direction.
	 */
	public function tangentX(): int
	{
		return $this->gravityY;
	}

	/**
	 * Resolve the world Y component of the body's input-relative right tangent.
	 *
	 * @return int Vertical component perpendicular to the active gravity direction.
	 */
	public function tangentY(): int
	{
		return -$this->gravityX;
	}

	/**
	 * Project current world velocity onto the active gravity direction.
	 *
	 * @return float Signed velocity; positive values travel with gravity.
	 */
	public function gravityVelocity(): float
	{
		return $this->vx * $this->gravityX + $this->vy * $this->gravityY;
	}

	/**
	 * Project current world velocity onto the input-relative right tangent.
	 *
	 * @return float Signed velocity; positive values travel toward logical right.
	 */
	public function tangentVelocity(): float
	{
		return $this->vx * $this->tangentX() + $this->vy * $this->tangentY();
	}

	/**
	 * Resolve the surface angle that leaves the body's sole facing with gravity.
	 *
	 * @return float World-space radians used while the actor is airborne and upright.
	 */
	public function uprightAngle(): float
	{
		return atan2($this->tangentY(), $this->tangentX());
	}
}

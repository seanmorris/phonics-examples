<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Input\Controls;
use SanePhonics\Physics\Body;
use SanePhonics\Physics\Terrain;

/** Fuelled two-dimensional aircraft with MB2-compatible takeoff, thrust, gear, pitch, and stall controls. */
final class Tornado implements VehicleBehavior, VehicleDiagnostics, VehicleTerrainAware
{
	private const REFERENCE_RATE = 60;
	private const GROUND_ACCELERATION = 1800;
	private const GROUND_BRAKING = 855;
	private const GROUND_FRICTION = 405;
	private const GROUND_SPEED = 300;
	private const CRUISE_SPEED = 480;
	private const THRUST_SPEED = 600;
	private const STALL_SPEED = 180;
	private const GRAVITY = 2340;
	private const SENSOR_LENGTH = 128;
	private const VERTICAL_SPEED_EXCHANGE = 1.025;

	public bool $flying = false, $thrusting = false, $landingGear = true, $crashed = false;
	public float $fuel = 100, $pitch = -.26;
	private float $refillDelay = 0, $noThrust = 0;
	private ?Terrain $terrain = null;

	/**
	 * Retain the annotated artwork GIDs used for the aircraft's observable states.
	 *
	 * @param array<string,int> $gids Tile GIDs keyed by tornado, tornado-flight, tornado-gear,
	 * tornado-thrust, and tornado-crashed class names.
	 * @return void Creates a fully fuelled aircraft with its landing gear extended.
	 */
	public function __construct(private array $gids = []) {}

	/**
	 * Create an aircraft chassis with reference speeds converted from sixty-hertz frame units.
	 *
	 * @param float $x Chassis center X coordinate.
	 * @param float $y Chassis center Y coordinate.
	 * @return Body Wide aircraft collision body suitable for swept terrain movement.
	 */
	public static function body(float $x = 0, float $y = 0): Body
	{
		$body = new Body($x, $y);
		$body->radius = 48;
		$body->halfHeight = $body->standingHeight = $body->rollingHeight = 24;
		$body->acceleration = self::GROUND_ACCELERATION;
		$body->braking = self::GROUND_BRAKING;
		$body->friction = self::GROUND_FRICTION;

		$body->airAcceleration = 0;
		$body->gravity = self::GRAVITY;
		$body->jumpSpeed = 480;
		$body->maxSpeed = self::GROUND_SPEED;
		$body->terminalSpeed = 30720;

		return $body;
	}

	/**
	 * Bind the reference downward flight sensor to the vehicle owner's collision terrain.
	 *
	 * @param Terrain $terrain Shared terrain used for the 128-pixel clearance ray.
	 * @return void Replaces the sensor source used by subsequent control steps.
	 */
	public function bindTerrain(Terrain $terrain): void { $this->terrain = $terrain; }

	/**
	 * Transfer a rider's forward momentum and reset transient flight state when boarding.
	 *
	 * @param Vehicle $vehicle Newly occupied Tornado.
	 * @param Body $rider Character retaining its incoming velocity.
	 * @return void Initializes ground speed, gear, fuel timers, and presentation.
	 */
	public function mounted(Vehicle $vehicle, Body $rider): void
	{
		$body = $vehicle->body;
		$body->groundSpeed = $rider->vx;
		$this->flying = !$body->grounded;
		$this->thrusting = $this->crashed = false;
		$this->landingGear = true;
		$this->pitch = -.26;
		$this->refillDelay = $this->noThrust = 0;

		$this->present($vehicle);
	}

	/**
	 * Apply takeoff, landing gear, rocket thrust, fuel, pitch, and stall controls before collision.
	 *
	 * @param Vehicle $vehicle Aircraft receiving controls.
	 * @param Controls $input Logical keyboard or gamepad controls.
	 * @param Combo|null $combo Rider reward chain, unused by flight.
	 * @param float $dt Positive simulation seconds.
	 * @return void Mutates aircraft velocity and tuning before the shared swept movement step.
	 */
	public function control(Vehicle $vehicle, Controls $input, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;
		$frames = self::REFERENCE_RATE * $dt;
		$this->noThrust = max(0, $this->noThrust - $dt);
		$this->refillDelay = max(0, $this->refillDelay - $dt);

		if($vehicle->occupant === null)
		{
			$this->thrusting = false;
			$this->refill($dt);
			$this->present($vehicle);

			return;
		}

		if(!$body->grounded && $input->pressed(1)) $this->landingGear = !$this->landingGear;

		if(!$input->down(2))
		{
			$this->thrusting = $this->crashed = false;
		}
		else
		{
			$this->landingGear = false;

			if(($this->thrusting || $this->fuel > 1) && $this->fuel > 0
				&& !$this->crashed && $this->noThrust <= 0
			){
				$this->thrusting = true;
			}
		}

		if($body->grounded)
		{
			$this->flying = false;
			$this->pitch = -.26;
		}

		if(!$this->thrusting && abs($body->vx) < self::STALL_SPEED)
		{
			$this->flying = false;
			$body->gravity = self::GRAVITY;
		}
		elseif(!$body->grounded || abs($body->groundSpeed) > self::GROUND_SPEED)
		{
			if($body->grounded)
			{
				$body->grounded = false;
				$body->vy = -120;
			}

			$this->flying = true;
			$body->gravity = 0;
		}

		if($this->thrusting && $this->fuel <= 0)
		{
			$this->thrusting = false;
			$this->noThrust = $this->refillDelay = .5;
		}

		if($this->thrusting) $this->fuel = max(0, $this->fuel - .1 * $frames);
		else $this->refill($dt);

		$direction = $body->direction ?: ($body->facing ?: (($body->vx <=> 0) ?: 1));

		if(!$this->thrusting && abs($body->vx) > self::THRUST_SPEED)
		{
			$body->vx -= min(abs($body->vx), 12 * $frames) * ($body->vx <=> 0);
		}

		if($this->thrusting && (($body->vx <=> 0) !== $direction || abs($body->vx) < self::THRUST_SPEED))
		{
			if($body->grounded && abs($body->vx) < 1e-9)
			{
				$this->pitch = -.26;
				$body->grounded = false;
				$body->jumping = true;
				$body->vy = -300;
				$this->flying = true;
				$body->gravity = 0;
			}

			$body->vx += $direction * 240 * $frames;
		}

		if(!$this->thrusting && abs($body->vx) > self::CRUISE_SPEED / 2
			&& !$input->axis('left', 'right')
		){
			$body->vx *= .95 ** $frames;
		}

		if(abs($body->vx) > self::THRUST_SPEED) $body->vx = self::THRUST_SPEED * $direction;

		if($this->flying)
		{
			$axis = $input->axis('up', 'down');

			if(abs($axis) < .1) $axis = 0;
			elseif(abs($axis) > .55) $axis = $axis <=> 0;

			$clearance = $this->clearance($body);

			// The terrain sensor stabilizes a released or nose-down aircraft near the
			// ground, but must not fight an intentional climb. Applying the damper before
			// every input capped a held nose-up command around -0.175 radians.
			if($axis >= 0 && ($clearance <= self::SENSOR_LENGTH || $this->pitch > 0))
			{
				$this->pitch *= .8 ** $frames;
			}

			if($this->landingGear) $this->pitch += .005 * $frames;

			if(abs($body->vx) < 1e-9)
			{
				$this->flying = false;
				$body->gravity = self::GRAVITY;
				$this->present($vehicle);

				return;
			}

			$newPitch = $this->pitch + ($axis <=> 0) * .035 * $frames;

			if($axis && abs($newPitch) < M_PI_2) $this->pitch = $newPitch;

			if($this->thrusting && $clearance < self::SENSOR_LENGTH)
			{
				$this->pitch -= .025 * $frames;
			}

			// Exchange forward speed continuously with climb or descent. The old reference branch
			// applied a full 2.5% step to every positive pitch and accidentally repeated that same
			// condition for climbs. Scaling its exponent by the vertical pitch component makes the
			// exchange symmetric, smooth through level flight, and independent of the fixed-step rate.
			$body->vx *= self::VERTICAL_SPEED_EXCHANGE ** (sin($this->pitch) * $frames);

			if(($body->vx <=> 0) === $direction)
			{
				$body->vy = sin($this->pitch) * $body->vx * $direction * 2;
			}

			$body->jumping = false;
		}
		elseif(!$this->thrusting && $this->pitch < M_PI_2 && $body->vy > 0)
		{
			$this->pitch += .025 * $frames;
		}

		if($body->grounded)
		{
			$this->landingGear = true;
			$this->pitch = -.26;
			$body->gravity = self::GRAVITY;
		}

		$body->maxSpeed = self::GROUND_SPEED;

		$this->present($vehicle);
	}

	/**
	 * Detect landing or a stopped airborne impact and publish final camera and artwork state.
	 *
	 * @param Vehicle $vehicle Aircraft after shared terrain collision.
	 * @param Combo|null $combo Rider reward chain, unused by flight.
	 * @param float $dt Positive simulation seconds.
	 * @return void Resolves landing, crash cooldown, camera mode, pose, and sprite selection.
	 */
	public function updated(Vehicle $vehicle, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;

		if($body->grounded)
		{
			$this->flying = $this->thrusting = $this->crashed = false;
			$this->landingGear = true;
			$this->pitch = -.26;
			$body->gravity = self::GRAVITY;
			$body->maxSpeed = self::GROUND_SPEED;
		}
		elseif(!$body->jumping && abs($body->vx) < 1e-9)
		{
			$this->flying = false;
			$body->gravity = self::GRAVITY;

			if($this->thrusting)
			{
				$this->crashed = true;
				$this->thrusting = false;
				$this->noThrust = .5;
			}
		}

		$this->present($vehicle);
	}

	/**
	 * Publish aircraft state without coupling the generic vehicle owner to this behavior type.
	 *
	 * @param Vehicle $vehicle Aircraft whose telemetry is requested.
	 * @return array<string,mixed> Serializable flight, fuel, gear, and crash state.
	 */
	public function vehicleSnapshot(Vehicle $vehicle): array
	{
		return [
			'type' => 'tornado'
			, 'flying' => $this->flying
			, 'thrusting' => $this->thrusting
			, 'landingGear' => $this->landingGear
			, 'fuel' => $this->fuel
			, 'pitch' => $this->pitch
			, 'crashed' => $this->crashed
		];
	}

	/**
	 * Restore fuel unless an exhausted thruster is inside its half-second lockout.
	 *
	 * @param float $dt Positive simulation seconds.
	 * @return void Advances the delay or replenishes fuel at fifteen units per second.
	 */
	private function refill(float $dt): void
	{
		if($this->refillDelay <= 0) $this->fuel = min(100, $this->fuel + 15 * $dt);
	}

	/**
	 * Measure the moving-boxes-2 downward sensor from the chassis support point.
	 *
	 * @param Body $body Aircraft body supplying position and collision layer.
	 * @return float Terrain distance in pixels, or 128 when the ray misses or starts on terrain.
	 */
	private function clearance(Body $body): float
	{
		if($this->terrain === null) return self::SENSOR_LENGTH;

		$hit = $this->terrain->raycast(
			$body->supportX()
			, $body->supportY()
			, 0
			, self::SENSOR_LENGTH
			, false
			, $body->collisionLayer
		);

		$distance = $hit === null ? self::SENSOR_LENGTH : self::SENSOR_LENGTH * $hit->t;

		return $distance <= 1e-9 ? self::SENSOR_LENGTH : $distance;
	}

	/**
	 * Synchronize vehicle-facing presentation with current flight state.
	 *
	 * @param Vehicle $vehicle Aircraft receiving camera, pose, angle, and annotated art state.
	 * @return void Selects an available state GID with graceful fallback to the base tile.
	 */
	private function present(Vehicle $vehicle): void
	{
		$state = $this->crashed
			? 'tornado-crashed'
			: ($this->thrusting
				? 'tornado-thrust'
				: ($this->flying ? ($this->landingGear ? 'tornado-gear' : 'tornado-flight') : 'tornado')
			);

		$vehicle->pose = $state;
		$vehicle->cameraMode = $this->flying ? 'airplane' : 'auto';
		$vehicle->visualAngle = $this->flying ? $this->pitch : 0;
		$vehicle->spriteGid = $this->gids[$state] ?? $this->gids['tornado'] ?? $vehicle->spriteGid;
	}
}

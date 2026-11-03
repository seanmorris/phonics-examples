<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Effects\CameraShake;
use SanePhonics\Effects\Rumble;
use Phonics\Input\Controls;
use SanePhonics\Physics\Body;
use Phonics\Render\SpriteLayers;

/** MB2 EggWalker locomotion and presentation composed with reusable ECS weapon components. */
final class EggWalker implements VehicleBehavior, VehicleComponents, VehicleDiagnostics
{
	public bool $crouching = false, $boosting = false, $latchedBooster = false;
	private float $age = 0;
	private ?float $standingSeat = null;
	private VehicleMelee $melee;
	private VehicleEmitter $emitter;
	private CameraShake $shake;
	private Rumble $rumble;
	private bool $dropArmed = false;
	private float $dropSpeed = 0;

	/**
	 * Retain annotated artwork and configure reference punch and cannon capabilities.
	 *
	 * @param array<string,int> $gids Tile GIDs keyed by egg-walker state class names.
	 * @param SpriteLayers|null $layers Optional rear and foreground artwork planes.
	 * @return void Creates locomotion state plus reusable melee and projectile components.
	 */
	public function __construct(private array $gids = [], private ?SpriteLayers $layers = null)
	{
		$this->melee = new VehicleMelee(1, 12 / 60, 25 / 60, 128, 24, 48);

		$this->emitter = new VehicleEmitter(
			2
			, 6 / 60
			, 240
			, 540
			, 6 / 60
			, 200 / 60
			, 4
			, 24
			, 72
			, $gids['egg-walker-shot'] ?? null
		);

		$this->shake = new CameraShake;
		$this->rumble = new Rumble;
	}

	/**
	 * Create the reference thirty-two by sixty-four physics body at sixty-hertz-derived tuning.
	 *
	 * @param float $x Chassis center X coordinate.
	 * @param float $y Chassis center Y coordinate, thirty-two pixels above its support point.
	 * @return Body Slow, heavy walker body using the common swept surface solver.
	 */
	public static function body(float $x = 0, float $y = 0): Body
	{
		$body = new Body($x, $y);
		$body->radius = 16;
		$body->halfHeight = $body->standingHeight = $body->rollingHeight = 32;
		$body->acceleration = 360;
		$body->braking = 324;
		$body->friction = 180;
		$body->airAcceleration = 1080;
		$body->gravity = 2340;
		$body->jumpSpeed = 720;
		$body->maxSpeed = 150;

		return $body;
	}

	/**
	 * Register generic weapon, camera, and artwork components beside the EggWalker marker.
	 *
	 * @return list<object> Melee, projectile, shake, and optional layered-art capabilities.
	 */
	public function vehicleComponents(): array
	{
		$components = [$this->melee, $this->emitter, $this->shake, $this->rumble];

		if($this->layers !== null) $components[] = $this->layers;

		return $components;
	}

	/**
	 * Carry entry momentum into the chassis and reset locomotion state.
	 *
	 * @param Vehicle $vehicle Newly occupied walker.
	 * @param Body $rider Character retaining incoming velocity and facing.
	 * @return void Initializes direction, tangent speed, seat baseline, and presentation.
	 */
	public function mounted(Vehicle $vehicle, Body $rider): void
	{
		$body = $vehicle->body;
		$body->groundSpeed = $rider->vx * cos($body->angle) + $rider->vy * sin($body->angle);
		$body->facing = $body->direction = $rider->facing ?: $body->direction;
		$this->standingSeat = $vehicle->seatHeight;
		$this->crouching = $this->boosting = $this->latchedBooster = false;
		$this->dropArmed = false;
		$this->dropSpeed = 0;
		$this->shake->impulse();
		$vehicle->jumpEnabled = true;
		$this->present($vehicle);
	}

	/**
	 * Apply reference facing, crouch-drop, booster, and jump gating controls.
	 *
	 * Weapon timing is intentionally absent: VehicleWeapons operates on the attached reusable ECS
	 * components before this behavior chooses its presentation.
	 *
	 * @param Vehicle $vehicle Walker receiving locomotion controls.
	 * @param Controls $input Logical keyboard or gamepad controls.
	 * @param Combo|null $combo Rider reward chain, unused by the walker.
	 * @param float $dt Positive simulation delta in seconds.
	 * @return void Updates body geometry, vertical velocity, jump gating, and artwork.
	 */
	public function control(Vehicle $vehicle, Controls $input, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;
		$this->standingSeat ??= $vehicle->seatHeight;
		$this->age += $dt;

		if($vehicle->occupant === null)
		{
			$this->boosting = $this->latchedBooster = $this->crouching = false;
			$this->dropArmed = false;
			$this->dropSpeed = 0;
			$this->resize($vehicle, false);
			$vehicle->jumpEnabled = true;
			$this->present($vehicle);

			return;
		}

		if($input->pressed(4)) $body->facing = $body->direction = -1;

		if($input->pressed(5)) $body->facing = $body->direction = 1;

		$vertical = $input->axis('up', 'down');
		$horizontal = $input->axis('left', 'right');
		$descending = !$body->grounded && $body->gravityVelocity() > 0;
		$crouching = $vertical > .55 && (!$horizontal || $descending);

		if($crouching && !$this->crouching && $descending)
		{
			$body->vx += $body->gravityX * 240;
			$body->vy += $body->gravityY * 240;
			$this->dropArmed = true;
			$this->dropSpeed = 0;
		}

		$this->crouching = $crouching;
		$this->resize($vehicle, $crouching);
		$vehicle->jumpEnabled = !$crouching;
		$this->emitter->normalOffset = $crouching ? 52 : 72;

		if($crouching)
		{
			$body->groundSpeed = 0;
			$this->latchedBooster = false;
		}
		elseif($vertical > .55)
		{
			$this->latchedBooster = false;
		}

		if(!$body->grounded && $input->down(0) && $vertical <= .55)
		{
			if($vertical < -.55) $this->latchedBooster = true;

			$this->boosting = true;
		}
		elseif(!$this->latchedBooster)
		{
			$this->boosting = false;
		}

		if($this->boosting && !$body->grounded && $body->gravityVelocity() > 0)
		{
			$force = ((sin($this->age * 6) + .9) / 2 + .5) * 3600 * $dt;
			$body->vx -= $body->gravityX * $force;
			$body->vy -= $body->gravityY * $force;
		}

		if($this->dropArmed && !$body->grounded)
		{
			$this->dropSpeed = max($this->dropSpeed, $body->gravityVelocity() + $body->gravity * $dt);
		}

		$this->present($vehicle);
	}

	/**
	 * Cancel an airborne booster on landing and publish the final pose and state tile.
	 *
	 * @param Vehicle $vehicle Walker after its shared movement step.
	 * @param Combo|null $combo Rider reward chain, unused by the walker.
	 * @param float $dt Positive simulation delta used by the camera-shake decay.
	 * @return void Reconciles landing impact, shake, state, and presentation with the endpoint.
	 */
	public function updated(Vehicle $vehicle, ?Combo $combo, float $dt): void
	{
		if($vehicle->body->grounded)
		{
			$this->boosting = $this->latchedBooster = false;

			if($this->dropArmed && $this->crouching && $this->dropSpeed >= 8 * 60)
			{
				$this->shake->impulse(y: 7);
				$this->rumble->cameraShake(7);
			}

			$this->dropArmed = false;
			$this->dropSpeed = 0;
		}

		$this->shake->update($dt);

		$this->present($vehicle);
	}

	/**
	 * Publish behavior and composed capability state without a branch in the generic owner.
	 *
	 * @param Vehicle $vehicle Walker whose telemetry is requested.
	 * @return array<string,mixed> Serializable locomotion and weapon state.
	 */
	public function vehicleSnapshot(Vehicle $vehicle): array
	{
		return [
			'type' => 'egg-walker'
			, 'crouching' => $this->crouching
			, 'boosting' => $this->boosting
			, 'punching' => $this->melee->attacking()
			, 'shooting' => $this->emitter->firing()
			, 'shots' => $this->emitter->shots
			, 'shakeY' => $this->shake->y
		];
	}

	/**
	 * Preserve the support point while switching between sixty-four and forty-eight pixel bodies.
	 *
	 * @param Vehicle $vehicle Walker whose chassis and seat geometry are updated.
	 * @param bool $crouching Whether to use the compact forty-eight pixel body.
	 * @return void Resizes collision and adjusts the seat without moving the feet.
	 */
	private function resize(Vehicle $vehicle, bool $crouching): void
	{
		$body = $vehicle->body;
		$height = $crouching ? 24.0 : 32.0;
		$supportX = $body->supportX();
		$supportY = $body->supportY();
		$body->standingHeight = $body->rollingHeight = $height;
		$body->halfHeight = $height;
		$body->x = $supportX + sin($body->angle) * $height;
		$body->y = $supportY - cos($body->angle) * $height;
		$vehicle->seatHeight = max(0, ($this->standingSeat ?? 48) - ($crouching ? 18 : 0));
	}

	/**
	 * Select rider pose, walker camera framing, and the closest available state tile.
	 *
	 * @param Vehicle $vehicle Walker receiving presentation-only state.
	 * @return void Updates pose, camera mode, and annotated sprite GID.
	 */
	private function present(Vehicle $vehicle): void
	{
		$body = $vehicle->body;
		$vehicle->cameraMode = 'walker';
		$state = 'egg-walker';
		$vehicle->pose = 'riding';

		if($this->crouching)
		{
			$state = 'egg-walker-crouching';
			$vehicle->pose = 'riding-crouching';
		}
		elseif($this->melee->attacking()) $state = 'egg-walker-punch';
		elseif($this->emitter->firing()) $state = 'egg-walker-shooting';
		elseif($this->boosting && !$body->grounded) $state = 'egg-walker-boosting';
		elseif(!$body->grounded) $state = 'egg-walker-falling';
		elseif(abs($body->groundSpeed) > 1) $state = 'egg-walker-walking';

		$vehicle->spriteGid = $this->gids[$state] ?? $this->gids['egg-walker'] ?? null;

		if($this->layers !== null && isset($this->layers->front[0]))
		{
			$this->layers->front[0]->gid = $this->gids[$state . '-front']
				?? $this->gids['egg-walker-front']
				?? $this->layers->front[0]->gid;
		}
	}
}

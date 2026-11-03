<?php
declare(strict_types=1);
namespace SanePhonics\Physics;
use Phonics\Physics\Hit;
use Phonics\Input\Controls;

/** Fresh surface-tangent controller. Distances are pixels, time is seconds. */
final class Platformer
{
	private ?\Closure $onImpact;

	/**
	 * Bind surface-tangent movement to a terrain collision source.
	 *
	 * @param Terrain $terrain Collision terrain and directional gates used by movement and sensor
	 * casts.
	 * @param callable(Body,Hit):mixed|null $onImpact Callback before collision response; removing
	 * the contacted shape allows remaining movement. The callback's return value is ignored.
	 * @param WaterRegions|null $waters Optional liquid volumes that change movement and expose
	 * entry, exit, buoyancy, current, and surface-skimming state.
	 * @return void Creates a controller that can update multiple independent bodies.
	 */
	public function __construct(
		public Terrain $terrain
		, ?callable $onImpact = null
		, public ?WaterRegions $waters = null
	){
		$this->onImpact = $onImpact === null ? null : \Closure::fromCallable($onImpact);
	}

	/**
	 * Move a scalar toward a target without overshooting.
	 *
	 * @param float $value Current scalar value.
	 * @param float $target Desired scalar value.
	 * @param float $step Nonnegative maximum change for this update.
	 * @return float Value after applying at most the supplied step.
	 */
	private static function approach(float $value, float $target, float $step): float
	{
		return $value < $target?min($target, $value + $step):max($target, $value - $step);
	}

	/**
	 * Replace velocity components expressed along the body's logical tangent and gravity axes.
	 *
	 * @param Body $body Body receiving reconstructed world velocity.
	 * @param float $tangent Signed velocity toward logical right.
	 * @param float $gravity Signed velocity in the active gravity direction.
	 * @return void Updates world X and Y velocity without changing gravity orientation.
	 */
	private static function velocity(Body $body, float $tangent, float $gravity): void
	{
		$body->vx = $body->tangentX() * $tangent + $body->gravityX * $gravity;
		$body->vy = $body->tangentY() * $tangent + $body->gravityY * $gravity;
	}

	/**
	 * Advance one actor through controls, abilities, continuous collision, and surface adhesion.
	 *
	 * @param Body $b Actor to simulate in place.
	 * @param Controls $input Current logical controls and button edges.
	 * @param float $dt Finite, positive simulation delta in seconds.
	 * @param bool $jumpConsumed True when an earlier ability intercepted this step's jump press.
	 * @return void Mutates pose, momentum, collision layer, and ability state on the supplied body.
	 */
	public function step(Body $b, Controls $input, float $dt, bool $jumpConsumed = false): void
	{
		if($dt <= 0 || !is_finite($dt))
		{
			throw new \InvalidArgumentException('Invalid physics step');
		}

		if($b->ability !== 'glide')
		{
			$b->glideDirection = 0;
		}

		$startX = $b->x;
		$startY = $b->y;
		$previousWater = $b->water;
		$water = $this->waters?->at($b->x, $b->y);
		$this->waterState($b, $water, $previousWater);

		if(in_array($b->ability, ['hanging', 'riding'], true))
		{
			return;
		}

		$drag = $water?->drag ?? 1;
		$gravityScale = $water?->gravity ?? 1;
		$axis = $input->axis('left', 'right');

		if($water !== null && $b->density > 0 && $b->density < $water->density && $b->waterDepth > 0)
		{
			$b->grounded = false;
		}

		if($axis && $b->grounded)
		{
			$b->facing = $axis <=> 0;

			if(!$b->grinding || abs($b->groundSpeed) < $b->minGrindSpeed)
			{
				$b->direction = $b->facing;
			}
		}

		// Retry blocked gates while overlapping, including actors at rest.
		$seen = [];

		while($gate = $this->terrain->nextSwitch($b, 0, 0, $seen))
		{
			$seen[$gate['id']] = true;
			$this->terrain->applySwitch($b, $gate['switch']);
		}

		$b->dropTimer = max(0, $b->dropTimer - $dt);
		$b->jumpBuffer = max(0, $b->jumpBuffer - $dt);
		$b->coyote = $b->grounded?.08:max(0, $b->coyote - $dt);

		if(!$jumpConsumed && $input->pressed('jump'))
		{
			$b->jumpBuffer = .1;
		}

		if($b->grounded)
		{
			$b->flightRemaining = 5.8;
			$b->ability = '';
			$b->jumping = false;
			$b->lightDashed = false;
		}

		if($b->ability === 'climb')
		{
			$b->grinding = false;
			$b->jumping = false;
			$b->crouching = false;
			$b->charge = 0;
			$b->crouchTime = 0;
			$this->height($b);
			$this->climb($b, $input, $dt);

			return;
		}

		if($b->grounded)
		{
			if($b->grinding)
			{
				$this->grindPose($b, $input);
			}
			else
			{
				$this->groundPose($b, $input, $dt);
			}

			if(!$b->charge)
			{
				$slope = $b->grinding ? .75 : ($b->rolling ? .75 : .35);
				$gravitySlope = $b->gravityX * cos($b->angle) + $b->gravityY * sin($b->angle);
				$b->groundSpeed += $gravitySlope * $b->gravity * $gravityScale * $slope * $dt;

				if($b->grinding)
				{
					$direction = $b->groundSpeed <=> 0;

					if($direction === 0)
					{
						$direction = $b->direction ?: $b->facing ?: 1;
					}

					$speed = abs($b->groundSpeed);

					if($axis && ($axis === $direction || $speed < $b->minGrindSpeed))
					{
						$speed += $b->acceleration * abs($axis) * $dt;
					}
					elseif(!$b->crouching)
					{
						$speed = max(0, $speed - $b->friction * .125 * $dt);
					}

					$speed += $b->grindAcceleration * $dt;
					$b->groundSpeed = $direction * max($b->minGrindSpeed, min($b->maxGrindSpeed, $speed));
					$b->direction = $direction;
				}
				elseif($axis && !$b->rolling)
				{
					$accel = $axis * $b->groundSpeed < 0?$b->braking:$b->acceleration;

					// Input cannot erase momentum already above the running cap.
					if(abs($b->groundSpeed) < $b->maxSpeed || $axis * $b->groundSpeed < 0)
					{
						$b->groundSpeed = self::approach($b->groundSpeed, $axis * $b->maxSpeed, $accel * $drag * $dt);
					}
				}
				else
				{
					$b->groundSpeed = self::approach(
						$b->groundSpeed
						, 0
						, ($b->rolling?$b->friction * .12:$b->friction) * $dt
					);
				}
			}

			if($b->rolling && abs($b->groundSpeed) < 60 && !$input->down('down'))
			{
				$this->stand($b);
			}

			$this->height($b);
			$b->vx = cos($b->angle) * $b->groundSpeed;
			$b->vy = sin($b->angle) * $b->groundSpeed;

			// Slow actors lose adhesion on walls/ceilings. Fast actors retain tangent motion.
			$supportGravity = -sin($b->angle) * $b->gravityX + cos($b->angle) * $b->gravityY;

			if($supportGravity < .5 && abs($b->groundSpeed) < 180)
			{
				$b->grounded = false;
				$b->coyote = 0;
			}
		}

		$jumped = false;

		if($b->jumpBuffer > 0 && $b->coyote > 0)
		{
			$platform = $this->terrain->raycast(
				$b->x
				, $b->y
				, -sin($b->angle) * ($b->halfHeight + 1)
				, cos($b->angle) * ($b->halfHeight + 1)
				, false
				, $b->collisionLayer
			);

			$spindashTap = $input->down('down') && $input->pressed('jump') && $b->crouching
				&& !($platform?->oneWay ?? false);

			if($input->down('down') && $platform?->oneWay)
			{
				$b->dropTimer = .25;
				$b->grounded = false;
				$b->x += $b->gravityX * .2;
				$b->y += $b->gravityY * .2;
			}
			elseif(!$spindashTap)
			{
				$b->vx += sin($b->angle) * $b->jumpSpeed * $drag;
				$b->vy -= cos($b->angle) * $b->jumpSpeed * $drag;
				$b->grounded = false;
				$b->rolling = false;
				$b->grinding = false;
				$b->jumping = true;
				$b->crouching = false;
				$b->charge = 0;
				$b->crouchTime = 0;
				$this->height($b);
				$b->x += sin($b->angle) * .05;
				$b->y -= cos($b->angle) * .05;
			}

			$b->jumpBuffer = 0;
			$b->coyote = 0;
			$jumped = !$spindashTap;
		}

		if(!$b->grounded)
		{
			$b->grinding = false;
			$b->crouching = false;
			$b->charge = 0;
			$b->crouchTime = 0;

			$gravityVelocity = $b->gravityVelocity();

			if($input->released('jump') && $b->jumping && $gravityVelocity < 0 && $b->ability === ''
				&& !$b->lightDashed
			){
				self::velocity($b, $b->tangentVelocity(), $gravityVelocity * .5);
			}

			if($b->jumping && !$jumped && !$jumpConsumed && $input->pressed('jump') && $b->ability !== 'lightdash')
			{
				if($b->profile === 'flyer' && $b->flightRemaining > 0)
				{
					$b->ability = 'fly';
					self::velocity($b, $b->tangentVelocity(), -120);
				}

				if($b->profile === 'glider')
				{
					$b->ability = 'glide';
					$tangentVelocity = $b->tangentVelocity();
					$b->glideDirection = ($tangentVelocity <=> 0) ?: $b->facing;

					self::velocity(
						$b
						, $b->glideDirection * max(240, abs($tangentVelocity))
						, max(0, $b->gravityVelocity())
					);
				}
			}

			if($b->profile === 'runner' && $b->jumping && $input->down('action') && $b->ability !== 'lightdash')
			{
				$b->dropCharge = min(1, $b->dropCharge + $dt * 2);
			}
			elseif($b->dropCharge > 0)
			{
				$b->dropCharge = 0;
			}

			$gravity = $b->gravity * $gravityScale;

			if($water !== null)
			{
				$b->vx += $water->currentX * $dt;
				$b->vy += $water->currentY * $dt;

				if($b->density > 0 && $b->density < $water->density)
				{
					$gravity -= $b->gravity * ($water->density / $b->density) * $b->waterDepth;
				}
			}

			if($b->ability === 'fly')
			{
				$b->flightRemaining = max(0, $b->flightRemaining - $dt);
				$gravity *= .125;

				if($input->down('jump') && $b->flightRemaining > 0)
				{
					self::velocity($b, $b->tangentVelocity(), max(-180, $b->gravityVelocity() - 600 * $dt));
				}

				if($b->flightRemaining <= 0 || $input->down('down'))
				{
					$b->ability = '';
				}
			}
			elseif($b->ability === 'glide')
			{
				$gravity *= .125;
				$tangentVelocity = $b->tangentVelocity();

				if($axis)
				{
					$b->glideDirection = $axis <=> 0;
				}

				$b->glideDirection = $b->glideDirection ?: (($tangentVelocity <=> 0) ?: $b->facing);
				// MB2 retains requested steering and brakes four times faster during a reversal.
				$turning = $tangentVelocity * $b->glideDirection < 0;

				$tangentVelocity = self::approach(
					$tangentVelocity
					, $b->glideDirection * 420
					, 240 * ($turning ? 4 : 1) * $dt
				);

				self::velocity($b, $tangentVelocity, min(100, $b->gravityVelocity()));

				if($tangentVelocity != 0)
				{
					$b->facing = $b->direction = $tangentVelocity <=> 0;
				}

				if($input->down('down'))
				{
					$b->ability = '';
				}
			}

			$gravityVelocity = $b->gravityVelocity();

			if($b->jumping && $gravityVelocity > -240 && $gravityVelocity < 0)
			{
				// MB2 loses 1/32 of horizontal speed per reference frame near a jump's apex.
				self::velocity($b, $b->tangentVelocity() * (31 / 32) ** (60 * $dt), $gravityVelocity);
			}

			// The speed gate also rejects braking; apex drag can bring a jump back below it.
			if(!in_array($b->ability, ['glide', 'lightdash'], true) && abs($b->tangentVelocity()) < $b->maxSpeed)
			{
				self::velocity(
					$b
					, $b->tangentVelocity() + $axis * $b->airAcceleration * $drag * $dt
					, $b->gravityVelocity()
				);
			}

			self::velocity(
				$b
				, $b->tangentVelocity()
				, min($b->terminalSpeed * $drag, $b->gravityVelocity() + $gravity * $dt)
			);
		}

		$wasGrounded = $b->grounded;
		// MB2 permits skimming only in floor mode, within 45 degrees of upright.
		// Retain the starting orientation: losing adhesion resets the angle below.
		$skimEligible = $wasGrounded && $b->gravityX === 0 && $b->gravityY === 1
			&& cos($b->angle) >= M_SQRT1_2;

		if($wasGrounded)
		{
			// Arc increments control curvature. Every increment remains a continuous cast.
			$steps = max(1, min(1024, (int)ceil(abs($b->groundSpeed) * $dt / 4)));

			for($i = 0; $i < $steps; $i++)
			{
				if($b->grounded)
				{
					$b->vx = cos($b->angle) * $b->groundSpeed;
					$b->vy = sin($b->angle) * $b->groundSpeed;
				}

				$this->sweep($b, $b->vx * $dt / $steps, $b->vy * $dt / $steps);

				if($b->grounded)
				{
					$this->adhere($b);
				}
			}
		}
		else
		{
			$this->sweep($b, $b->vx * $dt, $b->vy * $dt);
		}

		if(!$wasGrounded && $b->grounded)
		{
			$b->groundSpeed = $b->vx * cos($b->angle) + $b->vy * sin($b->angle);
			$b->jumping = false;
			$b->lightDashed = false;

			if($b->dropCharge > 0)
			{
				// MB2 processes landing input before checkDropDash, after air speed becomes ground speed.
				if($axis && abs($b->groundSpeed) > 1e-8 && (!$b->grinding || abs($b->groundSpeed) < 180))
				{
					$b->facing = $b->direction = $axis <=> 0;
				}

				$b->groundSpeed += $b->direction * (360 + 480 * $b->dropCharge);
				$b->rolling = true;
				$b->dropCharge = 0;
			}

			$b->ability = '';
			$b->charge = 0;
			$this->height($b);
		}

		if(!$b->grounded)
		{
			$b->grinding = false;
			$b->angle = $b->uprightAngle();
		}
		elseif($b->grinding && abs($b->groundSpeed) > 1e-8)
		{
			$b->direction = $b->groundSpeed <=> 0;
		}

		if($b->ability !== 'glide')
		{
			$b->glideDirection = 0;
		}

		$this->finishWater($b, $previousWater, $startX, $startY, $skimEligible);
	}

	/**
	 * Publish current liquid occupancy and one-step entry or exit edges on a body.
	 *
	 * @param Body $body Body receiving the active region and overlap fraction.
	 * @param WaterRegion|null $water Region containing the body's center.
	 * @param WaterRegion|null $previous Region retained after the preceding physics step.
	 * @return void Updates water, underwater, transition, depth, and skimming state.
	 */
	private function waterState(Body $body, ?WaterRegion $water, ?WaterRegion $previous): void
	{
		$body->waterEntered = $water !== null && $water !== $previous;
		$body->waterExited = $previous !== null && $water !== $previous;
		$body->water = $water;
		$body->underwater = $water !== null;
		$body->waterDepth = $water?->depth($body) ?? 0;
		$body->skimming = false;
	}

	/**
	 * Resolve fast water-surface skimming and retain boundary events after the movement sweep.
	 * Segment intersection catches complete pass-throughs without treating water as solid terrain.
	 *
	 * @param Body $body Body after terrain movement and adhesion.
	 * @param WaterRegion|null $previous Region active at the end of the preceding step.
	 * @param float $startX World X coordinate before this step.
	 * @param float $startY World Y coordinate before this step.
	 * @param bool $skimEligible Whether the body began its sweep grounded and facing a floor.
	 * @return void Snaps eligible runners to the surface and publishes final liquid state.
	 */
	private function finishWater(
		Body $body
		, ?WaterRegion $previous
		, float $startX
		, float $startY
		, bool $skimEligible
	): void {
		if($this->waters === null)
		{
			return;
		}

		$dy = $body->y - $startY;
		$bottom = $body->y + $body->halfHeight;
		$tolerance = max(2, abs($dy) + .01);
		$surface = $this->waters->surfaceAt($body->x, $bottom, $tolerance);
		$speed = abs($body->grounded ? $body->groundSpeed : $body->vx);
		$startedAbove = $startY + $body->halfHeight <= ($surface?->y ?? -INF) + 2;
		$floorContact = cos($body->angle) >= M_SQRT1_2;

		if($skimEligible && $floorContact && $surface !== null && $speed >= $surface->skimSpeed
			&& ($dy >= 0 || $body->skimming)
			&& $startedAbove
		){
			$body->y = $surface->y - $body->halfHeight - .001;
			$body->groundSpeed = $body->grounded ? $body->groundSpeed : $body->vx;
			$body->vx = $body->groundSpeed;
			$body->vy = 0;
			$body->grounded = true;
			$body->angle = 0;
			$body->water = null;
			$body->underwater = false;
			$body->waterDepth = 0;
			$body->skimming = true;
		}
		else
		{
			$final = $this->waters->at($body->x, $body->y);
			$entered = $body->waterEntered;
			$exited = $body->waterExited;
			$this->waterState($body, $final, $previous);
			$body->waterEntered = $body->waterEntered || $entered;
			$body->waterExited = $body->waterExited || $exited;
		}

		if($this->waters->crossed($startX, $startY, $body->x - $startX, $body->y - $startY)
			&& $previous === null && $body->water === null && !$body->skimming
		){
			$body->waterEntered = $body->waterExited = true;
		}
	}

	/**
	 * Select the visual crouch and clear ground-only actions while attached to a rail.
	 *
	 * @param Body $b Grounded body whose collision contact is annotated as grinding.
	 * @param Controls $input Logical controls used to select the low-friction crouching pose.
	 * @return void Keeps standing collision bounds and leaves speed changes to the ground solver.
	 */
	private function grindPose(Body $b, Controls $input): void
	{
		$b->rolling = false;
		$b->crouching = $input->down('down');
		$b->charge = 0;
		$b->crouchTime = 0;
		$b->dropCharge = 0;
	}

	/**
	 * Select standing, crouching, rolling, or charged-spindash state on a surface.
	 *
	 * @param Body $b Grounded actor whose pose and tangent speed are updated.
	 * @param Controls $input Logical directions plus jump taps and held action input.
	 * @param float $dt Simulation delta in seconds used for passive charge decay.
	 * @return void Retains charge while Down stays held and launches it when Down is released.
	 */
	private function groundPose(Body $b, Controls $input, float $dt): void
	{
		$down = $input->down('down');
		$b->crouching = false;

		if($down && abs($b->groundSpeed) > 100)
		{
			$b->rolling = true;
			$b->charge = 0;
			$b->crouchTime = 0;

			return;
		}

		if(!$down)
		{
			$b->crouchTime = 0;

			if($b->charge > 0)
			{
				$power = max(.375, min(1, $b->charge));
				$b->groundSpeed = $b->facing * (480 + 960 * $power);
				$b->rolling = true;
				$b->charge = 0;
			}

			return;
		}

		if($b->rolling || abs($b->groundSpeed) >= 1)
		{
			$b->charge = 0;
			$b->crouchTime = 0;

			return;
		}

		$b->crouching = true;
		$b->groundSpeed = 0;

		if($input->pressed('jump') || $input->pressed('action'))
		{
			$b->charge = min(1, $b->charge + .25);
		}

		if($input->down('action'))
		{
			$b->charge = min(1, $b->charge + 1.5 * $dt);
		}

		$b->charge = max(0, $b->charge - .3 * $dt);
		$b->crouchTime = $b->charge > 0 ? 0 : $b->crouchTime + $dt;
	}

	/**
	 * Apply compact somersault or standing height while preserving the contact point along the body
	 * normal.
	 *
	 * @param Body $b Actor whose rolling or jumping flag selects the desired height.
	 * @return void Moves the body center and updates its half-height.
	 */
	private function height(Body $b): void
	{
		$height = $b->rolling || $b->jumping?$b->rollingHeight:$b->standingHeight;
		$b->y += ($b->halfHeight - $height) * cos($b->angle);
		$b->x -= ($b->halfHeight - $height) * sin($b->angle);
		$b->halfHeight = $height;
	}

	/**
	 * Leave rolling mode when a head-clearance ray finds room to stand.
	 *
	 * @param Body $b Actor whose current head position and standing height are tested.
	 * @return void Clears the rolling flag only when the selected collision plane permits standing.
	 */
	private function stand(Body $b): void
	{
		$extra = ($b->standingHeight - $b->halfHeight) * 2;
		$nx = sin($b->angle);
		$ny = -cos($b->angle);

		if(!$this->terrain->raycast(
			$b->x + $nx * $b->halfHeight
			, $b->y + $ny * $b->halfHeight
			, $nx * $extra
			, $ny * $extra
			, true
			, $b->collisionLayer
		)){
			$b->rolling = false;
		}
	}

	/**
	 * Resolve the earliest terrain impact or gate crossing over the full remaining motion.
	 * Expanded segment endpoints close sensor gaps; unresolved motion after six contacts is
	 * discarded. Removed shapes preserve momentum and do not consume that contact budget;
	 * at most 1024 removals are processed per sweep to bound custom impact callbacks.
	 *
	 * @param Body $b Actor whose oriented bounds and collision layer participate in each cast.
	 * @param float $dx Full proposed horizontal displacement in pixels.
	 * @param float $dy Full proposed vertical displacement in pixels.
	 * @return void Moves the body, switches eligible routes, and clips momentum against contact
	 * normals.
	 */
	private function sweep(Body $b, float $dx, float $dy): void
	{
		$seen = [];
		$contacts = 0;
		$removed = 0;

		while($contacts < 6 && $removed < 1024 && abs($dx) + abs($dy) > 1e-7)
		{
			$best = $this->terrain->castBody($b, $dx, $dy, $b->dropTimer > 0);
			$gate = $this->terrain->nextSwitch($b, $dx, $dy, $seen);

			if($gate && (!$best || $gate['t'] < $best->t))
			{
				$b->x += $dx * $gate['t'];
				$b->y += $dy * $gate['t'];
				$dx *= 1 - $gate['t'];
				$dy *= 1 - $gate['t'];
				$seen[$gate['id']] = true;
				$this->terrain->applySwitch($b, $gate['switch']);
				// Recast the unconsumed motion against the newly selected plane.
				continue;
			}

			if(!$best)
			{
				$b->x += $dx;
				$b->y += $dy;

				return;
			}

			if($this->onImpact !== null)
			{
				$shape = $best->shape;
				$t = $best->t;
				($this->onImpact)($b, $best);

				if($shape !== null && !$this->terrain->hasShape($shape))
				{
					// Advance to the removed obstacle, then sweep the rest against live geometry.
					$b->x += $dx * $t;
					$b->y += $dy * $t;
					$dx *= 1 - $t;
					$dy *= 1 - $t;
					$removed++;
					continue;
				}
			}

			if($b->ability === 'lightdash')
			{
				$b->ability = '';
			}

			$contacts++;
			$b->x += $dx * $best->t + $best->nx * .001;
			$b->y += $dy * $best->t + $best->ny * .001;
			$normalGravity = $best->nx * $b->gravityX + $best->ny * $b->gravityY;
			$normalTangent = $best->nx * $b->tangentX() + $best->ny * $b->tangentY();
			$support = $normalGravity < -.25;
			// MB2 distinguishes a flat head bump from an angled upper collision. An actor moving
			// against gravity may acquire an angled wall-to-ceiling surface, then ordinary grounded
			// adhesion carries it around the rest of the curve. Both tests use gravity-local axes so
			// rotated worlds behave identically.
			$ceilingReground = !$b->grounded && $b->gravityVelocity() < -180 && $normalGravity > .25
				&& abs($normalTangent) > .25;

			if($support || $ceilingReground
				|| ($b->grounded && ($best->nx * sin($b->angle) - $best->ny * cos($b->angle)) > .5
				)
			){
				$b->grounded = true;
				$b->grinding = $best->grinding;
				$b->angle = atan2($best->nx, -$best->ny);
				$distance = ($b->x - $best->x) * $best->nx + ($b->y - $best->y) * $best->ny;
				$b->x += $best->nx * ($b->halfHeight + .001 - $distance);
				$b->y += $best->ny * ($b->halfHeight + .001 - $distance);
			}
			elseif(abs($normalTangent) > .8)
			{
				$b->grinding = false;

				if($b->ability === 'glide' && $b->gravityX === 0 && $b->gravityY === 1)
				{
					$b->ability = 'climb';
					$b->jumping = false;
					$b->wall = (int)-$best->nx;
					$b->vx = $b->vy = 0;

					return;
				}

				$b->groundSpeed = 0;
			}

			$remaining = 1 - $best->t;
			$dx *= $remaining;
			$dy *= $remaining;
			$into = $dx * $best->nx + $dy * $best->ny;
			$dx -= $into * $best->nx;
			$dy -= $into * $best->ny;
			$velocity = $b->vx * $best->nx + $b->vy * $best->ny;

			if($velocity < 0)
			{
				$b->vx -= $velocity * $best->nx;
				$b->vy -= $velocity * $best->ny;
			}
		}
	}

	/**
	 * Cast short inward foot rays to maintain floor, wall, or ceiling attachment.
	 *
	 * @param Body $b Grounded actor whose pose and surface angle are updated.
	 * @return void Aligns the actor to the nearest support, or releases it when both sensors miss.
	 */
	private function adhere(Body $b): void
	{
		$ca = cos($b->angle);
		$sa = sin($b->angle);
		$hits = [];

		foreach([-$b->radius * .6,$b->radius * .6] as $side)
		{
			$x = $b->x + $side * $ca - ($b->halfHeight - 6) * $sa;
			$y = $b->y + $side * $sa + ($b->halfHeight - 6) * $ca;

			$hit = $this->terrain->raycast($x, $y, -$sa * 16, $ca * 16, $b->dropTimer > 0, $b->collisionLayer);

			if($hit)
			{
				$hits[] = $hit;
			}
		}

		if(!$hits)
		{
			$b->grounded = false;
			$b->grinding = false;

			return;
		}

		$distance = min(array_map(
			/**
			 * Convert a foot-ray contact fraction to distance from the nominal sole.
			 *
			 * @param Hit $h Contact on a 16-pixel inward ray starting six pixels above the sole.
			 * @return float Signed support correction in pixels.
			 */
			fn($h) => $h->t * 16 - 6
			, $hits
		));

		// A lower neighboring ledge is not a second point on the nearest support surface.
		$supports = [];

		foreach($hits as $hit)
		{
			if(abs($hit->t * 16 - 6 - $distance) < .01)
			{
				$supports[] = $hit;
			}
		}

		$b->x -= $sa * $distance;
		$b->y += $ca * $distance;
		$b->grinding = false;

		foreach($supports as $hit)
		{
			if($hit->grinding)
			{
				$b->grinding = true;

				break;
			}
		}

		if(count($supports) === 2)
		{
			$b->angle = atan2($supports[1]->y - $supports[0]->y, $supports[1]->x - $supports[0]->x);
		}
		else
		{
			$b->angle = atan2($supports[0]->nx, -$supports[0]->ny);
		}
	}

	/**
	 * Advance a glider along a caught wall, handling jumps and the wall's end.
	 *
	 * @param Body $b Actor currently using the climb ability.
	 * @param Controls $input Logical climb and jump controls.
	 * @param float $dt Simulation delta in seconds.
	 * @return void Updates the actor's pose, velocity, and climb ability state.
	 */
	private function climb(Body $b, Controls $input, float $dt): void
	{
		if($input->pressed('jump'))
		{
			$b->ability = '';
			$b->jumping = true;
			$b->vx = -$b->wall * 300;
			$b->vy = -$b->jumpSpeed * .8;
			$this->height($b);
			$b->x -= $b->wall * .1;

			return;
		}

		$dy = $input->axis('up', 'down') * 240 * $dt;
		$b->vx = 0;
		$b->vy = $dy / $dt;
		$this->sweep($b, 0, $dy);

		$hit = $this->terrain->raycast($b->x, $b->y, $b->wall * ($b->radius + 3), 0, true, $b->collisionLayer);

		if(!$hit)
		{
			if($dy < 0 && $this->climbOver($b))
			{
				return;
			}

			$b->ability = '';
			$b->vx = $b->wall * 120;
			$b->vy = -180;
		}
	}

	/**
	 * Pull a climber over a wall end when a nearby upper surface has room for the body.
	 *
	 * @param Body $b Climbing actor whose side probe just passed the top of its wall.
	 * @return bool True after placing the actor upright on the ledge; false when no usable top
	 * surface exists.
	 */
	private function climbOver(Body $b): bool
	{
		$wall = $b->wall;
		$x = $b->x + $wall * ($b->radius * 2 + 2);
		$height = $b->standingHeight;
		$start = $b->y - $height - 2;
		$hit = $this->terrain->raycast($x, $start, 0, $height * 2 + 20, false, $b->collisionLayer);

		if($hit === null || $hit->ny >= -.5 || abs($hit->y - $b->y) > $height + 4)
		{
			return false;
		}

		$angle = atan2($hit->nx, -$hit->ny);
		$ca = cos($angle);
		$sa = sin($angle);
		$targetX = $hit->x + $hit->nx * ($height + .001);
		$targetY = $hit->y + $hit->ny * ($height + .001);
		$points = [
			[$targetX, $targetY]
			, [$targetX + $hit->nx * ($height - .01), $targetY + $hit->ny * ($height - .01)]
			, [$targetX - $ca * ($b->radius - .01), $targetY - $sa * ($b->radius - .01)]
			, [$targetX + $ca * ($b->radius - .01), $targetY + $sa * ($b->radius - .01)]
		];

		foreach($points as [$checkX, $checkY])
		{
			if($this->terrain->solidAt($checkX, $checkY, $b->collisionLayer))
			{
				return false;
			}
		}

		$b->x = $targetX;
		$b->y = $targetY;
		$b->vx = $b->vy = $b->groundSpeed = 0;
		$b->angle = $angle;
		$b->grounded = true;
		$b->rolling = false;
		$b->jumping = false;
		$b->ability = '';
		$b->wall = 0;
		$b->facing = $wall;
		$b->direction = $wall;

		return true;
	}
}

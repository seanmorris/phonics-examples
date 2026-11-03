<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Input\Controls;
use SanePhonics\Physics\Body;

/** MB2 board coasting, crouching, fakie tricks, manuals, and rail combo continuation. */
final class Skateboard implements VehicleBehavior, VehicleDiagnostics
{
	public string $trick = '';
	public float $remaining = 0;
	public bool $fakie = false, $crouching = false;
	private ?ComboReward $grind = null;
	private float $grindRemainder = 0, $idle = 0;

	/**
	 * Create a board chassis with reference tuning converted from 60 Hz units.
	 *
	 * @param float $x Chassis center X coordinate.
	 * @param float $y Chassis center Y coordinate, six pixels above its wheels' support point.
	 * @return Body Independent body supporting the shared slope, rail, water, and air solver.
	 */
	public static function body(float $x = 0, float $y = 0): Body
	{
		$body = new Body($x, $y);
		$body->radius = 12;
		$body->halfHeight = $body->standingHeight = $body->rollingHeight = 6;
		$body->acceleration = 1800;
		$body->braking = 3060;
		$body->friction = 2160;
		$body->gravity = 2340;
		$body->jumpSpeed = 750;
		$body->maxSpeed = 2160;

		return $body;
	}

	/**
	 * Carry entry momentum onto the deck and clear the previous rider's trick.
	 *
	 * @param Vehicle $vehicle Newly occupied board.
	 * @param Body $rider Character with its incoming world velocity.
	 * @return void Initializes tangent speed and resets timed actions.
	 */
	public function mounted(Vehicle $vehicle, Body $rider): void
	{
		$vehicle->body->groundSpeed = $rider->vx * cos($vehicle->body->angle)
			+ $rider->vy * sin($vehicle->body->angle);

		$this->trick = '';
		$this->remaining = 0;
		$this->grind = null;
		$this->grindRemainder = $this->idle = 0;
	}

	/**
	 * Apply coasting and timed button-one/two/three tricks without character abilities.
	 *
	 * @param Vehicle $vehicle Board whose chassis receives tuning and pose changes.
	 * @param Controls $input Logical controls shared by keyboard and gamepad.
	 * @param Combo|null $combo Rider's existing reward chain.
	 * @param float $dt Positive simulation delta in seconds.
	 * @return void Updates trick timers and adds rewards only on eligible fresh presses.
	 */
	public function control(Vehicle $vehicle, Controls $input, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;
		$this->remaining = max(0, $this->remaining - $dt);

		if($this->remaining <= 1e-9) $this->trick = '';

		$this->crouching = $body->grounded && $input->value('down') > .55;
		$body->friction = $this->crouching ? 360 : 2160;
		$axis = $input->axis('left', 'right');
		$body->rolling = $body->grounded && !$body->grinding && !$axis && abs($body->groundSpeed) > 0;
		$this->fakie = $body->groundSpeed * $body->facing < 0;
		$vehicle->scoreGrinding = false;

		if($vehicle->occupant === null)
		{
			$this->trick = '';
			$this->remaining = 0;
			$this->grind = null;

			return;
		}

		foreach([1 => 'leanback', 2 => 'handstand', 3 => 'manual'] as $button => $trick)
		{
			$blocked = ($body->grinding && $button !== 2)
				|| ($button === 3 && !$body->grounded && $this->trick !== '' && $this->trick !== 'manual');

			if($input->pressed($button) && !$blocked && $this->remaining <= 1 / 6 + 1e-9)
			{
				$changed = $this->trick !== $trick;
				$this->trick = $trick;
				$this->remaining = .5;

				if($changed && !$body->grinding && $combo !== null
					&& (($trick !== 'manual' && !$body->grounded) || ($trick === 'manual' && $combo->chain))
				){
					$combo->add(new ComboReward(($this->fakie ? 'Fakie ' : '') . ucfirst($trick), 100));
				}
			}
			elseif($this->trick === $trick && $input->released($button))
			{
				$this->remaining = 1 / 6;
			}
			elseif($this->trick === $trick && $input->down($button) && $this->remaining <= 1 / 6 + 1e-9)
			{
				$this->remaining = .25;
			}
		}
	}

	/**
	 * Choose the riding pose and accrue a single rail reward while a combo is active.
	 *
	 * @param Vehicle $vehicle Board after collision and layer-switch resolution.
	 * @param Combo|null $combo Rider's reward chain.
	 * @param float $dt Positive simulation delta in seconds.
	 * @return void Updates pose, settlement suppression, and time-scaled rail points.
	 */
	public function updated(Vehicle $vehicle, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;
		$moving = abs($body->vx) + abs($body->vy) + abs($body->groundSpeed) > 1;
		$this->idle = $moving ? 0 : $this->idle + $dt;
		$vehicle->pose = $moving ? 'riding' : ($this->idle > .5 ? 'idle' : 'standing');

		if($this->crouching) $vehicle->pose = 'riding-crouching';

		if($this->trick !== '') $vehicle->pose = $this->trick;

		if($body->grinding) $vehicle->pose = 'board-grinding';

		$vehicle->holdCombo = $this->remaining > 0 || $body->grinding;

		if(!$body->grinding || $combo === null || !$combo->chain)
		{
			$this->grind = null;
			$this->grindRemainder = 0;

			return;
		}

		if($this->grind === null || !in_array($this->grind, $combo->chain, true))
		{
			$this->grind = $combo->add(
				new ComboReward(($this->fakie ? 'Fakie ' : '') . 'Rail Grind', 100, 1, '', true)
			);
		}
		else
		{
			$this->grindRemainder += 600 * $dt;
			$points = (int)floor($this->grindRemainder + 1e-9);
			$this->grind->points += $points;
			$this->grindRemainder -= $points;
		}
	}

	/**
	 * Identify board telemetry without a behavior-type branch in the generic vehicle owner.
	 *
	 * @param Vehicle $vehicle Board whose telemetry is requested.
	 * @return array<string,mixed> Serializable board type field.
	 */
	public function vehicleSnapshot(Vehicle $vehicle): array { return ['type' => 'skateboard']; }
}

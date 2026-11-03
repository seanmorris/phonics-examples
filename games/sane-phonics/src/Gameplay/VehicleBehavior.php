<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use Phonics\Input\Controls;
use SanePhonics\Physics\Body;

/** Vehicle-specific movement and actions surrounding the shared terrain simulation. */
interface VehicleBehavior
{

	/**
	 * Initialize driving state when a character boards the vehicle.
	 *
	 * @param Vehicle $vehicle Vehicle whose seat has been assigned.
	 * @param Body $rider Character retaining its pre-boarding momentum for this callback.
	 * @return void Transfers behavior-specific momentum and clears old driver actions.
	 */
	public function mounted(Vehicle $vehicle, Body $rider): void;

	/**
	 * Apply the current driver's logical buttons before terrain movement.
	 *
	 * @param Vehicle $vehicle Vehicle supplying body and seat state.
	 * @param Controls $input Fresh logical press, hold, release, and analog values.
	 * @param Combo|null $combo Occupant's reward chain, absent when empty or not configured.
	 * @param float $dt Positive simulation seconds.
	 * @return void Adjusts tuning, actions, and optional reward state.
	 */
	public function control(Vehicle $vehicle, Controls $input, ?Combo $combo, float $dt): void;

	/**
	 * Resolve riding presentation and rewards after terrain movement.
	 *
	 * @param Vehicle $vehicle Simulated vehicle with final contact and surface state.
	 * @param Combo|null $combo Occupant's reward chain, absent when not configured.
	 * @param float $dt Positive simulation seconds.
	 * @return void Sets the riding pose and combo settlement policy.
	 */
	public function updated(Vehicle $vehicle, ?Combo $combo, float $dt): void;
}

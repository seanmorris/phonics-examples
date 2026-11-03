<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Behavior that contributes reusable ECS capability components to its vehicle entity. */
interface VehicleComponents
{

	/**
	 * Supply capability components when the behavior's vehicle entity is registered.
	 *
	 * @return list<object> Capability components registered beside Vehicle and Body.
	 */
	public function vehicleComponents(): array;
}

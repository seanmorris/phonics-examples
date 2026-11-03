<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Behavior that contributes type-specific fields to generic vehicle telemetry. */
interface VehicleDiagnostics
{

	/**
	 * Contribute behavior-specific values to generic vehicle telemetry.
	 *
	 * @param Vehicle $vehicle Vehicle whose current behavior state is being inspected.
	 * @return array<string,mixed> Serializable type and behavior state.
	 */
	public function vehicleSnapshot(Vehicle $vehicle): array;
}

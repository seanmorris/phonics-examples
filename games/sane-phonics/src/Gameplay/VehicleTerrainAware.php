<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

use SanePhonics\Physics\Terrain;

/** Vehicle behavior that needs read-only access to the owning movement terrain. */
interface VehicleTerrainAware
{

	/**
	 * Bind the terrain shared by the generic vehicle movement owner.
	 *
	 * @param Terrain $terrain Collision source used by behavior-specific sensors.
	 * @return void Retains the shared terrain without taking ownership of it.
	 */
	public function bindTerrain(Terrain $terrain): void;
}

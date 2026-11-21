<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** A continuous water height field in meters, with world Y pointing upward. */
interface WaterSurface
{

	/**
	 * Evaluate the surface independently of rendering or the last update call.
	 * @param float $x World X in meters.
	 * @param float $z World Z in meters.
	 * @param float $time Simulation time in seconds.
	 * @return array{height:float,normal:array,velocity:float,dx:float,dz:float} Height, upward unit normal,
	 * vertical surface velocity in meters per second, and the two spatial height derivatives.
	 */
	public function sample(float $x, float $z, float $time): array;
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Translate gearbox and tire telemetry into targets for the audio clock. */
final class CarEngineSound
{
	/** Four-stroke cycle Hz, output gain, muffler cutoff Hz, turbulent flow, pressure scatter, tire gain and tire rate. */
	public static function controls(float $rpm, float $load, float $slip): array
	{
		$revs = max(0.,min(1.,($rpm-CarGearbox::IDLE)/(CarGearbox::REDLINE-CarGearbox::IDLE)));
		$load = max(0.,min(1.,$load));
		$slip = max(0.,min(1.,$slip));
		// Open throttle fills the cylinders: louder, brighter discharges and faster gas through the pipes.
		return [$rpm/120., .030 + .050*$load + .014*$revs, 700. + 900.*$revs + $load*(900. + 1800.*$revs),
			.45 + .35*$load + .10*$revs, .10 + .12*(1.-$load), .08*$slip, .92 + .14*$slip];
	}
}

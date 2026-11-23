<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Translate gearbox and tire telemetry into targets for the audio clock. */
final class CarEngineSound
{
	/** Four-stroke cycle Hz, output gain, exhaust cutoff Hz, flow, pressure scatter, tire gain/rate and mount gain. */
	public static function controls(float $rpm, float $load, float $slip): array
	{
		$revs = max(0.,min(1.,($rpm-CarGearbox::IDLE)/(CarGearbox::REDLINE-CarGearbox::IDLE)));
		$load = max(0.,min(1.,$load));
		$slip = max(0.,min(1.,$slip));
		$rise = max(0.,min(1.,($rpm-1500.)/2400.));
		// Rotating imbalance loads the mounts more strongly as the square of shaft speed.
		$mount = (1.14 + (.32 + 1.20*$revs*$revs)*$load)*$rise*$rise*(3.-2.*$rise);
		return [$rpm/120., .030 + .050*$load + .014*$revs, 570. + 650.*$revs + $load*(1700. + 1900.*$revs),
			.24 + (1.00 + .68*$revs)*$load + .65*$revs, .14 + .13*(1.-$load), .08*$slip, .92 + .14*$slip, $mount];
	}
}

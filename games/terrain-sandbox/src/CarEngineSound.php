<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Translate gearbox and tire telemetry into targets for the audio clock. */
final class CarEngineSound
{
	/** Four-stroke cycle Hz, output gain, muffler cutoff Hz, exhaust overtone gain, tire gain and tire rate. */
	public static function controls(float $rpm, float $load, float $slip): array
	{
		$revs = max(0.,min(1.,($rpm-CarGearbox::IDLE)/(CarGearbox::REDLINE-CarGearbox::IDLE)));
		$load = max(0.,min(1.,$load));
		$slip = max(0.,min(1.,$slip));
		// Open throttle fills the cylinders and brings out the upper harmonics of the discharges.
		$level = .032 + .099*$load + (.057 + .100*$load)*$revs + (.171 + .255*$load)*$revs*$revs;
		// The muffler passes the note, rpm/20, and rounds off what lies above it. Idle keeps enough to be heard.
		return [$rpm/120., $level, max(350., (1.3 + .2*$load)*$rpm/20.),
			(.08 + 1.15*$load)*(.35 + .65*$revs), .08*$slip, .92 + .14*$slip];
	}
}

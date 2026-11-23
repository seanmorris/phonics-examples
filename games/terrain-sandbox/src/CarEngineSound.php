<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Translate gearbox and tire telemetry into targets for the audio clock. */
final class CarEngineSound
{
	/** Four-stroke cycle Hz, output gain, muffler cutoff Hz, tailpipe rasp, pressure scatter, tire gain and tire rate. */
	public static function controls(float $rpm, float $load, float $slip): array
	{
		$revs = max(0.,min(1.,($rpm-CarGearbox::IDLE)/(CarGearbox::REDLINE-CarGearbox::IDLE)));
		$load = max(0.,min(1.,$load));
		$slip = max(0.,min(1.,$slip));
		// Open throttle fills the cylinders: louder discharges and faster, raspier gas through the pipes.
		// The muffler passes the note, rpm/20, and rounds off what lies above it. Idle keeps enough to be heard.
		return [$rpm/120., .047 + .069*$load + .113*$revs, max(350., (1.3 + .2*$load)*$rpm/20.),
			(1.1 + 2.0*$load)*(.6 + .4*$revs), .10 + .12*(1.-$load), .08*$slip, .92 + .14*$slip];
	}
}

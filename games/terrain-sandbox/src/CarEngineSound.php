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
		// Open throttle fills the cylinders: louder discharges and faster gas through the pipes. The rasp and the
		// pressure wobble stay low so the note sounds clean.
		$level = .037 + .109*$load + (.081 + .137*$load)*$revs + (.136 + .075*$load)*$revs*$revs;
		// The muffler passes the note, rpm/20, and rounds off what lies above it. Idle keeps enough to be heard.
		return [$rpm/120., $level, max(350., (1.3 + .2*$load)*$rpm/20.),
			(.33 + .60*$load)*(.6 + .4*$revs), .04 + .048*(1.-$load), .08*$slip, .92 + .14*$slip];
	}
}

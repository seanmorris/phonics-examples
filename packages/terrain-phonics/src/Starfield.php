<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Celestial effects sampled from simulation time so pause, seeking, and camera turns keep the same sky. */
final class Starfield
{
	public const DEFAULTS = ['starBrightness'=>100., 'starTwinkle'=>0., 'shootingStars'=>0.];

	public function __construct(
		public readonly float $brightness = 100.,
		public readonly float $twinkle = 0.,
		public readonly float $perMinute = 0.
	) {
		foreach([[$brightness,200.],[$twinkle,100.],[$perMinute,60.]] as [$value,$limit])
			if(!is_finite($value) || $value<0. || $value>$limit)
				throw new \InvalidArgumentException('Star field control outside its range.');
	}

	public function visibility(float $sunY): float
	{
		if($this->brightness<=0.) return 0.;
		// At 100% retain the usual sunset to −10° fade. Double brightness starts the fade at +6°.
		$start=($this->brightness*.01-1.)*.10452846;
		$t=min(1.,max(0.,($start-$sunY)/.17364818));
		return $t*$t*(3.-2.*$t);
	}

	/** At most one meteor in each time slot, with a stable offset, duration and path for that slot. */
	public function meteor(float $time): ?array
	{
		if($this->perMinute<=0. || $time<0.) return null;
		$interval=60./$this->perMinute;
		$slot=(int)floor($time/$interval);
		$hash=crc32(pack('V',$slot));
		$duration=.48+.38*(($hash>>16)&65535)/65535.;
		$offset=($interval-$duration)*($hash&65535)/65535.;
		$age=$time-$slot*$interval-$offset;
		return $age>=0. && $age<$duration ? [$slot&0x7fffffff,$age/$duration] : null;
	}
}

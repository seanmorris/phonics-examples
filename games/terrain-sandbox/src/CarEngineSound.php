<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Exhaust pressure over two crank revolutions of an even-firing twelve-cylinder engine. */
final class CarEngineSound
{
	/** The two collectors alternate firings. Small cylinder differences leave some bank order in the exhaust. */
	private const BANKS = [
		[1.00, .92, .98, .88, 1.04, .95],
		[.94, 1.02, .89, .97, .92, 1.00],
	];

	public static function voice(): array
	{
		$banks = [];
		foreach(self::BANKS as $bank => $cylinders)
		{
			$real = $imag = [0.]; // No DC pressure at the tailpipe.
			for($order = 1; $order <= 240; $order++)
			{
				// A short pressure rise, a longer decay and a negative scavenging pulse.
				// Work in crank angle so these coefficients describe the same firing at every RPM.
				$angle = 2.*M_PI*$order;
				$decay = atan($order/19.);
				$amplitude = .021*exp(-.5*($order/145.)**2)/hypot(1.,$order/19.);
				$cosine = $sine = 0.;
				foreach($cylinders as $i => $pressure)
				{
					$phase = $angle*(2*$i+$bank)/12. + $decay;
					$cosine += $pressure*(cos($phase) - .42*cos($phase + .026*$angle));
					$sine += $pressure*(sin($phase) - .42*sin($phase + .026*$angle));
				}
				$real[] = round($amplitude*$cosine,6);
				$imag[] = round($amplitude*$sine,6);
			}
			$banks[] = ['real'=>$real,'imag'=>$imag];
		}
		// Broadband excitation for exhaust flow and slight common crank jitter. Make it once on boarding.
		$noise = ''; $state = 17493;
		for($i = 0; $i < 32768; $i++)
		{
			$state = (25173*$state + 13849) & 65535;
			$noise .= chr($state >> 8);
		}
		return ['banks'=>$banks,'flowNoise'=>base64_encode($noise),'noiseRate'=>16000];
	}

	/** Audio-clock targets: four-stroke cycle Hz, exhaust gain, muffler Hz, flow gain, jitter cents, tire gain and rate. */
	public static function controls(float $rpm, float $load, float $slip): array
	{
		$revs = max(0., min(1., ($rpm-CarGearbox::IDLE)/(CarGearbox::REDLINE-CarGearbox::IDLE)));
		$load = max(0., min(1.,$load));
		$slip = max(0., min(1.,$slip));
		return [$rpm/120., .085 + .060*$load + .030*$revs, 560. + 920.*$revs + $load*(1900. + 2300.*$revs),
			.012 + .075*$load + .025*$revs, 125. - 102.*$revs, .08*$slip, .92 + .14*$slip];
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Time a drive at the same gantries the player sees, across forks and landscape handoffs. */
final class JourneyClock
{
	public array $passed = [];
	public ?array $split = null;
	public function __construct(public float $start) {}

	public function advance(array $gates, array $stages, array $from, array $to, float $now, float $dt): void
	{
		foreach($gates as $gate) {
			[$x,,$z] = $gate['position'];
			[$hx,$hz] = $gate['heading'];
			$dx = $to['x']-$x; $dz = $to['z']-$z;
			$a = ($from['x']-$x)*$hx + ($from['z']-$z)*$hz;
			$b = $dx*$hx + $dz*$hz;
			if($a >= 0. || $b < 0.) continue;
			$t = -$a/($b-$a);
			$across = ($from['x']-$x + $t*($to['x']-$from['x']))*$hz
				- ($from['z']-$z + $t*($to['z']-$from['z']))*$hx;
			if(abs($across) > .5*$gate['width']) continue;
			$kind = $gate['kind'];
			$square = $gate['square'] ?? 0;
			$key = ($stages[$square] ?? $square).'/'.($gate['fraction'] ?? $kind);
			if(isset($this->passed[$key])) continue;
			$this->passed[$key] = true;
			$at = $now + $t*$dt;
			if($kind === 'start') { $this->start = $at; continue; }
			$this->split = ['label'=>$kind === 'finish' ? 'Journey finish' : 'Journey split',
				'at'=>$at,'seconds'=>$at-$this->start];
		}
	}
}

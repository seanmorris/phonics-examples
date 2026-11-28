<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** Short-lived dust puffs in world space, emitted from individual tire contact patches. */
final class TireDust
{
	private const INTERVAL = .05, LIFE = 1.25;
	private float $now = 0., $previous = 0., $untilNext = 0.;
	private array $frames = [];
	public int $revision = 0;

	public function active(): bool { return (bool)$this->frames; }
	public function timeAt(float $alpha): float { return $this->previous + $alpha*($this->now - $this->previous); }
	public function instances(): string { return implode('',array_column($this->frames,1)); }

	public function step(float $dt, array $contacts, array $pose): void
	{
		if($dt <= 0.) return;
		$this->previous = $this->now;
		$this->now += $dt;
		while(($first = array_key_first($this->frames)) !== null && $this->previous - $this->frames[$first][0] >= self::LIFE)
		{
			unset($this->frames[$first]);
			$this->revision++;
		}
		$this->untilNext = max(0.,$this->untilNext-$dt);
		if(!$contacts || $this->untilNext > 1.e-6) return;
		$this->untilNext = self::INTERVAL;
		$sin = sin($pose['yaw']); $cos = cos($pose['yaw']);
		$along = max(-3.,min(4.,.09*$pose['speed'])) - ($pose['speed'] < 0. ? -1. : 1.)*1.6;
		$bytes = '';
		foreach($contacts as [$x,$y,$z,$side,$strength])
		{
			$across = $side*(.65 + .80*$strength);
			// The second vec4 holds initial velocity for a puff, instead of another trail endpoint.
			$bytes .= pack('g*',$x,$y+.10,$z,$this->now, $sin*$along+$cos*$across,.55+.65*$strength,
				$cos*$along-$sin*$across,0., 3.,self::LIFE,$strength);
		}
		$this->frames[] = [$this->now,$bytes];
		$this->revision++;
	}
}

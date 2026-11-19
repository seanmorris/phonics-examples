<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Arcade flight with a world-up chase camera. Coordinates are metres, angles are radians. */
final class Jet
{
	private const BOOM = 24.0;
	private array $pose = [], $previous = [];

	public function board(Camera $camera, Landscape $land, float $water): void
	{
		[$x,$y,$z] = $camera->position;
		$half = $land->recipe()['worldSize'] / 2;
		$x = max(-$half,min($half,$x));
		$z = max(-$half,min($half,$z));
		$this->pose = ['x'=>$x, 'y'=>max($y, max($water,$land->height($x,$z)) + 75), 'z'=>$z,
			'yaw'=>M_PI - $camera->yaw, 'pitch'=>0.0, 'roll'=>0.0, 'speed'=>110.0];
		$this->previous = [];
		$this->follow($camera, $land, $water);
	}

	/** Return false on reaching the map edge, where the session hands back the free camera. */
	public function step(float $dt, int $throttle, int $bank, int $climb, Camera $camera, Landscape $land, float $water): bool
	{
		$this->previous = $this->pose;
		$p = &$this->pose;
		$p['speed'] = max(55.0,min(260.0,$p['speed'] + $throttle * 55 * $dt));
		$p['roll'] += (-$bank * 1.10 - $p['roll']) * (1 - exp(-3.6 * $dt));
		$p['pitch'] += ($climb * .78 - $p['pitch']) * (1 - exp(-2.1 * $dt));
		$p['yaw'] += sin($p['roll']) * .68 * $dt;
		$travel = $p['speed'] * $dt;
		$x = $p['x'] + sin($p['yaw']) * cos($p['pitch']) * $travel;
		$z = $p['z'] + cos($p['yaw']) * cos($p['pitch']) * $travel;
		$half = $land->recipe()['worldSize'] / 2;
		if(abs($x) > $half || abs($z) > $half) return false;
		$p['x'] = $x; $p['z'] = $z;
		$p['y'] = min(15000.0,$p['y'] + sin($p['pitch']) * $travel);
		if($p['y'] < $land->bounds()['maxY'] + 8)
			$p['y'] = max($p['y'], $land->height($x,$z) + 8);
		$p['y'] = max($p['y'], $water + 8);
		$this->follow($camera,$land,$water);
		return true;
	}

	private function follow(Camera $camera, Landscape $land, float $water): void
	{
		$p = &$this->pose;
		// Raise the camera behind a descending jet and keep the horizon level through banked turns.
		$angle = .38 - .75 * $p['pitch'];
		$p['boom'] = self::BOOM * cos($angle);
		$p['eyeY'] = max($water + 3, $p['y'] + self::BOOM * sin($angle));
		if($p['eyeY'] < $land->bounds()['maxY'] + 3)
		{
			$half = $land->recipe()['worldSize'] / 2;
			$x = max(-$half,min($half,$p['x'] - $p['boom'] * sin($p['yaw'])));
			$z = max(-$half,min($half,$p['z'] - $p['boom'] * cos($p['yaw'])));
			$p['eyeY'] = max($p['eyeY'], $land->height($x,$z) + 3);
		}
		$this->placeCamera($camera,$p);
	}

	private function placeCamera(Camera $camera, array $p): void
	{
		$camera->position = [$p['x'] - $p['boom'] * sin($p['yaw']), $p['eyeY'], $p['z'] - $p['boom'] * cos($p['yaw'])];
		$camera->yaw = M_PI - $p['yaw'];
		$camera->pitch = atan2($p['y'] + 1.5 + 6 * sin($p['pitch']) - $p['eyeY'], $p['boom'] + 6);
	}

	public function state(): array { return $this->pose; }

	/** Interpolate jet and camera together without sampling terrain between simulation ticks. */
	public function view(Camera $camera, float $alpha): array
	{
		$p = $this->pose;
		if(!$this->previous || $alpha >= 1) return [$camera,$p];
		foreach($this->previous as $key => $value) $p[$key] = $value + $alpha * ($p[$key] - $value);
		$eye = clone $camera;
		$this->placeCamera($eye,$p);
		return [$eye,$p];
	}
}

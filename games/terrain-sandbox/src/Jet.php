<?php
declare(strict_types=1);
namespace TerrainSandbox;

use TerrainPhonics\Camera;
use TerrainPhonics\Landscape;

/** Arcade flight with a world-up chase camera. Coordinates are metres, angles are radians. */
final class Jet
{
	private const BOOM = 24.0;
	/** Smallest and largest square blocks, in lattice cells, whose height bound can replace an exact sample. */
	private const FINEST = 2, COARSEST = 256;
	/** Steps to fly on after a block rises too close before asking about a larger one. */
	private const PATIENCE = 8;
	private array $pose = [], $previous = [];
	/** Surface and revision that the cached limits and blocks below describe. */
	private ?Landscape $surveyed = null;
	private int $revision = 0;
	private float $half = 0.0, $roof = 0.0;
	/** Last terrain block consulted for the fuselage and for the chase eye. */
	private array $body = [], $eye = [];

	public function board(Camera $camera, Landscape $land, float $water): void
	{
		$this->survey($land);
		[$x,$y,$z] = $camera->position;
		$half = $this->half;
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
		$this->survey($land);
		$this->previous = $this->pose;
		$p = &$this->pose;
		$p['speed'] = max(55.0,min(260.0,$p['speed'] + $throttle * 55 * $dt));
		$p['roll'] += (-$bank * 1.10 - $p['roll']) * (1 - exp(-3.6 * $dt));
		$p['pitch'] += ($climb * .78 - $p['pitch']) * (1 - exp(-2.1 * $dt));
		$p['yaw'] += sin($p['roll']) * .68 * $dt;
		$travel = $p['speed'] * $dt;
		$x = $p['x'] + sin($p['yaw']) * cos($p['pitch']) * $travel;
		$z = $p['z'] + cos($p['yaw']) * cos($p['pitch']) * $travel;
		$half = $this->half;
		if(abs($x) > $half || abs($z) > $half) return false;
		$p['x'] = $x; $p['z'] = $z;
		$p['y'] = min(15000.0,$p['y'] + sin($p['pitch']) * $travel);
		if($p['y'] < $this->roof + 8) $p['y'] = $this->lift($this->body, $land, $x, $z, $p['y'], 8.0);
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
		if($p['eyeY'] < $this->roof + 3)
		{
			$half = $this->half;
			$x = max(-$half,min($half,$p['x'] - $p['boom'] * sin($p['yaw'])));
			$z = max(-$half,min($half,$p['z'] - $p['boom'] * cos($p['yaw'])));
			$p['eyeY'] = $this->lift($this->eye, $land, $x, $z, $p['eyeY'], 3.0);
		}
		$this->placeCamera($camera,$p);
	}

	/**
	 * Refresh the cached limits and forget the remembered blocks when the surface is replaced or edited.
	 * @param Landscape $land Surface the jet flies over on this step.
	 * @return void Leaves the cache describing this surface at its current revision.
	 */
	private function survey(Landscape $land): void
	{
		if($land === $this->surveyed && $land->revision() === $this->revision) return;
		$this->surveyed = $land;
		$this->revision = $land->revision();
		$this->half = $land->recipe()['worldSize'] / 2;
		$this->roof = $land->bounds()['maxY'];
		$this->body = $this->eye = ['cells' => 0, 'wait' => 0];
	}

	/**
	 * Return a point's height once the ground has pushed it up to its margin, sampling only as finely as needed.
	 * The landscape bounds a whole block of cells without generating a node. A point at or above that bound plus
	 * the margin cannot be moved by the exact sample, so the answer always equals max($y, height + $margin).
	 * Higher points clear larger blocks and keep them for more steps. A point too low for the finest block
	 * samples the surface exactly.
	 * @param array $block Block remembered for this point, updated in place.
	 * @param Landscape $land Surface under the point.
	 * @param float $x World X of the point in metres.
	 * @param float $z World Z of the point in metres.
	 * @param float $y Height of the point before the ground acts on it.
	 * @param float $margin Clearance the point keeps above the ground, in metres.
	 * @return float The given height, or the exact surface plus the margin when that is higher.
	 */
	private function lift(array &$block, Landscape $land, float $x, float $z, float $y, float $margin): float
	{
		$cells = $block['cells'];
		if($block['wait'] > 0) $block['wait']--;
		elseif($cells < self::COARSEST)
		{
			// A block twice as wide lasts twice as many steps. Move up to it whenever the point clears it.
			$wider = self::block($land, $x, $z, $cells ? $cells * 2 : self::FINEST, $margin, 0);
			if($y >= $wider['clear']) { $block = $wider; return $y; }
			$block['wait'] = self::PATIENCE;
		}
		if($cells)
		{
			if($x < $block['minX'] || $x >= $block['maxX'] || $z < $block['minZ'] || $z >= $block['maxZ'])
				$block = self::block($land, $x, $z, $cells, $margin, $block['wait']);
			if($y >= $block['clear']) return $y;
			// The ground has come too close for this size. Halve the block until one clears.
			while(($cells >>= 1) >= self::FINEST)
			{
				$block = self::block($land, $x, $z, $cells, $margin, self::PATIENCE);
				if($y >= $block['clear']) return $y;
			}
			$block['cells'] = 0;
			$block['wait'] = self::PATIENCE;
		}
		$floor = $land->height($x, $z) + $margin;
		if($floor <= $y) return $y;
		// No bound can clear a point resting on its floor, so a skimming jet never asks for one.
		$block['wait'] = self::PATIENCE;
		return $floor;
	}

	/**
	 * Ask the landscape to bound the block of one size around a point.
	 * @param Landscape $land Surface to bound.
	 * @param float $x World X of the point in metres.
	 * @param float $z World Z of the point in metres.
	 * @param int $cells Lattice cells along each edge of the block.
	 * @param float $margin Clearance the point keeps above the ground, in metres.
	 * @param int $wait Steps to fly on before asking about a larger block.
	 * @return array Block rectangle and size, the lowest height that clears it, and the wait.
	 */
	private static function block(Landscape $land, float $x, float $z, int $cells, float $margin, int $wait): array
	{
		$block = $land->localBounds($x, $z, $cells);
		$block['clear'] = $block['maxY'] + $margin;
		$block['cells'] = $cells;
		$block['wait'] = $wait;
		return $block;
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

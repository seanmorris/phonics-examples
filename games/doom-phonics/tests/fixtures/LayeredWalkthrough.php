<?php
declare(strict_types=1);
namespace DoomPhonics\Tests;

use DoomPhonics\Game\Level;

require_once dirname(__DIR__,2).'/tools/PlayerWalk.php';

/**
 * Walk the generated route with ordinary player commands and game physics.
 *
 * The same fixture runs natively and in browser PHP. Positions are never corrected
 * after construction; portal warps, swimming, steps and falling belong to the game.
 */
final class LayeredWalkthrough extends \DoomPhonics\Tools\PlayerWalk
{
	/** Run the pump, flood ascent, staircase, upper landing and balcony route. */
	public function run(array $regions): array
	{
		$this->enter($regions['pump'],0);
		$this->walkTo(4320,64);
		$this->pose->angle = 0;
		if(!$this->specials->use($this->pose,$this->player)) throw new \RuntimeException('Walked player cannot activate fill switch');
		$this->command->forward = 0;
		$this->tick(200);
		$this->checkpoints['pump'] = $this->position();
		$this->walkTo(4160,128);
		$this->enter($regions['cavern.lower'],M_PI);
		$this->walkTo(512,128);
		$this->enter($regions['cavern.upper'],M_PI / 2,.75);
		$this->walkTo(512,320);
		$this->checkpoints['ledge'] = $this->position();
		$this->enter($regions['building.entry'],M_PI);
		$this->walkTo(6592,320);
		$this->walkTo(6592,1152);
		$this->checkpoints['stairs'] = $this->position();
		$this->enter($regions['upper.hall'],M_PI);
		$this->walkTo(8512,1152);
		$this->walkTo(8512,320);
		$this->walkTo(8512,64);
		$this->enter($regions['balcony'],M_PI);
		$this->walkTo(12416,256);
		$this->checkpoints['balcony'] = $this->position();
		return $this->checkpoints;
	}

	/** Record every tick through the two upper wall portals, including reverse and airborne crossings. */
	public static function portalCrossings(Level $level): array
	{
		$traces = [];
		foreach([
			'stairs to hall' => [[6592,1152,192],M_PI,27],
			'hall to stairs' => [[8512,1152,192],0,21],
			'balcony to gallery' => [[12480,64,192],0,22],
			'gallery to balcony' => [[8256,64,192],M_PI,29],
		] as $route => [$start,$angle,$destination])
		foreach(['slow','walk','run','backward','strafe','fall'] as $mode)
		{
			$walk = new self($level,$start);
			$walk->pose->angle = $angle;
			$walk->command->forward = match($mode) { 'slow' => .15, 'run' => 1, 'backward' => -.6, 'strafe' => 0, default => .6 };
			if($mode === 'backward') $walk->pose->angle += M_PI;
			if($mode === 'strafe')
			{
				$walk->pose->angle += M_PI / 2;
				$walk->command->strafe = .6;
			}
			if($mode === 'fall')
			{
				// Start four units before the seam with enough momentum to cross this tick.
				$walk->pose->x += cos($angle) * 60;
				$walk->pose->y += sin($angle) * 60;
				$walk->pose->z = 224;
				$walk->velocity->x = cos($angle) * 210;
				$walk->velocity->y = sin($angle) * 210;
				$walk->velocity->z = -70;
			}
			$target = $destination;
			// Recessed passages are real regions on both sides of the seam.
			$origin = $level->sectorAt($start[0] + cos($angle) * 60,$start[1] + sin($angle) * 60)->index;
			foreach($level->cameraPortalLinks() as $link)
			if($link->originSector === $origin && $link->originLine !== null) $target = $link->destinationSector;
			$frames = [];
			for($tick = 0; $tick < ($mode === 'fall' ? 1 : 60); $tick++)
			{
				$walk->tick();
				$frames[] = $walk->position() + ['previousZ' => $walk->pose->previousZ,
					'velocityZ' => $walk->velocity->z,'grounded' => $walk->body->grounded];
			}
			$traces["$route / $mode"] = ['destination' => $target,'airborne' => $mode === 'fall','frames' => $frames];
		}
		return $traces;
	}
}

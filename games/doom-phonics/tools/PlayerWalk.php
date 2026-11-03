<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

use DoomPhonics\Component\{Body,PlayerCommand,PlayerStart,Transform,Velocity};
use DoomPhonics\Game\{Collision,Level,LineSpecials,PlayerState};
use DoomPhonics\System\PlayerMovementSystem;
use Phonics\Ecs\Registry;
use Phonics\Input\Controls;

/**
 * Walk the generated route with ordinary player commands and game physics.
 *
 * The same fixture runs natively and in browser PHP. Positions are never corrected
 * after construction; portal warps, swimming, steps and falling belong to the game.
 */
class PlayerWalk
{

	/** Player pose, command and physics state for the isolated test entity. */
	public Transform $pose;
	public PlayerCommand $command;
	public Body $body;
	public Velocity $velocity;
	/** Local switch/mover processing for the tested map instance. */
	public LineSpecials $specials;
	/** Recorded endpoints after each completed part of the route. */
	public array $checkpoints = [];
	/** Maximum command strength; short generated passages use a cautious walk. */
	public float $travelStrength = .6;
	private Registry $registry;
	private PlayerMovementSystem $movement;
	protected PlayerState $player;

	/** Spawn a normal player at the basement start or an explicit test position. */
	public function __construct(public Level $level, array $start = [2176,128,-192])
	{
		$this->registry = new Registry;
		$this->pose = new Transform(...$start);
		$this->command = new PlayerCommand;
		$this->body = new Body(16,56);
		$this->velocity = new Velocity;
		$this->player = new PlayerState;
		$this->registry->create(new PlayerStart,$this->pose,$this->command,$this->body,$this->velocity,$this->player);
		$this->specials = new LineSpecials($level,$this->registry);
		$this->movement = new PlayerMovementSystem(new Collision($level),$this->specials,new Controls);
	}

	/** Advance commands, collision, gravity and movers at the real 35 Hz tick rate. */
	public function tick(int $count = 1): void
	{
		for($i = 0; $i < $count; $i++)
		{
			$this->pose->beginStep();
			$this->movement->update($this->registry,1 / 35);
			$this->specials->update(1 / 35);
		}
	}

	/** Move toward a point within the current packed room, with a bounded timeout. */
	public function walkTo(float $x, float $y, float $pitch = 0): void
	{
		$this->command->pitch = $pitch;
		for($tick = 0; $tick < 1200; $tick++)
		{
			$distance = hypot($x - $this->pose->x,$y - $this->pose->y);
			if($distance < 6) return;
			$this->pose->angle = atan2($y - $this->pose->y,$x - $this->pose->x);
			$this->command->forward = min($this->travelStrength,$distance / 100);
			$this->tick();
		}
		throw new \RuntimeException("Walk stalled toward $x,$y at " . json_encode($this->position()));
	}

	/** Walk or swim in one direction until the expected region is reached. */
	public function enter(int $sector, float $angle, float $pitch = 0): void
	{
		if($this->level->sectorAt($this->pose->x,$this->pose->y)->index === $sector) return;
		$this->pose->angle = $angle;
		$this->command->forward = $this->travelStrength;
		$this->command->pitch = $pitch;
		for($tick = 0; $tick < 1200; $tick++)
		{
			$this->tick();
			if($this->level->sectorAt($this->pose->x,$this->pose->y)->index === $sector) return;
		}
		throw new \RuntimeException("Cannot enter sector $sector from " . json_encode($this->position()));
	}

	/** Return a concise position and sector for native/browser assertions. */
	public function position(): array
	{
		return ['x' => $this->pose->x,'y' => $this->pose->y,'z' => $this->pose->z,
			'sector' => $this->level->sectorAt($this->pose->x,$this->pose->y)->index];
	}

}

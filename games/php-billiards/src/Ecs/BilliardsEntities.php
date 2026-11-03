<?php
declare(strict_types=1);
namespace PhpBilliards\Ecs;

use Phonics\Ecs\Registry;
use PhpBilliards\Game;
use PhpBilliards\Physics\Ball;
use PhpBilliards\Physics\Simulation;

/**
 * ECS ownership adapter for one deterministic billiards session and its balls.
 *
 * The simulation remains the sole physics authority. Each ball entity holds the exact Ball object used by that
 * simulation, and reconciliation replaces references by number after rack or checkpoint operations create new
 * objects. Entity IDs remain stable without copying, reordering, or otherwise changing deterministic state.
 */
final class BilliardsEntities
{
	/** Entity containing the BilliardsSession component. */
	private int $sessionEntity;
	/** @var array<int,int> Stable entity IDs keyed by regulation ball number. */
	private array $ballEntities = [];
	/** @var list<Ball> Last simulation-owned object list reconciled into the registry. */
	private array $source = [];

	/**
	 * Register the session and its initial ball objects.
	 *
	 * @param Registry $registry Engine registry receiving session and ball components.
	 * @param Game $game Authoritative billiards game exposed by the session component.
	 */
	public function __construct(Registry $registry, Game $game)
	{
		$this->sessionEntity = $registry->create(new BilliardsSession($game));
		$this->reconcile($registry, $game->simulation);
	}

	/**
	 * Resolve the authoritative game through its registered session entity.
	 *
	 * @param Registry $registry Engine registry containing this adapter's entities.
	 * @return Game Registered authoritative game.
	 */
	public function game(Registry $registry): Game
	{
		/** @var BilliardsSession $session */
		$session = $registry->get($this->sessionEntity, BilliardsSession::class);

		return $session->game;
	}

	/**
	 * Replace ECS ball references when the simulation constructs a new object set.
	 *
	 * Unchanged simulation steps return after one object-list comparison. Rack, reset, and checkpoint restoration keep
	 * existing entity IDs by replacing each numbered entity's Ball component with the new authoritative object.
	 *
	 * @param Registry $registry Engine registry containing this adapter's entities.
	 * @param Simulation $simulation Authoritative source of current ball objects.
	 * @return bool Whether any entity membership or Ball reference changed.
	 */
	public function reconcile(Registry $registry, Simulation $simulation): bool
	{
		if($this->source === $simulation->balls) return false;

		$balls = [];

		foreach($simulation->balls as $ball)
		{
			if(isset($balls[$ball->number])) throw new \LogicException("Duplicate billiards ball {$ball->number}");
			$balls[$ball->number] = $ball;
		}

		$changed = false;

		foreach(array_diff_key($this->ballEntities, $balls) as $number => $entity)
		{
			if($registry->alive($entity)) $registry->destroy($entity);
			unset($this->ballEntities[$number]);
			$changed = true;
		}

		foreach($balls as $number => $ball)
		{
			$entity = $this->ballEntities[$number] ?? null;

			if($entity === null)
			{
				$this->ballEntities[$number] = $registry->create(new BallIdentity($number), $ball);
				$changed = true;
				continue;
			}

			if($registry->get($entity, Ball::class) !== $ball)
			{
				$registry->add($entity, $ball);
				$changed = true;
			}
		}

		$this->source = $simulation->balls;

		return $changed;
	}

	/**
	 * Return the stable entity assigned to one numbered ball.
	 *
	 * @param int $number Regulation ball number to find.
	 * @return int Live entity containing BallIdentity and Ball components.
	 */
	public function ballEntity(int $number): int
	{
		return $this->ballEntities[$number] ?? throw new \OutOfBoundsException("Unknown billiards ball $number");
	}

	/**
	 * Destroy every entity owned by this adapter.
	 *
	 * @param Registry $registry Engine registry releasing session and ball components.
	 * @return void The adapter no longer owns live entities after this call.
	 */
	public function close(Registry $registry): void
	{
		foreach($this->ballEntities as $entity) if($registry->alive($entity)) $registry->destroy($entity);
		if($registry->alive($this->sessionEntity)) $registry->destroy($this->sessionEntity);
		$this->ballEntities = [];
		$this->source = [];
	}
}

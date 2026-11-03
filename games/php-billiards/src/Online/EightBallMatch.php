<?php
declare(strict_types=1);
namespace PhpBilliards\Online;

use PhpBilliards\Physics\Simulation;

/**
 * Deterministic casual eight-ball turn and outcome state.
 *
 * Physics reports ordered pocket events. This class assigns groups from the first legal object ball, changes turns,
 * handles table-wide ball in hand, reracks an eight on the break, and decides early-eight and legal wins.
 */
final class EightBallMatch
{
	/** @var list<string> Matrix user IDs in stable seat order. */
	public array $players;
	/** Matrix user ID allowed to perform the next deterministic action. */
	public string $actor;
	/** Matrix user ID that broke the current rack. */
	public string $breaker;
	/** @var array<string,string|null> Assigned solid or stripe group for each player. */
	public array $groups;
	/** Last fully acknowledged action sequence. */
	public int $sequence = 0;
	/** Derived rack index, incremented only after an eight on the break. */
	public int $rackIndex = 0;
	/** Whether the next shot is the break shot for the current rack. */
	public bool $breakShot = true;
	/** Final winner and reason, or null while play continues. */
	public ?array $outcome = null;
	/** Match seed fixed by the room commit/reveal exchange. */
	public readonly string $seed;
	/** @var array<string,mixed>|null */
	private ?array $pending = null;
	/** @var list<array<string,mixed>> */
	private array $events = [];

	/**
	 * @param list<string> $players Exactly two distinct seated Matrix user IDs.
	 * @param string $breaker Seed-selected first breaker, which must be one of the players.
	 * @param string $seed Commit/reveal-derived match seed.
	 */
	public function __construct(array $players, string $breaker, string $seed)
	{
		if(count($players) !== 2 || $players[0] === $players[1] || !in_array($breaker, $players, true))
		throw new \InvalidArgumentException('Eight-ball requires two distinct players and a seated breaker');
		$this->players = array_values($players);
		$this->breaker = $this->actor = $breaker;
		$this->seed = $seed;
		$this->groups = [$players[0] => null,$players[1] => null];
	}

	/**
	 * Begin one validated shot or cue-ball placement from the current actor.
	 *
	 * @param int $sequence Next explicit room-action sequence number.
	 * @param string $actor Seated Matrix user ID performing the action.
	 * @param array<string,mixed> $action Quantized shot or placement payload.
	 * @param string $preHash Exact acknowledged state hash preceding the action.
	 */
	public function begin(int $sequence, string $actor, array $action, string $preHash): void
	{
		if($this->outcome !== null || $this->pending !== null || $sequence !== $this->sequence + 1
			|| $actor !== $this->actor || !in_array($action['type'] ?? null, ['shot','place'], true)
		) throw new \UnexpectedValueException('Invalid online eight-ball action');
		$this->pending = compact('sequence', 'actor', 'action', 'preHash');
		$this->events = [];
	}

	/**
	 * Retain ordered physics events for the active shot.
	 *
	 * @param list<array<string,mixed>> $events Newly emitted simulation events in fixed-step order.
	 */
	public function record(array $events): void
	{
		if($this->pending === null || ($this->pending['action']['type'] ?? null) !== 'shot') return;
		foreach($events as $event) if(is_array($event)) $this->events[] = $event;
	}

	/**
	 * Complete a cue-ball placement; the same player then takes the shot.
	 *
	 * @param Simulation $simulation Simulation containing the accepted placement.
	 * @return array<string,mixed> Settled sequence, exact hash, next actor, and outcome.
	 */
	public function settlePlacement(Simulation $simulation): array
	{
		if(($this->pending['action']['type'] ?? null) !== 'place')
		throw new \LogicException('No placement is pending');
		$this->sequence = $this->pending['sequence'];
		$this->pending = null;
		return $this->result($simulation);
	}

	/**
	 * Apply casual eight-ball rules after all balls from the pending shot have stopped.
	 *
	 * @param Simulation $simulation Settled deterministic ball simulation.
	 * @return array<string,mixed> Settled sequence, exact hash, next actor, and outcome.
	 */
	public function settleShot(Simulation $simulation): array
	{
		if(($this->pending['action']['type'] ?? null) !== 'shot') throw new \LogicException('No shot is pending');
		$shooter = $this->pending['actor'];
		$other = $this->other($shooter);
		$pocketed = [];
		foreach($this->events as $event) if(($event['type'] ?? null) === 'pocket')
		$pocketed[] = (int)($event['ball'] ?? -1);
		$scratch = in_array(0, $pocketed, true) || $simulation->scratched;
		$eight = in_array(8, $pocketed, true);
		$objects = array_values(array_filter($pocketed, static fn(int $number): bool => $number > 0 && $number !== 8));
		$this->sequence = $this->pending['sequence'];
		$this->pending = null;

		if($eight && $this->breakShot)
		{
			$this->rackIndex++;
			$simulation->rack($this->seed, $this->rackIndex);
			$this->actor = $this->breaker;
			$this->breakShot = true;
			$this->events = [];
			return $this->result($simulation);
		}

		if($eight)
		{
			$cleared = $this->groups[$shooter] !== null && $this->groupCleared($simulation, $this->groups[$shooter]);
			$winner = !$scratch && $cleared ? $shooter : $other;
			$this->outcome = [
				'winner' => $winner
				, 'loser' => $winner === $shooter ? $other : $shooter
				, 'reason' => $scratch ? 'scratched-eight' : ($cleared ? 'legal-eight' : 'early-eight')
			];
			$this->events = [];
			return $this->result($simulation);
		}

		if(!$scratch && $this->groups[$shooter] === null)
		{
			foreach($objects as $number)
			{
				$group = self::group($number);
				if($group === null) continue;
				$this->groups[$shooter] = $group;
				$this->groups[$other] = $group === 'solid' ? 'stripe' : 'solid';
				break;
			}
		}

		if($this->breakShot)
		{
			$this->actor = !$scratch && count($objects) > 0 ? $shooter : $other;
			$this->breakShot = false;
		}
		else
		{
			$own = array_filter($objects, fn(int $number): bool => self::group($number) === $this->groups[$shooter]);
			$this->actor = !$scratch && count($own) > 0 ? $shooter : $other;
		}

		$this->events = [];
		return $this->result($simulation);
	}

	/**
	 * Return all authoritative match and physics fields used by checkpoints and exact hashes.
	 *
	 * @param Simulation $simulation Simulation to include in the state.
	 * @return array<string,mixed> Complete versioned match state.
	 */
	public function snapshot(Simulation $simulation): array
	{
		return [
			'version' => 1
			, 'rules' => 'casual-eight-ball-v1'
			, 'seed' => $this->seed
			, 'players' => $this->players
			, 'actor' => $this->actor
			, 'breaker' => $this->breaker
			, 'groups' => $this->groups
			, 'sequence' => $this->sequence
			, 'rackIndex' => $this->rackIndex
			, 'breakShot' => $this->breakShot
			, 'outcome' => $this->outcome
			, 'simulation' => $simulation->snapshot()
		];
	}

	/**
	 * Restore a confirmed checkpoint into this rule state and simulation.
	 *
	 * @param array<string,mixed> $state Decoded versioned match checkpoint.
	 * @param Simulation $simulation Simulation instance to restore in place.
	 */
	public function restore(array $state, Simulation $simulation): void
	{
		if(($state['version'] ?? null) !== 1 || ($state['rules'] ?? null) !== 'casual-eight-ball-v1'
			|| ($state['seed'] ?? null) !== $this->seed || ($state['players'] ?? null) !== $this->players
		) throw new \UnexpectedValueException('Checkpoint does not describe this match');
		$this->actor = (string)$state['actor'];
		$this->breaker = (string)$state['breaker'];
		$this->groups = $state['groups'];
		$this->sequence = (int)$state['sequence'];
		$this->rackIndex = (int)$state['rackIndex'];
		$this->breakShot = (bool)$state['breakShot'];
		$this->outcome = is_array($state['outcome']) ? $state['outcome'] : null;
		$this->pending = null;
		$this->events = [];
		$simulation->restore($state['simulation']);
	}

	/**
	 * Compute the exact cross-host digest of the complete confirmed state.
	 *
	 * @param Simulation $simulation Simulation whose canonical state participates in the digest.
	 * @return string Lowercase SHA-256 digest.
	 */
	public function hash(Simulation $simulation): string { return BinaryState::hash($this->snapshot($simulation)); }

	/**
	 * Export the complete confirmed state as a versioned binary checkpoint.
	 *
	 * @param Simulation $simulation Simulation whose state participates in the checkpoint.
	 * @return string Unpadded base64url checkpoint text.
	 */
	public function checkpoint(Simulation $simulation): string { return BinaryState::export($this->snapshot($simulation)); }

	private function result(Simulation $simulation): array
	{
		return [
			'sequence' => $this->sequence
			, 'hash' => $this->hash($simulation)
			, 'nextActor' => $this->actor
			, 'outcome' => $this->outcome
		];
	}

	private function other(string $player): string { return $this->players[0] === $player ? $this->players[1] : $this->players[0]; }

	private static function group(int $number): ?string
	{
		return $number >= 1 && $number <= 7 ? 'solid' : ($number >= 9 && $number <= 15 ? 'stripe' : null);
	}

	private function groupCleared(Simulation $simulation, string $group): bool
	{
		foreach($simulation->balls as $ball) if($ball->active && self::group($ball->number) === $group) return false;
		return true;
	}
}

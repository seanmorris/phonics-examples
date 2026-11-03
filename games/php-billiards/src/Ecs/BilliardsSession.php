<?php
declare(strict_types=1);
namespace PhpBilliards\Ecs;

use PhpBilliards\Game;

/**
 * Session component exposing the application state to scheduled ECS systems.
 *
 * The component keeps the existing Game aggregate authoritative while allowing systems to discover it through the
 * registry. Physics, exact checkpoints, camera state, controls, and online rules therefore retain one shared owner.
 */
final class BilliardsSession
{
	/**
	 * @param Game $game Authoritative billiards session controlled by the registered systems.
	 */
	public function __construct(
		/** Authoritative game state for this session entity. */
		public readonly Game $game
	) {}
}

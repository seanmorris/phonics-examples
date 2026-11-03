<?php
declare(strict_types=1);
namespace PhpBilliards\Ecs;

/**
 * Stable billiards-ball identity independent of a replaceable physics object.
 *
 * Rack and checkpoint restoration may construct a new Ball instance for the same numbered ball. This component
 * keeps the entity associated with that number stable while BilliardsEntities replaces the attached Ball reference.
 */
final class BallIdentity
{
	/**
	 * @param int $number Regulation ball number, with zero identifying the cue ball.
	 */
	public function __construct(
		/** Regulation ball number retained for the entity's complete lifetime. */
		public readonly int $number
	) {}
}

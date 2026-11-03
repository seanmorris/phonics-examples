<?php
declare(strict_types=1);
namespace PhpSlalom;

/** One colored tetrahedral gate moving from the horizon toward the player. */
final class Obstacle
{
	public float $previousX;
	public float $previousZ;

	/**
	 * Place a gate on the course and record that position as its previous pose.
	 *
	 * @param float $x Lateral course position.
	 * @param float $width Gate width in course units.
	 * @param float $z Distance ahead of the player.
	 * @param array{float,float,float} $color Normalized base color.
	 * @return void The previous pose starts at the initial position.
	 */
	public function __construct(
		public float $x
		, public float $width
		, public float $z
		, public array $color
	){
		$this->previousX = $x;
		$this->previousZ = $z;
	}

	/**
	 * Retain the last fixed pose and move the obstacle with the course.
	 *
	 * @param float $x Lateral displacement for this step.
	 * @param float $z Depth displacement for this step.
	 * @return void The current and previous poses are updated in place.
	 */
	public function move(float $x, float $z): void
	{
		$this->previousX = $this->x;
		$this->previousZ = $this->z;
		$this->x += $x;
		$this->z += $z;
	}
}

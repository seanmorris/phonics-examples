<?php
declare(strict_types=1);
namespace PhpBuster;

/** Mutable fixed-step actor state with a previous pose for display-rate interpolation. */
final class Actor
{
	public float $previousX;
	public float $previousY;
	public float $age = 0;
	public float $timer = 0;
	public int $frame = 0;
	public bool $alive = true;

	/**
	 * Create an actor and preserve its initial pose for interpolation.
	 *
	 * @param int $id Stable identifier for this simulation run.
	 * @param string $type Actor behavior and sprite family.
	 * @param float $x Horizontal logical position.
	 * @param float $y Vertical logical position.
	 * @param float $width Collision width in logical pixels.
	 * @param float $height Collision height in logical pixels.
	 * @param float $vx Horizontal velocity in logical pixels per second.
	 * @param float $vy Vertical velocity in logical pixels per second.
	 * @param int $team Collision team identifier.
	 * @param int $hits Remaining hit points.
	 * @param int $strength Score value and collision damage metadata.
	 * @param int $variant Sprite or weapon variant index.
	 * @return void The actor is initialized in place.
	 */
	public function __construct(
		public int $id
		, public string $type
		, public float $x
		, public float $y
		, public float $width
		, public float $height
		, public float $vx = 0
		, public float $vy = 0
		, public int $team = 1
		, public int $hits = 1
		, public int $strength = 1
		, public int $variant = 0
	){
		$this->previousX = $x;
		$this->previousY = $y;
	}

	/**
	 * Return collision bounds, retaining the original fighter insets.
	 *
	 * @return array{0:float,1:float,2:float,3:float} X, Y, width, and height.
	 */
	public function bounds(): array
	{
		return match($this->type)
		{
			'midi' => [$this->x + $this->width / 4, $this->y + 3, $this->width / 2, $this->height - 6]
			, 'maxi' => [$this->x + 3, $this->y + 3, $this->width - 6, $this->height - 6]
			, default => [$this->x, $this->y, $this->width, $this->height]
		};
	}
}

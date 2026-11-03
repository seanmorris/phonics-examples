<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/**
 * Mutable rigid sphere constrained to the cloth plane.
 *
 * A ball carries translational and angular state, activation and pocket state, deterministic texture pose,
 * and the previous-step values required to interpolate stable rendering between fixed physics updates.
 */
final class Ball
{
	public const RADIUS = 0.028575;

	/** Cloth-plane X coordinate from the preceding fixed update. */
	public float $previousX;
	/** Cloth-plane Y coordinate from the preceding fixed update. */
	public float $previousY;
	/** Linear X velocity in metres per second. */
	public float $vx = 0;
	/** Linear Y velocity in metres per second. */
	public float $vy = 0;
	/** Angular velocity about the world X axis in radians per second. */
	public float $spinX = 0;
	/** Angular velocity about the world Y axis in radians per second. */
	public float $spinY = 0;
	/** Angular velocity about the world Z axis in radians per second. */
	public float $spinZ = 0;
	/** X component of the visible orientation quaternion. */
	public float $qx = 0;
	/** Y component of the visible orientation quaternion. */
	public float $qy = 0;
	/** Z component of the visible orientation quaternion. */
	public float $qz = 0;
	/** Scalar component of the visible orientation quaternion. */
	public float $qw = 1;
	/** Pocket index currently containing the ball, or null while on the cloth. */
	public ?int $pocket = null;
	/** Current vertical distance below the cloth surface. */
	public float $drop = 0;
	/** Pocket drop from the preceding fixed update. */
	public float $previousDrop = 0;
	/** Downward pocket velocity in metres per second. */
	public float $vDrop = 0;
	/** Whether the ball remains active in play or in a pocket channel. */
	public bool $active = true;

	/**
	 * @param int $number Ball number, with zero identifying the cue ball.
	 * @param float $x Initial cloth-plane X coordinate.
	 * @param float $y Initial cloth-plane Y coordinate.
	 */
	public function __construct(
		public readonly int $number
		, public float $x
		, public float $y
	) {
		$this->previousX = $x;
		$this->previousY = $y;
	}

	/** Store the fixed-step pose used by render interpolation. */
	public function remember(): void
	{
		$this->previousX = $this->x;
		$this->previousY = $this->y;
		$this->previousDrop = $this->drop;
	}

	/**
	 * Integrate position and visible sphere orientation.
	 *
	 * Linear velocity advances the cloth-plane centre while angular velocity is integrated into a normalized
	 * quaternion, giving rendering a camera-independent material orientation for every ball.
	 *
	 * @param float $seconds Integration interval in seconds.
	 */
	public function advance(float $seconds): void
	{
		$this->x += $this->vx * $seconds;
		$this->y += $this->vy * $seconds;

		$half = 0.5 * $seconds;
		[$x,$y,$z,$w] = [$this->qx,$this->qy,$this->qz,$this->qw];
		$this->qx += $half * ($this->spinX * $w + $this->spinY * $z - $this->spinZ * $y);
		$this->qy += $half * (-$this->spinX * $z + $this->spinY * $w + $this->spinZ * $x);
		$this->qz += $half * ($this->spinX * $y - $this->spinY * $x + $this->spinZ * $w);
		$this->qw += $half * (-$this->spinX * $x - $this->spinY * $y - $this->spinZ * $z);
		$length = sqrt($this->qx ** 2 + $this->qy ** 2 + $this->qz ** 2 + $this->qw ** 2);

		if($length > 0)
		{
			$this->qx /= $length;
			$this->qy /= $length;
			$this->qz /= $length;
			$this->qw /= $length;
		}
	}

	/**
	 * Return serializable deterministic state at an interpolated render position.
	 *
	 * Fixed-step ownership remains untouched; only position, pocket drop, and the normalized orientation exposed
	 * to the renderer are blended by the requested frame alpha.
	 *
	 * @param float $alpha Interpolation fraction between fixed poses.
	 * @return array<string,int|float|bool>
	 */
	public function state(float $alpha = 1): array
	{
		return [
			'number' => $this->number
			, 'x' => $this->previousX + ($this->x - $this->previousX) * $alpha
			, 'y' => $this->previousY + ($this->y - $this->previousY) * $alpha
			, 'vx' => $this->vx
			, 'vy' => $this->vy
			, 'spin' => [$this->spinX,$this->spinY,$this->spinZ]
			, 'rotation' => [$this->qx,$this->qy,$this->qz,$this->qw]
			, 'pocket' => $this->pocket
			, 'drop' => $this->previousDrop + ($this->drop - $this->previousDrop) * $alpha
			, 'vDrop' => $this->vDrop
			, 'active' => $this->active
		];
	}

	/**
	 * @param array<string,mixed> $state Previously captured deterministic ball state.
	 */
	public static function restore(array $state): self
	{
		$ball = new self((int)$state['number'], (float)$state['x'], (float)$state['y']);
		$ball->previousX = (float)($state['previousX'] ?? $ball->x);
		$ball->previousY = (float)($state['previousY'] ?? $ball->y);
		$ball->vx = (float)$state['vx'];
		$ball->vy = (float)$state['vy'];
		[$ball->spinX,$ball->spinY,$ball->spinZ] = array_map('floatval', $state['spin']);
		[$ball->qx,$ball->qy,$ball->qz,$ball->qw] = array_map('floatval', $state['rotation']);
		$ball->pocket = isset($state['pocket']) ? (int)$state['pocket'] : null;
		$ball->drop = (float)($state['drop'] ?? 0);
		$ball->previousDrop = (float)($state['previousDrop'] ?? $ball->drop);
		$ball->vDrop = (float)($state['vDrop'] ?? 0);
		$ball->active = (bool)$state['active'];

		return $ball;
	}

	/** @return array<string,mixed> Exact snapshot, including the prior render pose. */
	public function snapshot(): array
	{
		return $this->state() + ['previousX' => $this->previousX,'previousY' => $this->previousY
			,'previousDrop' => $this->previousDrop];
	}
}

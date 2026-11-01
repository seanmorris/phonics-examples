<?php
declare(strict_types=1);
namespace PhpBilliards\Physics;

/** Mutable rigid sphere constrained to the cloth plane. */
final class Ball
{
	public const RADIUS = 0.028575;

	public float $previousX;
	public float $previousY;
	public float $vx = 0;
	public float $vy = 0;
	public float $spinX = 0;
	public float $spinY = 0;
	public float $spinZ = 0;
	public float $qx = 0;
	public float $qy = 0;
	public float $qz = 0;
	public float $qw = 1;
	public bool $active = true;

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
	}

	/** Integrate position and visible sphere orientation. */
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

	/** @return array<string,int|float|bool> Serializable deterministic state. */
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
			, 'active' => $this->active
		];
	}

	/** @param array<string,mixed> $state */
	public static function restore(array $state): self
	{
		$ball = new self((int)$state['number'], (float)$state['x'], (float)$state['y']);
		$ball->previousX = (float)($state['previousX'] ?? $ball->x);
		$ball->previousY = (float)($state['previousY'] ?? $ball->y);
		$ball->vx = (float)$state['vx'];
		$ball->vy = (float)$state['vy'];
		[$ball->spinX,$ball->spinY,$ball->spinZ] = array_map('floatval', $state['spin']);
		[$ball->qx,$ball->qy,$ball->qz,$ball->qw] = array_map('floatval', $state['rotation']);
		$ball->active = (bool)$state['active'];

		return $ball;
	}

	/** @return array<string,mixed> Exact snapshot, including the prior render pose. */
	public function snapshot(): array
	{
		return $this->state() + ['previousX' => $this->previousX,'previousY' => $this->previousY];
	}
}

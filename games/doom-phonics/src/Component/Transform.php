<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Mutable map-space pose shared by movement, collision, AI, and rendering. */
final class Transform
{
	private bool $networkCorrection = false;
	public float $previousX;
	public float $previousY;
	public float $previousZ;

	public function __construct(
		public float $x
		, public float $y
		, public float $z = 0
		, public float $angle = 0
	){
		$this->previousX = $x;
		$this->previousY = $y;
		$this->previousZ = $z;
	}

	/** Retain the pose at the start of one fixed simulation step. */
	public function beginStep(): void
	{
		if($this->networkCorrection)
		{
			$this->networkCorrection = false;

			return;
		}

		$this->previousX = $this->x;
		$this->previousY = $this->y;
		$this->previousZ = $this->z;
	}

	/**
	 * Install an authoritative pose while retaining a continuous render endpoint.
	 *
	 * @param float $x Corrected horizontal map coordinate.
	 * @param float $y Corrected vertical map coordinate.
	 * @param float $z Corrected floor-relative height.
	 * @param float $angle Corrected facing angle in radians.
	 * @return self This mutable pose after installing the correction.
	 */
	public function reconcile(float $x, float $y, float $z, float $angle): self
	{
		if(!$this->networkCorrection)
		{
			$this->previousX = $this->x;
			$this->previousY = $this->y;
			$this->previousZ = $this->z;
		}

		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->angle = $angle;
		$this->networkCorrection = true;

		return $this;
	}

	public function xAt(float $alpha): float
	{
		$alpha = max(0, min(1, $alpha));

		return $this->previousX + ($this->x - $this->previousX) * $alpha;
	}

	public function yAt(float $alpha): float
	{
		$alpha = max(0, min(1, $alpha));

		return $this->previousY + ($this->y - $this->previousY) * $alpha;
	}

	public function zAt(float $alpha): float
	{
		$alpha = max(0, min(1, $alpha));

		return $this->previousZ + ($this->z - $this->previousZ) * $alpha;
	}
}

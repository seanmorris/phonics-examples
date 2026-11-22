<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** The movement a vehicle's physics publishes for the race system each fixed step. */
final class RacerMotion
{
	private ?array $segment = null;
	public int $boarding = 0;

	public function __construct(public readonly string $vehicle) {}

	/** A recovery or teleport replaces any pending crossing with a discontinuity. */
	public function publish(array $before, array $after, bool $relocated = false): void
	{
		$recovered = ($before['recoveries'] ?? 0) !== ($after['recoveries'] ?? 0);
		$this->segment = $relocated || $recovered ? null : [
			[$before['x'], $before['y'], $before['z']],
			[$after['x'], $after['y'], $after['z']],
		];
	}

	public function take(): ?array
	{
		$segment = $this->segment;
		$this->segment = null;
		return $segment;
	}
}

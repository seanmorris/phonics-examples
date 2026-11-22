<?php
declare(strict_types=1);
namespace TerrainSandbox;

/** One driver's finishing times by course signature, shared across their choice of vehicle. */
final class RaceRecords
{
	public array $latest = [], $best = [];
}

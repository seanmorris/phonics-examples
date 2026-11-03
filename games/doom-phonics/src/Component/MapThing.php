<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Provenance and spawn flags retained from one map THING record. */
final class MapThing
{

	/** @param array<string,mixed> $flags */
	public function __construct(public int $index, public int $type, public array $flags = []) {}
}

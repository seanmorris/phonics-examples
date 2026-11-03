<?php
declare(strict_types=1);
namespace SanePhonics\Gameplay;

/** Opt-in single-seat association; inventories and character tuning stay on the rider entity. */
final class Rider
{
	public ?int $vehicle = null;
	/** @var array<int,float> Seconds before a released vehicle can be boarded again. */
	public array $cooldowns = [];
}

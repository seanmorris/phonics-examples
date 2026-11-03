<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Links a first-person DECORATE weapon-state entity to its player entity. */
final class WeaponOwner
{
	/** Vertical classic-screen offset used while lowering and raising the first-person sprite. */
	public float $offsetY = 0;

	public function __construct(public int $entity) {}
}

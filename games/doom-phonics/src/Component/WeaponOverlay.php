<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** One independently animated first-person weapon layer, such as a muzzle flash. */
final class WeaponOverlay
{

	public function __construct(
		public int $playerEntity
		, public int $weaponEntity
		, public int $layer = 1000
	){
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** A monster temporarily moving as a contact-damage projectile toward one target. */
final class ChargeAttack
{

	public function __construct(public int $target, public int $damage) {}
}

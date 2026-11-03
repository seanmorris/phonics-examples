<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** A host-simulated projectile whose local entity only presents replicated state. */
final class RemoteProjectile
{
	public ?string $soundLabel = null;
}

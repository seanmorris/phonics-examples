<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/**
 * A host-simulated monster or shootable thing whose guest entity only presents replicated state.
 *
 * Guests run no AI, physics, or damage for it. They show the host's pose and animation, keep its collision in step
 * with its health, and replay its death, pain, sight, and hitscan sounds.
 */
final class RemoteActor
{
	/** Replicated label and frame last presented, so each new frame's sounds play once. */
	public ?string $presented = null;
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** An actor's running alpha as A_FadeOut and A_FadeIn change it; it scales the opacity its render style gives. */
final class ActorFade
{
	public function __construct(public float $alpha = 1.0) {}
}

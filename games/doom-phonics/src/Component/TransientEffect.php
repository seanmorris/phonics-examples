<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Runtime DECORATE actor that retires when its finite state sequence stops. */
final class TransientEffect
{
	public bool $retiring = false;
}

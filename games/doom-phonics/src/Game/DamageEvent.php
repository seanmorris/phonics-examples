<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** One resolved hit, including attribution and the actor that supplies its thrust direction. */
final class DamageEvent
{

	public function __construct(
		public int $target
		, public int $source
		, public int $amount
		, public ?int $inflictor = null
		, public bool $thrust = true
	){
		if($amount < 0) throw new \InvalidArgumentException('Damage cannot be negative');
	}

	public function thrustSource(): int { return $this->inflictor ?? $this->source; }
}

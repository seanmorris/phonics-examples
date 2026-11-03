<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** First static-map surface reached by one swept projectile body. */
final class ProjectileImpact
{
	public const WALL = 'wall';
	public const FLOOR = 'floor';
	public const CEILING = 'ceiling';
	public const SKY = 'sky';

	public function __construct(
		public string $surface
		, public float $fraction
		, public float $x
		, public float $y
		, public float $z
		, public int $sector
		, public ?int $line = null
	){
	}

	public function escapesIntoSky(): bool { return $this->surface === self::SKY; }
}

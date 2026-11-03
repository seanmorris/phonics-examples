<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** A line, floor, or ceiling whose pixels come from a camera portal link. */
final class CameraPortalSurface
{
	public const LINE = 'line';
	public const FLOOR = 'floor';
	public const CEILING = 'ceiling';

	public function __construct(
		public string $kind
		, public int $index
		, public CameraPortalLink $link
		// Hexen portal alpha controls the source flat drawn over a sector portal.
		// The remote camera image itself remains opaque.
		, public float $overlayAlpha = 0.0
	){
		if(!in_array($kind, [self::LINE,self::FLOOR,self::CEILING], true))
		throw new \InvalidArgumentException("Unknown camera portal surface $kind");
	}

	public function plane(): ?int
	{
		return match($this->kind) { self::FLOOR => 0, self::CEILING => 1, default => null };
	}
}

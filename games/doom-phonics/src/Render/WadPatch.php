<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** A direct WAD picture placed in the classic 320 by 200 interface coordinate space. */
final class WadPatch
{

	public function __construct(
		public string $name
		, public float $x
		, public float $y
		, public ?float $width = null
		, public ?float $height = null
		, public float $opacity = 1.0
	){
		if($name === '') throw new \InvalidArgumentException('WAD patch names cannot be empty');

		if(($width !== null && $width <= 0) || ($height !== null && $height <= 0))
		throw new \InvalidArgumentException('WAD patch dimensions must be positive');

		if(!is_finite($opacity) || $opacity < 0 || $opacity > 1)
		throw new \InvalidArgumentException('WAD patch opacity must be between zero and one');
	}
}

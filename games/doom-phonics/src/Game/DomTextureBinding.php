<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Graphics\CameraTextureDefinition;

/** Trusted browser DOM renderer bound to one WAD-declared DOMTexture name. */
final readonly class DomTextureBinding
{

	public function __construct(
		public CameraTextureDefinition $texture
		, public object $bridge
	){
		if($texture->kind !== CameraTextureDefinition::DOM)
		throw new \InvalidArgumentException('DOM texture bindings require a DOMTexture definition');
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Graphics\CameraTextureDefinition;

/** Runtime SetCameraToTexture binding. */
final readonly class CameraTextureBinding
{

	public function __construct(
		public CameraTextureDefinition $texture
		, public CameraViewpoint $camera
		, public float $fov = 90
	){
	}
}

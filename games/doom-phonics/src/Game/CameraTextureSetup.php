<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Graphics\CameraTextureDefinition;

/** Bind constant SetCameraToTexture calls in source-bearing OPEN ACS scripts. */
final class CameraTextureSetup
{

	public static function apply(Level $level, TextureCatalog $textures): void
	{
		$lump = $level->map->lumps['SCRIPTS'] ?? null;

		if(!$lump) return;

		$source = preg_replace('~/\*.*?\*/|//[^\r\n]*~s', ' ', $lump->bytes()) ?? $lump->bytes();
		preg_match_all('/\bscript\s+(?:"[^"]+"|\d+)\s+open\s*\{(.*?)\}/is', $source, $scripts);

		foreach($scripts[1] as $body)
		{
			preg_match_all(
				'/\bSetCameraToTexture\s*\(\s*(\d+)\s*,\s*"([^"]+)"\s*,\s*(\d+(?:\.\d+)?)\s*\)/i'
				, $body
				, $calls
				, PREG_SET_ORDER
			);

			foreach($calls as $call)
			if(($definition = $textures->cameraTexture($call[2]))
				&& $definition->kind === CameraTextureDefinition::CAMERA
				&& ($camera = $level->camera((int)$call[1]))
			)
			$level->bindCameraTexture(
				new CameraTextureBinding($definition, $camera, max(1, min(179, (float)$call[3])))
			);
		}
	}
}

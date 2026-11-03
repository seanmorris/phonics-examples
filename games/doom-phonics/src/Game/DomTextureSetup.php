<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Graphics\CameraTextureDefinition;
use DoomPhonics\Graphics\TextureCatalog;

/** Match WAD DOMTexture declarations to trusted documents registered by the game host. */
final class DomTextureSetup
{

	/** @param array<string,object> $providers Browser DomTexture bridges keyed by texture name. */
	public static function apply(Level $level, TextureCatalog $textures, array $providers): void
	{
		$providers = array_change_key_case($providers, CASE_UPPER);

		foreach($textures->cameraTextures() as $name => $definition)
		{
			if($definition->kind !== CameraTextureDefinition::DOM) continue;

			$bridge = $providers[$name] ?? null;

			if(!is_object($bridge)) continue;

			$level->bindDomTexture(new DomTextureBinding($definition, $bridge));
		}

		self::bindScrollScripts($level);
	}

	/** Bind constant ScrollDomTexture calls without executing arbitrary ACS source. */
	private static function bindScrollScripts(Level $level): void
	{
		$lump = $level->map->lumps['SCRIPTS'] ?? null;

		if(!$lump) return;

		$source = preg_replace('~/\*.*?\*/|//[^\r\n]*~s', ' ', $lump->bytes()) ?? $lump->bytes();
		preg_match_all('/\bscript\s+(\d+)\s*(?:\([^)]*\))?\s*\{(.*?)\}/is', $source, $scripts, PREG_SET_ORDER);

		foreach($scripts as $script)
		{
			preg_match_all(
				'/\bScrollDomTexture\s*\(\s*"([^"]+)"\s*,\s*([-+]?\d+(?:\.\d+)?)\s*\)/i'
				, $script[2]
				, $calls
				, PREG_SET_ORDER
			);

			foreach($calls as $call)
			if($binding = $level->domTextureBinding($call[1]))
			$level->bindDomTextureScroll((int)$script[1], $binding, (float)$call[2]);
		}
	}
}

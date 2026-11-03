<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

/** Extract CAMERA/CANVAS/DOMTEXTURE declarations from layered ANIMDEFS text. */
final class AnimDefsParser
{

	/** @return array<string,CameraTextureDefinition> Last declaration wins. */
	public static function cameraTextures(string $source): array
	{
		$source = preg_replace('~/\*.*?\*/|//[^\r\n]*~s', ' ', $source) ?? $source;
		preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}]|[^\s{}]+/', $source, $matches);
		$tokens = $matches[0];
		$result = [];

		for($i = 0, $count = count($tokens); $i < $count; $i++)
		{
			$kind = strtoupper($tokens[$i]);

			if(!in_array($kind, ['CAMERATEXTURE','CANVASTEXTURE','DOMTEXTURE'], true)) continue;

			$name = self::text($tokens[++$i] ?? '');
			$width = self::integer($tokens[++$i] ?? '');
			$height = self::integer($tokens[++$i] ?? '');

			if($name === '' || $width === null || $height === null) continue;

			$fitWidth = $fitHeight = 0;
			$worldPanning = false;

			while($i + 1 < $count)
			{
				$option = strtoupper($tokens[$i + 1]);

				if(in_array(
					$option
					, ['CAMERATEXTURE','CANVASTEXTURE','DOMTEXTURE','TEXTURE','FLAT','SWITCH','WARP','PIC']
					, true
				))
				break;

				$i++;

				if($option === 'FIT')
				{
					$fitWidth = self::integer($tokens[++$i] ?? '') ?? 0;
					$fitHeight = self::integer($tokens[++$i] ?? '') ?? 0;
				}
				elseif($option === 'WORLDPANNING') $worldPanning = true;
				elseif($option !== 'HDR')
				{
					$i--;
					break;
				}
			}

			try
			{
				$type = match($kind)
				{
					'CANVASTEXTURE' => CameraTextureDefinition::CANVAS,
					'DOMTEXTURE' => CameraTextureDefinition::DOM,
					default => CameraTextureDefinition::CAMERA
				};

				$definition = new CameraTextureDefinition(
					$name
					, $width
					, $height
					, $fitWidth
					, $fitHeight
					, $worldPanning
					, $type
				);

				$result[$definition->name] = $definition;
			} catch(\InvalidArgumentException)
			{
			}
		}

		return $result;
	}

	private static function text(string $token): string
	{
		return str_starts_with($token, '"') ? stripcslashes(substr($token, 1, -1)) : $token;
	}

	private static function integer(string $token): ?int
	{
		return preg_match('/^\d+$/', $token) ? (int)$token : null;
	}
}

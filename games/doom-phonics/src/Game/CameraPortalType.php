<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** ZDoom line-portal interaction levels used by Hexen-format maps. */
final class CameraPortalType
{
	public const VISUAL = 0;
	public const TELEPORTER = 1;
	public const INTERACTIVE = 2;
	public const LINKED = 3;

	public static function valid(int $type): bool
	{
		return $type >= self::VISUAL && $type <= self::LINKED;
	}
}

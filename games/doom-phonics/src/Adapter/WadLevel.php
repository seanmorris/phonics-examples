<?php
declare(strict_types=1);
namespace DoomPhonics\Adapter;

use DoomPhonics\Archive\Stack;
use DoomPhonics\Audio\SoundCatalog;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\WeaponSlots;
use DoomPhonics\Definition\LanguageCatalog;
use DoomPhonics\Game\Level;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Render\GlesMapRenderer;

/** Parsed resources shared by the systems and renderer for one active WAD map. */
final class WadLevel
{
	public ?GlesMapRenderer $renderer = null;
	/** @var list<int> Unsupported editor numbers omitted by an explicit inspection world. */
	public array $skippedThings = [];

	public function __construct(
		public Stack $wad
		, public Level $level
		, public TextureCatalog $textures
		, public Palette $palette
		, public ActorCatalog $actors
		, public SoundCatalog $sounds
		, public WeaponSlots $weapons
		, public LanguageCatalog $language
	){
	}
}

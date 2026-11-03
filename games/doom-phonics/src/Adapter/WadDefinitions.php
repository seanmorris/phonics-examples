<?php
declare(strict_types=1);
namespace DoomPhonics\Adapter;

use DoomPhonics\Audio\SoundCatalog;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\DecorateParser;
use DoomPhonics\Definition\LanguageCatalog;

/** Stack-local DECORATE, SNDINFO, KEYCONF, and LANGUAGE state with source provenance. */
final class WadDefinitions
{
	public ActorCatalog $actors;
	public SoundCatalog $sounds;
	public LanguageCatalog $language;
	/** @var array<string,string> Layered KEYCONF documents keyed by canonical source. */
	public array $keyconf;

	/**
	 * @param array<string,string> $decorate Later DECORATE documents keyed by canonical source.
	 * @param array<string,string> $sndinfo Later SNDINFO documents keyed by canonical source.
	 * @param array<string,string> $keyconf Later KEYCONF documents keyed by canonical source.
	 * @param array<string,string> $language Later LANGUAGE documents keyed by canonical source.
	 */
	public function __construct(array $decorate = [], array $sndinfo = [], array $keyconf = [], array $language = [])
	{
		$root = dirname(__DIR__, 2) . '/resources/';
		$baseDecorate = [];

		foreach(['decor.dec','objects.dec','weaps.dec','doom1-enemies.dec','wiki-actors.dec'] as $name)
		$baseDecorate[$root . $name] = self::read($root . $name);

		$this->actors = DecorateParser::catalog([...$baseDecorate, ...$decorate]);

		$this->sounds = SoundCatalog::documents([
			$root . 'sndinfo.txt' => self::read($root . 'sndinfo.txt')
			, ...$sndinfo
		]);

		$this->language = LanguageCatalog::documents([
			$root . 'language.txt' => self::read($root . 'language.txt')
			, ...$language
		]);

		$this->keyconf = $keyconf;
	}

	private static function read(string $path): string
	{
		$text = file_get_contents($path);

		if(!is_string($text)) throw new \RuntimeException("Cannot read $path");

		return $text;
	}
}

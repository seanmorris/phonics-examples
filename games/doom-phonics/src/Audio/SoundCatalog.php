<?php
declare(strict_types=1);
namespace DoomPhonics\Audio;

/** Logical DECORATE sound names resolved through classic SNDINFO aliases and random groups. */
final class SoundCatalog
{
	/** @var array<string,string> */
	public array $aliases = [];
	/** @var array<string,list<string>> */
	public array $random = [];

	public static function parse(string $text): self
	{
		$self = new self;
		$text = (string)preg_replace('/\/\/[^\r\n]*/', '', $text);

		preg_match_all('/\$random\s+(\S+)\s*\{([^}]*)\}/i', $text, $groups, PREG_SET_ORDER);

		foreach($groups as $group)
		{
			$names = preg_split('/\s+/', trim($group[2])) ?: [];
			$self->random[strtolower($group[1])] = array_values(array_filter($names, 'strlen'));
		}

		$text = (string)preg_replace('/\$random\s+\S+\s*\{[^}]*\}/i', '', $text);

		foreach(preg_split('/\R/', $text) ?: [] as $line)
		{
			$line = trim($line);

			if($line === '' || $line[0] === '$') continue;

			$parts = preg_split('/\s+/', $line);

			if(count($parts) >= 2) $self->aliases[strtolower($parts[0])] = strtoupper($parts[1]);
		}

		return $self;
	}

	/** @param array<string,string> $documents SNDINFO text keyed by its declaring resource. */
	public static function documents(array $documents): self
	{
		$result = new self;

		foreach($documents as $source => $text)
		{
			if(!is_string($text)) throw new \InvalidArgumentException("SNDINFO document $source must contain text");

			$result->overlay(self::parse($text));
		}

		return $result;
	}

	/** Apply a later SNDINFO layer while keeping aliases and random groups mutually exclusive. */
	public function overlay(self $later): void
	{
		foreach($later->aliases as $name => $lump)
		{
			unset($this->random[$name]);
			$this->aliases[$name] = $lump;
		}

		foreach($later->random as $name => $group)
		{
			unset($this->aliases[$name]);
			$this->random[$name] = $group;
		}
	}

	/** Resolve a logical name to an archive lump. Random groups use the supplied stable choice. */
	public function resolve(string $name, int $choice = 0): ?string
	{
		$key = strtolower($name);

		if(isset($this->random[$key]))
		{
			$group = $this->random[$key];

			if(!$group) return null;

			$key = strtolower($group[(($choice % count($group)) + count($group)) % count($group)]);
		}

		return $this->aliases[$key] ?? null;
	}
}

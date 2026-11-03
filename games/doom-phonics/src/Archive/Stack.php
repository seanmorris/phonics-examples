<?php
declare(strict_types=1);
namespace DoomPhonics\Archive;

use DoomPhonics\Map\MapData;

/** Layered WAD lookup where the first IWAD supplies a base and every later archive may override it. */
final class Stack
{
	/** @var list<Archive> Archives in increasing precedence. */
	public array $archives = [];

	/**
	 * @param Archive ...$archives IWAD followed by ordered IWAD/PWAD overlays.
	 * @return void Validates the base role.
	 */
	public function __construct(Archive ...$archives)
	{
		if(!$archives || $archives[0]->type !== 'IWAD')
		throw new \InvalidArgumentException('A WAD stack must begin with an IWAD');

		$this->archives = array_values($archives);
	}

	/** @param Archive $archive Later IWAD or PWAD overlay. @return void Appends it at higher precedence. */
	public function add(Archive $archive): void
	{
		$this->archives[] = $archive;
	}

	/** @param string $name Case-insensitive lump name. @return Lump|null Highest-precedence lump. */
	public function lump(string $name): ?Lump
	{
		foreach(array_reverse($this->archives) as $archive)
		if($lump = $archive->lump($name)) return $lump;

		return null;
	}

	/**
	 * @param string $name Case-insensitive lump name.
	 * @return list<Lump> Every occurrence in increasing archive precedence and source order.
	 */
	public function lumps(string $name): array
	{
		$result = [];

		foreach($this->archives as $archive)
		foreach($archive->lumps($name) as $lump) $result[] = $lump;

		return $result;
	}

	/** @return list<string> Unique map names with later archives retaining their position. */
	public function maps(): array
	{
		$result = [];

		foreach($this->archives as $archive)
		foreach($archive->maps() as $name)
		{
			$result = array_values(array_filter($result, static fn(string $item): bool => $item !== $name));
			$result[] = $name;
		}

		return $result;
	}

	/** @param string $name Map marker name. @return MapData Parsed highest-precedence whole map. */
	public function map(string $name): MapData
	{
		foreach(array_reverse($this->archives) as $archive)
		if($lumps = $archive->mapLumps($name)) return new MapData(strtoupper($name), $lumps);

		throw new \OutOfBoundsException("Missing map $name");
	}

	/**
	 * @param list<string> $starts Namespace start markers.
	 * @param list<string> $ends Namespace end markers.
	 * @return array<string,Lump> Layered namespace.
	 */
	public function namespace(array $starts, array $ends): array
	{
		$result = [];

		foreach($this->archives as $archive) $result = array_replace($result, $archive->namespace($starts, $ends));

		return $result;
	}
}

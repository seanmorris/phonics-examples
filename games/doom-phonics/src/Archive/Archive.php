<?php
declare(strict_types=1);
namespace DoomPhonics\Archive;

/** Validated IWAD/PWAD directory with duplicate-aware lump and map lookup. */
final class Archive
{
	private string $data;
	/** @var list<Lump> Directory entries in source order. */
	public array $lumps = [];
	/** @var array<string,list<Lump>> Duplicate-aware uppercase name index. */
	public array $named = [];
	/** WAD header kind, IWAD or PWAD. */
	public string $type;

	/**
	 * Parse one complete WAD archive.
	 *
	 * @param string $bytes Unmodified archive bytes.
	 * @param string $source Canonical source URL or native path.
	 * @return void Validates every directory and lump boundary eagerly.
	 */
	public function __construct(string $bytes, public string $source = 'memory.wad')
	{
		$this->data = $bytes;
		Binary::slice($bytes, 0, 12, $source . ' header');
		$this->type = substr($bytes, 0, 4);

		if(!in_array($this->type, ['IWAD','PWAD'], true))
		throw new \UnexpectedValueException("$source: expected IWAD or PWAD header");

		$count = Binary::u32($bytes, 4, $source . ' header');
		$directory = Binary::u32($bytes, 8, $source . ' header');

		if($count > 1000000) throw new \UnexpectedValueException("$source: unreasonable lump count $count");

		Binary::slice($bytes, $directory, $count * 16, $source . ' directory');

		for($index = 0; $index < $count; $index++)
		{
			$entry = $directory + $index * 16;
			$offset = Binary::u32($bytes, $entry, $source . ' directory');
			$size = Binary::u32($bytes, $entry + 4, $source . ' directory');
			$name = Binary::name($bytes, $entry + 8, 8, $source . ' directory');

			if($name === '') throw new \UnexpectedValueException("$source: empty lump name at directory index $index");

			Binary::slice($bytes, $offset, $size, "$source#$index:$name");
			$lump = new Lump($this, $index, $name, $offset, $size);
			$this->lumps[] = $lump;
			$this->named[$name][] = $lump;
		}
	}

	/** @return string Complete immutable archive bytes. */
	public function bytes(): string { return $this->data; }

	/** @param string $name Case-insensitive lump name. @return Lump|null Last matching entry inside this archive. */
	public function lump(string $name): ?Lump
	{
		$matches = $this->named[strtoupper($name)] ?? [];

		return $matches ? $matches[array_key_last($matches)] : null;
	}

	/** @param string $name Case-insensitive lump name. @return list<Lump> Every matching entry in source order. */
	public function lumps(string $name): array { return $this->named[strtoupper($name)] ?? []; }

	/** @return list<string> Map marker names in archive order. */
	public function maps(): array
	{
		$result = [];

		for($i = 0, $count = count($this->lumps) - 3; $i < $count; $i++)
		{
			$lump = $this->lumps[$i];

			if($lump->size !== 0) continue;

			if($this->lumps[$i + 1]->name === 'THINGS'
				&& $this->lumps[$i + 2]->name === 'LINEDEFS'
				&& $this->lumps[$i + 3]->name === 'SIDEDEFS'
			) $result[] = $lump->name;
		}

		return $result;
	}

	/**
	 * Collect the map namespace following a marker.
	 *
	 * @param string $name Map marker name.
	 * @return array<string,Lump>|null Last lump for each map-namespace name, including HEADER.
	 */
	public function mapLumps(string $name): ?array
	{
		$name = strtoupper($name);
		$markers = array_flip($this->maps());
		$header = null;

		foreach($this->lumps as $lump)
		if($lump->name === $name && isset($markers[$name])) $header = $lump;

		if(!$header) return null;

		$allowed = array_flip([
			'THINGS','LINEDEFS','SIDEDEFS','VERTEXES','SEGS','SSECTORS','NODES','SECTORS'
			, 'REJECT'
			, 'BLOCKMAP'
			, 'BLOCKMP'
			, 'BEHAVIOR'
			, 'SCRIPTS'
			, 'TEXTMAP'
			, 'ENDMAP'
			, 'ZNODES'
			, 'GL_LEVEL','GL_VERT','GL_SEGS','GL_SSECT','GL_NODES','GL_PVS'
		]);

		$result = ['HEADER' => $header];

		for($i = $header->index + 1, $count = count($this->lumps); $i < $count; $i++)
		{
			$lump = $this->lumps[$i];

			if(isset($markers[$lump->name])) break;

			if(!isset($allowed[$lump->name]) && !str_starts_with($lump->name, 'GL_')) break;

			$result[$lump->name] = $lump;
		}

		return $result;
	}

	/**
	 * Enumerate entries between namespace markers such as P_START/P_END.
	 *
	 * @param list<string> $starts Opening marker names.
	 * @param list<string> $ends Closing marker names.
	 * @return array<string,Lump> Last nonempty entry by name inside matching ranges.
	 */
	public function namespace(array $starts, array $ends): array
	{
		$starts = array_flip($starts);
		$ends = array_flip($ends);
		$inside = false;
		$result = [];

		foreach($this->lumps as $lump)
		{
			if(isset($starts[$lump->name]))
			{
				$inside = true;
				continue;
			}

			if(isset($ends[$lump->name]))
			{
				$inside = false;
				continue;
			}

			if($inside && $lump->size > 0) $result[$lump->name] = $lump;
		}

		return $result;
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Archive;

/** One immutable directory entry retaining its archive and canonical source identity. */
final class Lump
{

	/**
	 * @param Archive $archive Owning archive.
	 * @param int $index Directory index.
	 * @param string $name Uppercase lump name.
	 * @param int $offset Byte offset.
	 * @param int $size Byte length.
	 * @return void Stores validated directory metadata.
	 */
	public function __construct(
		public Archive $archive
		, public int $index
		, public string $name
		, public int $offset
		, public int $size
	){
	}

	/** @return string Unmodified lump bytes. */
	public function bytes(): string
	{
		return Binary::slice($this->archive->bytes(), $this->offset, $this->size, $this->identity());
	}

	/** @return string Canonical archive-and-entry diagnostic identity. */
	public function identity(): string { return $this->archive->source . '#' . $this->index . ':' . $this->name; }
}

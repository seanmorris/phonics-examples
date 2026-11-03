<?php
declare(strict_types=1);
namespace DoomPhonics\Graphics;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Stack;

/** PLAYPAL colors and COLORMAP light translation for indexed WAD artwork. */
final class Palette
{
	/** Rows 0-31 are light levels; row 32 is invulnerability and row 33 is black. */
	private const STANDARD_LIGHT_MAP_COUNT = 32;

	/** @var list<array{0:int,1:int,2:int}> */
	public array $colors = [];
	/** @var list<list<array{0:int,1:int,2:int}>> Every complete PLAYPAL page. */
	private array $palettes = [];
	/** @var list<string> Each colormap contains 256 translated palette indices. */
	public array $maps = [];
	/** @var list<list<int>> Normal-page packed RGBA colors by light map and source index. */
	private array $packed = [];
	/** @var array<string,int> */
	private array $closest = [];

	/** @param Stack $wad Layered resource source. @return void Parses all palettes and colormaps. */
	public function __construct(Stack $wad)
	{
		$playpal = $wad->lump('PLAYPAL') ?? throw new \UnexpectedValueException('WAD stack has no PLAYPAL');
		$playpalBytes = $playpal->bytes();
		$count = max(1, intdiv(strlen($playpalBytes), 768));

		for($page = 0; $page < $count; $page++)
		{
			$bytes = Binary::slice($playpalBytes, $page * 768, 768, $playpal->identity());
			$palette = [];

			for($i = 0; $i < 256; $i++)
			$palette[] = [ord($bytes[$i * 3]), ord($bytes[$i * 3 + 1]), ord($bytes[$i * 3 + 2])];

			$this->palettes[] = $palette;
		}

		$this->colors = $this->palettes[0];

		$colormap = $wad->lump('COLORMAP');
		$bytes = $colormap?->bytes() ?? implode('', array_map('chr', range(0, 255)));
		$count = max(1, intdiv(strlen($bytes), 256));

		for($i = 0; $i < $count; $i++) $this->maps[] = substr($bytes, $i * 256, 256);

		for($map = 0; $map < count($this->maps); $map++)
		for($index = 0; $index < 256; $index++)
		{
			$translated = ord($this->maps[$map][$index]);
			[$r,$g,$b] = $this->colors[$translated];
			$this->packed[$map][$index] = $r | ($g << 8) | ($b << 16) | (255 << 24);
		}
	}

	/**
	 * @param int $index Source palette index.
	 * @param int $lightMap COLORMAP row.
	 * @param int $palettePage PLAYPAL page.
	 * @return int Little-endian packed RGBA.
	 */
	public function rgba(int $index, int $lightMap = 0, int $palettePage = 0): int
	{
		$lightMap = max(0, min(count($this->packed) - 1, $lightMap));
		$palettePage = max(0, min(count($this->palettes) - 1, $palettePage));
		$index &= 255;

		if($palettePage === 0) return $this->packed[$lightMap][$index];

		$translated = ord($this->maps[$lightMap][$index]);
		[$r,$g,$b] = $this->palettes[$palettePage][$translated];

		return $r | ($g << 8) | ($b << 16) | (255 << 24);
	}

	/** Find the nearest active PLAYPAL entry for an RGB color. */
	public function closestIndex(int $red, int $green, int $blue): int
	{
		$red = max(0, min(255, $red));
		$green = max(0, min(255, $green));
		$blue = max(0, min(255, $blue));
		$key = "$red:$green:$blue";

		if(isset($this->closest[$key])) return $this->closest[$key];

		$best = 0;
		$distance = PHP_INT_MAX;

		foreach($this->colors as $index => [$r,$g,$b])
		{
			$candidate = ($r - $red) ** 2 + ($g - $green) ** 2 + ($b - $blue) ** 2;

			if($candidate >= $distance) continue;

			$best = $index;
			$distance = $candidate;
		}

		return $this->closest[$key] = $best;
	}

	/**
	 * @param int $sectorLight Doom sector light in [0,255].
	 * @param float $distance Corrected view distance.
	 * @return int COLORMAP row.
	 */
	public function lightMap(int $sectorLight, float $distance = 0): int
	{
		$base = (int)round((255 - max(0, min(255, $sectorLight))) / 8);
		$falloff = (int)floor(max(0, $distance - 160) / 96);

		return max(0, min($this->lightMapCount() - 1, $base + $falloff));
	}

	/** Number of COLORMAP rows available to ordinary sector and distance lighting. */
	public function lightMapCount(): int
	{
		return min(self::STANDARD_LIGHT_MAP_COUNT, count($this->maps));
	}

	/** Number of complete PLAYPAL pages available for full-screen color effects. */
	public function paletteCount(): int { return count($this->palettes); }

	/** Number of COLORMAP translations, including any special effect rows. */
	public function mapCount(): int { return count($this->maps); }

	/** Whether this WAD supplies a requested fixed COLORMAP translation. */
	public function hasMap(int $map): bool { return isset($this->maps[$map]); }

	/** Height of the flattened PLAYPAL-by-COLORMAP GLES lookup texture. */
	public function textureRowCount(): int { return $this->paletteCount() * $this->mapCount(); }

	/** Packed RGBA rows for every PLAYPAL page and COLORMAP row in page-major order. */
	public function textureBytes(): string
	{
		$bytes = '';

		for($palette = 0; $palette < $this->paletteCount(); $palette++)
		for($map = 0; $map < $this->mapCount(); $map++)
		{
			$row = [];

			for($index = 0; $index < 256; $index++) $row[] = $this->rgba($index, $map, $palette);

			$bytes .= pack('V*', ...$row);
		}

		return $bytes;
	}
}

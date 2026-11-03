<?php
declare(strict_types=1);
namespace DoomPhonics\Map;

use DoomPhonics\Archive\Binary;
use DoomPhonics\Archive\Lump;

/** Parsed immutable Doom/Hexen binary map structures with BSP and topology indexes. */
final class MapData
{
	/** @var list<array<string,mixed>> */
	public array $things = [], $linedefs = [], $sidedefs = [], $vertices = [];
	/** @var list<array<string,mixed>> */
	public array $segs = [], $subsectors = [], $nodes = [], $sectors = [];
	/** Doom REJECT visibility matrix, or an empty string when the map omits it. */
	public string $reject = '';
	/** @var array<int,list<array{0:float,1:float}>>|null */
	private ?array $subsectorPolygons = null;
	/** @var array<int,list<int>> Sector to linedef index. */
	public array $sectorLines = [];
	/** @var array<int,list<int>> Sector adjacency. */
	public array $neighbors = [];
	/** @var array<int,array<int,true>> Normal-geometry components keyed by their starting sector. */
	private array $sectorComponents = [];
	/** @var array<int,int> Canonical normal-geometry component ID keyed by sector. */
	private array $sectorComponentIds = [];
	/** @var array{minX:int,minY:int,maxX:int,maxY:int,width:int,height:int} */
	public array $bounds;
	public string $format;

	/**
	 * Parse all gameplay structures for one map namespace.
	 *
	 * @param string $name Stable map marker name.
	 * @param array<string,Lump> $lumps Map namespace entries.
	 * @return void Rejects missing and misaligned required structures.
	 */
	public function __construct(public string $name, public array $lumps)
	{
		foreach(['THINGS','LINEDEFS','SIDEDEFS','VERTEXES','SECTORS'] as $required)
		if(!isset($lumps[$required])) throw new \UnexpectedValueException("Map $name is missing $required");

		$this->format = isset($lumps['TEXTMAP']) ? 'UDMF' : (isset($lumps['BEHAVIOR']) ? 'HEXEN' : 'DOOM');

		if($this->format === 'UDMF') throw new \UnexpectedValueException("Map $name requires the UDMF parser");

		$this->parseThings();
		$this->parseLinedefs();
		$this->parseSidedefs();
		$this->parseVertices();
		$this->parseSegs();
		$this->parseSubsectors();
		$this->parseNodes();
		$this->parseSectors();
		$this->parseReject();
		$this->indexTopology();
	}

	/**
	 * @param string $name Lump name.
	 * @param int $stride Record size.
	 * @return array{string,int,string} Bytes, count, diagnostic identity.
	 */
	private function records(string $name, int $stride): array
	{
		$lump = $this->lumps[$name] ?? null;

		if(!$lump) return ['',0,"{$this->name}/$name"];

		if($lump->size % $stride !== 0)
		throw new \UnexpectedValueException("{$lump->identity()}: size {$lump->size} is not divisible by $stride");

		return [$lump->bytes(), intdiv($lump->size, $stride), $lump->identity()];
	}

	/** @return void Parses Doom or Hexen things. */
	private function parseThings(): void
	{
		$stride = $this->format === 'HEXEN' ? 20 : 10;
		[$bytes,$count,$source] = $this->records('THINGS', $stride);

		for($i = 0; $i < $count; $i++)
		{
			$o = $i * $stride;

			if($this->format === 'DOOM')
			{
				$flags = Binary::u16($bytes, $o + 8, $source);
				$this->things[] = [
					'index' => $i
					, 'x' => Binary::s16($bytes, $o, $source)
					, 'y' => Binary::s16($bytes, $o + 2, $source)
					, 'z' => 0
					, 'angle' => Binary::s16($bytes, $o + 4, $source)
					, 'type' => Binary::u16($bytes, $o + 6, $source)
					, 'flagsRaw' => $flags
					, 'flags' => [
						'easy' => (bool)($flags & 1)
						, 'medium' => (bool)($flags & 2)
						, 'hard' => (bool)($flags & 4)
						, 'ambush' => (bool)($flags & 8)
						, 'multiplayer' => (bool)($flags & 16)
					]
				];
			}
			else
			{
				$flags = Binary::u16($bytes, $o + 12, $source);

				$this->things[] = [
					'index' => $i
					, 'id' => Binary::u16($bytes, $o, $source)
					, 'x' => Binary::s16($bytes, $o + 2, $source)
					, 'y' => Binary::s16($bytes, $o + 4, $source)
					, 'z' => Binary::s16($bytes, $o + 6, $source)
					, 'angle' => Binary::u16($bytes, $o + 8, $source)
					, 'type' => Binary::u16($bytes, $o + 10, $source)
					, 'flagsRaw' => $flags
					, 'flags' => [
						'easy' => (bool)($flags & 1)
						, 'medium' => (bool)($flags & 2)
						, 'hard' => (bool)($flags & 4)
						, 'ambush' => (bool)($flags & 8)
						, 'single' => (bool)($flags & 256)
						, 'cooperative' => (bool)($flags & 512)
						, 'deathmatch' => (bool)($flags & 1024)
					]
					, 'special' => Binary::u8($bytes, $o + 14, $source)
					, 'args' => array_map(
						static fn(int $n): int => Binary::u8($bytes, $o + 15 + $n, $source)
						, range(0, 4)
					)
				];
			}
		}
	}

	/** @return void Parses Doom or Hexen linedefs. */
	private function parseLinedefs(): void
	{
		$stride = $this->format === 'HEXEN' ? 16 : 14;
		[$bytes,$count,$source] = $this->records('LINEDEFS', $stride);

		for($i = 0; $i < $count; $i++)
		{
			$o = $i * $stride;
			$line = [
				'index' => $i
				, 'from' => Binary::u16($bytes, $o, $source)
				, 'to' => Binary::u16($bytes, $o + 2, $source)
				, 'flags' => Binary::u16($bytes, $o + 4, $source)
			];

			if($this->format === 'DOOM')
			{
				$line += [
					'action' => Binary::u16($bytes, $o + 6, $source)
					, 'tag' => Binary::u16($bytes, $o + 8, $source)
					, 'right' => Binary::u16($bytes, $o + 10, $source)
					, 'left' => Binary::u16($bytes, $o + 12, $source)
				];
			}
			else
			{
				$line += [
					'action' => Binary::u8($bytes, $o + 6, $source)
					, 'tag' => 0
					, 'args' => array_map(
						static fn(int $n): int => Binary::u8($bytes, $o + 7 + $n, $source)
						, range(0, 4)
					)
					, 'right' => Binary::u16($bytes, $o + 12, $source)
					, 'left' => Binary::u16($bytes, $o + 14, $source)
				];
			}

			if($line['left'] === 0xffff) $line['left'] = -1;

			if($line['right'] === 0xffff) $line['right'] = -1;

			$this->linedefs[] = $line;
		}
	}

	/** @return void Parses sidedefs. */
	private function parseSidedefs(): void
	{
		[$bytes,$count,$source] = $this->records('SIDEDEFS', 30);

		for($i = 0; $i < $count; $i++)
		{
			$o = $i * 30;
			$this->sidedefs[] = [
				'index' => $i
				, 'xOffset' => Binary::s16($bytes, $o, $source)
				, 'yOffset' => Binary::s16($bytes, $o + 2, $source)
				, 'upper' => Binary::name($bytes, $o + 4, 8, $source)
				, 'lower' => Binary::name($bytes, $o + 12, 8, $source)
				, 'middle' => Binary::name($bytes, $o + 20, 8, $source)
				, 'sector' => Binary::u16($bytes, $o + 28, $source)
			];
		}
	}

	/** @return void Parses vertices and computes map bounds. */
	private function parseVertices(): void
	{
		[$bytes,$count,$source] = $this->records('VERTEXES', 4);
		$minX = $minY = PHP_INT_MAX;
		$maxX = $maxY = PHP_INT_MIN;

		for($i = 0; $i < $count; $i++)
		{
			$x = Binary::s16($bytes, $i * 4, $source);
			$y = Binary::s16($bytes, $i * 4 + 2, $source);
			$this->vertices[] = ['index' => $i, 'x' => $x, 'y' => $y];
			$minX = min($minX, $x);
			$maxX = max($maxX, $x);
			$minY = min($minY, $y);
			$maxY = max($maxY, $y);
		}

		$this->bounds = [
			'minX' => $minX
			, 'minY' => $minY
			, 'maxX' => $maxX
			, 'maxY' => $maxY
			, 'width' => $maxX - $minX
			, 'height' => $maxY - $minY
		];
	}

	/** @return void Parses BSP segments. */
	private function parseSegs(): void
	{
		[$bytes,$count,$source] = $this->records('SEGS', 12);

		for($i = 0; $i < $count; $i++)
		{
			$o = $i * 12;
			$this->segs[] = [
				'index' => $i
				, 'start' => Binary::u16($bytes, $o, $source)
				, 'end' => Binary::u16($bytes, $o + 2, $source)
				, 'angle' => Binary::u16($bytes, $o + 4, $source)
				, 'linedef' => Binary::u16($bytes, $o + 6, $source)
				, 'direction' => Binary::u16($bytes, $o + 8, $source)
				, 'offset' => Binary::u16($bytes, $o + 10, $source)
			];
		}
	}

	/** @return void Parses BSP subsectors. */
	private function parseSubsectors(): void
	{
		[$bytes,$count,$source] = $this->records('SSECTORS', 4);

		for($i = 0; $i < $count; $i++)
		$this->subsectors[] = [
			'index' => $i
			, 'count' => Binary::u16($bytes, $i * 4, $source)
			, 'first' => Binary::u16($bytes, $i * 4 + 2, $source)
		];
	}

	/** @return void Parses BSP nodes. */
	private function parseNodes(): void
	{
		[$bytes,$count,$source] = $this->records('NODES', 28);

		for($i = 0; $i < $count; $i++)
		{
			$o = $i * 28;

			$this->nodes[] = [
				'index' => $i
				, 'x' => Binary::s16($bytes, $o, $source)
				, 'y' => Binary::s16($bytes, $o + 2, $source)
				, 'dx' => Binary::s16($bytes, $o + 4, $source)
				, 'dy' => Binary::s16($bytes, $o + 6, $source)
				, 'rightBounds' => array_map(
					static fn(int $n): int => Binary::s16($bytes, $o + 8 + $n * 2, $source)
					, range(0, 3)
				)
				, 'leftBounds' => array_map(
					static fn(int $n): int => Binary::s16($bytes, $o + 16 + $n * 2, $source)
					, range(0, 3)
				)
				, 'right' => Binary::u16($bytes, $o + 24, $source)
				, 'left' => Binary::u16($bytes, $o + 26, $source)
			];
		}
	}

	/** @return void Parses sectors. */
	private function parseSectors(): void
	{
		[$bytes,$count,$source] = $this->records('SECTORS', 26);

		for($i = 0; $i < $count; $i++)
		{
			$o = $i * 26;
			$this->sectors[] = [
				'index' => $i
				, 'floor' => Binary::s16($bytes, $o, $source)
				, 'ceiling' => Binary::s16($bytes, $o + 2, $source)
				, 'floorTexture' => Binary::name($bytes, $o + 4, 8, $source)
				, 'ceilingTexture' => Binary::name($bytes, $o + 12, 8, $source)
				, 'light' => Binary::u16($bytes, $o + 20, $source)
				, 'special' => Binary::u16($bytes, $o + 22, $source)
				, 'tag' => Binary::u16($bytes, $o + 24, $source)
			];
		}
	}

	/** Retain the conservative sector visibility matrix used to reject hidden actors. */
	private function parseReject(): void
	{
		$lump = $this->lumps['REJECT'] ?? null;
		$this->reject = $lump ? $lump->bytes() : '';
	}

	/**
	 * Test the WAD's conservative sector pair visibility bit.
	 * A missing or truncated matrix never hides content.
	 */
	public function rejects(int $from, int $to): bool
	{
		$count = count($this->sectors);

		if($from < 0 || $to < 0 || $from >= $count || $to >= $count) return false;

		$bit = $from * $count + $to;
		$byte = intdiv($bit, 8);

		return isset($this->reject[$byte]) && (ord($this->reject[$byte]) & (1 << ($bit & 7))) !== 0;
	}

	/** @return array<int,true> Sectors reachable through ordinary two-sided geometry. */
	public function connectedSectors(int $origin): array
	{
		if(isset($this->sectorComponents[$origin])) return $this->sectorComponents[$origin];

		$component = [];
		$pending = isset($this->sectors[$origin]) ? [$origin] : [];

		while($pending)
		{
			$sector = array_pop($pending);

			if(isset($component[$sector])) continue;

			$component[$sector] = true;

			foreach($this->neighbors[$sector] ?? [] as $neighbor)
			if(!isset($component[$neighbor])) $pending[] = $neighbor;
		}

		foreach(array_keys($component) as $sector) $this->sectorComponents[$sector] = $component;

		return $component;
	}

	/** Return a stable ID shared by sectors joined through ordinary two-sided geometry. */
	public function sectorComponent(int $origin): int
	{
		if(isset($this->sectorComponentIds[$origin])) return $this->sectorComponentIds[$origin];

		$component = $this->connectedSectors($origin);
		$id = $component ? min(array_keys($component)) : $origin;

		foreach(array_keys($component) as $sector) $this->sectorComponentIds[$sector] = $id;

		return $this->sectorComponentIds[$origin] = $id;
	}

	/**
	 * Reconstruct closed convex leaf polygons from classic BSP data.
	 *
	 * Vanilla SEGS omit partition-only minisegs, so their endpoints cannot be fanned
	 * directly into complete floor and ceiling surfaces. Node half-planes supply those
	 * missing boundaries; the real segs then trim each leaf to playable map space.
	 *
	 * @return array<int,list<array{0:float,1:float}>> Polygon points keyed by subsector.
	 */
	public function subsectorPolygons(): array
	{
		if($this->subsectorPolygons !== null) return $this->subsectorPolygons;

		if(!$this->subsectors || !$this->segs) return $this->subsectorPolygons = [];

		$bounds = $this->bounds;
		$root = [
			[(float)$bounds['minX'], (float)$bounds['minY']]
			, [(float)$bounds['maxX'], (float)$bounds['minY']]
			, [(float)$bounds['maxX'], (float)$bounds['maxY']]
			, [(float)$bounds['minX'], (float)$bounds['maxY']]
		];

		// A convex map with one subsector has no partition to encode, so an empty
		// NODES lump is valid. Its sole leaf starts with the complete map bounds and
		// is trimmed by the ordinary SEGS below just like every BSP-derived leaf.
		$leaves = !$this->nodes && count($this->subsectors) === 1 ? [0 => $root] : [];
		$pending = $this->nodes ? [[count($this->nodes) - 1, $root]] : [];

		while($pending)
		{
			[$child,$polygon] = array_pop($pending);

			if($child & 0x8000)
			{
				$index = $child & 0x7fff;

				if(isset($this->subsectors[$index])) $leaves[$index] = $polygon;

				continue;
			}

			$node = $this->nodes[$child] ?? null;

			if(!$node) continue;

			$right = self::clipPolygon($polygon, $node['x'], $node['y'], $node['dx'], $node['dy'], true);
			$left = self::clipPolygon($polygon, $node['x'], $node['y'], $node['dx'], $node['dy'], false);

			if(count($right) >= 3) $pending[] = [$node['right'], $right];

			if(count($left) >= 3) $pending[] = [$node['left'], $left];
		}

		$result = [];

		foreach($this->subsectors as $subsector)
		{
			$polygon = $leaves[$subsector['index']] ?? [];

			for($i = 0; $i < $subsector['count'] && count($polygon) >= 3; $i++)
			{
				$seg = $this->segs[$subsector['first'] + $i] ?? null;

				if(!$seg) continue;

				$from = $this->vertices[$seg['start']] ?? null;
				$to = $this->vertices[$seg['end']] ?? null;
				$line = $seg['linedef'] === 0xffff ? null : ($this->linedefs[$seg['linedef']] ?? null);

				/*
				 * Node builders may add integer SEGS vertices that sit slightly beside the
				 * linedef they split (the classic "slime trail" error). GL renderers stitch
				 * those generated vertices back to the source line. Use that exact line as
				 * the clipping plane here as well, while retaining the SEG orientation.
				 */
				if($line)
				{
					$fromIndex = $seg['direction'] ? $line['to'] : $line['from'];
					$toIndex = $seg['direction'] ? $line['from'] : $line['to'];
					$from = $this->vertices[$fromIndex] ?? $from;
					$to = $this->vertices[$toIndex] ?? $to;
				}

				if(!$from || !$to) continue;

				$polygon = self::clipPolygon(
					$polygon
					, $from['x']
					, $from['y']
					, $to['x'] - $from['x']
					, $to['y'] - $from['y']
					, true
				);
			}

			$result[$subsector['index']] = self::deduplicatePolygon($polygon);
		}

		return $this->subsectorPolygons = $result;
	}

	/** @param list<array{0:float,1:float}> $polygon @return list<array{0:float,1:float}> */
	private static function clipPolygon(array $polygon, float $x, float $y, float $dx, float $dy, bool $positive): array
	{
		if(!$polygon || ($dx == 0.0 && $dy == 0.0)) return $polygon;

		$result = [];
		$count = count($polygon);

		for($i = 0; $i < $count; $i++)
		{
			$current = $polygon[$i];
			$next = $polygon[($i + 1) % $count];
			$currentSide = ($current[0] - $x) * $dy - ($current[1] - $y) * $dx;
			$nextSide = ($next[0] - $x) * $dy - ($next[1] - $y) * $dx;
			$currentInside = $positive ? $currentSide >= -1e-7 : $currentSide <= 1e-7;
			$nextInside = $positive ? $nextSide >= -1e-7 : $nextSide <= 1e-7;

			if($currentInside) $result[] = $current;

			if($currentInside !== $nextInside)
			{
				$amount = $currentSide / ($currentSide - $nextSide);
				$result[] = [
					$current[0] + ($next[0] - $current[0]) * $amount
					, $current[1] + ($next[1] - $current[1]) * $amount
				];
			}
		}

		return self::deduplicatePolygon($result);
	}

	/** @param list<array{0:float,1:float}> $polygon @return list<array{0:float,1:float}> */
	private static function deduplicatePolygon(array $polygon): array
	{
		$result = [];

		foreach($polygon as $point)
		if(!$result || abs($point[0] - $result[array_key_last($result)][0]) > 1e-7
			|| abs($point[1] - $result[array_key_last($result)][1]) > 1e-7
		) $result[] = $point;

		if(count($result) > 1 && abs($result[0][0] - $result[array_key_last($result)][0]) <= 1e-7
			&& abs($result[0][1] - $result[array_key_last($result)][1]) <= 1e-7
		) array_pop($result);

		return $result;
	}

	/** @return void Validates references and builds sector line/neighbor indexes. */
	private function indexTopology(): void
	{
		foreach($this->linedefs as $line)
		{
			if(!isset($this->vertices[$line['from']], $this->vertices[$line['to']]))
			throw new \UnexpectedValueException("{$this->name}: linedef {$line['index']} references a missing vertex");

			$sectorIds = [];

			foreach(['right','left'] as $side)
			{
				$sideId = $line[$side];

				if($sideId < 0) continue;

				if(!isset($this->sidedefs[$sideId]))
				throw new \UnexpectedValueException(
					"{$this->name}: linedef {$line['index']} references a missing sidedef"
				);

				$sector = $this->sidedefs[$sideId]['sector'];

				if(!isset($this->sectors[$sector]))
				throw new \UnexpectedValueException("{$this->name}: sidedef $sideId references a missing sector");

				$this->sectorLines[$sector][] = $line['index'];
				$sectorIds[] = $sector;
			}

			if(count($sectorIds) === 2 && $sectorIds[0] !== $sectorIds[1])
			{
				$this->neighbors[$sectorIds[0]][] = $sectorIds[1];
				$this->neighbors[$sectorIds[1]][] = $sectorIds[0];
			}
		}

		foreach($this->neighbors as &$neighbors) $neighbors = array_values(array_unique($neighbors));
	}

	/** Locate a map-space point without rounding it across a BSP partition or portal seam. */
	public function sectorAt(float $x, float $y): int
	{
		if(!$this->nodes || !$this->subsectors || !$this->segs) return 0;

		$child = count($this->nodes) - 1;

		for($guard = 0; $guard < count($this->nodes) + 8; $guard++)
		{
			if($child & 0x8000)
			{
				$subsector = $this->subsectors[$child & 0x7fff] ?? null;

				if(!$subsector) break;

				for($i = 0; $i < $subsector['count']; $i++)
				{
					$seg = $this->segs[$subsector['first'] + $i] ?? null;

					if(!$seg || $seg['linedef'] === 0xffff) continue;

					$line = $this->linedefs[$seg['linedef']];
					$sideId = $seg['direction'] ? $line['left'] : $line['right'];

					if($sideId >= 0) return $this->sidedefs[$sideId]['sector'];
				}

				break;
			}

			$node = $this->nodes[$child] ?? null;

			if(!$node) break;

			$behind = ($x - $node['x']) * $node['dy'] - ($y - $node['y']) * $node['dx'] <= 0;
			$child = $node[$behind ? 'left' : 'right'];
		}

		return 0;
	}

	/** @param int $sector Sector index. @return list<int> Linedefs surrounding the sector. */
	public function linesForSector(int $sector): array { return $this->sectorLines[$sector] ?? []; }
}

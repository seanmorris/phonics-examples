<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

/** Decode ZDoom/Skulltag Hexen-format line and stacked-sector portal conventions. */
final class HexenCameraPortalSetup
{
	public const LINE_SET_PORTAL = 156;
	public const SECTOR_SET_PORTAL = 57;
	public const LINE_SET_IDENTIFICATION = 121;
	public const UPPER_STACK_LOOK_ONLY = 9077;
	public const LOWER_STACK_LOOK_ONLY = 9078;

	public static function structuralThing(int $type): bool
	{
		return $type === self::UPPER_STACK_LOOK_ONLY || $type === self::LOWER_STACK_LOOK_ONLY;
	}

	public static function apply(Level $level): void
	{
		self::indexLineIds($level);
		self::linePortals($level);
		self::sectorLinePortals($level);
		self::stackedSectorThings($level);
	}

	private static function indexLineIds(Level $level): void
	{
		foreach($level->map->linedefs as $line)
		{
			$id = match($line['action'])
			{
				self::LINE_SET_IDENTIFICATION => $line['args'][0] + 256 * $line['args'][4]
				, self::LINE_SET_PORTAL => $line['args'][1]
				, 208, 215, 222 => $line['args'][0]
				, default => 0
			};

			if($id) $level->addLineId($id, $line['index']);
		}
	}

	private static function linePortals(Level $level): void
	{
		foreach($level->map->linedefs as $line)
		{
			if($line['action'] !== self::LINE_SET_PORTAL || !CameraPortalType::valid($line['args'][2])) continue;

			$destination = $level->firstLineWithId($line['args'][0], $line['index']);
			$link = self::lineLink($level, $line['index'], $destination, $line['args'][2], $line['args'][3]);
			$level->addCameraPortalSurface(new CameraPortalSurface(CameraPortalSurface::LINE, $line['index'], $link));
		}

		foreach($level->cameraPortalLinks() as $link)
		{
			if($link->originLine === null || $link->destinationLine === null) continue;

			$reverse = $level->cameraPortalForLine($link->destinationLine)?->link;

			if($reverse && $reverse->destinationLine === $link->originLine)
			$link->reciprocalId = $reverse->id;

			if($link->type === CameraPortalType::LINKED
				&& (!$reverse || $reverse->destinationLine !== $link->originLine || $reverse
					->type !== CameraPortalType::LINKED
				)
			) $link->type = CameraPortalType::TELEPORTER;
		}
	}

	private static function lineLink(
		Level $level
		, int $originId
		, ?int $destinationId
		, int $type
		, int $alignment
	): CameraPortalLink {
		$origin = $level->map->linedefs[$originId];
		$a = $level->map->vertices[$origin['from']];
		$b = $level->map->vertices[$origin['to']];
		$originSector = $level->sides($originId)['front'] ?? 0;
		$destinationSector = $originSector;
		$destinationX = (float)$a['x'];
		$destinationY = (float)$a['y'];
		$angle = 0.0;

		if($destinationId !== null)
		{
			$destination = $level->map->linedefs[$destinationId];
			$c = $level->map->vertices[$destination['from']];
			$d = $level->map->vertices[$destination['to']];
			$destinationSector = $level->sides($destinationId)['front'] ?? $originSector;
			$destinationX = (float)$d['x'];
			$destinationY = (float)$d['y'];
			$angle = $type === CameraPortalType::LINKED
				? 0.0
				: atan2($d['y'] - $c['y'], $d['x'] - $c['x'])
					- atan2($b['y'] - $a['y'], $b['x'] - $a['x']) + M_PI;
		}

		if($type === CameraPortalType::LINKED) $alignment = CameraPortalLink::ALIGN_ABSOLUTE;

		if($alignment < CameraPortalLink::ALIGN_ABSOLUTE || $alignment > CameraPortalLink::ALIGN_CEILING)
		$alignment = CameraPortalLink::ALIGN_ABSOLUTE;

		if($type === CameraPortalType::INTERACTIVE && $alignment !== CameraPortalLink::ALIGN_ABSOLUTE)
		$type = CameraPortalType::TELEPORTER;

		if($type !== CameraPortalType::VISUAL && $level->sides($originId)['back'] === null)
		$type = CameraPortalType::VISUAL;

		$link = new CameraPortalLink(
			$level->nextCameraPortalId()
			, $type
			, $alignment
			, $originSector
			, $destinationSector
			, (float)$a['x']
			, (float)$a['y']
			, $destinationX
			, $destinationY
			, $angle
			, $originId
			, $destinationId
		);

		$link->active = $destinationId !== null;
		$level->addCameraPortalLink($link);

		return $link;
	}

	private static function sectorLinePortals(Level $level): void
	{
		$definitions = [];
		$links = [];

		foreach($level->map->linedefs as $line)
		{
			$args = $line['args'];

			if($line['action'] !== self::SECTOR_SET_PORTAL || !in_array($args[1], [0,6], true)
				|| $args[3] !== 0 || $args[2] < 0 || $args[2] > 2 || ($args[1] === 6 && $args[2] === 2)
			) continue;

			$reference = null;

			foreach($level->map->linedefs as $candidate)
			if($candidate['action'] === self::SECTOR_SET_PORTAL && $candidate['args'][0] === $args[0]
				&& $candidate['args'][1] === $args[1] && $candidate['args'][2] === $args[2]
				&& $candidate['args'][3] === 1
			){
				$reference = $candidate;
				break;
			}

			if(!$reference) continue;

			$originSector = $level->sides($line['index'])['front'];
			$destinationSector = $level->sides($reference['index'])['front'];

			if($originSector === null || $destinationSector === null) continue;

			[$originX,$originY] = self::lineCenter($level, $line);
			[$destinationX,$destinationY] = self::lineCenter($level, $reference);
			$overlayAlpha = max(0.0, min(1.0, $args[4] / 255));
			$planes = $args[2] === 2 ? [0,1] : [$args[2]];

			foreach($planes as $plane)
			{
				$sourceAlignment = $plane ? CameraPortalLink::ALIGN_CEILING : CameraPortalLink::ALIGN_FLOOR;
				$destinationAlignment = $plane ? CameraPortalLink::ALIGN_FLOOR : CameraPortalLink::ALIGN_CEILING;

				$link = new CameraPortalLink(
					$level->nextCameraPortalId()
					, $args[1] === 6 ? CameraPortalType::LINKED : CameraPortalType::VISUAL
					, $sourceAlignment
					, $originSector
					, $destinationSector
					, $originX
					, $originY
					, $destinationX
					, $destinationY
					, 0.0
					, null
					, null
					, null
					, $destinationAlignment
				);

				$level->addCameraPortalLink($link);
				$links[] = $link;
				$key = $args[0] . ':' . $plane;
				$definitions[$key] = ['link' => $link,'overlayAlpha' => $overlayAlpha];

				foreach($level->tags[$args[0]] ?? [] as $sector)
				$level->addCameraPortalSurface(
					new CameraPortalSurface(
						$plane ? CameraPortalSurface::CEILING : CameraPortalSurface::FLOOR
						, $sector
						, $link
						, $overlayAlpha
					)
				);
			}
		}

		foreach($level->map->linedefs as $line)
		{
			$args = $line['args'];

			if($line['action'] !== self::SECTOR_SET_PORTAL || $args[1] !== 1) continue;

			$planes = $args[2] === 3 ? [0,1] : [$args[2]];

			foreach($planes as $plane)
			{
				$definition = $definitions[$args[3] . ':' . $plane] ?? null;

				if(!$definition) continue;

				$link = $definition['link'];
				$overlayAlpha = $definition['overlayAlpha'];

				$targets = $args[0]
					? ($level->tags[$args[0]] ?? [])
					: array_values(
						array_filter([$level->sides($line['index'])['front']], static fn($v): bool => $v !== null)
					);

				foreach($targets as $sector)
				$level->addCameraPortalSurface(
					new CameraPortalSurface(
						$plane ? CameraPortalSurface::CEILING : CameraPortalSurface::FLOOR
						, $sector
						, $link
						, $overlayAlpha
					)
				);
			}
		}

		self::pairReciprocals($links);
	}

	private static function stackedSectorThings(Level $level): void
	{
		$upper = $lower = [];

		foreach($level->map->things as $thing)
		{
			$id = (int)($thing['id'] ?? 0);

			if(!$id) continue;

			if($thing['type'] === self::UPPER_STACK_LOOK_ONLY) $upper[$id][] = $thing;
			elseif($thing['type'] === self::LOWER_STACK_LOOK_ONLY) $lower[$id][] = $thing;
		}

		foreach(array_intersect(array_keys($upper), array_keys($lower)) as $id)
		foreach($upper[$id] as $top)
		{
			$bottom = $lower[$id][0];
			$topSector = $level->map->sectorAt((int)$top['x'], (int)$top['y']);
			$bottomSector = $level->map->sectorAt((int)$bottom['x'], (int)$bottom['y']);
			$alpha = max(0.0, min(1.0, (int)($top['args'][0] ?? 0) / 255));

			$down = new CameraPortalLink(
				$level->nextCameraPortalId()
				, CameraPortalType::VISUAL
				, CameraPortalLink::ALIGN_FLOOR
				, $topSector
				, $bottomSector
				, (float)$top['x']
				, (float)$top['y']
				, (float)$bottom['x']
				, (float)$bottom['y']
				, 0.0
				, null
				, null
				, null
				, CameraPortalLink::ALIGN_CEILING
			);

			$level->addCameraPortalLink($down);

			$level->addCameraPortalSurface(
				new CameraPortalSurface(CameraPortalSurface::FLOOR, $topSector, $down, $alpha)
			);

			$alpha = max(0.0, min(1.0, (int)($bottom['args'][0] ?? 0) / 255));

			$up = new CameraPortalLink(
				$level->nextCameraPortalId()
				, CameraPortalType::VISUAL
				, CameraPortalLink::ALIGN_CEILING
				, $bottomSector
				, $topSector
				, (float)$bottom['x']
				, (float)$bottom['y']
				, (float)$top['x']
				, (float)$top['y']
				, 0.0
				, null
				, null
				, null
				, CameraPortalLink::ALIGN_FLOOR
			);

			$level->addCameraPortalLink($up);
			$down->reciprocalId = $up->id;
			$up->reciprocalId = $down->id;

			$level->addCameraPortalSurface(
				new CameraPortalSurface(CameraPortalSurface::CEILING, $bottomSector, $up, $alpha)
			);
		}
	}

	/** @param list<CameraPortalLink> $links */
	private static function pairReciprocals(array $links): void
	{
		foreach($links as $link)
		foreach($links as $reverse)
		{
			$offsetX = $link->destinationX - $link->originX;
			$offsetY = $link->destinationY - $link->originY;
			$reverseOffsetX = $reverse->destinationX - $reverse->originX;
			$reverseOffsetY = $reverse->destinationY - $reverse->originY;

			if($reverse->id === $link->id
				|| $reverse->originSector !== $link->destinationSector
				|| $reverse->destinationSector !== $link->originSector
				|| abs($reverseOffsetX + $offsetX) > .001
				|| abs($reverseOffsetY + $offsetY) > .001
			) continue;

			$link->reciprocalId = $reverse->id;
			break;
		}
	}

	/** @return array{0:float,1:float} */
	private static function lineCenter(Level $level, array $line): array
	{
		$a = $level->map->vertices[$line['from']];
		$b = $level->map->vertices[$line['to']];

		return [($a['x'] + $b['x']) / 2,($a['y'] + $b['y']) / 2];
	}
}

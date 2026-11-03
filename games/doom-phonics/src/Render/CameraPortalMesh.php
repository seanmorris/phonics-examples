<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\CameraPortalLink;
use DoomPhonics\Game\CameraPortalSurface;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\PitchMode;

/** Portal-only geometry, separated from the material mesh so camera pixels can replace it. */
final class CameraPortalMesh
{
	public const FLOATS = 5;
	/** Near plane used while a line portal crosses the ordinary one-unit view plane. */
	public const LINE_NEAR_PLANE = .01;
	/** @var list<float> x, y, sector, plane selector, opposite sector. */
	public array $vertices = [];
	/** @var list<array{first:int,count:int,surface:CameraPortalSurface}> */
	public array $spans = [];

	public function vertexCount(): int { return intdiv(count($this->vertices), self::FLOATS); }

	public function bytes(): string
	{
		return $this->vertices ? pack('f*', ...$this->vertices) : '';
	}

	/** @param list<array{0:float,1:float}> $points */
	public function plane(array $points, int $sector, int $plane, CameraPortalSurface $surface): void
	{
		$first = $this->vertexCount();

		foreach($points as [$x,$y])
		array_push($this->vertices, $x, $y, (float)$sector, (float)$plane, (float)$sector);
		$this->spans[] = ['first' => $first,'count' => count($points),'surface' => $surface];
	}

	/** @param array{x:int,y:int} $a @param array{x:int,y:int} $b */
	public function line(array $a, array $b, int $sector, ?int $backSector, CameraPortalSurface $surface): void
	{
		$first = $this->vertexCount();
		$bottom = $backSector === null ? MapMesh::FLOOR : MapMesh::MAX_FLOOR;
		$top = $backSector === null ? MapMesh::CEILING : MapMesh::MIN_CEILING;
		$backSector ??= $sector;

		foreach([
			[$a['x'],$a['y'],$bottom],[$b['x'],$b['y'],$bottom],[$b['x'],$b['y'],$top]
			, [$a['x'],$a['y'],$bottom],[$b['x'],$b['y'],$top],[$a['x'],$a['y'],$top]
		] as [$x,$y,$plane])
		array_push($this->vertices, (float)$x, (float)$y, (float)$sector, (float)$plane, (float)$backSector);
		$this->spans[] = ['first' => $first,'count' => 6,'surface' => $surface];
	}

	/** @return list<CameraPortalLink> Potentially visible links inside the camera frustum, in stable link order. */
	public function visibleLinks(
		Level $level
		, float $x
		, float $y
		, float $z
		, float $angle
		, int $limit = 8
		, float $horizontalFov = 90
		, ?int $cameraSector = null
		, ?ViewProjection $projection = null
		, float $pitch = 0
		, PitchMode $pitchMode = PitchMode::Perspective
	): array {
		$candidates = [];
		$cameraSector ??= $level->map->sectorAt($x, $y);
		$visibleSectors = $level->map->connectedSectors($cameraSector);
		$eye = $z + 41;
		$forwardX = cos($angle);
		$forwardY = sin($angle);
		$rightX = $forwardY;
		$rightY = -$forwardX;
		$tanHalfFov = tan(deg2rad(max(1, min(179, $horizontalFov)) / 2));

		foreach($this->spans as $span)
		{
			$surface = $span['surface'];
			$link = $surface->link;

			if(!$link->active || isset($candidates[$link->id]) || !isset($visibleSectors[$link->originSector]))
			continue;

			if($surface->kind === CameraPortalSurface::LINE)
			{
				$line = $level->map->linedefs[$surface->index];
				$a = $level->map->vertices[$line['from']];
				$b = $level->map->vertices[$line['to']];

				// A portal is visible from sidedef zero, the line's right/front side.
				if(($b['x'] - $a['x']) * ($y - $a['y']) - ($b['y'] - $a['y']) * ($x - $a['x']) > 0) continue;
			}
			else
			{
				if($level->map->rejects($cameraSector, $surface->index)) continue;

				$height = $surface->kind === CameraPortalSurface::FLOOR
					? $level->sectors[$surface->index]->floorZ($x, $y)
					: $level->sectors[$surface->index]->ceilingZ($x, $y);

				if(($surface->kind === CameraPortalSurface::FLOOR && $eye < $height)
					|| ($surface->kind === CameraPortalSurface::CEILING && $eye > $height)
				) continue;
			}

			if($projection)
			{
				if(!$this->spanInView(
					$level
					, $span['first']
					, $span['count']
					, $x
					, $y
					, $eye
					, $pitch
					, $pitchMode
					, $forwardX
					, $forwardY
					, $rightX
					, $rightY
					, $projection
					, $surface->kind === CameraPortalSurface::LINE ? self::LINE_NEAR_PLANE : 1.0
				)) continue;
			}
			elseif(!$this->spanInHorizontalView(
				$span['first']
				, $span['count']
				, $x
				, $y
				, $forwardX
				, $forwardY
				, $rightX
				, $rightY
				, $tanHalfFov
			)) continue;

			$candidates[$link->id] = $link;
		}

		// Capture membership must not change merely because the camera crossed the
		// point where two portal centers are equally distant. Portal surfaces are
		// composited with depth testing, so their capture order need not be spatial.
		ksort($candidates, SORT_NUMERIC);

		return array_values(array_slice($candidates, 0, $limit, true));
	}

	/** Conservatively reject spans wholly behind the near plane or outside one side of the horizontal frustum. */
	private function spanInHorizontalView(
		int $first
		, int $count
		, float $cameraX
		, float $cameraY
		, float $forwardX
		, float $forwardY
		, float $rightX
		, float $rightY
		, float $tanHalfFov
	): bool {
		$behind = $left = $right = true;

		for($vertex = $first; $vertex < $first + $count; $vertex++)
		{
			$offset = $vertex * self::FLOATS;
			$dx = $this->vertices[$offset] - $cameraX;
			$dy = $this->vertices[$offset + 1] - $cameraY;
			$depth = $dx * $forwardX + $dy * $forwardY;
			$side = $dx * $rightX + $dy * $rightY;

			if($depth >= 1) $behind = false;

			if($depth * $tanHalfFov + $side >= 0) $left = false;

			if($depth * $tanHalfFov - $side >= 0) $right = false;
		}

		return !$behind && !$left && !$right;
	}

	/** Conservatively reject spans wholly outside any shader clip plane. */
	private function spanInView(
		Level $level
		, int $first
		, int $count
		, float $cameraX
		, float $cameraY
		, float $eyeZ
		, float $pitch
		, PitchMode $pitchMode
		, float $forwardX
		, float $forwardY
		, float $rightX
		, float $rightY
		, ViewProjection $projection
		, float $nearPlane
	): bool {
		for($triangle = $first; $triangle < $first + $count; $triangle += 3)
		{
			$polygon = [];

			for($vertex = $triangle; $vertex < min($triangle + 3, $first + $count); $vertex++)
			{
				$offset = $vertex * self::FLOATS;
				$x = $this->vertices[$offset];
				$y = $this->vertices[$offset + 1];
				$dx = $x - $cameraX;
				$dy = $y - $cameraY;
				$horizontalDepth = $dx * $forwardX + $dy * $forwardY;
				$side = $dx * $rightX + $dy * $rightY;
				$verticalOffset = $this->vertexHeight($level, $offset, $x, $y) - $eyeZ;
				$depth = $projection->viewDepth($horizontalDepth, $verticalOffset, $pitch, $pitchMode);
				$polygon[] = [
					$side * $projection->horizontalScale
					, $projection->viewHeight($horizontalDepth, $verticalOffset, $pitch, $pitchMode)
						* $projection->verticalScale
					, $depth
				];
			}

			for($plane = 0; $plane < 5; $plane++)
			{
				$polygon = $this->clipPolygon($polygon, $plane, $nearPlane);

				if(!$polygon) break;
			}

			if($polygon) return true;
		}

		return false;
	}

	/** @param list<array{0:float,1:float,2:float}> $polygon @return list<array{0:float,1:float,2:float}> */
	private function clipPolygon(array $polygon, int $plane, float $nearPlane): array
	{
		if(!$polygon) return [];

		$clipped = [];
		$previous = $polygon[count($polygon) - 1];
		$previousDistance = $this->clipDistance($previous, $plane, $nearPlane);

		foreach($polygon as $current)
		{
			$currentDistance = $this->clipDistance($current, $plane, $nearPlane);
			$previousInside = $previousDistance >= 0;
			$currentInside = $currentDistance >= 0;

			if($previousInside !== $currentInside)
			{
				$t = $previousDistance / ($previousDistance - $currentDistance);
				$clipped[] = [
					$previous[0] + ($current[0] - $previous[0]) * $t
					, $previous[1] + ($current[1] - $previous[1]) * $t
					, $previous[2] + ($current[2] - $previous[2]) * $t
				];
			}

			if($currentInside) $clipped[] = $current;

			$previous = $current;
			$previousDistance = $currentDistance;
		}

		return $clipped;
	}

	/** @param array{0:float,1:float,2:float} $vertex */
	private function clipDistance(array $vertex, int $plane, float $nearPlane): float
	{
		return match($plane)
		{
			0 => $vertex[2] - $nearPlane,
			1 => $vertex[0] + $vertex[2],
			2 => $vertex[2] - $vertex[0],
			3 => $vertex[1] + $vertex[2],
			default => $vertex[2] - $vertex[1]
		};
	}

	/** Match camera-portal.vert's moving floor/ceiling selector calculation. */
	private function vertexHeight(Level $level, int $offset, float $x, float $y): float
	{
		$sector = (int)round($this->vertices[$offset + 2]);
		$selector = (int)round($this->vertices[$offset + 3]);
		$plane = $selector < 2 ? $selector : intdiv($selector - 2, 2);
		$method = $plane ? 'ceilingZ' : 'floorZ';
		$front = $level->sectors[$sector]->$method($x, $y);

		if($selector < 2) return $front;

		$otherSector = (int)round($this->vertices[$offset + 4]);
		$back = $level->sectors[$otherSector]->$method($x, $y);

		return $selector % 2 === 0 ? min($front, $back) : max($front, $back);
	}
}

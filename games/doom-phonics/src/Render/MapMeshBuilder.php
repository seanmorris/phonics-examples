<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\CameraTextureBinding;
use DoomPhonics\Game\DomTextureBinding;
use DoomPhonics\Game\Level;
use DoomPhonics\Graphics\IndexedAtlas;

/** Bakes subsector planes and linedef wall spans into one draw-ready triangle stream. */
final class MapMeshBuilder
{
	private MapMesh $mesh;

	public function __construct(public Level $level, public IndexedAtlas $atlas) { $this->mesh = new MapMesh; }

	public function build(): MapMesh
	{
		$this->planes();
		$this->walls();
		$this->mesh->baseVertexCount = $this->mesh->vertexCount();
		(new VolumeMeshBuilder($this->level, $this->atlas, $this->mesh))->build();
		VolumePortalFaces::append($this->mesh, $this->level);
		$this->mesh->watch($this->level);

		return $this->mesh;
	}

	private function planes(): void
	{
		foreach($this->level->map->subsectorPolygons() as $index => $points)
		{
			$subsector = $this->level->map->subsectors[$index];
			$sector = null;

			for($i = 0; $i < $subsector['count']; $i++)
			{
				$seg = $this->level->map->segs[$subsector['first'] + $i] ?? null;

				if(!$seg) continue;

				if($sector === null && $seg['linedef'] !== 0xffff)
				{
					$line = $this->level->map->linedefs[$seg['linedef']];
					$side = $seg['direction'] ? $line['left'] : $line['right'];

					if($side >= 0) $sector = $this->level->map->sidedefs[$side]['sector'];
				}
			}

			if($sector === null || count($points) < 3) continue;

			$definition = $this->level->sectors[$sector];
			$floorTexture = $this->atlas->id('flat', $definition->floorTexture);
			$ceilingTexture = $definition->ceilingTexture === 'F_SKY1'
				? -1
				: $this->atlas->id('flat', $definition->ceilingTexture);

			for($i = 1; $i < count($points) - 1; $i++)
			{
				$floor = [$points[0],$points[$i],$points[$i + 1]];
				$floorPortal = $this->level->cameraPortalForPlane($sector, 0);

				if($floorPortal) $this->mesh->cameraPortals->plane($floor, $sector, 0, $floorPortal);
				else
				$this->triangle(
					$floor
					, $floorTexture
					, $sector
					, 0
					, $this->level->surfaceTextureBinding($definition->floorTexture)
				);

				$ceiling = [$points[0],$points[$i + 1],$points[$i]];
				$ceilingPortal = $this->level->cameraPortalForPlane($sector, 1);

				if($ceilingPortal) $this->mesh->cameraPortals->plane($ceiling, $sector, 1, $ceilingPortal);
				else
				$this->triangle(
					$ceiling
					, $ceilingTexture
					, $sector
					, 1
					, $this->level->surfaceTextureBinding($definition->ceilingTexture)
				);
			}
		}
	}

	private function walls(): void
	{
		foreach($this->level->map->linedefs as $line)
		{
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];
			$rightSide = $line['right'] >= 0 ? $this->level->map->sidedefs[$line['right']] : null;
			$leftSide = $line['left'] >= 0 ? $this->level->map->sidedefs[$line['left']] : null;
			$right = $rightSide ? $rightSide['sector'] : null;
			$left = $leftSide ? $leftSide['sector'] : null;
			$length = hypot($b['x'] - $a['x'], $b['y'] - $a['y']);
			$cameraPortal = $this->level->cameraPortalForLine($line['index']);
			$mirror = $this->level->mirrorForLine($line['index']);

			if($cameraPortal)
			{
				$sector = $right ?? $left;
				$backSector = $right !== null ? $left : null;

				if($sector !== null)
				$this->mesh->cameraPortals->line($a, $b, $sector, $backSector, $cameraPortal);

				continue;
			}

			if($right === null || $left === null)
			{
				$sector = $right ?? $left;
				$definition = $this->level->sectors[$sector];

				if($definition->hasSkyCeiling())
				{
					// The terminal wall owns the sky mask above it. Wind toward the playable
					// sector so geometry beyond the map is hidden only from the interior.
					$right !== null
						? $this->mesh->sky->boundary($a, $b, $sector, $line['index'])
						: $this->mesh->sky->boundary($b, $a, $sector, $line['index']);
				}

				if($mirror)
				{
					$this->mesh->mirrors->line($a, $b, $mirror->sector, $mirror);
					continue;
				}

				$this->quad(
					$a
					, $b
					, $length
					, $rightSide
					, $leftSide
					, 'middle'
					, [$sector,1]
					, [$sector,0]
					, $line['flags']
					, $line['index']
					, $line['right'] >= 0?$line['right']:null
					, $line['left'] >= 0?$line['left']:null
				);

				continue;
			}

			$r = $this->level->sectors[$right];
			$l = $this->level->sectors[$left];
			$rSky = $r->hasSkyCeiling();
			$lSky = $l->hasSkyCeiling();

			// Equal-height borders can expose walls when a lift or door moves. Keep
			// textured spans in the mesh and select their live extrema on the GPU.
			if(!($rSky && $lSky))
			$this->quad(
				$a
				, $b
				, $length
				, $rightSide
				, $leftSide
				, 'upper'
				, [$right,MapMesh::MAX_CEILING]
				, [$right,MapMesh::MIN_CEILING]
				, $line['flags']
				, $line['index']
				, $line['right']
				, $line['left']
			);

			$this->quad(
				$a
				, $b
				, $length
				, $rightSide
				, $leftSide
				, 'lower'
				, [$right,MapMesh::MAX_FLOOR]
				, [$right,MapMesh::MIN_FLOOR]
				, $line['flags']
				, $line['index']
				, $line['right']
				, $line['left']
			);

			if(($rightSide['middle'] ?? '-') !== '-' || ($leftSide['middle'] ?? '-') !== '-')
			{
				$this->quad(
					$a
					, $b
					, $length
					, $rightSide
					, $leftSide
					, 'middle'
					, [$right,MapMesh::MIN_CEILING]
					, [$right,MapMesh::MAX_FLOOR]
					, $line['flags']
					, $line['index']
					, $line['right']
					, $line['left']
				);
			}
		}
	}

	/**
	 * @param array{x:int,y:int} $a
	 * @param array{x:int,y:int} $b
	 * @param array<string,mixed>|null $right
	 * @param array<string,mixed>|null $left
	 * @param array{int,int} $top
	 * @param array{int,int} $bottom
	 */
	private function quad(
		array $a
		, array $b
		, float $length
		, ?array $right
		, ?array $left
		, string $slot
		, array $top
		, array $bottom
		, int $flags
		, int $lineId
		, ?int $rightSideId
		, ?int $leftSideId
	): void {
		$rightTexture = $right ? $this->atlas->id('wall', $this->level->sideTextures[$rightSideId][$slot]) : -1;
		$leftTexture = $left ? $this->atlas->id('wall', $this->level->sideTextures[$leftSideId][$slot]) : -1;
		$rightBinding = $right
			? $this->level->surfaceTextureBinding($this->level->sideTextures[$rightSideId][$slot])
			: null;

		$leftBinding = $left
			? $this->level->surfaceTextureBinding($this->level->sideTextures[$leftSideId][$slot])
			: null;

		if($rightTexture < 0 && $leftTexture < 0) return;

		$rightX = (float)($right['xOffset'] ?? 0);
		$leftX = (float)($left['xOffset'] ?? 0);
		$rightAnchor = $this->wallAnchor($right, $slot, $top, $bottom, $flags, $rightTexture);
		$leftAnchor = $this->wallAnchor($left, $slot, $top, $bottom, $flags, $leftTexture);
		$rightSector = $right ? (int)$right['sector'] : 0;
		$leftSector = $left ? (int)$left['sector'] : 0;
		$first = $this->mesh->vertexCount();
		$vertices = [
			[$a['x'],$a['y'],$rightX,$leftX + $length,$bottom]
			, [$b['x'],$b['y'],$rightX + $length,$leftX,$bottom]
			, [$b['x'],$b['y'],$rightX + $length,$leftX,$top]
			, [$a['x'],$a['y'],$rightX,$leftX + $length,$bottom]
			, [$b['x'],$b['y'],$rightX + $length,$leftX,$top]
			, [$a['x'],$a['y'],$rightX,$leftX + $length,$top]
		];

		foreach($vertices as [$x,$y,$rightU,$leftU,$height])
		$this->append(
			$x
			, $y
			, $rightU
			, $leftU
			, $rightTexture
			, $leftTexture
			, $height[0]
			, $height[1]
			, $rightAnchor
			, $leftAnchor
			, $rightSector
			, $leftSector
		);
		$this->mesh->wallSpan($first, 6, $lineId, $slot, $rightSideId, $leftSideId);

		if($rightBinding) $this->mesh->cameraTextures->surface($this->mesh->vertices, $first, 6, $rightBinding, 0);

		if($leftBinding) $this->mesh->cameraTextures->surface($this->mesh->vertices, $first, 6, $leftBinding, 1);
	}

	/**
	 * @param array<string,mixed>|null $side
	 * @param array{int,int} $top
	 * @param array{int,int} $bottom
	 * @return array{float,float,float}
	 */
	private function wallAnchor(?array $side, string $slot, array $top, array $bottom, int $flags, int $texture): array
	{
		if(!$side || $texture < 0) return [-1.0,0.0,0.0];

		$offset = (float)$side['yOffset'];
		$textureHeight = (float)($this->atlas->rects[$texture]['height'] ?? 128);

		if($slot === 'upper')
		return ($flags & 8)
			? [(float)$top[0],(float)$top[1],$offset]
			: [(float)$bottom[0],(float)$bottom[1],$textureHeight + $offset];

		if($slot === 'lower')
		return ($flags & 16) ? [(float)$side['sector'],1.0,$offset] : [(float)$top[0],(float)$top[1],$offset];

		return ($flags & 16)
			? [(float)$bottom[0],(float)$bottom[1],$textureHeight + $offset]
			: [(float)$top[0],(float)$top[1],$offset];
	}

	/** @param list<array{0:float,1:float}> $points */
	private function triangle(
		array $points
		, int $texture
		, int $sector
		, int $plane
		, CameraTextureBinding|DomTextureBinding|null $binding = null
	): void {
		if($texture < 0) return;

		$first = $this->mesh->vertexCount();

		foreach($points as [$x,$y])
		$this->append(
			$x
			, $y
			, $x
			, $x
			, $texture
			, $texture
			, $sector
			, $plane
			, [-1.0,0.0,-$y]
			, [-1.0,0.0,-$y]
			, $sector
			, $sector
		);
		$this->mesh->planeSpan($first, 3, $sector, $plane);

		if($binding) $this->mesh->cameraTextures->surface($this->mesh->vertices, $first, 3, $binding, 2);
	}

	/** @param array{float,float,float} $rightAnchor @param array{float,float,float} $leftAnchor */
	private function append(
		float $x
		, float $y
		, float $rightU
		, float $leftU
		, int $right
		, int $left
		, int $sector
		, int $plane
		, array $rightAnchor
		, array $leftAnchor
		, int $rightSector
		, int $leftSector
	): void {
		array_push(
			$this->mesh->vertices
			, $x
			, $y
			, $rightU
			, $leftU
			, (float)$right
			, (float)$left
			, (float)$sector
			, (float)$plane
			, $rightAnchor[0]
			, $rightAnchor[1]
			, $rightAnchor[2]
			, $leftAnchor[0]
			, $leftAnchor[1]
			, $leftAnchor[2]
			, (float)$rightSector
			, (float)$leftSector
		);
	}
}

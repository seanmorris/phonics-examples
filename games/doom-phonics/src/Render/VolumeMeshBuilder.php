<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\Level;
use DoomPhonics\Game\SectorVolume;
use DoomPhonics\Graphics\IndexedAtlas;

/**
 * Append 3D-floor surfaces to the map buffer with live model-plane selectors.
 *
 * Each face is retained as a draw span. Opaque faces write depth with the world;
 * translucent liquid faces are sorted and composited after actors and beams.
 */
final class VolumeMeshBuilder
{
	/** Compatible model faces awaiting one-time greedy meshing. */
	private array $planes = [];
	/** Bind the target mesh and the level's shared material atlas. */
	public function __construct(private Level $level, private IndexedAtlas $atlas, private MapMesh $mesh) {}

	/** Bake surfaces once; model-sector motion is evaluated by the map vertex shader. */
	public function build(): void
	{
		foreach($this->level->map->subsectorPolygons() as $index => $points)
		{
			$subsector = $this->level->map->subsectors[$index];
			$sector = null;
			for($i = 0; $i < $subsector['count']; $i++)
			{
				$seg = $this->level->map->segs[$subsector['first'] + $i];
				if($seg['linedef'] === 0xffff) continue;
				$line = $this->level->map->linedefs[$seg['linedef']];
				$side = $line[$seg['direction'] ? 'left' : 'right'];
				if($side >= 0) { $sector = $this->level->sectors[$this->level->map->sidedefs[$side]['sector']]; break; }
			}
			if(!$sector || count($points) < 3) continue;
			foreach($sector->volumes as $volume)
			{
				if(!$volume->alpha) continue;
				foreach([true,false] as $top)
				{
					$plane = $top ? $volume->topPlane() : $volume->bottomPlane();
					$name = $plane ? $volume->model->ceilingTexture : $volume->model->floorTexture;
					$texture = $this->atlas->id('flat', $name);
					$face = $top ? $points : array_reverse($points);
					$this->plane($face, $texture, $volume->model->index, $plane, $sector->index,
						$volume->alpha / 255, (bool)($volume->flags & 64), $volume,
						$volume->swimmable() || (bool)($volume->type & 4));
				}
			}
			// Improved texture control keeps real planes and adds model flats on both sides.
			$t = $sector->heightTransfer;
			if($t && ($t->flags & 4) && !($t->flags & 16))
			{
				$this->plane($points, $this->atlas->id('flat', $t->model->floorTexture), $t->model->index, 0, $sector->index);
				if(!($t->flags & 2)) $this->plane(array_reverse($points), $this->atlas->id('flat', $t->model->ceilingTexture), $t->model->index, 1, $sector->index);
			}
		}
		foreach($this->planes as $group)
		foreach(PlaneRectangles::merge($group['polygons']) as $points)
		$this->emitPlane($points, ...$group['arguments']);
		$this->planes = [];
		foreach($this->level->map->linedefs as $line)
		{
			$sides = $this->level->sides($line['index']);
			if($sides['front'] === null || $sides['back'] === null) continue;
			foreach([['front','back','right',false],['back','front','left',true]] as [$here,$there,$sideKey,$reverse])
			{
				$sector = $this->level->sectors[$sides[$here]];
				$neighbor = $this->level->sectors[$sides[$there]];
				foreach($sector->volumes as $volume)
				{
					if(!$volume->alpha) continue;
					foreach($neighbor->volumes as $other)
					if($other->model === $volume->model && $other->type === $volume->type) continue 2;
					$a = $this->level->map->vertices[$line[$reverse ? 'to' : 'from']];
					$b = $this->level->map->vertices[$line[$reverse ? 'from' : 'to']];
					$side = $this->level->sideTextures[$line[$sideKey]];
					$name = $volume->flags & 16 ? $side['upper'] : ($volume->flags & 32 ? $side['lower'] : $volume->texture);
					$id = $this->atlas->id('wall', $name);
					$length = hypot($b['x'] - $a['x'], $b['y'] - $a['y']);
					$first = $this->mesh->vertexCount();
					foreach([[0,0],[1,0],[1,1],[0,0],[1,1],[0,1]] as [$end,$top])
					{
						$p = $end ? $b : $a;
						// The slab is inside the right sector: the outward face is the back face.
						$this->vertex($p['x'], $p['y'], $side['xOffset'] + $end * $length, 0,
							$volume->swimmable() || ($volume->type & 4) ? $id : -1, $id,
							$volume->model->index, $top ? $volume->topPlane() : $volume->bottomPlane(),
							$sector->index, [$volume->model->index,$volume->topPlane(),$side['yOffset']]);
					}
					$this->span($first, $sector->index, $volume->model->index, $volume->topPlane(),
						$volume->alpha / 255, (bool)($volume->flags & 64), $volume);
				}
			}
		}
	}

	/** Append one convex flat, optionally visible from inside the volume. */
	private function plane(array $points, int $texture, int $model, int $plane, int $sector,
		float $opacity = 1, bool $additive = false, ?SectorVolume $volume = null, bool $inside = true): void
	{
		if($texture < 0) return;
		$arguments = [$texture,$model,$plane,$sector,$opacity,$additive,$volume,$inside];
		if(!$volume) { $this->emitPlane($points, ...$arguments); return; }
		// Both model and target identity must survive merging: independently moving
		// target floors/ceilings clip these faces, even when currently coplanar.
		$key = implode(':', [$texture,$model,$plane,$opacity,(int)$additive,(int)$inside,
			$volume->type,$volume->flags,$sector]);
		$this->planes[$key] ??= ['arguments' => $arguments,'polygons' => []];
		$this->planes[$key]['polygons'][] = $points;
	}

	/** Triangulate a merged face using world-space UVs and its original live plane. */
	private function emitPlane(array $points, int $texture, int $model, int $plane, int $sector,
		float $opacity, bool $additive, ?SectorVolume $volume, bool $inside): void
	{
		if($texture < 0) return;
		$first = $this->mesh->vertexCount();
		for($i = 1; $i < count($points) - 1; $i++)
		foreach([$points[0],$points[$i],$points[$i + 1]] as [$x,$y])
		$this->vertex($x, $y, $x, -$y, $texture, $inside ? $texture : -1, $model, $plane, $sector);
		$this->span($first, $sector, $model, $plane, $opacity, $additive, $volume);
	}

	/** Append one existing map-format vertex, separating its model height from its target sector. */
	private function vertex(float $x, float $y, float $u, float $v, int $front, int $back,
		int $model, int $plane, int $sector, ?array $anchor = null): void
	{
		$anchor ??= [-1,0,$v];
		array_push($this->mesh->vertices, $x,$y,$u,$u,(float)$front,(float)$back,(float)$model,(float)$plane,
			...array_map('floatval', [...$anchor,...$anchor,$sector,$sector]));
	}

	/** Store a face center for transparency ordering and its live lighting source. */
	private function span(int $first, int $sector, int $model, int $plane, float $opacity, bool $additive, ?SectorVolume $volume): void
	{
		$count = $this->mesh->vertexCount() - $first;
		$x = $y = 0;
		for($i = $first; $i < $first + $count; $i++)
		{
			$x += $this->mesh->vertices[$i * MapMesh::FLOATS];
			$y += $this->mesh->vertices[$i * MapMesh::FLOATS + 1];
		}
		$this->mesh->volumeSpans[] = ['first' => $first,'count' => $count,'sector' => $sector,
			'model' => $model,'plane' => $plane,'x' => $x / $count,'y' => $y / $count,
			'opacity' => $opacity,'additive' => $additive,'volume' => $volume];
	}
}

<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Map\MapData;
use DoomPhonics\Map\SpatialIndex;

/** Live map instance: immutable parsed geometry plus independently mutable sector state. */
final class Level
{
	/** @var list<SectorState> */
	public array $sectors = [];
	/** @var array<int,list<int>> */
	public array $tags = [];
	/** @var list<array{upper:string,lower:string,middle:string,xOffset:int,yOffset:int,sector:int}> */
	public array $sideTextures = [];
	/** @var array<int,float> Live texture-column offsets for automatic scrolling lines. */
	public array $wallScroll = [];
	public int $appearanceRevision = 0;
	public SpatialIndex $spatial;
	/** @var array<int,LinePortal|null> */
	private array $portals = [];
	/** @var list<CameraPortalLink> */
	private array $cameraPortalLinks = [];
	/** @var list<CameraPortalSurface> */
	private array $cameraPortalSurfaces = [];
	/** @var array<int,CameraPortalSurface> */
	private array $lineCameraPortals = [];
	/** @var array<int,array<int,CameraPortalSurface>> */
	private array $planeCameraPortals = [];
	/** @var array<int,CameraViewpoint> */
	private array $cameras = [];
	/** @var array<int,array<string,mixed>> */
	private array $thingsByTid = [];
	/** @var array<string,CameraTextureBinding> */
	private array $cameraTextures = [];
	/** @var array<string,DomTextureBinding> */
	private array $domTextures = [];
	/** @var array<int,list<array{binding:DomTextureBinding,amount:float}>> */
	private array $domTextureScrollScripts = [];
	/** @var list<MirrorView> */
	private array $mirrors = [];
	/** @var array<int,MirrorView> */
	private array $lineMirrors = [];
	/** @var array<int,list<int>> Hexen line IDs, which are distinct from array indexes. */
	private array $lineIds = [];
	/** @var array<int,int> Unimplemented Hexen specials and their line counts. */
	public array $unsupportedSpecials = [];

	public function __construct(public MapData $map)
	{
		foreach($map->sectors as $sector)
		{
			$this->sectors[] = new SectorState($sector['index'], $sector);

			if($sector['tag']) $this->tags[$sector['tag']][] = $sector['index'];
		}

		foreach($map->sidedefs as $side)
		$this->sideTextures[] = [
			'upper' => strtoupper((string)$side['upper'])
			, 'lower' => strtoupper((string)$side['lower'])
			, 'middle' => strtoupper((string)$side['middle'])
			, 'xOffset' => (int)$side['xOffset']
			, 'yOffset' => (int)$side['yOffset']
			, 'sector' => (int)$side['sector']
		];
		$this->spatial = new SpatialIndex($map);

		foreach($map->things as $thing)
		if(($tid = (int)($thing['id'] ?? 0)) !== 0) $this->thingsByTid[$tid] = $thing;

		if($map->format === 'HEXEN')
		{
			HexenMapSetup::apply($this);

			foreach($map->linedefs as $line)
			if(!in_array($line['action'], [0,12,47,57,69,70,80,100,101,107,121,156,160,181,182,206,209,210,211], true))
			$this->unsupportedSpecials[$line['action']] = ($this->unsupportedSpecials[$line['action']] ?? 0) + 1;
		}
		SectorVolumeSetup::apply($this);
	}

	public function nextCameraPortalId(): int { return count($this->cameraPortalLinks); }

	/**
	 * Find liquid touching a body, including its continuation through a plane portal.
	 *
	 * The player changes sector when the eye crosses the seam, while the lower body
	 * can still be submerged on the other side. A flooded source column may also
	 * extend above its portal ceiling. Translate that live water interval into the
	 * destination frame so thrust and buoyancy remain continuous during the exit.
	 */
	public function waterSurface(float $x,float $y,float $z,float $height): ?float
	{
		return $this->portalWaterSurface($x,$y,$z,$height,[]);
	}

	/** Follow liquid intervals through passable planes with bounded reciprocal traversal. */
	private function portalWaterSurface(float $x,float $y,float $z,float $height,array $visited): ?float
	{
		$sector=$this->sectorAt($x,$y);
		if(isset($visited[$sector->index]) || count($visited)>=8) return null;
		$visited[$sector->index]=true;
		$surface=$sector->waterSurface($x,$y,$z,$height);
		if($surface!==null) return $surface;
		foreach([0,1] as $plane) {
			$link=$this->cameraPortalForPlane($sector->index,$plane)?->link;
			if(!$link?->passable() || !$link->flipsVertical()) continue;
			$origin=$plane ? $sector->ceilingZ($x,$y) : $sector->floorZ($x,$y);
			// An upper liquid cannot touch the body before its head reaches the seam.
			// A lower column can extend above its ceiling when the room is flooded.
			if($plane && $z+$height<=$origin) continue;
			if(!$plane && $sector->supportZ($x,$y,$z)>$origin+.001) continue;
			if($plane && $sector->headroomZ($x,$y,$z)<$origin-.001) continue;
			[$rx,$ry]=$link->point($x,$y);
			$remote=$this->sectorAt($rx,$ry);
			$destination=$plane ? $remote->floorZ($rx,$ry) : $remote->ceilingZ($rx,$ry);
			$offset=$destination-$origin;
			$water=$this->portalWaterSurface($rx,$ry,$z+$offset,$height,$visited);
			if($water!==null) $surface=max($surface ?? -INF,$water-$offset);
		}
		return $surface;
	}

	public function addCameraPortalLink(CameraPortalLink $link): void
	{
		$this->cameraPortalLinks[$link->id] = $link;
	}

	public function addCameraPortalSurface(CameraPortalSurface $surface): void
	{
		if($surface->kind === CameraPortalSurface::LINE) $this->lineCameraPortals[$surface->index] = $surface;
		else $this->planeCameraPortals[$surface->index][$surface->plane()] = $surface;

		$this->cameraPortalSurfaces[] = $surface;
	}

	/** @return list<CameraPortalLink> */
	public function cameraPortalLinks(): array { return array_values($this->cameraPortalLinks); }

	/** @return list<CameraPortalSurface> */
	public function cameraPortalSurfaces(): array { return $this->cameraPortalSurfaces; }

	public function cameraPortalForLine(int $line): ?CameraPortalSurface
	{
		return $this->lineCameraPortals[$line] ?? null;
	}

	public function cameraPortalForPlane(int $sector, int $plane): ?CameraPortalSurface
	{
		return $this->planeCameraPortals[$sector][$plane] ?? null;
	}

	public function addCamera(CameraViewpoint $camera): void
	{
		if($camera->tid) $this->cameras[$camera->tid] = $camera;
	}

	public function camera(int $tid): ?CameraViewpoint { return $this->cameras[$tid] ?? null; }

	/** @return list<CameraViewpoint> */
	public function cameras(): array { return array_values($this->cameras); }

	/** @return array<string,mixed>|null */
	public function thingWithTid(int $tid): ?array { return $this->thingsByTid[$tid] ?? null; }

	public function bindCameraTexture(CameraTextureBinding $binding): void
	{
		$this->cameraTextures[$binding->texture->name] = $binding;
	}

	public function cameraTextureBinding(string $name): ?CameraTextureBinding
	{
		return $this->cameraTextures[strtoupper($name)] ?? null;
	}

	/** @return array<string,CameraTextureBinding> */
	public function cameraTextureBindings(): array { return $this->cameraTextures; }

	public function bindDomTexture(DomTextureBinding $binding): void
	{
		$this->domTextures[$binding->texture->name] = $binding;
	}

	public function domTextureBinding(string $name): ?DomTextureBinding
	{
		return $this->domTextures[strtoupper($name)] ?? null;
	}

	/** @return array<string,DomTextureBinding> */
	public function domTextureBindings(): array { return $this->domTextures; }

	public function bindDomTextureScroll(int $script, DomTextureBinding $binding, float $amount): void
	{
		$this->domTextureScrollScripts[$script][] = ['binding' => $binding, 'amount' => $amount];
	}

	/** Run the safe ScrollDomTexture subset attached to an ACS_Execute switch. */
	public function runDomTextureScript(int $script): bool
	{
		$controls = $this->domTextureScrollScripts[$script] ?? [];
		$activated = false;

		foreach($controls as $control)
		{
			try
			{
				$control['binding']->bridge->scrollBy($control['amount']);
				$activated = true;
			}
			catch(\Throwable)
			{
				// Browser-owned surfaces may be unavailable during context recovery.
			}
		}

		return $activated;
	}

	public function surfaceTextureBinding(string $name): CameraTextureBinding|DomTextureBinding|null
	{
		$name = strtoupper($name);

		return $this->cameraTextures[$name] ?? $this->domTextures[$name] ?? null;
	}

	public function addMirror(MirrorView $mirror): void
	{
		$this->mirrors[] = $mirror;
		$this->lineMirrors[$mirror->line] = $mirror;
	}

	/** @return list<MirrorView> */
	public function mirrors(): array { return $this->mirrors; }

	public function mirrorForLine(int $line): ?MirrorView { return $this->lineMirrors[$line] ?? null; }

	/** Whether the complete square actor footprint lies over one passable plane portal. */
	public function boundsWithinCameraPortalPlane(
		float $x
		, float $y
		, float $radius
		, int $sector
		, int $plane
	): bool {
		$portal = $this->cameraPortalForPlane($sector, $plane)?->link;

		if(!$portal?->passable()) return false;

		if($radius <= 0) return true;

		// Map point queries use integer coordinates. Sampling just inside the square
		// treats a footprint touching the portal rim as contained instead of letting
		// BSP tie-breaking choose the neighboring sector.
		$edge = max(0.0, $radius - .51);

		foreach([[-$edge,-$edge],[-$edge,$edge],[$edge,-$edge],[$edge,$edge]] as [$dx,$dy])
		{
			$sample = $this->sectorAt($x + $dx, $y + $dy);

			if($this->cameraPortalForPlane($sample->index, $plane)?->link !== $portal) return false;
		}

		return true;
	}

	/** Whether an actor's square footprint intersects an active, passable line camera portal. */
	public function boundsCrossCameraPortal(float $x, float $y, float $radius): bool
	{
		if($radius <= 0) return false;

		$minX = $x - $radius;
		$minY = $y - $radius;
		$maxX = $x + $radius;
		$maxY = $y + $radius;

		foreach($this->spatial->near($minX, $minY, $maxX, $maxY) as $lineId)
		{
			$surface = $this->lineCameraPortals[$lineId] ?? null;

			if(!$surface?->link->passable()) continue;

			$line = $this->map->linedefs[$lineId];
			$a = $this->map->vertices[$line['from']];
			$b = $this->map->vertices[$line['to']];

			if(self::segmentIntersectsBounds(
				(float)$a['x']
				, (float)$a['y']
				, (float)$b['x']
				, (float)$b['y']
				, $minX
				, $minY
				, $maxX
				, $maxY
			)) return true;
		}

		return false;
	}

	/** Liang-Barsky segment clipping against an axis-aligned actor footprint. */
	private static function segmentIntersectsBounds(
		float $fromX
		, float $fromY
		, float $toX
		, float $toY
		, float $minX
		, float $minY
		, float $maxX
		, float $maxY
	): bool {
		$minimum = 0.0;
		$maximum = 1.0;

		foreach([[$fromX,$toX - $fromX,$minX,$maxX],[$fromY,$toY - $fromY,$minY,$maxY]] as [$origin,$delta,$low,$high])
		{
			if(abs($delta) < 1e-9)
			{
				if($origin < $low || $origin > $high) return false;

				continue;
			}

			$enter = ($low - $origin) / $delta;
			$leave = ($high - $origin) / $delta;

			if($enter > $leave) [$enter,$leave] = [$leave,$enter];

			$minimum = max($minimum, $enter);
			$maximum = min($maximum, $leave);

			if($minimum > $maximum) return false;
		}

		return true;
	}

	public function addLineId(int $id, int $line): void { $this->lineIds[$id][] = $line; }

	public function firstLineWithId(int $id, ?int $except = null): ?int
	{
		foreach($this->lineIds[$id] ?? [] as $line) if($line !== $except) return $line;

		return null;
	}

	/** Implement Hexen Line_SetPortalTarget(thisid, destid). */
	public function retargetCameraPortals(int $thisId, int $destinationId, ?int $activatingLine = null): bool
	{
		$origins = $thisId === 0 && $activatingLine !== null ? [$activatingLine] : ($this->lineIds[$thisId] ?? []);
		$changed = false;

		foreach($origins as $origin)
		{
			$link = $this->lineCameraPortals[$origin]->link ?? null;

			if(!$link) continue;

			$destination = $destinationId ? $this->firstLineWithId($destinationId, $origin) : null;
			$changed = $link->retarget($this, $destination) || $changed;
		}

		if($changed) $this->refreshLineCameraPortalReciprocals();

		return $changed;
	}

	/** Recompute inverse pairs after Line_SetPortalTarget changes either endpoint. */
	private function refreshLineCameraPortalReciprocals(): void
	{
		foreach($this->lineCameraPortals as $surface) $surface->link->reciprocalId = null;

		foreach($this->lineCameraPortals as $surface)
		{
			$link = $surface->link;

			if(!$link->active || $link->originLine === null || $link->destinationLine === null) continue;

			$reverse = $this->lineCameraPortals[$link->destinationLine]->link ?? null;

			if($reverse?->active && $reverse->destinationLine === $link->originLine)
			$link->reciprocalId = $reverse->id;
		}
	}

	public function update(float $dt): void
	{
		foreach($this->sectors as $sector) $sector->update($dt);

		foreach($this->cameras as $camera) $camera->update($dt, $this);
	}

	/** Snapshot mutable planes once before the fixed-step geometry systems advance them. */
	public function beginStep(): void { foreach($this->sectors as $sector) $sector->beginStep(); }

	public function setSideTexture(int $side, string $slot, string $texture): void
	{
		if(!isset($this->sideTextures[$side]) || !in_array($slot, ['upper','lower','middle'], true)) return;

		$texture = strtoupper($texture);

		if($this->sideTextures[$side][$slot] === $texture) return;

		$this->sideTextures[$side][$slot] = $texture;
		$this->appearanceRevision++;
	}

	public function setWallScroll(int $line, float $offset): void
	{
		$this->wallScroll[$line] = $offset;
	}

	/** @return array{front:?int,back:?int,frontSide:?int,backSide:?int} */
	public function sides(int $lineId, ?int $fromSector = null): array
	{
		$line = $this->map->linedefs[$lineId];
		$rightSector = $line['right'] >= 0 ? $this->map->sidedefs[$line['right']]['sector'] : null;
		$leftSector = $line['left'] >= 0 ? $this->map->sidedefs[$line['left']]['sector'] : null;

		if($fromSector !== null && $leftSector === $fromSector)
		return [
			'front' => $leftSector
			, 'back' => $rightSector
			, 'frontSide' => $line['left']
			, 'backSide' => $line['right']
		];

		return [
			'front' => $rightSector
			, 'back' => $leftSector
			, 'frontSide' => $line['right'] >= 0 ? $line['right'] : null
			, 'backSide' => $line['left'] >= 0 ? $line['left'] : null
		];
	}

	/** Return the live geometric opening for a two-sided linedef. */
	public function portal(int $lineId): ?LinePortal
	{
		if(array_key_exists($lineId, $this->portals)) return $this->portals[$lineId];

		$line = $this->map->linedefs[$lineId];

		if($line['right'] < 0 || $line['left'] < 0)
		return $this->portals[$lineId] = null;

		$right = (int)$this->map->sidedefs[$line['right']]['sector'];
		$left = (int)$this->map->sidedefs[$line['left']]['sector'];

		return $this->portals[$lineId] = new LinePortal($right, $left, $this->sectors[$right], $this->sectors[$left]);
	}

	/** Return the sole playable sector beside a one-sided terminal linedef. */
	public function terminalSector(int $lineId): ?SectorState
	{
		$line = $this->map->linedefs[$lineId];
		$right = $line['right'] >= 0 ? $this->map->sidedefs[$line['right']]['sector'] : null;
		$left = $line['left'] >= 0 ? $this->map->sidedefs[$line['left']]['sector'] : null;

		if(($right === null) === ($left === null)) return null;

		return $this->sectors[$right ?? $left];
	}

	/** Return the sector containing the full-precision simulation or camera position. */
	public function sectorAt(float $x, float $y): SectorState
	{
		return $this->sectors[$this->map->sectorAt($x, $y)] ?? $this->sectors[0];
	}

	/** @return list<SectorState> */
	public function neighbors(int $sector): array
	{
		return array_map(fn(int $id): SectorState => $this->sectors[$id], $this->map->neighbors[$sector] ?? []);
	}

	public function lowestNeighborCeiling(int $sector): float
	{
		$values = array_map(static fn(SectorState $s): float => $s->ceiling, $this->neighbors($sector));

		return $values ? min($values) : $this->sectors[$sector]->ceiling;
	}

	public function lowestNeighborFloor(int $sector): float
	{
		$values = array_map(static fn(SectorState $s): float => $s->floor, $this->neighbors($sector));

		return $values ? min($values) : $this->sectors[$sector]->floor;
	}

	public function highestNeighborFloor(int $sector): float
	{
		$values = array_map(static fn(SectorState $s): float => $s->floor, $this->neighbors($sector));

		return $values ? max($values) : $this->sectors[$sector]->floor;
	}

	public function nextHigherFloor(int $sector): float
	{
		$current = $this->sectors[$sector]->floor;

		$values = array_values(
			array_filter(
				array_map(static fn(SectorState $s): float => $s->floor, $this->neighbors($sector))
				, static fn(float $floor): bool => $floor > $current
			)
		);

		return $values ? min($values) : $current;
	}

	/** @return array{float,float} Approximate sound origin inside a sector. */
	public function sectorCenter(int $sector): array
	{
		$x = $y = $count = 0;

		foreach($this->map->linedefs as $line)
		{
			$right = $line['right'] >= 0 ? $this->map->sidedefs[$line['right']]['sector'] : null;
			$left = $line['left'] >= 0 ? $this->map->sidedefs[$line['left']]['sector'] : null;

			if($right !== $sector && $left !== $sector) continue;

			foreach([$line['from'],$line['to']] as $vertex)
			{
				$x += $this->map->vertices[$vertex]['x'];
				$y += $this->map->vertices[$vertex]['y'];
				$count++;
			}
		}

		return $count ? [$x / $count, $y / $count] : [0.0,0.0];
	}
}

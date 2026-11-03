<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

/** Interleaved static map vertices; sector/plane selectors keep moving geometry on the GPU. */
final class MapMesh
{
	public const FLOATS = 16;
	/** Height selectors shared with map.vert; extrema use both sidedef sectors. */
	public const FLOOR = 0, CEILING = 1;
	public const MIN_FLOOR = 2, MAX_FLOOR = 3, MIN_CEILING = 4, MAX_CEILING = 5;
	public SkyOcclusionMesh $sky;
	public CameraPortalMesh $cameraPortals;
	public CameraTextureMesh $cameraTextures;
	public MirrorMesh $mirrors;
	/** @var list<float> x,y,right u,left u,textures,height selector,right V anchor,left V anchor,side sectors. */
	public array $vertices = [];
	/** Vertices drawn before separately blended model-volume faces. */
	public int $baseVertexCount = 0;
	/** @var list<array<string,mixed>> Model-volume surfaces with opacity and lighting metadata. */
	public array $volumeSpans = [];
	/** @var list<array{first:int,count:int,sector:int,plane:int}> */
	public array $planeSpans = [];
	/**
	 * @var
	 * list<array{first:int,count:int,line:int,slot:string,right:?int,left:?int,rightU:list<float>,leftU:list<float>}>
	 */
	public array $wallSpans = [];
	/** @var array<int,array<int,list<int>>> */
	private array $planeGroups = [];
	/** @var array<int,list<int>> */
	private array $wallGroups = [];
	/** @var array<int,array{string,string}> */
	private array $planeNames = [];
	private int $wallRevision = -1;
	/** @var array<int,float> */
	private array $scrollOffsets = [];

	/** Cache the material state represented by the freshly built vertex buffer. */
	public function watch(\DoomPhonics\Game\Level $level): void
	{
		foreach($level->sectors as $sector)
		$this->planeNames[$sector->index] = [$sector->floorTexture,$sector->ceilingTexture];
		$this->wallRevision = $level->appearanceRevision;
	}

	public function __construct()
	{
		$this->sky = new SkyOcclusionMesh;
		$this->cameraPortals = new CameraPortalMesh;
		$this->cameraTextures = new CameraTextureMesh;
		$this->mirrors = new MirrorMesh;
	}

	public function vertexCount(): int { return intdiv(count($this->vertices), self::FLOATS); }

	public function bytes(): string
	{
		$bytes = '';

		foreach(array_chunk($this->vertices, 4096) as $chunk) $bytes .= pack('f*', ...$chunk);

		return $bytes;
	}

	public function planeSpan(int $first, int $count, int $sector, int $plane): void
	{
		$this->planeGroups[$sector][$plane][] = count($this->planeSpans);
		$this->planeSpans[] = compact('first', 'count', 'sector', 'plane');
	}

	public function wallSpan(int $first, int $count, int $line, string $slot, ?int $right, ?int $left): void
	{
		$rightU = $leftU = [];

		for($vertex = 0; $vertex < $count; $vertex++)
		{
			$offset = ($first + $vertex) * self::FLOATS;
			$rightU[] = $this->vertices[$offset + 2];
			$leftU[] = $this->vertices[$offset + 3];
		}

		$this->wallSpans[] = compact('first', 'count', 'line', 'slot', 'right', 'left', 'rightU', 'leftU');
		$this->wallGroups[$line][] = count($this->wallSpans) - 1;
	}

	/**
	 * Apply mutable flat/switch/scroll data without rebuilding geometry.
	 * @return list<array{0:int,1:string}> Byte offset and compact VBO replacement.
	 */
	public function sync(\DoomPhonics\Game\Level $level, \DoomPhonics\Graphics\IndexedAtlas $atlas): array
	{
		$updates = [];
		$planes = [];

		foreach($level->sectors as $sector)
		{
			$previous = $this->planeNames[$sector->index] ?? null;

			if($previous !== null && $previous[0] === $sector->floorTexture
				&& $previous[1] === $sector->ceilingTexture
			) continue;

			if(($previous[0] ?? null) !== $sector->floorTexture)
			array_push($planes, ...($this->planeGroups[$sector->index][0] ?? []));

			if(($previous[1] ?? null) !== $sector->ceilingTexture)
			array_push($planes, ...($this->planeGroups[$sector->index][1] ?? []));

			$this->planeNames[$sector->index] = [$sector->floorTexture,$sector->ceilingTexture];
		}

		foreach($planes as $index)
		{
			$span = $this->planeSpans[$index];
			$sector = $level->sectors[$span['sector']];
			$name = $span['plane']?$sector->ceilingTexture:$sector->floorTexture;
			$id = $name === 'F_SKY1'?-1:$atlas->id('flat', $name);
			$changed = false;

			for($vertex = 0; $vertex < $span['count']; $vertex++)
			{
				$offset = ($span['first'] + $vertex) * self::FLOATS;

				if((int)$this->vertices[$offset + 4] !== $id || (int)$this->vertices[$offset + 5] !== $id)
				{
					$this->vertices[$offset + 4] = $this->vertices[$offset + 5] = (float)$id;
					$changed = true;
				}
			}

			if($changed)$updates[] = $this->spanBytes($span['first'], $span['count']);
		}

		$walls = [];

		if($this->wallRevision !== $level->appearanceRevision)
		{
			$walls = array_keys($this->wallSpans);
			$this->wallRevision = $level->appearanceRevision;
		}
		else
		foreach($level->wallScroll + $this->scrollOffsets as $line => $unused)
		{
			$offset = (float)($level->wallScroll[$line] ?? 0);

			if(($this->scrollOffsets[$line] ?? 0.0) !== $offset)
			array_push($walls, ...($this->wallGroups[$line] ?? []));
		}

		$this->scrollOffsets = $level->wallScroll;

		foreach($walls as $index)
		{
			$span = $this->wallSpans[$index];
			$right = $span['right'] === null?-1:$atlas->id('wall', $level->sideTextures[$span['right']][$span['slot']]);
			$left = $span['left'] === null?-1:$atlas->id('wall', $level->sideTextures[$span['left']][$span['slot']]);
			$scroll = (float)($level->wallScroll[$span['line']] ?? 0);
			$changed = false;

			for($vertex = 0; $vertex < $span['count']; $vertex++)
			{
				$offset = ($span['first'] + $vertex) * self::FLOATS;
				$rightU = $span['rightU'][$vertex] + $scroll;

				if($this->vertices[$offset + 2] !== $rightU
					|| (int)$this->vertices[$offset + 4] !== $right
					|| (int)$this->vertices[$offset + 5] !== $left
				){
					$this->vertices[$offset + 2] = $rightU;
					$this->vertices[$offset + 4] = (float)$right;
					$this->vertices[$offset + 5] = (float)$left;
					$changed = true;
				}
			}

			if($changed)$updates[] = $this->spanBytes($span['first'], $span['count']);
		}

		return $updates;
	}

	/** @return array{0:int,1:string} */
	private function spanBytes(int $first, int $count): array
	{
		$floats = array_slice($this->vertices, $first * self::FLOATS, $count * self::FLOATS);

		return [$first * self::FLOATS * 4,pack('f*', ...$floats)];
	}

	/** Update Boom plane materials only when the view changes region or a material changes. */
	public function syncTransfers(\DoomPhonics\Game\Level $level, \DoomPhonics\Graphics\IndexedAtlas $atlas, int $region): array
	{
		$updates = [];
		foreach($level->sectors as $sector)
		{
			$t = $sector->heightTransfer;
			if(!$t) continue;
			$view = $t->view($sector, $region);
			foreach(['floor','ceiling'] as $plane => $name)
			{
				$texture = $t->flags & 4 ? $sector->{$name . 'Texture'} : $view[$name][2];
				$id = $texture === 'F_SKY1' ? -1 : $atlas->id('flat', $texture);
				foreach($this->planeGroups[$sector->index][$plane] ?? [] as $index)
				{
					$span = $this->planeSpans[$index];
					$offset = $span['first'] * self::FLOATS;
					if((int)$this->vertices[$offset + 4] === $id) continue;
					for($v = 0; $v < $span['count']; $v++)
					$this->vertices[$offset + $v * self::FLOATS + 4] = $this->vertices[$offset + $v * self::FLOATS + 5] = (float)$id;
					$updates[] = $this->spanBytes($span['first'], $span['count']);
				}
			}
		}
		return $updates;
	}
}

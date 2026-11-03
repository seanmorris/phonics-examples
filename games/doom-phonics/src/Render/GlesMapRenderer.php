<?php
declare(strict_types=1);
namespace DoomPhonics\Render;

use DoomPhonics\Game\Level;
use DoomPhonics\Game\PitchMode;
use DoomPhonics\Graphics\IndexedAtlas;
use DoomPhonics\Graphics\IndexedImage;
use DoomPhonics\Graphics\Palette;
use DoomPhonics\Graphics\TextureCatalog;
use DoomPhonics\Graphics\ViewColorState;
use DoomPhonics\Definition\ActorCatalog;
use DoomPhonics\Definition\WeaponAmmo;
use DoomPhonics\Component\ActorState;
use DoomPhonics\Component\BeamEffect;
use DoomPhonics\Component\FloorClip;
use DoomPhonics\Component\Projectile;
use DoomPhonics\Component\RemoteProjectile;
use DoomPhonics\Component\StatusFace;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\HitPoints;
use DoomPhonics\Component\PlayerStart;
use DoomPhonics\Component\NetworkPlayer;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Component\WeaponOverlay;
use Phonics\Ecs\Query;
use Phonics\Ecs\Registry;
use Phonics\Render\Atlas;
use Phonics\Render\Program;

/** Direct GLES3 renderer with static surface and depth-only sky batches. */
final class GlesMapRenderer
{
	private const CAMERA_PORTALS_PER_VIEW = 8;
	private const CAMERA_PORTAL_RECURSION_DEPTH = 6;
	private const CAMERA_PORTAL_VIEW_BUDGET = 16;
	private const NEAR_PLANE = 1.0;
	private Program $program;
	private Program $spriteProgram;
	private Program $skyProgram;
	private Program $skyOcclusionProgram;
	private Program $overlayProgram;
	private Program $beamProgram;
	private Program $cameraPortalProgram;
	private Program $cameraTextureProgram;
	private Program $mirrorProgram;
	private IndexedAtlas $atlas;
	private MapMesh $mesh;
	/** Current view used to order translucent model surfaces after actors. */
	private array $volumeView = [0.0,0.0,0.0,1.0];
	private ViewProjection $projection;
	private RenderViewport $viewport;
	private PitchMode $pitchMode;
	private IndexedImage $reticleImage;
	/** @var array<int,int> Ordinary-geometry component ID keyed by sector. */
	private array $sectorComponents = [];
	/** @var list<int> */
	private array $vao = [], $buffers = [];
	/** @var list<int> */
	private array $textures = [];
	private string $sectorBytes = '';
	private Query $actorQuery;
	private Query $beamQuery;
	private int $spriteCapacity = 0;
	private int $overlayCapacity = 0;
	private int $beamCapacity = 0;
	/** @var array<int,CameraPortalTarget> */
	private array $cameraPortalTargets = [];
	/** @var array<int,CameraPortalTarget> Targets rendered for the current display frame. */
	private array $cameraPortalViews = [];
	/** @var array<string,CameraPortalTarget> */
	private array $cameraTextureTargets = [];
	/** @var array<string,CameraPortalTarget> */
	private array $cameraTextureViews = [];
	/** @var array<int,CameraPortalTarget> */
	private array $mirrorTargets = [];
	/** @var array<int,CameraPortalTarget> */
	private array $mirrorViews = [];
	/** @var array<int,true> Front-facing portal links in the current camera space. */
	private array $cameraPortalVisible = [];
	/** @var array{camera:list<float|int>,visible:list<int>,targets:array<int,int>} */
	private array $cameraPortalFrame = ['camera' => [],'visible' => [],'targets' => []];
	private int $cameraPortalTargetCursor = 0;
	private int $cameraPortalViewBudget = 0;
	public int $cameraPortalViewsRendered = 0;
	public int $cameraTextureViewsRendered = 0;
	public int $domTextureViewsRendered = 0;
	public int $mirrorViewsRendered = 0;
	/** @var array<int,array{pose:Transform,state:ActorState,x:float,y:float,sector:int}> */
	private array $actorCache = [];
	/** @var array{considered:int,visible:int,rejected:int,frustum:int,vertices:int} */
	public array $spriteStats = ['considered' => 0,'visible' => 0,'rejected' => 0,'frustum' => 0,'vertices' => 0];
	public float $mapCpuMs = 0;
	/** @var array<string,float> CPU time per map-render stage, including graphics-driver waits. */
	public array $mapStagesMs = [];
	public float $actorCpuMs = 0;
	public float $beamCpuMs = 0;
	public float $overlayCpuMs = 0;
	public int $beamVertices = 0;
	public int $width;
	public int $height;
	public float $renderScale = 1.0;
	public readonly float $classicRenderScale;
	private bool $closed = false;

	public function __construct(
		public Level $level
		, public TextureCatalog $catalog
		, public Palette $palette
		, int $width = 960
		, int $height = 540
		, public float $fov = 90
		, public ?ActorCatalog $actors = null
		, float $renderScale = .5
	){
		if(!is_finite($renderScale) || $renderScale <= 0 || $renderScale > 1)
		throw new \InvalidArgumentException('Render scale must be within (0, 1]');

		$this->width = $width;
		$this->height = $height;
		$this->classicRenderScale = $renderScale;
		$this->viewport = new RenderViewport($width, $height);
		$this->projection = new ViewProjection($width, $height, $fov);
		$this->pitchMode = PitchMode::Perspective;
		$this->actorQuery = Query::all(Transform::class, ActorState::class);
		$this->beamQuery = Query::all(BeamEffect::class);
		$this->reticleImage = new IndexedImage(1, 1, chr($palette->closestIndex(255, 128, 0)), "\xff");

		foreach($level->sectors as $sector)
		$this->sectorComponents[$sector->index] = $level->map->sectorComponent($sector->index);
		$this->atlas = IndexedAtlas::forLevel($level, $catalog, 2048, $actors, $palette);
		$this->mesh = (new MapMeshBuilder($level, $this->atlas))->build();

		$this->program = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/map.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/map.frag')
		);

		$this->spriteProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/sprite.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/sprite.frag')
		);

		$this->skyProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/sky.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/sky.frag')
		);

		$this->skyOcclusionProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/sky-occlusion.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/sky-occlusion.frag')
		);

		$this->overlayProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/overlay.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/overlay.frag')
		);

		$this->beamProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/beam.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/beam.frag')
		);

		$this->cameraPortalProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/camera-portal.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/camera-portal.frag')
		);

		$this->cameraTextureProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/map.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/camera-texture.frag')
		);

		$this->mirrorProgram = new Program(
			file_get_contents(dirname(__DIR__, 2) . '/shaders/camera-portal.vert')
			, file_get_contents(dirname(__DIR__, 2) . '/shaders/mirror.frag')
		);

		$this->create();
	}

	private function create(): void
	{
		glGenVertexArrays(8, $this->vao);
		glGenBuffers(10, $this->buffers);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);
		$bytes = $this->mesh->bytes();
		glBufferData(GL_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STATIC_DRAW);
		$stride = MapMesh::FLOATS * 4;

		foreach([[0,2,0],[1,2,8],[2,2,16],[3,2,24],[4,3,32],[5,3,44],[6,2,56]] as [$location,$size,$offset])
		{
			glVertexAttribPointer($location, $size, GL_FLOAT, false, $stride, $offset);
			glEnableVertexAttribArray($location);
		}

		glBindVertexArray(0);
		glBindVertexArray($this->vao[6]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[6]);
		$cameraTextureBytes = $this->mesh->cameraTextures->bytes();

		glBufferData(
			GL_ARRAY_BUFFER
			, max($stride, strlen($cameraTextureBytes))
			, $cameraTextureBytes ?: str_repeat("\0", $stride)
			, GL_STATIC_DRAW
		);

		foreach([[0,2,0],[1,2,8],[2,2,16],[3,2,24],[4,3,32],[5,3,44],[6,2,56]] as [$location,$size,$offset])
		{
			glVertexAttribPointer($location, $size, GL_FLOAT, false, $stride, $offset);
			glEnableVertexAttribArray($location);
		}

		glBindVertexArray(0);
		glBindVertexArray($this->vao[7]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[7]);
		$mirrorBytes = $this->mesh->mirrors->bytes();

		glBufferData(
			GL_ARRAY_BUFFER
			, max(20, strlen($mirrorBytes))
			, $mirrorBytes ?: str_repeat("\0", 20)
			, GL_STATIC_DRAW
		);

		glVertexAttribPointer(0, 2, GL_FLOAT, false, MirrorMesh::FLOATS * 4, 0);
		glEnableVertexAttribArray(0);
		glVertexAttribPointer(1, 2, GL_FLOAT, false, MirrorMesh::FLOATS * 4, 8);
		glEnableVertexAttribArray(1);
		glVertexAttribPointer(2, 1, GL_FLOAT, false, MirrorMesh::FLOATS * 4, 16);
		glEnableVertexAttribArray(2);
		glBindVertexArray(0);
		glBindVertexArray($this->vao[5]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[5]);
		$portalBytes = $this->mesh->cameraPortals->bytes();

		glBufferData(
			GL_ARRAY_BUFFER
			, max(20, strlen($portalBytes))
			, $portalBytes ?: str_repeat("\0", 20)
			, GL_STATIC_DRAW
		);

		glVertexAttribPointer(0, 2, GL_FLOAT, false, CameraPortalMesh::FLOATS * 4, 0);
		glEnableVertexAttribArray(0);
		glVertexAttribPointer(1, 2, GL_FLOAT, false, CameraPortalMesh::FLOATS * 4, 8);
		glEnableVertexAttribArray(1);
		glVertexAttribPointer(2, 1, GL_FLOAT, false, CameraPortalMesh::FLOATS * 4, 16);
		glEnableVertexAttribArray(2);
		glBindVertexArray(0);
		glBindVertexArray($this->vao[2]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[2]);

		foreach([[0,2,0],[1,2,8],[2,1,16],[3,1,20],[4,1,24]] as [$location,$size,$offset])
		{
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 28, $offset);
			glEnableVertexAttribArray($location);
		}

		glBindVertexArray(0);
		glBindVertexArray($this->vao[1]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[1]);

		foreach([[0,3,0],[1,2,12],[2,1,20],[3,1,24],[4,1,28]] as [$location,$size,$offset])
		{
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 32, $offset);
			glEnableVertexAttribArray($location);
		}

		glBindVertexArray(0);
		glBindVertexArray($this->vao[3]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[3]);
		$skyBytes = $this->mesh->sky->bytes();
		glBufferData(GL_ARRAY_BUFFER, max(12, strlen($skyBytes)), $skyBytes ?: str_repeat("\0", 12), GL_STATIC_DRAW);
		glVertexAttribPointer(0, 2, GL_FLOAT, false, SkyOcclusionMesh::FLOATS * 4, 0);
		glEnableVertexAttribArray(0);
		glVertexAttribPointer(1, 1, GL_FLOAT, false, SkyOcclusionMesh::FLOATS * 4, 8);
		glEnableVertexAttribArray(1);
		glVertexAttribPointer(2, 1, GL_FLOAT, false, SkyOcclusionMesh::FLOATS * 4, 12);
		glEnableVertexAttribArray(2);
		glBindVertexArray(0);

		glBindVertexArray($this->vao[4]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[4]);

		foreach([[0,3,0],[1,3,12],[2,1,24],[3,1,28],[4,1,32]] as [$location,$size,$offset])
		{
			glVertexAttribPointer($location, $size, GL_FLOAT, false, 36, $offset);
			glEnableVertexAttribArray($location);
		}

		glBindVertexArray(0);

		$this->textures = [
			Atlas::texture(GL_TEXTURE_2D)
			, Atlas::texture(GL_TEXTURE_2D)
			, Atlas::texture(GL_TEXTURE_2D)
			, Atlas::texture(GL_TEXTURE_2D)
		];

		glBindTexture(GL_TEXTURE_2D, $this->textures[0]);

		glTexImage2D(
			GL_TEXTURE_2D
			, 0
			, GL_RG8
			, $this->atlas->width
			, $this->atlas->height
			, 0
			, GL_RG
			, GL_UNSIGNED_BYTE
			, $this->atlas->pixels
		);

		glBindTexture(GL_TEXTURE_2D, $this->textures[1]);

		glTexImage2D(
			GL_TEXTURE_2D
			, 0
			, GL_RGBA32F
			, max(1, count($this->atlas->rects))
			, 1
			, 0
			, GL_RGBA
			, GL_FLOAT
			, $this->atlas->metadata()
		);

		glBindTexture(GL_TEXTURE_2D, $this->textures[2]);

		glTexImage2D(
			GL_TEXTURE_2D
			, 0
			, GL_RGBA8
			, 256
			, $this->palette->textureRowCount()
			, 0
			, GL_RGBA
			, GL_UNSIGNED_BYTE
			, $this->palette->textureBytes()
		);

		glBindTexture(GL_TEXTURE_2D, $this->textures[3]);
		$this->sectorBytes = $this->sectorData();
		glTexImage2D(
			GL_TEXTURE_2D
			, 0
			, GL_RGBA32F
			, max(1, count($this->level->sectors))
			, 3
			, 0
			, GL_RGBA
			, GL_FLOAT
			, $this->sectorBytes
		);

		$this->configurePrograms();
	}

	private function configurePrograms(): void
	{
		$projectionX = $this->projection->horizontalScale;
		$projectionY = $this->projection->verticalScale;
		$this->program->use();
		glUniform1i($this->program->location('indexedAtlas'), 0);
		glUniform1i($this->program->location('atlasRects'), 1);
		glUniform1i($this->program->location('paletteMap'), 2);
		glUniform1i($this->program->location('sectorHeights'), 3);
		glUniform1i($this->program->location('lightRows'), $this->palette->lightMapCount());
		glUniform1i($this->program->location('paletteRows'), $this->palette->mapCount());
		glUniform1i($this->program->location('palettePage'), 0);
		glUniform1i($this->program->location('fixedLightMap'), -1);
		glUniform2f($this->program->location('projection'), $projectionX, $projectionY);
		$this->spriteProgram->use();
		glUniform1i($this->spriteProgram->location('indexedAtlas'), 0);
		glUniform1i($this->spriteProgram->location('atlasRects'), 1);
		glUniform1i($this->spriteProgram->location('paletteMap'), 2);
		glUniform1i($this->spriteProgram->location('lightRows'), $this->palette->lightMapCount());
		glUniform1i($this->spriteProgram->location('paletteRows'), $this->palette->mapCount());
		glUniform1i($this->spriteProgram->location('palettePage'), 0);
		glUniform1i($this->spriteProgram->location('fixedLightMap'), -1);

		glUniform2f($this->spriteProgram->location('projection'), $projectionX, $projectionY);

		$this->skyProgram->use();
		glUniform1i($this->skyProgram->location('indexedAtlas'), 0);
		glUniform1i($this->skyProgram->location('atlasRects'), 1);
		glUniform1i($this->skyProgram->location('paletteMap'), 2);
		glUniform1i($this->skyProgram->location('paletteRows'), $this->palette->mapCount());
		glUniform1i($this->skyProgram->location('palettePage'), 0);
		glUniform1i($this->skyProgram->location('fixedLightMap'), -1);
		glUniform2f($this->skyProgram->location('projection'), $projectionX, $projectionY);
		$this->skyOcclusionProgram->use();
		glUniform1i($this->skyOcclusionProgram->location('sectorHeights'), 3);

		glUniform2f($this->skyOcclusionProgram->location('projection'), $projectionX, $projectionY);

		$this->overlayProgram->use();
		glUniform1i($this->overlayProgram->location('indexedAtlas'), 0);
		glUniform1i($this->overlayProgram->location('atlasRects'), 1);
		glUniform1i($this->overlayProgram->location('paletteMap'), 2);
		glUniform1i($this->overlayProgram->location('paletteRows'), $this->palette->mapCount());
		glUniform1i($this->overlayProgram->location('palettePage'), 0);
		glUniform1i($this->overlayProgram->location('fixedLightMap'), -1);
		glUniform2f($this->overlayProgram->location('viewport'), $this->width, $this->height);

		$this->beamProgram->use();
		glUniform2f($this->beamProgram->location('projection'), $projectionX, $projectionY);

		glUniform2f(
			$this->beamProgram->location('viewport')
			, max(1, (int)round($this->width * $this->renderScale))
			, max(1, (int)round($this->height * $this->renderScale))
		);

		$this->cameraPortalProgram->use();
		glUniform1i($this->cameraPortalProgram->location('sectorHeights'), 3);
		glUniform1i($this->cameraPortalProgram->location('portalView'), 4);
		glUniform2f($this->cameraPortalProgram->location('projection'), $projectionX, $projectionY);

		glUniform2f(
			$this->cameraPortalProgram->location('viewport')
			, $this->viewport->sceneWidth
			, $this->viewport->sceneHeight
		);

		$this->cameraTextureProgram->use();
		glUniform1i($this->cameraTextureProgram->location('sectorHeights'), 3);
		glUniform1i($this->cameraTextureProgram->location('cameraView'), 4);
		glUniform2f($this->cameraTextureProgram->location('projection'), $projectionX, $projectionY);
		$this->mirrorProgram->use();
		glUniform1i($this->mirrorProgram->location('sectorHeights'), 3);
		glUniform1i($this->mirrorProgram->location('portalView'), 4);
		glUniform2f($this->mirrorProgram->location('projection'), $projectionX, $projectionY);

		glUniform2f(
			$this->mirrorProgram->location('viewport')
			, $this->viewport->sceneWidth
			, $this->viewport->sceneHeight
		);

		glUseProgram(0);
	}

	/** Synchronize output, scene, projection, and screen-space uniforms after a framebuffer resize. */
	public function resize(RenderViewport $viewport): bool
	{
		if($this->viewport->equals($viewport)) return false;

		$this->viewport = $viewport;
		$this->width = $viewport->outputWidth;
		$this->height = $viewport->outputHeight;
		$this->renderScale = $viewport->sceneScale;
		$this->projection = new ViewProjection($viewport->sceneWidth, $viewport->sceneHeight, $this->fov);
		$this->configureViewport();

		return true;
	}

	public function viewport(): RenderViewport { return $this->viewport; }

	public function setPitchMode(PitchMode $mode): void { $this->pitchMode = $mode; }

	/** Update only uniforms derived from framebuffer dimensions. */
	private function configureViewport(): void
	{
		$projectionX = $this->projection->horizontalScale;
		$projectionY = $this->projection->verticalScale;

		foreach([
			$this->program
			, $this->spriteProgram
			, $this->skyProgram
			, $this->skyOcclusionProgram
			, $this->beamProgram
			, $this->cameraPortalProgram
			, $this->cameraTextureProgram
			, $this->mirrorProgram
		] as $program)
		{
			$program->use();
			glUniform2f($program->location('projection'), $projectionX, $projectionY);
		}

		$this->overlayProgram->use();
		glUniform2f($this->overlayProgram->location('viewport'), $this->width, $this->height);
		$this->beamProgram->use();

		glUniform2f(
			$this->beamProgram->location('viewport')
			, $this->viewport->sceneWidth
			, $this->viewport->sceneHeight
		);

		$this->mirrorProgram->use();

		glUniform2f(
			$this->mirrorProgram->location('viewport')
			, $this->viewport->sceneWidth
			, $this->viewport->sceneHeight
		);

		$this->cameraPortalProgram->use();

		glUniform2f(
			$this->cameraPortalProgram->location('viewport')
			, $this->viewport->sceneWidth
			, $this->viewport->sceneHeight
		);

		glUseProgram(0);
	}

	/** Apply one typed palette/colormap selection to every indexed drawing program. */
	public function setViewColor(ViewColorState $state): void
	{
		if($this->closed) return;

		$palettePage = min($this->palette->paletteCount() - 1, $state->palettePage);
		$fixedMap = $state->fixedColormap !== null && $this->palette->hasMap($state->fixedColormap)
			? $state->fixedColormap
			: -1;

		foreach([$this->program,$this->spriteProgram,$this->skyProgram,$this->overlayProgram] as $program)
		{
			$program->use();
			glUniform1i($program->location('palettePage'), $palettePage);
			glUniform1i($program->location('fixedLightMap'), $fixedMap);
		}

		glUseProgram(0);
	}

	private function bindTextures(): void
	{
		foreach($this->textures as $unit => $texture)
		{
			glActiveTexture(GL_TEXTURE0 + $unit);
			glBindTexture(GL_TEXTURE_2D, $texture);
		}

		glActiveTexture(GL_TEXTURE0);
	}

	/** Draw interpolated DECORATE frames as depth-tested camera-facing billboards. */
	public function renderActors(
		Registry $registry
		, float $cameraX
		, float $cameraY
		, float $cameraZ
		, float $angle
		, float $pitch = 0
		, ?int $viewEntity = null
		, int $extraLight = 0
		, float $alpha = 1
		, ?int $cameraSector = null
		, ?float $nearPlane = null
	): void {
		$started = hrtime(true);

		if($this->closed || !$this->actors) return;

		$nearPlane ??= $this->visiblePortalNearPlane($cameraX, $cameraY);

		// WAD map coordinates are right-handed with +Y north; screen-right is clockwise.
		$rightX = sin($angle);
		$rightY = -cos($angle);
		$forwardX = cos($angle);
		$forwardY = sin($angle);
		$cameraSector ??= $this->level->map->sectorAt($cameraX, $cameraY);
		$connectedSectors = $this->level->map->connectedSectors($cameraSector);
		$halfWidth = tan(deg2rad($this->fov / 2));
		$opaque = [];
		$translucent = [];
		$stats = ['considered' => 0,'visible' => 0,'rejected' => 0,'frustum' => 0,'vertices' => 0];
		$entities = $registry->query($this->actorQuery);
		$seen = [];

		foreach($entities as $entity)
		{
			unset($cached);
			$seen[$entity] = true;

			// Never let the camera's owning billboard cross the first-person near plane.
			if($entity === $viewEntity) continue;

			$stats['considered']++;

			if(isset($this->actorCache[$entity]))
			{
				$cached = &$this->actorCache[$entity];
				$pose = $cached['pose'];
				$state = $cached['state'];
			}
			else
			{
				$pose = $registry->get($entity, Transform::class);
				$state = $registry->get($entity, ActorState::class);
				$this->actorCache[$entity] = [
					'pose' => $pose
					, 'state' => $state
					, 'x' => $pose->x
					, 'y' => $pose->y
					, 'sector' => -1
				];

				$cached = &$this->actorCache[$entity];
			}

			if($cached['sector'] < 0 || $cached['x'] !== $pose->x || $cached['y'] !== $pose->y)
			{
				$cached['x'] = $pose->x;
				$cached['y'] = $pose->y;
				$cached['sector'] = $this->level->map->sectorAt($pose->x, $pose->y);
			}

			$actorSector = $cached['sector'];

			if(!isset($connectedSectors[$actorSector])
				|| $this->level->map->rejects($cameraSector, $actorSector)
			){
				$stats['rejected']++;
				continue;
			}

			$frame = $state->current();

			if(!$frame || strlen($frame->sprite) !== 4 || str_contains('#-', $frame->sprite[0])) continue;

			$renderX = $pose->xAt($alpha);
			$renderY = $pose->yAt($alpha);
			$renderZ = $pose->zAt($alpha);
			$relativeX = $renderX - $cameraX;
			$relativeY = $renderY - $cameraY;
			$distance = $relativeX * $forwardX + $relativeY * $forwardY;
			$sideDistance = $relativeX * $rightX + $relativeY * $rightY;
			$radius = 48.0;

			if($distance <= max(.05, $nearPlane * .5)
				|| $distance > 8192
				|| abs($sideDistance) > $distance * $halfWidth + $radius
			){
				$stats['frustum']++;
				continue;
			}

			$relative = fmod(
				atan2($cameraY - $renderY, $cameraX - $renderX) - $pose->angle + 2 * M_PI + M_PI / 8
				, 2 * M_PI
			);

			$rotation = 1 + ((int)floor($relative / (2 * M_PI) * 8) % 8);

			$sprite = $this->catalog->sprite($frame->sprite, $frame->frame, $rotation) ?? $this->catalog->sprite(
				$frame->sprite
				, $frame->frame
				, 0
			);

			if(!$sprite || ($texture = $this->atlas->id('sprite', $sprite['name'])) < 0) continue;

			$image = $sprite['image'];
			$left = -$image->leftOffset;
			$right = $left + $image->width;
			$floor = $this->level->sectors[$actorSector]->floorZ($renderX, $renderY, $alpha);
			// A grounded actor's transform is its physical floor contact. Doom patch
			// origins often extend 2–5 units below it and relied on software floor
			// clipping. Clamp only that submerged part; preserve deliberate positive
			// offsets (keys and spheres), ceiling actors, and airborne projectiles.
			$supported = !$registry->has($entity, Projectile::class)
				&& !$registry->has($entity, RemoteProjectile::class)
				&& !$state->definition->hasFlag('SPAWNCEILING')
				&& $renderZ <= $floor + .01;

			$vertical = $image->verticalBounds($renderZ, $supported ? $floor : null);
			$clip = $registry->has($entity, FloorClip::class)
				? $registry->get($entity, FloorClip::class)->amount
				: 0.0;

			$bottom = $vertical['bottom'] - $clip;
			$top = $vertical['top'] - $clip;
			$light = $frame->bright ? 255 : $this->level->sectors[$actorSector]->light;
			$opacity = max(0.0, min(1.0, (float)$state->definition->value('Alpha', 1)));

			if($state->definition->hasFlag('SHADOW')) $opacity = min($opacity, .32);

			$u0 = $sprite['flipped'] ? $image->width - 1 : 0;
			$u1 = $sprite['flipped'] ? 0 : $image->width - 1;
			$lx = $renderX + $rightX * $left;
			$ly = $renderY + $rightY * $left;
			$rx = $renderX + $rightX * $right;
			$ry = $renderY + $rightY * $right;
			$record = [
				$distance
				, [
					[$lx,$ly,$bottom,$u0,$image->height - 1]
					, [$rx,$ry,$bottom,$u1,$image->height - 1]
					, [$rx,$ry,$top,$u1,0]
					, [$lx,$ly,$bottom,$u0,$image->height - 1]
					, [$rx,$ry,$top,$u1,0]
					, [$lx,$ly,$top,$u0,0]
				]
				, $texture,$light,$opacity
			];

			if($opacity < 1) $translucent[] = $record;
			else $opaque[] = $record;

			$stats['visible']++;
		}

		if(count($seen) !== count($this->actorCache))
		$this->actorCache = array_intersect_key($this->actorCache, $seen);

		// Masked sprites use the depth buffer and need no CPU sort. Only translucent actors do.
		usort($translucent, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
		$sprites = [...$opaque, ...$translucent];
		$values = [];

		foreach($sprites as [, $vertices, $texture, $light, $opacity])
		foreach($vertices as [$x,$y,$z,$u,$v])
		array_push($values, $x, $y, $z, $u, $v, (float)$texture, (float)$light, $opacity);
		$stats['vertices'] = intdiv(count($values), 8);
		$this->spriteStats = $stats;

		if(!$values)
		{
			$this->actorCpuMs = (hrtime(true) - $started) / 1e6;

			return;
		}

		$bytes = pack('f*', ...$values);
		$program = $this->spriteProgram;
		$program->use();
		glUniform3f($program->location('camera'), $cameraX, $cameraY, $cameraZ + 41);
		glUniform2f($program->location('forward'), cos($angle), sin($angle));
		glUniform1f($program->location('cameraPitch'), $pitch);
		glUniform1f($program->location('nearPlane'), $nearPlane);
		glUniform1i($program->location('pitchMode'), $this->pitchMode->shaderValue());
		glUniform1i($program->location('extraLight'), max(0, $extraLight));
		glEnable(GL_DEPTH_TEST);
		glEnable(GL_BLEND);
		glDepthMask(true);
		glBindVertexArray($this->vao[1]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[1]);
		$length = strlen($bytes);

		if($length > $this->spriteCapacity)
		{
			$this->spriteCapacity = 1;

			while($this->spriteCapacity < $length) $this->spriteCapacity <<= 1;

			glBufferData(
				GL_ARRAY_BUFFER
				, $this->spriteCapacity
				, str_repeat("\0", $this->spriteCapacity)
				, GL_STREAM_DRAW
			);
		}

		glBufferSubData(GL_ARRAY_BUFFER, 0, $length, $bytes);
		$opaqueVertices = count($opaque) * 6;

		if($opaqueVertices) glDrawArrays(GL_TRIANGLES, 0, $opaqueVertices);

		$translucentVertices = count($translucent) * 6;

		if($translucentVertices)
		{
			glDepthMask(false);
			glDrawArrays(GL_TRIANGLES, $opaqueVertices, $translucentVertices);
			glDepthMask(true);
		}

		glBindVertexArray(0);
		$this->actorCpuMs = (hrtime(true) - $started) / 1e6;
	}

	/** Draw transient world-space beams with stable pixel width and map depth testing. */
	public function renderBeams(
		Registry $registry
		, float $cameraX
		, float $cameraY
		, float $cameraZ
		, float $angle
		, float $pitch = 0
		, float $alpha = 1
		, ?float $nearPlane = null
	): void {
		$started = hrtime(true);
		$this->beamVertices = 0;

		if($this->closed) return;

		$forwardX = cos($angle);
		$forwardY = sin($angle);
		$eyeZ = $cameraZ + 41;
		$nearPlane ??= $this->visiblePortalNearPlane($cameraX, $cameraY);
		$near = $nearPlane + .05;
		$values = [];

		foreach($registry->query($this->beamQuery) as $entity)
		{
			$beam = $registry->get($entity, BeamEffect::class);
			$remaining = $beam->remaining($alpha);

			if($remaining <= 0) continue;

			$from = $beam->from;
			$to = $beam->to;

			$fromDepth = $this->projection->viewDepth(
				($from->x - $cameraX) * $forwardX + ($from->y - $cameraY) * $forwardY
				, $from->z - $eyeZ
				, $pitch
				, $this->pitchMode
			);

			$toDepth = $this->projection->viewDepth(
				($to->x - $cameraX) * $forwardX + ($to->y - $cameraY) * $forwardY
				, $to->z - $eyeZ
				, $pitch
				, $this->pitchMode
			);

			if($fromDepth < $near && $toDepth < $near) continue;

			if($fromDepth < $near)
			{
				$distance = $toDepth - $fromDepth;

				if($distance <= 1e-9) continue;

				$from = $from->interpolate($to, ($near - $fromDepth) / $distance);
			}
			elseif($toDepth < $near)
			{
				$distance = $fromDepth - $toDepth;

				if($distance <= 1e-9) continue;

				$to = $to->interpolate($from, ($near - $toDepth) / $distance);
			}

			$halfWidth = $beam->width * ($this->viewport->sceneHeight / 540) * $remaining / 2;

			foreach([[0,-1],[1,-1],[1,1],[0,-1],[1,1],[0,1]] as [$along,$side])
			array_push(
				$values
				, $from->x
				, $from->y
				, $from->z
				, $to->x
				, $to->y
				, $to->z
				, (float)$along
				, (float)$side
				, $halfWidth
			);
		}

		$this->beamVertices = intdiv(count($values), 9);

		if(!$values)
		{
			$this->beamCpuMs = (hrtime(true) - $started) / 1e6;

			return;
		}

		$bytes = pack('f*', ...$values);
		$length = strlen($bytes);
		$program = $this->beamProgram;
		$program->use();
		glUniform3f($program->location('camera'), $cameraX, $cameraY, $cameraZ + 41);
		glUniform2f($program->location('forward'), $forwardX, $forwardY);
		glUniform1f($program->location('cameraPitch'), $pitch);
		glUniform1f($program->location('nearPlane'), $nearPlane);
		glUniform1i($program->location('pitchMode'), $this->pitchMode->shaderValue());
		glEnable(GL_DEPTH_TEST);
		glEnable(GL_BLEND);
		glDisable(GL_CULL_FACE);
		glDepthMask(false);
		glBindVertexArray($this->vao[4]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[4]);

		if($length > $this->beamCapacity)
		{
			$this->beamCapacity = 1;

			while($this->beamCapacity < $length) $this->beamCapacity <<= 1;

			glBufferData(GL_ARRAY_BUFFER, $this->beamCapacity, str_repeat("\0", $this->beamCapacity), GL_STREAM_DRAW);
		}

		glBufferSubData(GL_ARRAY_BUFFER, 0, $length, $bytes);
		glDrawArrays(GL_TRIANGLES, 0, $this->beamVertices);
		glBindVertexArray(0);
		glDepthMask(true);
		$this->beamCpuMs = (hrtime(true) - $started) / 1e6;
	}

	/** Draw one world view, optionally retaining the real observer's color through a portal. */
	public function render(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch = 0
		, int $extraLight = 0
		, float $alpha = 1
		, ?int $cameraSector = null
		, ?float $nearPlane = null
		, ?array $viewBlend = null
	): void {
		$started = hrtime(true);

		if($this->closed || !$this->mesh->vertexCount()) return;

		$nearPlane ??= $this->visiblePortalNearPlane($x, $y);

		$cameraSector ??= $this->level->map->sectorAt($x, $y);
		$viewSector = $this->level->sectors[$cameraSector];
		$region = $viewSector->heightTransfer?->region($x, $y, $z + 41, $alpha) ?? 0;
		$blend = $viewBlend ?? $this->viewBlend($x, $y, $z + 41, $cameraSector, $alpha);
		foreach([$this->program,$this->spriteProgram,$this->skyProgram,$this->beamProgram] as $program)
		{
			$program->use();
			glUniform4f($program->location('viewBlend'), ...$blend);
		}
		$this->volumeView = [$x,$y,$z + 41,$alpha];
		$meshUpdates = [...$this->mesh->sync($this->level, $this->atlas),
			...$this->mesh->syncTransfers($this->level, $this->atlas, $region)];
		$materials = hrtime(true);

		if($meshUpdates)
		{
			glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[0]);

			foreach($meshUpdates as [$offset,$bytes]) glBufferSubData(GL_ARRAY_BUFFER, $offset, strlen($bytes), $bytes);
		}

		$meshUpload = hrtime(true);
		$sectorBytes = $this->sectorData($alpha, $region);
		$sectors = hrtime(true);

		if($sectorBytes !== $this->sectorBytes)
		{
			glActiveTexture(GL_TEXTURE3);
			glBindTexture(GL_TEXTURE_2D, $this->textures[3]);
			glTexSubImage2D(GL_TEXTURE_2D, 0, 0, 0, count($this->level->sectors), 3, GL_RGBA, GL_FLOAT, $sectorBytes);
			$this->sectorBytes = $sectorBytes;
		}

		$sectorUpload = hrtime(true);
		$cameraSector ??= $this->level->map->sectorAt($x, $y);
		$cameraComponent = $this->sectorComponents[$cameraSector] ?? $cameraSector;
		$this->bindTextures();
		$this->renderSky($angle, $pitch);
		$this->renderSkyOcclusion($x, $y, $z, $angle, $pitch, $cameraComponent, $nearPlane);
		$sky = hrtime(true);
		$this->program->use();
		glUniform3f($this->program->location('camera'), $x, $y, $z + 41);
		glUniform2f($this->program->location('forward'), cos($angle), sin($angle));
		glUniform1f($this->program->location('cameraPitch'), $pitch);
		glUniform1f($this->program->location('nearPlane'), $nearPlane);
		glUniform1i($this->program->location('pitchMode'), $this->pitchMode->shaderValue());
		glUniform1i($this->program->location('extraLight'), max(0, $extraLight));
		glUniform1i($this->program->location('cameraComponent'), $cameraComponent);
		glEnable(GL_DEPTH_TEST);
		glDepthMask(true);
		glDisable(GL_BLEND);
		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[0]);
		glUniform1f($this->program->location('surfaceAlpha'), 1);
		glUniform1i($this->program->location('surfaceLight'), -1);
		glUniform1i($this->program->location('clipSectorBounds'), 0);
		glUniform1i($this->program->location('volumeProjected'), 0);
		glDrawArrays(GL_TRIANGLES, 0, $this->mesh->baseVertexCount);
		$this->drawVolumes(false);
		glBindVertexArray(0);
		$this->renderCameraTextureSurfaces($x, $y, $z, $angle, $pitch, $nearPlane);
		$this->renderCameraPortalSurfaces($x, $y, $z, $angle, $pitch, $nearPlane);
		$this->renderMirrorSurfaces($x, $y, $z, $angle, $pitch, $nearPlane);
		$finished = hrtime(true);
		$this->mapCpuMs = ($finished - $started) / 1e6;
		$this->mapStagesMs = [
			'materials' => ($materials - $started) / 1e6
			, 'meshUpload' => ($meshUpload - $materials) / 1e6
			, 'sectors' => ($sectors - $meshUpload) / 1e6
			, 'sectorUpload' => ($sectorUpload - $sectors) / 1e6
			, 'sky' => ($sky - $sectorUpload) / 1e6
			, 'draw' => ($finished - $sky) / 1e6
		];
	}

	/** Resolve color at the observer; transformed portal cameras may lie outside their visible room. */
	private function viewBlend(float $x, float $y, float $eye, int $sector, float $alpha): array
	{
		$transfer = $this->level->sectors[$sector]->heightTransfer;
		if($transfer) return $transfer->blend($transfer->region($x, $y, $eye, $alpha));
		return $this->level->waterSurface($x, $y, $eye, .01) !== null
			? [0.0,.31,.65,.35] : [0.0,0.0,0.0,0.0];
	}

	/** Render visible portal cameras, including bounded views through another portal. */
	public function renderCameraPortals(
		Registry $registry
		, float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch = 0
		, ?int $viewEntity = null
		, int $extraLight = 0
		, float $alpha = 1
		, ?int $cameraSector = null
	): void {
		$this->cameraPortalViews = [];
		$this->cameraPortalVisible = [];
		$this->cameraPortalViewsRendered = 0;
		$this->cameraTextureViewsRendered = 0;
		$this->domTextureViewsRendered = 0;
		$this->mirrorViewsRendered = 0;
		$this->cameraPortalTargetCursor = 0;
		$this->cameraPortalViewBudget = self::CAMERA_PORTAL_VIEW_BUDGET;
		$this->cameraTextureViews = [];
		$this->mirrorViews = [];

		if($this->closed) return;

		$width = $this->viewport->sceneWidth;
		$height = $this->viewport->sceneHeight;
		$blend = $this->viewBlend($x, $y, $z + PlayerState::VIEW_HEIGHT,
			$cameraSector ?? $this->level->map->sectorAt($x, $y), $alpha);
		$this->renderDomTextureViews();
		$this->renderCameraTextureViews($registry, $viewEntity, $extraLight, $alpha);
		$this->renderMirrorViews($registry, $x, $y, $z, $angle, $pitch, $extraLight, $alpha, $blend);

		if(!$this->mesh->cameraPortals->vertexCount())
		{
			glViewport(0, 0, $width, $height);

			return;
		}

		$visibleLinks = $this->mesh->cameraPortals->visibleLinks(
			$this->level
			, $x
			, $y
			, $z
			, $angle
			, PHP_INT_MAX
			, $this->fov
			, $cameraSector
			, $this->projection
			, $pitch
			, $this->pitchMode
		);

		$this->cameraPortalVisible = array_fill_keys(
			array_map(static fn($visibleLink): int => $visibleLink->id, $visibleLinks)
			, true
		);

		$this->cameraPortalFrame = [
			'camera' => [$x,$y,$z,$cameraSector ?? $this->level->map->sectorAt($x, $y),$alpha]
			, 'visible' => array_keys($this->cameraPortalVisible)
			, 'targets' => []
		];

		$links = array_slice($visibleLinks, 0, min(self::CAMERA_PORTALS_PER_VIEW, $this->cameraPortalViewBudget));
		$linkCount = count($links);
		$branchBudget = $linkCount ? intdiv($this->cameraPortalViewBudget, $linkCount) : 0;

		foreach($links as $link)
		{
			$target = $this->renderCameraPortalView(
				$registry
				, $link
				, $x
				, $y
				, $z
				, $angle
				, $pitch
				, $viewEntity
				, $extraLight
				, $alpha
				, 1
				, $branchBudget
				, $blend
			);

			if($target)
			{
				$this->cameraPortalViews[$link->id] = $target;

				$this->cameraPortalFrame['targets'][$link->id] = (int)array_search(
					$target
					, $this->cameraPortalTargets
					, true
				);
			}
		}

		glViewport(0, 0, $width, $height);
	}

	/** Rasterize trusted host DOM documents into targets named by custom ANIMDEFS DOMTexture entries. */
	private function renderDomTextureViews(): void
	{
		foreach($this->level->domTextureBindings() as $name => $binding)
		{
			$definition = $binding->texture;

			$target = $this->cameraTextureTargets[$name] ??= new CameraPortalTarget(
				$definition->width
				, $definition->height
			);

			$target->resize($definition->width, $definition->height);
			$target->begin();
			$ready = false;

			try
			{
				$ready = (bool)$binding->bridge->render($definition->width, $definition->height, 38);
			}
			catch(\Throwable)
			{
				// Browser-backed documents can be temporarily unavailable while rasterizing
				// or recovering a context. Leave the ordinary placeholder visible this frame.
			}
			finally
			{
				$target->end();
			}

			if(!$ready) continue;

			$this->cameraTextureViews[$name] = $target;
			$this->domTextureViewsRendered++;
		}

		$this->configureViewport();
	}

	/** Capture every bound ANIMDEFS camera texture before drawing the player's view. */
	private function renderCameraTextureViews(
		Registry $registry
		, ?int $viewEntity
		, int $extraLight
		, float $alpha
	): void {
		foreach($this->level->cameraTextureBindings() as $name => $binding)
		{
			$definition = $binding->texture;

			$target = $this->cameraTextureTargets[$name] ??= new CameraPortalTarget(
				$definition->width
				, $definition->height
			);

			$target->resize($definition->width, $definition->height);
			$projection = new ViewProjection($definition->width, $definition->height, $binding->fov);
			$this->configureSceneProjection($projection, $definition->width, $definition->height);
			$camera = $binding->camera;
			$target->begin();

			try
			{
				$feet = $camera->z - PlayerState::VIEW_HEIGHT;

				$this->render(
					$camera->x
					, $camera->y
					, $feet
					, $camera->angle
					, $camera->pitch
					, $extraLight
					, $alpha
					, $camera->sector
				);

				// A remote camera should see the local player's body as well.
				$this->renderActors(
					$registry
					, $camera->x
					, $camera->y
					, $feet
					, $camera->angle
					, $camera->pitch
					, null
					, $extraLight
					, $alpha
					, $camera->sector
				);

				$this->renderBeams($registry, $camera->x, $camera->y, $feet, $camera->angle, $camera->pitch, $alpha);
				$this->renderTranslucentVolumes();
			}
			finally
			{
				$target->end();
			}

			$this->cameraTextureViews[$name] = $target;
			$this->cameraTextureViewsRendered++;
		}

		$this->configureViewport();
	}

	/** Capture each Line_Mirror using a point and heading reflected across its line. */
	private function renderMirrorViews(
		Registry $registry
		, float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch
		, int $extraLight
		, float $alpha
		, array $viewBlend
	): void {
		$width = $this->viewport->sceneWidth;
		$height = $this->viewport->sceneHeight;

		foreach($this->level->mirrors() as $mirror)
		{
			$camera = $mirror->camera($this->level, $x, $y, $z, $angle);
			$target = $this->mirrorTargets[$mirror->line] ??= new CameraPortalTarget($width, $height);
			$target->resize($width, $height);
			$target->begin();

			try
			{
				$this->render(
					$camera['x']
					, $camera['y']
					, $camera['z']
					, $camera['angle']
					, $pitch
					, $extraLight
					, $alpha
					, $camera['sector']
					, viewBlend: $viewBlend
				);

				$this->renderActors(
					$registry
					, $camera['x']
					, $camera['y']
					, $camera['z']
					, $camera['angle']
					, $pitch
					, null
					, $extraLight
					, $alpha
					, $camera['sector']
				);

				$this->renderBeams(
					$registry
					, $camera['x']
					, $camera['y']
					, $camera['z']
					, $camera['angle']
					, $pitch
					, $alpha
				);
				$this->renderTranslucentVolumes();
			}
			finally
			{
				$target->end();
			}

			$this->mirrorViews[$mirror->line] = $target;
			$this->mirrorViewsRendered++;
		}
	}

	/** Temporarily assign one projection to every world-space shader. */
	private function configureSceneProjection(ViewProjection $projection, int $width, int $height): void
	{
		foreach([
			$this->program
			, $this->spriteProgram
			, $this->skyProgram
			, $this->skyOcclusionProgram
			, $this->beamProgram
			, $this->cameraPortalProgram
			, $this->cameraTextureProgram
			, $this->mirrorProgram
		] as $program)
		{
			$program->use();
			glUniform2f($program->location('projection'), $projection->horizontalScale, $projection->verticalScale);
		}

		$this->beamProgram->use();
		glUniform2f($this->beamProgram->location('viewport'), $width, $height);

		foreach([$this->cameraPortalProgram,$this->mirrorProgram] as $program)
		{
			$program->use();
			glUniform2f($program->location('viewport'), $width, $height);
		}

		glUseProgram(0);
	}

	/**
	 * Render one transformed camera after its child portal textures are ready.
	 *
	 * Every capture uses the originating observer's blend. Portal compositing
	 * copies already-colored pixels, so recursive views apply that blend once.
	 *
	 * @param int $branchBudget Maximum captures available to this branch, including this view.
	 */
	private function renderCameraPortalView(
		Registry $registry
		, \DoomPhonics\Game\CameraPortalLink $link
		, float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch
		, ?int $viewEntity
		, int $extraLight
		, float $alpha
		, int $depth
		, int $branchBudget
		, array $viewBlend
	): ?CameraPortalTarget {
		if($depth > self::CAMERA_PORTAL_RECURSION_DEPTH
			|| $this->cameraPortalViewBudget <= 0
			|| $branchBudget <= 0
		) return null;

		$this->cameraPortalViewBudget--;
		$width = $this->viewport->sceneWidth;
		$height = $this->viewport->sceneHeight;
		$targetIndex = $this->cameraPortalTargetCursor++;
		$target = $this->cameraPortalTargets[$targetIndex] ??= new CameraPortalTarget($width, $height);
		$target->resize($width, $height);
		$camera = $link->camera($x, $y, $z, $angle, $this->level, PlayerState::VIEW_HEIGHT);
		// Plane captures retain pitch and eye distance on the approach side of the
		// open destination plane. Rays then cross the aperture naturally.
		$cameraPitch = $pitch;
		$nearPlane = $this->linePortalNearPlane($link, $x, $y);
		$childViews = [];

		$visibleChildLinks = $this->mesh->cameraPortals->visibleLinks(
			$this->level
			, $camera['x']
			, $camera['y']
			, $camera['z']
			, $camera['angle']
			, PHP_INT_MAX
			, $this->fov
			, $link->destinationSector
			, $this->projection
			, $cameraPitch
			, $this->pitchMode
		);

		if($link->reciprocalId !== null)
		$visibleChildLinks = array_values(
			array_filter($visibleChildLinks, static fn($childLink): bool => $childLink->id !== $link->reciprocalId)
		);

		// A nested plane portal can cross the near plane while its parent line is
		// passing almost parallel beside the eye. Use the plane's local material for
		// that grazing parent view; the direct plane portal remains fully active.
		if($this->grazingLinePortal($link, $x, $y, $angle)) $visibleChildLinks = [];

		$availableChildViews = min($this->cameraPortalViewBudget, $branchBudget - 1);
		$childLinks = array_slice($visibleChildLinks, 0, min(self::CAMERA_PORTALS_PER_VIEW, $availableChildViews));
		$childCount = count($childLinks);
		$childBudget = $childCount ? intdiv($availableChildViews, $childCount) : 0;

		foreach($childLinks as $childLink)
		{
			$child = $this->renderCameraPortalView(
				$registry
				, $childLink
				, $camera['x']
				, $camera['y']
				, $camera['z']
				, $camera['angle']
				, $cameraPitch
				, $viewEntity
				, $extraLight
				, $alpha
				, $depth + 1
				, $childBudget
				, $viewBlend
			);

			if($child) $childViews[$childLink->id] = $child;
		}

		$parentViews = $this->cameraPortalViews;
		$parentVisible = $this->cameraPortalVisible;
		$this->cameraPortalViews = $childViews;

		$this->cameraPortalVisible = array_fill_keys(
			array_map(static fn($visibleLink): int => $visibleLink->id, $visibleChildLinks)
			, true
		);

		$target->begin();

		try
		{
			$this->render(
				$camera['x']
				, $camera['y']
				, $camera['z']
				, $camera['angle']
				, $cameraPitch
				, $extraLight
				, $alpha
				, $link->destinationSector
				, $nearPlane
				, $viewBlend
			);

			$this->renderActors(
				$registry
				, $camera['x']
				, $camera['y']
				, $camera['z']
				, $camera['angle']
				, $cameraPitch
				, $viewEntity
				, $extraLight
				, $alpha
				, $link->destinationSector
				, $nearPlane
			);

			$this->renderBeams(
				$registry
				, $camera['x']
				, $camera['y']
				, $camera['z']
				, $camera['angle']
				, $cameraPitch
				, $alpha
				, $nearPlane
			);
			$this->renderTranslucentVolumes();
		}
		finally
		{
			$target->end();
			$this->cameraPortalViews = $parentViews;
			$this->cameraPortalVisible = $parentVisible;
		}

		$this->cameraPortalViewsRendered++;

		return $target;
	}

	/** Project each portal camera texture onto its line or sector-plane geometry. */
	private function renderCameraPortalSurfaces(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch
		, float $nearPlane
	): void {
		if(!$this->mesh->cameraPortals->vertexCount()) return;

		$program = $this->cameraPortalProgram;
		$program->use();
		glUniform3f($program->location('camera'), $x, $y, $z + 41);
		glUniform2f($program->location('forward'), cos($angle), sin($angle));
		glUniform1f($program->location('cameraPitch'), $pitch);
		glUniform1f($program->location('nearPlane'), $nearPlane);
		glUniform1i($program->location('pitchMode'), $this->pitchMode->shaderValue());
		glEnable(GL_DEPTH_TEST);
		glDepthFunc(GL_LEQUAL);
		glDepthMask(true);
		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[5]);
		glColorMask(true, true, true, true);

		try
		{
			foreach($this->mesh->cameraPortals->spans as $span)
			{
				$link = $span['surface']->link;

				if(!isset($this->cameraPortalVisible[$link->id])) continue;

				$target = $link->active ? ($this->cameraPortalViews[$link->id] ?? null) : null;

				if($target)
				{
					glUniform1i($program->location('portalReady'), 1);
					glActiveTexture(GL_TEXTURE0 + 4);
					glBindTexture(GL_TEXTURE_2D, $target->texture());
				}
				else glUniform1i($program->location('portalReady'), 0);

				glDrawArrays(GL_TRIANGLES, $span['first'], $span['count']);
			}
		}
		finally
		{
			glBindVertexArray(0);
			glActiveTexture(GL_TEXTURE0);
			glDepthFunc(GL_LESS);
			glDisable(GL_BLEND);
		}
	}

	/** Draw captured camera targets using the WAD texture coordinates of each surface. */
	private function renderCameraTextureSurfaces(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch
		, float $nearPlane
	): void {
		if(!$this->mesh->cameraTextures->vertexCount()) return;

		$program = $this->cameraTextureProgram;
		$cameraSector = $this->level->map->sectorAt($x, $y);
		$program->use();
		glUniform3f($program->location('camera'), $x, $y, $z + PlayerState::VIEW_HEIGHT);
		glUniform2f($program->location('forward'), cos($angle), sin($angle));
		glUniform1f($program->location('cameraPitch'), $pitch);
		glUniform1f($program->location('nearPlane'), $nearPlane);
		glUniform1i($program->location('pitchMode'), $this->pitchMode->shaderValue());
		glUniform1i($program->location('cameraComponent'), $this->sectorComponents[$cameraSector] ?? $cameraSector);
		glEnable(GL_DEPTH_TEST);
		glDepthFunc(GL_LEQUAL);
		glDepthMask(true);
		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[6]);

		try
		{
			foreach($this->mesh->cameraTextures->spans as $span)
			{
				$binding = $span['binding'];
				$target = $this->cameraTextureViews[$binding->texture->name] ?? null;
				glUniform1i($program->location('surfaceSide'), $span['side']);

				glUniform2f(
					$program->location('textureSize')
					, $binding->texture->displayWidth()
					, $binding->texture->displayHeight()
				);

				glUniform1i($program->location('cameraReady'), $target ? 1 : 0);

				if($target)
				{
					glActiveTexture(GL_TEXTURE0 + 4);
					glBindTexture(GL_TEXTURE_2D, $target->texture());
				}

				glDrawArrays(GL_TRIANGLES, $span['first'], $span['count']);
			}
		}
		finally
		{
			glBindVertexArray(0);
			glActiveTexture(GL_TEXTURE0);
			glDepthFunc(GL_LESS);
		}
	}

	/** Project reflected scene targets over one-sided mirror geometry. */
	private function renderMirrorSurfaces(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch
		, float $nearPlane
	): void {
		if(!$this->mesh->mirrors->vertexCount()) return;

		$program = $this->mirrorProgram;
		$program->use();
		glUniform3f($program->location('camera'), $x, $y, $z + PlayerState::VIEW_HEIGHT);
		glUniform2f($program->location('forward'), cos($angle), sin($angle));
		glUniform1f($program->location('cameraPitch'), $pitch);
		glUniform1f($program->location('nearPlane'), $nearPlane);
		glUniform1i($program->location('pitchMode'), $this->pitchMode->shaderValue());
		glEnable(GL_DEPTH_TEST);
		glDepthFunc(GL_LEQUAL);
		glDepthMask(true);
		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[7]);

		try
		{
			foreach($this->mesh->mirrors->spans as $span)
			{
				$target = $this->mirrorViews[$span['mirror']->line] ?? null;

				if(!$target) continue;

				glActiveTexture(GL_TEXTURE0 + 4);
				glBindTexture(GL_TEXTURE_2D, $target->texture());
				glDrawArrays(GL_TRIANGLES, $span['first'], $span['count']);
			}
		}
		finally
		{
			glBindVertexArray(0);
			glActiveTexture(GL_TEXTURE0);
			glDepthFunc(GL_LESS);
		}
	}

	/** Keep the usual depth precision except while a visible line portal intersects the near plane. */
	private function visiblePortalNearPlane(float $x, float $y): float
	{
		foreach($this->level->cameraPortalLinks() as $link)
		if(isset($this->cameraPortalVisible[$link->id])
			&& $this->linePortalNearPlane($link, $x, $y) < self::NEAR_PLANE
		) return CameraPortalMesh::LINE_NEAR_PLANE;

		return self::NEAR_PLANE;
	}

	/** A portal capture starts behind its destination seam by the same distance as this source camera. */
	private function linePortalNearPlane(
		\DoomPhonics\Game\CameraPortalLink $link
		, float $x
		, float $y
	): float {
		if(!$link->active || $link->originLine === null) return self::NEAR_PLANE;

		$line = $this->level->map->linedefs[$link->originLine] ?? null;

		if(!$line) return self::NEAR_PLANE;

		$a = $this->level->map->vertices[$line['from']];
		$b = $this->level->map->vertices[$line['to']];
		$lineX = $b['x'] - $a['x'];
		$lineY = $b['y'] - $a['y'];
		$length = hypot($lineX, $lineY);

		if($length <= 0) return self::NEAR_PLANE;

		$distance = abs($lineX * ($y - $a['y']) - $lineY * ($x - $a['x'])) / $length;

		return $distance < self::NEAR_PLANE ? CameraPortalMesh::LINE_NEAR_PLANE : self::NEAR_PLANE;
	}

	/** Whether a nearby line portal is crossing the view's near plane edge-on. */
	private function grazingLinePortal(
		\DoomPhonics\Game\CameraPortalLink $link
		, float $x
		, float $y
		, float $angle
	): bool {
		if($link->originLine === null) return false;

		$line = $this->level->map->linedefs[$link->originLine] ?? null;

		if(!$line) return false;

		$a = $this->level->map->vertices[$line['from']];
		$b = $this->level->map->vertices[$line['to']];
		$lineX = $b['x'] - $a['x'];
		$lineY = $b['y'] - $a['y'];
		$length = hypot($lineX, $lineY);

		if($length <= 0) return false;

		$side = $lineX * ($y - $a['y']) - $lineY * ($x - $a['x']);
		$facing = abs(cos($angle) * $lineY - sin($angle) * $lineX) / $length;

		return abs($side) / $length < 32.0 && $facing < 0.08715574274765817;
	}

	/** Draw the view weapon and classic status patches as one batched GLES overlay. */
	public function renderOverlay(Registry $registry, bool $reticle = true): void
	{
		$started = hrtime(true);
		$players = $registry->query(Query::all(PlayerStart::class, PlayerState::class, HitPoints::class));

		if(!$players) return;

		$entity = $players[0];

		foreach($players as $candidate)
		if($registry->has($candidate, NetworkPlayer::class)
			&& $registry->get($candidate, NetworkPlayer::class)->local
		){
			$entity = $candidate;
			break;
		}

		$player = $registry->get($entity, PlayerState::class);
		$health = $registry->get($entity, HitPoints::class);
		$patches = [];

		if($health->current > 0 && $player->weaponEntity !== null && $registry->alive($player->weaponEntity)
			&& $registry->has($player->weaponEntity, ActorState::class)
		){
			$offsetY = $registry->has($player->weaponEntity, \DoomPhonics\Component\WeaponOwner::class)
				? $registry->get($player->weaponEntity, \DoomPhonics\Component\WeaponOwner::class)->offsetY
				: 0;

			$this->appendWeaponPatch($patches, $registry->get($player->weaponEntity, ActorState::class), $offsetY);
		}

		$overlays = [];

		foreach($health->current > 0
			? $registry->query(Query::all(WeaponOverlay::class, ActorState::class))
			: [] as $overlayEntity
		){
			$overlay = $registry->get($overlayEntity, WeaponOverlay::class);
			$state = $registry->get($overlayEntity, ActorState::class);

			if($overlay->playerEntity === $entity && !$state->stopped)
			$overlays[] = [$overlay->layer, $overlayEntity, $state];
		}

		usort($overlays, static fn(array $left, array $right): int => [$left[0],$left[1]] <=> [$right[0],$right[1]]);

		foreach($overlays as [,,$state]) $this->appendWeaponPatch($patches, $state);

		if($bar = $this->catalog->picture('STBAR')) $patches[] = ['picture','STBAR',0,168,$bar];

		$faceName = $registry->has($entity, StatusFace::class)
			? $registry->get($entity, StatusFace::class)->patch(
				$health->current
				, $player->godMode || isset($player->powers['Invulnerable'])
			)
			: 'STFST00';

		if($face = $this->catalog->picture($faceName))
		$patches[] = ['picture',$faceName,143 - $face->leftOffset,168 - $face->topOffset,$face];

		$this->numberPatches($patches, max(0, $this->readyAmmo($player)), 44, 171);
		$this->numberPatches($patches, max(0, $health->current), 90, 171);
		$this->numberPatches($patches, max(0, $player->armor), 221, 171);

		foreach(['Clip','Shell','RocketAmmo','Cell'] as $row => $type)
		{
			$this->numberPatches($patches, max(0, (int)($player->ammo[$type] ?? 0)), 288, 173 + $row * 6, 'STYSNUM');

			$this->numberPatches(
				$patches
				, max(0, (int)($player->ammoMaximum[$type] ?? 0))
				, 314
				, 173 + $row * 6
				, 'STYSNUM'
			);
		}

		if($percent = $this->catalog->picture('STTPRCNT'))
		{
			$patches[] = ['picture','STTPRCNT',90,171,$percent];
			$patches[] = ['picture','STTPRCNT',221,171,$percent];
		}

		foreach([['BlueCard','BlueSkull'],['YellowCard','YellowSkull'],['RedCard','RedSkull']] as $row => [
			$card
			, $skull
		]){
			$index = !empty($player->keys[$skull]) ? $row + 3 : (!empty($player->keys[$card]) ? $row : -1);

			if($index >= 0 && ($key = $this->catalog->picture('STKEYS' . $index)))
			$patches[] = ['picture','STKEYS' . $index,239,171 + $row * 10,$key];
		}

		if($player->message !== '') $this->appendHudText($patches, $player->message, 2, 2);

		$scale = min($this->width / 320, $this->height / 200);
		$offsetX = ($this->width - 320 * $scale) / 2;
		$values = [];

		foreach($patches as $patch)
		{
			[$kind,$name,$x,$y,$image] = $patch;
			$fixedColormap = (bool)($patch[5] ?? false);
			$id = $this->atlas->id($kind, $name);

			if($id < 0) continue;

			$left = $offsetX + $x * $scale;
			$top = $y * $scale;
			$right = $left + $image->width * $scale;
			$bottom = $top + $image->height * $scale;

			$this->appendOverlayQuad($values, $left, $top, $right, $bottom, $image, $id, 1.0, $fixedColormap);
		}

		if($reticle && $health->current > 0)
		{
			$id = $this->atlas->id('ui', 'reticle');

			if($id >= 0)
			{
				$thickness = max(1.0, $scale);
				$length = max($thickness, 4 * $scale);
				$left = $this->width / 2 - $thickness / 2;
				$top = $this->height / 2 - $thickness / 2;

				$this->appendOverlayQuad(
					$values
					, $left
					, $top
					, $left + $length
					, $top + $thickness
					, $this->reticleImage
					, $id
				);

				$this->appendOverlayQuad(
					$values
					, $left
					, $top
					, $left + $thickness
					, $top + $length
					, $this->reticleImage
					, $id
				);
			}
		}

		$this->submitOverlay($values);
		$this->overlayCpuMs = (hrtime(true) - $started) / 1e6;
	}

	/**
	 * Draw direct WAD pictures in the classic interface coordinate space.
	 *
	 * The virtual canvas stretches to the output viewport so the title and menu fill a widescreen
	 * host while every source texel remains nearest-neighbor sampled.
	 *
	 * @param list<WadPatch> $patches Ordered picture placements.
	 */
	public function renderMenuPatches(array $patches): void
	{
		$values = [];
		$scaleX = $this->width / 320;
		$scaleY = $this->height / 200;

		foreach($patches as $patch)
		{
			if(!$patch instanceof WadPatch)
			throw new \InvalidArgumentException('Menu overlays require WadPatch values');

			$image = $this->catalog->picture($patch->name);
			$id = $this->atlas->id('picture', $patch->name);

			if(!$image || $id < 0) continue;

			$width = $patch->width ?? (float)$image->width;
			$height = $patch->height ?? (float)$image->height;
			$left = ($patch->x - $image->leftOffset * $width / $image->width) * $scaleX;
			$top = ($patch->y - $image->topOffset * $height / $image->height) * $scaleY;
			$right = $left + $width * $scaleX;
			$bottom = $top + $height * $scaleY;

			$this->appendOverlayQuad($values, $left, $top, $right, $bottom, $image, $id, $patch->opacity);
		}

		$this->submitOverlay($values);
	}

	/**
	 * Append a palette-indexed overlay quad using source texel-edge coordinates.
	 *
	 * The fragment shader floors interpolated coordinates before texelFetch(). Mapping the
	 * far edges to the image dimensions keeps the final row and column reachable at fragment
	 * centers; mapping them to width - 1 and height - 1 clips those texels entirely.
	 *
	 * @param list<float|int> $values Interleaved overlay vertices, updated in place.
	 */
	private function appendOverlayQuad(
		array &$values
		, float $left
		, float $top
		, float $right
		, float $bottom
		, IndexedImage $image
		, int $texture
		, float $opacity = 1.0
		, bool $fixedColormap = false
	): void {
		$sourceRight = (float)$image->width;
		$sourceBottom = (float)$image->height;

		foreach([
			[$left,$bottom,0.0,$sourceBottom]
			, [$right,$bottom,$sourceRight,$sourceBottom]
			, [$right,$top,$sourceRight,0.0]
			, [$left,$bottom,0.0,$sourceBottom]
			, [$right,$top,$sourceRight,0.0]
			, [$left,$top,0.0,0.0]
		] as [$x,$y,$u,$v])
		array_push($values, $x, $y, $u, $v, (float)$texture, max(0, min(1, $opacity)), $fixedColormap ? 1.0 : 0.0);
	}

	/** Draw a translucent palette-black rectangle behind a WAD menu page. */
	public function renderMenuPanel(
		float $x
		, float $y
		, float $width
		, float $height
		, float $opacity
	): void {
		if($width <= 0 || $height <= 0 || $opacity <= 0) return;

		$id = $this->atlas->id('ui', 'backdrop');

		if($id < 0) return;

		$left = $x * $this->width / 320;
		$top = $y * $this->height / 200;
		$right = ($x + $width) * $this->width / 320;
		$bottom = ($y + $height) * $this->height / 200;
		$values = [];

		foreach([
			[$left,$bottom],[$right,$bottom],[$right,$top]
			, [$left,$bottom],[$right,$top],[$left,$top]
		] as [$px,$py])
		array_push($values, $px, $py, 0.0, 0.0, (float)$id, max(0, min(1, $opacity)), 0.0);
		$this->submitOverlay($values);
	}

	/** @param list<float|int> $values Interleaved x, y, u, v, atlas ID, opacity, and fixed-map flag. */
	private function submitOverlay(array $values): void
	{
		if(!$values || $this->closed) return;

		$this->bindTextures();
		$bytes = pack('f*', ...$values);
		$length = strlen($bytes);
		$this->overlayProgram->use();
		glViewport(0, 0, $this->width, $this->height);
		glDisable(GL_DEPTH_TEST);
		glDepthMask(false);
		glEnable(GL_BLEND);
		glBindVertexArray($this->vao[2]);
		glBindBuffer(GL_ARRAY_BUFFER, $this->buffers[2]);

		if($length > $this->overlayCapacity)
		{
			$this->overlayCapacity = 1;

			while($this->overlayCapacity < $length) $this->overlayCapacity <<= 1;

			glBufferData(
				GL_ARRAY_BUFFER
				, $this->overlayCapacity
				, str_repeat("\0", $this->overlayCapacity)
				, GL_STREAM_DRAW
			);
		}

		glBufferSubData(GL_ARRAY_BUFFER, 0, $length, $bytes);
		glDrawArrays(GL_TRIANGLES, 0, intdiv(count($values), 7));
		glBindVertexArray(0);
		glDepthMask(true);
		glActiveTexture(GL_TEXTURE0);
	}

	/** Return compact diagnostics for benchmarks and browser acceptance tests. */
	public function metrics(): array
	{
		return [
			'mapVertices' => $this->mesh->vertexCount()
			, 'cameraPortalVertices' => $this->mesh->cameraPortals->vertexCount()
			, 'cameraPortalViews' => $this->cameraPortalViewsRendered
			, 'cameraTextureVertices' => $this->mesh->cameraTextures->vertexCount()
			, 'cameraTextureViews' => $this->cameraTextureViewsRendered
			, 'domTextureViews' => $this->domTextureViewsRendered
			, 'mirrorVertices' => $this->mesh->mirrors->vertexCount()
			, 'mirrorViews' => $this->mirrorViewsRendered
			, 'cameraPortalFrame' => $this->cameraPortalFrame
			, 'skyOccluderVertices' => $this->mesh->sky->vertexCount()
			, 'atlas' => [$this->atlas->width,$this->atlas->height,count($this->atlas->rects)]
			, 'framebuffer' => [$this->viewport->outputWidth,$this->viewport->outputHeight]
			, 'scene' => [$this->viewport->sceneWidth,$this->viewport->sceneHeight,$this->viewport->sceneScale]
			, 'pitchMode' => $this->pitchMode->value
			, 'mapCpuMs' => $this->mapCpuMs
			, 'mapStagesMs' => $this->mapStagesMs
			, 'actorCpuMs' => $this->actorCpuMs
			, 'beamCpuMs' => $this->beamCpuMs
			, 'overlayCpuMs' => $this->overlayCpuMs
			, 'beamVertices' => $this->beamVertices
			, 'sprites' => $this->spriteStats
		];
	}

	/** @param list<array{string,string,int,int,object}> $patches */
	private function numberPatches(array &$patches, int $value, int $right, int $top, string $prefix = 'STTNUM'): void
	{
		$text = substr((string)min(999, $value), -3);

		for($index = strlen($text) - 1; $index >= 0; $index--)
		{
			$name = $prefix . $text[$index];
			$image = $this->catalog->picture($name);

			if(!$image)continue;

			$right -= $image->width;
			$patches[] = ['picture',$name,$right,$top,$image];
		}
	}

	private function readyAmmo(PlayerState $player): int
	{
		$weapon = $this->actors?->named($player->weapon);
		$type = $weapon ? WeaponAmmo::type($weapon) : '';
		$type = $this->actors ? (WeaponAmmo::pool($this->actors, $type)?->name ?? $type) : $type;

		return $type === '' ? 0 : (int)($player->ammo[$type] ?? 0);
	}

	/** @param list<array{string,string,int,int,object}> $patches */
	private function appendHudText(array &$patches, string $text, int $left, int $top): void
	{
		$x = $left;
		$y = $top;

		foreach(str_split(strtoupper($text)) as $character)
		{
			if($character === "\n")
			{
				$x = $left;
				$y += 9;
				continue;
			}

			$code = ord($character);

			if($code < 33 || $code > 95)
			{
				$x += 4;
				continue;
			}

			$name = 'STCFN' . str_pad((string)$code, 3, '0', STR_PAD_LEFT);
			$image = $this->catalog->picture($name);

			if(!$image)
			{
				$x += 4;
				continue;
			}

			if($x + $image->width > 318)
			{
				$x = $left;
				$y += 9;
			}

			// STCFN punctuation uses patch origins to sit on the baseline. In
			// particular, the period has a negative top offset and otherwise looks
			// like a trailing apostrophe at the top of the text row.
			$patches[] = [
				'picture',$name,$x - $image->leftOffset,$y - $image->topOffset,$image
			];

			$x += $image->width;
		}
	}

	/** @param array<int,array<int,mixed>> $patches */
	private function appendWeaponPatch(array &$patches, ActorState $state, float $offsetY = 0): void
	{
		$frame = $state->current();

		if(!$frame || strlen($frame->sprite) !== 4) return;

		$sprite = $this->catalog->sprite($frame->sprite, $frame->frame, 0);

		if(!$sprite) return;

		$image = $sprite['image'];
		$placement = ViewWeaponPlacement::fromImage($image, $offsetY);
		$patches[] = [
			'sprite', $sprite['name'], $placement->x, $placement->y, $image, true
		];
	}

	private function renderSky(float $angle, float $pitch): void
	{
		$sky = $this->atlas->id('wall', 'SKY1');

		if($sky < 0) return;

		$this->skyProgram->use();
		glUniform1i($this->skyProgram->location('skyTexture'), $sky);
		glUniform1f($this->skyProgram->location('cameraAngle'), $angle);
		glUniform1f($this->skyProgram->location('cameraPitch'), $pitch);
		glUniform1i($this->skyProgram->location('pitchMode'), $this->pitchMode->shaderValue());
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_BLEND);
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glBindVertexArray(0);
	}

	/** Mask geometry beyond terminal sky walls without covering the sky color. */
	private function renderSkyOcclusion(
		float $x
		, float $y
		, float $z
		, float $angle
		, float $pitch
		, int $cameraComponent
		, float $nearPlane
	): void {
		if(!$this->mesh->sky->vertexCount()) return;

		$this->skyOcclusionProgram->use();
		glUniform3f($this->skyOcclusionProgram->location('camera'), $x, $y, $z + 41);
		glUniform2f($this->skyOcclusionProgram->location('forward'), cos($angle), sin($angle));
		glUniform1f($this->skyOcclusionProgram->location('cameraPitch'), $pitch);
		glUniform1f($this->skyOcclusionProgram->location('nearPlane'), $nearPlane);
		glUniform1i($this->skyOcclusionProgram->location('pitchMode'), $this->pitchMode->shaderValue());
		glUniform1i($this->skyOcclusionProgram->location('cameraComponent'), $cameraComponent);
		glEnable(GL_DEPTH_TEST);
		glDepthMask(true);
		glDisable(GL_BLEND);
		glEnable(GL_CULL_FACE);
		glCullFace(GL_BACK);
		glColorMask(false, false, false, false);

		try
		{
			glBindVertexArray($this->vao[3]);
			glDrawArrays(GL_TRIANGLES, 0, $this->mesh->sky->vertexCount());
		}
		finally
		{
			glBindVertexArray(0);
			glColorMask(true, true, true, true);
			glDisable(GL_CULL_FACE);
		}
	}

	/** Pack rendered planes separately from physical sector heights for each camera view. */
	private function sectorData(float $alpha = 1, int $region = 0): string
	{
		$values = $floors = $ceilings = [];
		$alpha = max(0, min(1, $alpha));
		foreach($this->level->sectors as $sector)
		{
			$view = $sector->heightTransfer?->view($sector, $region);
			$floor = $view && !($sector->heightTransfer->flags & 4) ? $view['floor'] : [$sector,0];
			$ceiling = $view && !($sector->heightTransfer->flags & 4) ? $view['ceiling'] : [$sector,1];
			$values[] = $floor[1] ? $floor[0]->ceilingAt($alpha) : $floor[0]->floorAt($alpha);
			$values[] = $ceiling[1] ? $ceiling[0]->ceilingAt($alpha) : $ceiling[0]->floorAt($alpha);
			$values[] = (float)($view['light'] ?? $sector->light);
			$values[] = (float)($this->sectorComponents[$sector->index] ?? $sector->index);
			array_push($floors, ...$floor[0]->{$floor[1] ? 'ceilingSlope' : 'floorSlope'});
			$floors[] = (float)($sector->floorLightSource ?? $sector->index);
			array_push($ceilings, ...$ceiling[0]->{$ceiling[1] ? 'ceilingSlope' : 'floorSlope'});
			$ceilings[] = (float)($sector->ceilingLightSource ?? $sector->index);
		}
		return $values ? pack('f*', ...$values,...$floors,...$ceilings) : pack('f*', 0,128,255,0,0,0,0,0,0,0,0,0);
	}

	/** Composite translucent water after world actors, in the currently configured view. */
	public function renderTranslucentVolumes(): void
	{
		if($this->closed || !$this->mesh->volumeSpans) return;
		$this->bindTextures();
		$this->program->use();
		glEnable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glBindVertexArray($this->vao[0]);
		$this->drawVolumes(true);
		glBindVertexArray(0);
	}

	/** Draw opaque model surfaces immediately or sort translucent faces back to front. */
	private function drawVolumes(bool $transparent): void
	{
		$batches = VolumeDrawList::build($this->mesh, $this->level, $this->volumeView, $transparent);
		if(!$batches) return;
		$indices = [];
		foreach($batches as $batch) array_push($indices, ...$batch['indices']);
		$bytes = pack('V*', ...$indices);
		glBindBuffer(GL_ELEMENT_ARRAY_BUFFER, $this->buffers[$transparent ? 9 : 8]);
		glBufferData(GL_ELEMENT_ARRAY_BUFFER, strlen($bytes), $bytes, GL_STREAM_DRAW);
		if($transparent)
		{
			glEnable(GL_BLEND);
			glDepthMask(false);
		}
		$offset = 0;
		// A projected liquid exactly on an open plane owns that seam's surface.
		glDepthFunc(GL_LEQUAL);
		foreach($batches as $batch)
		{
			glUniform1f($this->program->location('surfaceAlpha'), $batch['opacity']);
			glUniform1i($this->program->location('surfaceLight'), $batch['light']);
			glUniform1i($this->program->location('clipSectorBounds'), (int)$batch['clipBounds']);
			glUniform1i($this->program->location('volumeProjected'), (int)$batch['projected']);
			glUniform1f($this->program->location('volumeHeightOffset'), $batch['heightOffset']);
			if($transparent) glBlendFunc(GL_SRC_ALPHA, $batch['additive'] ? GL_ONE : GL_ONE_MINUS_SRC_ALPHA);
			$count = count($batch['indices']);
			glDrawElements(GL_TRIANGLES, $count, GL_UNSIGNED_INT, $offset);
			$offset += $count * 4;
		}
		glUniform1f($this->program->location('surfaceAlpha'), 1);
		glUniform1i($this->program->location('surfaceLight'), -1);
		glUniform1i($this->program->location('clipSectorBounds'), 0);
		glUniform1i($this->program->location('volumeProjected'), 0);
		glDepthFunc(GL_LESS);
		glDepthMask(true);
		glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA);
	}

	private function release(): void
	{
		foreach($this->cameraPortalTargets as $target) $target->close();

		foreach($this->cameraTextureTargets as $target) $target->close();

		foreach($this->mirrorTargets as $target) $target->close();
		$this->cameraPortalTargets = $this->cameraPortalViews = $this->cameraPortalVisible = [];
		$this->cameraTextureTargets = $this->cameraTextureViews = [];
		$this->mirrorTargets = $this->mirrorViews = [];
		$this->cameraPortalTargetCursor = $this->cameraPortalViewBudget = 0;

		if($this->textures) glDeleteTextures(count($this->textures), $this->textures);

		if($this->buffers) glDeleteBuffers(count($this->buffers), $this->buffers);

		if($this->vao) glDeleteVertexArrays(count($this->vao), $this->vao);

		$this->textures = $this->buffers = $this->vao = [];
		$this->spriteCapacity = $this->overlayCapacity = $this->beamCapacity = 0;
		$this->beamVertices = 0;
		$this->cameraPortalViewsRendered = 0;
		$this->cameraTextureViewsRendered = $this->domTextureViewsRendered = $this->mirrorViewsRendered = 0;
		$this->actorCache = [];
	}

	public function restore(): void
	{
		if($this->closed) return;

		$this->release();
		$this->program->restore();
		$this->spriteProgram->restore();
		$this->skyProgram->restore();
		$this->skyOcclusionProgram->restore();
		$this->overlayProgram->restore();
		$this->beamProgram->restore();
		$this->cameraPortalProgram->restore();
		$this->cameraTextureProgram->restore();
		$this->mirrorProgram->restore();
		$this->create();
	}

	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->release();
		$this->program->close();
		$this->spriteProgram->close();
		$this->skyProgram->close();
		$this->skyOcclusionProgram->close();
		$this->overlayProgram->close();
		$this->beamProgram->close();
		$this->cameraPortalProgram->close();
		$this->cameraTextureProgram->close();
		$this->mirrorProgram->close();
	}
}

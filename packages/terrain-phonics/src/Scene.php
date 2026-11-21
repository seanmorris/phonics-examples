<?php
declare(strict_types=1);
namespace TerrainPhonics;

use Phonics\Render\Program;

/**
 * Incrementally prepared landscape, sky and water renderer.
 *
 * Quadtree selection keeps parent coverage while detail is generated in bounded jobs.
 * GPU objects retain packed mesh bytes for context restoration and have a finite cache.
 */
final class Scene
{
	private Program $groundProgram, $skyProgram, $starsProgram, $waterProgram;

	/**
	 * Ground and water programs built with the lamp code. render() draws them while at least one spot light is set,
	 * and once before the first lamp needs them (litDrawn), and the lamp-free groundProgram and waterProgram
	 * otherwise: in SwiftShader the lamp code made the ground pass about a fifth slower even with no light set.
	 */
	private Program $litGroundProgram, $litWaterProgram;
	/**
	 * Whether the lit ground and water programs have drawn since they were linked. A GL implementation may compile a
	 * program's pipeline at its first draw: in SwiftShader the first draw of each lit program took about 0.2 s more
	 * than the next. While patches are ready to draw, render() draws with a lit program that has not drawn yet even
	 * without a light, so that cost falls on the scene's first frames instead of the frame in which the first lamp
	 * lights.
	 */
	private array $litDrawn = ['ground' => false, 'water' => false];

	private AtmosphereRenderer $atmosphereCache;
	private TerrainShadows $terrainShadows;
	private Lod $lod;
	private ?Clipmap $clipmap = null;
	public ?SurfaceLayers $layers=null;
	public ?TerrainAppearance $paint = null;
	private array $boundPaint = [];
	private ?SurfaceLayers $defaultLayers=null;
	private ?TerrainAppearance $paletteAppearance=null;
	private array $layerBinding=[];
	private array $materialPrograms = [];
	private string $materialKey = '', $groundVertexSource, $groundFragmentSource;
	private ?Program $chartProgram = null;
	private ?Landscape $preparing = null;
	private int $preparingRevision = -1, $preparingSourceRevision = -1;
	private array $replacementJobs = [], $replacements = [];
	private ?array $replacementRegion = null;
	private int $textureGeneration = -1;
	private array $heightTexture = [];
	private int $heightTextureRevision = -1;
	private array $patches = [], $jobs = [], $draw = [], $vao = [], $textures = [];
	private array $activated = [], $neighbors = [], $settledBoundaries = [], $gridBuffer = [];
	private ?array $drawIndex = null;
	/** Clip plane that last hid each drawn patch from the ground pass and from the water pass. */
	private array $groundCull = [], $waterCull = [];
	/** Transition weights already computed for the frame being drawn; null outside render(). */
	private ?array $morphMemo = null;
	/** For each cached seam, the parents whose retirement would set it moving again. */
	private array $settledParents = [];
	private int $groundDraws = 0, $waterDraws = 0;
	/** Reverse sibling transitions and the exact residency reservation for this selection. */
	private array $retiring = [], $reserved = [];
	/**
	 * For each patch key, its parent's key and the keys of the coarser cells that touch it.
	 *
	 * Both follow from the key alone, so an entry never goes stale. Building one of these strings costs
	 * more than a function call in the browser, and each selection asks for hundreds of them.
	 */
	private array $parentKeys = [], $coarserKeys = [];
	/**
	 * Ancestor sets of the last target cover and the last drawn cover, with the key lists they came from.
	 *
	 * A cover's ancestors follow from its keys alone, and a selection often meets the cover that the one
	 * before it met, so each set is rebuilt only when its keys change.
	 */
	private array $targetKeys = [], $targetBranches = [], $drawnKeys = [], $drawnBranches = [];
	private string $indices;
	private float $clock = 0, $lastUpdateMs = 0;
	private int $revision = -1, $frames = 0, $selections = 0;
	private bool $changed = true, $closed = false;
	private ?GpuPatch $craftMesh = null;
	/** Lamps lighting the ground and water, from the first setSpotLights() on. */
	private ?SpotLights $spotLights = null;
	private float $selectionTime = -1;
	private float $transitionUntil = -1;
	private string $selectionKey = '';
	private bool $selectionPending = false;
	/** A changed view or finished sibling group that the limiter made wait. dirty() reports it until it is selected. */
	private bool $selectionDeferred = false;
	/** Whether the last update ended less than one limiter spacing after a selection. dirty() reports it. */
	private bool $selectionRecent = false;
	/** Scene clock at the last limited update whose camera pose differed from the update before it. */
	private float $motionTime = -INF;
	/** Camera pose and selection settings seen by the previous limited update. */
	private ?array $previousPose = null, $previousSettings = null;
	/** Last observed eye and bounded predicted position; speculative jobs never drive visibility. */
	private ?array $previousEye = null, $lookahead = null;
	private int $viewportHeight = 720;
	private float $viewportAspect = 16 / 9;
	/**
	 * The selection in flight while it is spread over several updates, or null. See advanceSelection().
	 *
	 * It holds what the selection captured when it started, the phase it has reached and what the phases
	 * before that one produced.
	 */
	private ?array $flight = null;
	/** Milliseconds each phase took when it last ran, which decides whether it fits what is left of a slice. */
	private array $phaseCost = [];
	/** Selection work done so far in this update, in milliseconds and in steps. */
	private float $sliceMs = 0;
	private int $sliceSteps = 0;
	/**
	 * Updates run so far. Of the last completed selection: how many updates it took part in, and in which of
	 * them, counted from one, it changed the drawn cover.
	 */
	private int $updates = 0, $selectionUpdates = 0, $selectionApplyUpdate = 0;
	/** Selections that ran to the end, which falls short of $selections by those that were dropped. */
	private int $selectionsCompleted = 0;
	/**
	 * Selections that update() dropped for a relocation or a changed setting since the last one completed. A
	 * setting that changes on every update, such as a dragged slider or a window being resized, drops every
	 * spread selection before it can apply.
	 */
	private int $selectionsDropped = 0;
	/** Default for selectionInterval, shared with applications that expose the setting. */
	public const DEFAULT_SELECTION_INTERVAL = .1;
	/** Seconds between detail selections while the view moves. Zero selects on every change, as before the limiter. */
	public float $selectionInterval = self::DEFAULT_SELECTION_INTERVAL;
	/**
	 * Default for selectionSliceMs, shared with applications that expose the setting. It is set for PHP compiled to
	 * WebAssembly in a browser, where a whole selection takes 10 to 14 ms and 3 ms spreads it over about eight
	 * frames. Native PHP runs about six times faster, so the same work there needs about 0.5 ms.
	 */
	public const DEFAULT_SELECTION_SLICE_MS = 3.0;
	/**
	 * Milliseconds of selection work one update aims at. A selection that needs more continues on later updates
	 * and changes the drawn cover in one of them. An update can run past the slice by one pause interval, or by
	 * one phase that cannot pause, such as the one that changes the drawn cover. Zero runs every selection whole
	 * in the update that starts it, as before selections were spread.
	 */
	public float $selectionSliceMs = self::DEFAULT_SELECTION_SLICE_MS;
	/**
	 * Steps of selection work one update may do, in place of selectionSliceMs, so that tests can spread a
	 * selection without the clock. A step is one node that a walk refines, or one whole phase between the walks.
	 * Zero leaves the slice to selectionSliceMs.
	 */
	public int $selectionSliceSteps = 0;
	/** Detail multiplier: .5 economical, 1 balanced, 2 detailed. */
	public float $quality = 1;
	/** Time slice for incremental sampling. Slow display frames may also upload one ready sibling group. */
	public float $budgetMs = 2;
	/** Maximum retained patches including parents. */
	public int $maximumPatches = 384;
	/**
	 * Terrain colors, banding and blending. The next render() uploads a replacement. An application that skips
	 * unchanged frames must draw one after replacing it.
	 */
	public TerrainAppearance $appearance;
	/** Atmosphere uniforms last submitted to each linked scene program. */
	private array $boundEnvironment = [];

	/**
	 * Create an incremental terrain scene with independent simulation surfaces.
	 * @param Landscape $landscape Authoritative committed terrain surface.
	 * @param Water $water Shared analytic water model.
	 * @param Atmosphere $atmosphere Shared atmosphere parameters and numerical lookup data.
	 * @param bool $useClipmap Use GPU elevation textures. Pass false for the PHP patch renderer.
	 * @return void Initializes bounded preparation and graphics resources.
	 */
	public function __construct(
		private Landscape $landscape,
		private Water $water,
		private Atmosphere $atmosphere,
		private bool $useClipmap = true
	){
		if($useClipmap) $this->clipmap = new Clipmap;
		$this->indices = $useClipmap ? '' : GpuPatch::indices(Lod::CELLS);
		$this->lod = new Lod($landscape->recipe()['worldSize'], $landscape->recipe()['spacing']);
		$this->appearance = new TerrainAppearance;

		$this->create();
	}

	/**
	 * Allocate programs and shared mesh resources for the current graphics context.
	 * @return void Creates context-local programs and meshes.
	 */
	private function create(): void
	{
		$root = dirname(__DIR__) . '/shaders/';
		$atmosphere = AtmosphereRenderer::shaderSource();
		$water = file_get_contents($root . 'water-functions.glsl');
		$grid = $this->useClipmap ? "#define CLIPMAP\n".Clipmap::gridShader() : file_get_contents($root.'patch-grid.glsl');
		$optics = WaterAppearance::shaderSource();
		$lamps = SpotLights::shaderSource();
		$dark = SpotLights::shaderSource(false);

		$groundVertex = str_replace('// PATCH', $grid, file_get_contents($root . 'terrain.vert'));
		$ground = str_replace(['// ATMOSPHERE','// OPTICS'], [$atmosphere,$optics]
			, file_get_contents($root . 'terrain.frag'));
		$this->groundVertexSource = $groundVertex;
		$this->groundFragmentSource = $ground;
		$this->selectMaterial($this->palette());

		$this->skyProgram = new Program(
			file_get_contents($root . 'sky.vert')
			, str_replace('// ATMOSPHERE', $atmosphere, file_get_contents($root . 'sky.frag'))
		);

		$this->starsProgram = new Program(file_get_contents($root . 'stars.vert'), file_get_contents($root . 'stars.frag'));

		$waterVertex = str_replace(['// PATCH','// WATER'], [$grid,$water], file_get_contents($root . 'water.vert'));
		$surface = str_replace(['// ATMOSPHERE','// WATER','// OPTICS'], [$atmosphere,$water,$optics]
			, file_get_contents($root . 'water.frag'));
		$this->waterProgram = new Program($waterVertex, str_replace('// SPOT LIGHTS', $dark, $surface));
		$this->litWaterProgram = new Program($waterVertex, str_replace('// SPOT LIGHTS', $lamps, $surface));

		glGenVertexArrays(1, $this->vao);
		$this->createTextures();
		$this->atmosphereCache = new AtmosphereRenderer($this->atmosphere);
		$this->terrainShadows = new TerrainShadows;
		glGenTextures(1, $this->heightTexture);
		$this->uploadHeightMap();
		if(!$this->clipmap) $this->createGridBuffer();
		$this->craftMesh = $this->makeCraft();
	}

	/**
	 * Allocate one immutable index buffer shared by every terrain and water patch.
	 * @return void Uploads the triangle topology for the current context.
	 */
	private function createGridBuffer(): void
	{
		glGenBuffers(1, $this->gridBuffer);
		glBindVertexArray($this->vao[0]);
		glBindBuffer(GL_ELEMENT_ARRAY_BUFFER,$this->gridBuffer[0]);
		$patterns = GpuPatch::stitchPatterns(Lod::CELLS, $this->indices);
		glBufferData(GL_ELEMENT_ARRAY_BUFFER,strlen($patterns),$patterns,GL_STATIC_DRAW);
		glBindVertexArray(0);
	}

	/**
	 * Upload the cached atmospheric transmittance texture when its generation changes.
	 * @return void Publishes the current transmittance lookup texture.
	 */
	private function createTextures(): void
	{
		if($this->textures === []) glGenTextures(1, $this->textures);

		glBindTexture(GL_TEXTURE_2D, $this->textures[0]);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
		$lut = $this->atmosphere->transmittanceLut(64, 16, 24);
		glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, 64, 16, 0, GL_RGBA, GL_UNSIGNED_BYTE, $lut['rgba8']);
		$this->textureGeneration = $lut['generation'];
	}

	/**
	 * Prepare only affected resident meshes while the old revision remains visible and physical.
	 * @param Landscape $candidate Independent committed candidate surface.
	 * @param array|null $region Optional horizontal dirty rectangle; actual changes are also derived.
	 * @return void Starts an isolated bounded mesh replacement transaction.
	 */
	public function prepareLandscape(Landscape $candidate, ?array $region = null): void
	{
		if($this->closed) throw new \LogicException('Terrain scene is closed');

		if($this->preparing !== null) throw new \LogicException('A terrain edit is already being prepared');

		if($candidate === $this->landscape || $candidate->stats()['closed'])
		throw new \InvalidArgumentException('Preparation requires an independent live candidate');

		if($candidate->recipe() !== $this->landscape->recipe())
		throw new \InvalidArgumentException('Staged edits must retain the landscape recipe');

		// The selection in flight read patches that the eviction below can remove and that the edit replaces.
		$this->abandonSelection();
		// Derive the actual edit footprint; callers cannot accidentally omit a changed mesh.
		$region = $this->landscape->changedRegion($candidate);
		$this->jobs = [];
		$this->reserved = $this->ancestors($this->draw);
		$this->evict(true);
		$affected = $this->affected($region);

		if($affected !== [] && $this->capacity() < 2)
		throw new \OverflowException('Atomic terrain replacement requires two patch slots');

		while(count($this->patches) + count($affected) > $this->capacity())
		{
			$this->draw = $this->balance($this->coarsen($this->draw));
			$this->retiring = [];
			$this->reserved = $this->ancestors($this->draw);
			$this->evict(true);
			$affected = $this->affected($region);
		}

		// The cover may have coarsened above, so the next selection works out its ancestors again.
		$this->drawnKeys = $this->drawnBranches = [];

		$this->neighbors = $this->settledBoundaries = $this->settledParents = $this->groundCull = $this->waterCull = [];
		$this->drawIndex = null;
		$this->preparing = $candidate;
		$this->preparingRevision = $candidate->revision();
		$this->preparingSourceRevision = $this->landscape->revision();
		$this->replacementRegion = $region;
		$this->replacementJobs = $this->replacements = [];

		foreach($affected as $key => $n)
		{
			$this->replacementJobs[$key] = $n + ['index' => 0,'bytes' => '', 'heights' => []];
		}

		uksort($this->replacementJobs, /** Compare patch depth keys.
		 * @param string $a First patch key.
		 * @param string $b Second patch key.
		 * @return int Ascending depth order.
		 */
			static fn($a, $b) => (int)$a <=> (int)$b
		);

		$this->changed = true;
	}

	/**
	 * Publish a prepared revision at the caller's next fixed simulation boundary.
	 * @return Landscape|null Published candidate, or null while no candidate is ready.
	 */
	public function takePreparedLandscape(): ?Landscape
	{
		$this->checkPreparation();

		if($this->preparing === null || $this->replacementJobs !== []) return null;

		// prepareLandscape() dropped any selection in flight and update() starts none while it prepares, so this
		// only keeps the rule that a selection never outlives the meshes it read.
		$this->abandonSelection();

		foreach($this->replacements as $key => $patch)
		{
			$this->patches[$key]['mesh']->close();
			$this->patches[$key] = $patch;
		}

		$this->clipmap?->invalidate($this->replacementRegion);
		$this->landscape = $this->preparing;
		$this->preparing = null;
		$this->replacements = [];
		$this->revision = $this->landscape->revision();
		$this->jobs = [];
		$this->selectionKey = '';
		$this->changed = true;
		$this->heightTextureRevision++;
		$this->uploadHeightMap();

		return $this->landscape;
	}

	/**
	 * Cancel staged meshes and return the candidate without taking its ownership.
	 * @return Landscape|null Canceled candidate, still owned by the caller, or null.
	 */
	public function cancelPreparedLandscape(): ?Landscape
	{
		$candidate = $this->preparing;

		$this->abandonSelection();
		foreach($this->replacements as $patch) $patch['mesh']->close();
		$this->preparing = null;
		$this->replacementJobs = $this->replacements = [];
		$this->replacementRegion = null;
		$this->selectionKey = '';
		$this->changed = true;

		return $candidate;
	}

	/**
	 * Read finished mesh samples without requesting more terrain work. The grid runs west to east, then north
	 * to south, including both edges. Paint bytes are 0 for natural, then rock, grass, sand, snow and road.
	 * @param string $key Quadtree key of the form level:x:z.
	 * @return array|null Heights and optional paint bytes, or null if this patch is not resident.
	 */
	public function surfacePatch(string $key): ?array
	{
		$patch = $this->patches[$key] ?? null;
		if($patch === null) return null;

		return ['heights' => $patch['heights'], 'materials' => $patch['materials'] ?? ''];
	}

	/**
	 * Upload the resident coarse terrain heights for shared water and atmospheric occlusion.
	 * @return void Updates the height lookup texture.
	 */
	private function uploadHeightMap(): void
	{
		if($this->useClipmap || $this->heightTexture === []) return;

		// A new recipe can also start at revision zero; every upload invalidates shadows.
		$this->heightTextureRevision++;
		$heights = $this->patches['0:0:0']['heights'] ?? array_fill(0, 1089, -100000);
		glBindTexture(GL_TEXTURE_2D, $this->heightTexture[0]);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
		glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
		glTexImage2D(GL_TEXTURE_2D, 0, GL_R32F, 33, 33, 0, GL_RED, GL_FLOAT, pack('f*', ...$heights));
	}

	/**
	 * Replace the landscape and invalidate its rendering cache.
	 * @param Landscape $landscape Authoritative committed terrain surface.
	 * @return void Replaces the source and restarts complete root coverage.
	 */
	public function setLandscape(Landscape $landscape): void
	{
		$this->clearPatches();
		$this->clipmap?->invalidate();
		$this->landscape = $landscape;
		$this->defaultLayers=null; $this->layerBinding=[];
		$this->lod = new Lod($landscape->recipe()['worldSize'], $landscape->recipe()['spacing']);
		$this->revision = -1;
		$this->selectionKey = '';
		$this->changed = true;
		$this->uploadHeightMap();
	}

	/**
	 * Set the spot lights, such as a vehicle's headlights, that light the ground and the water from the next render().
	 * Call it each frame the lamps move; an empty list clears them. Without lights render() draws ground and water
	 * programs built without the lamp code and uploads nothing for them. See SpotLights::set() for the keys and units:
	 * position in world meters, a nonzero direction, linear color, intensity in candela, range in meters, and inner
	 * and outer cone half-angles in radians. Lamp light gets SpotLights::NIGHT_GAIN at night.
	 * @param array $lights List of at most SpotLights::MAX lights.
	 * @return void Replaces the lights, or throws InvalidArgumentException and keeps the previous ones.
	 */
	public function setSpotLights(array $lights): void
	{
		($this->spotLights ??= new SpotLights)->set($lights);
	}

	/**
	 * Advance generation work without spending an unbounded frame on a terrain tile.
	 * @param float $seconds Elapsed display seconds.
	 * @param Camera $camera Current viewing camera.
	 * @return bool Whether rendering state changed or transitions remain active.
	 */
	public function update(float $seconds, Camera $camera): bool
	{
		if($this->closed) return false;

		$this->morphMemo = null;
		$started = microtime(true);
		$deadline = $started + max(.25, $this->budgetMs) / 1000;
		$this->updates++;
		$this->sliceMs = 0;
		$this->sliceSteps = 0;

		if($this->clipmap !== null) {
			$lut=$this->atmosphere->transmittanceLut(64,16,24);
			$lighting=$lut['generation'] !== $this->textureGeneration;
			if($lighting) $this->createTextures();
			$this->changed=$this->clipmap->update($this->landscape,$camera,$this->quality) || $lighting;
			if($this->revision !== $this->landscape->revision()) $this->heightTextureRevision++;
			$this->revision=$this->landscape->revision();
			$this->lastUpdateMs=(microtime(true)-$started)*1000;
			return $this->changed;
		}
		$this->checkPreparation();
		$this->trackMotion($seconds, $camera);
		$this->clock += max(0, $seconds);
		$this->changed = false;
		$lut = $this->atmosphere->transmittanceLut(64, 16, 24);

		if($lut['generation'] !== $this->textureGeneration)
		{
			$this->createTextures();
			$this->changed = true;
		}

		if($this->preparing !== null)
		{
			while($this->replacementJobs !== [] && microtime(true) < $deadline)
			{
				$id = array_key_first($this->replacementJobs);
				$job = &$this->replacementJobs[$id];
				if($this->buildVertex($job, $this->preparing, $this->replacements)) { unset($job); break; }

				if($job['index'] === 1089)
				{
					$job['mesh'] = new GpuPatch($job['bytes'], $this->indices, $this->gridBuffer[0]);
					unset($job['bytes']);
					$job['centerHeight'] = $job['heights'][544];
					$job['lastUse'] = $this->clock;
					$this->replacements[$id] = $job;
					unset($this->replacementJobs[$id], $job);
					break;
				}

				unset($job);
			}

			$this->lastUpdateMs = (microtime(true) - $started) * 1000;

			return false;
		}

		if($this->revision !== $this->landscape->revision())
		{
			$this->clearPatches();
			$this->revision = $this->landscape->revision();
			$this->selectionKey = '';
			$this->changed = true;
		}

		$key = implode(':', array_map(
			/** Quantize camera motion for selection invalidation.
		 * @param float $v One world coordinate.
		 * @return int Eight-meter position bucket.
		 */
			static fn($v) => (int)floor($v / 8)
			, $camera->position
		)) . ':' . $this->quality . ':' . $this->viewportHeight . ':' . $this->viewportAspect . ':' . $camera->fov
			. ':' . $this->water->level() . ':' . $this->water->waveScale()
			. ':' . (int)floor($camera->yaw * 16) . ':' . (int)floor($camera->pitch * 16) . ':' . (int)floor($camera->roll * 16)
			. ':' . ($this->lookahead === null
				? 'stopped'
				: implode(':', array_map(
					/** Quantize the predicted eye independently of current-view motion.
					 * @param float $value Predicted world coordinate.
					 * @return int Thirty-two-meter prediction bucket.
					 */
					static fn($value) => (int)floor($value / 32)
					, $this->lookahead
				))
			);

		$transitioning = $this->retiring !== [] || $this->clock < $this->transitionUntil;

		// A selection in flight whose view was relocated, or whose settings changed, would apply a cover chosen for
		// a view that is gone. Dropping it here lets the code below start its replacement in this same update.
		if($this->flight !== null && $this->flightOutdated($seconds, $camera))
		{
			$this->abandonSelection();
			$this->selectionsDropped++;
		}

		// A selection the limiter postponed stays wanted until it runs, exactly like a changed view.
		$viewChanged = $key !== $this->selectionKey || $this->selectionDeferred;
		$transitionDue = ($transitioning || $this->selectionPending) && $this->clock - $this->selectionTime > .15;
		$hold = $this->selectionInterval > 0 && $this->holdSelection($seconds, $camera);

		if($this->flight !== null)
		{
			// One selection runs at a time. A view that changed since this one started is owed the next.
			if($key !== $this->selectionKey) $this->selectionDeferred = true;

			// A slice turned off while a selection is in flight finishes that selection now, as it would have run.
			$this->advanceSelection($this->selectionSliceMs <= 0 && $this->selectionSliceSteps <= 0);
		}
		elseif($viewChanged && !$hold)
		{
			$this->select($camera);
			$this->selectionKey = $key;
		}

		$coverReady = false;
		while($this->jobs !== [] && microtime(true) < $deadline)
		{
			$id = array_key_first($this->jobs);
			$job = &$this->jobs[$id];
			if($this->buildVertex($job)) { unset($job); break; }

			if($job['index'] === (Lod::CELLS + 1) ** 2)
			{
				$job['mesh'] = new GpuPatch($job['bytes'], $this->indices, $this->gridBuffer[0]);
				unset($job['bytes']);
				$job['centerHeight'] = $job['heights'][16 * 33 + 16];
				$job['lastUse'] = $this->clock;
				$this->patches[$id] = $job;

				if($id === '0:0:0')
				{
					$this->uploadHeightMap();
					$this->changed = true;
				}

				$parentKey = $this->parentKey($job);
				unset($this->jobs[$id], $job);
				$next = reset($this->jobs);
				if($next === false || $this->parentKey($next) !== $parentKey) $coverReady = true;
				break;
			}

			unset($job);
		}

		// A new root must draw immediately. Otherwise combine transitions and completed
		// siblings in one selection, even when a moving camera also reordered work.
		if($coverReady && $this->draw === []) $this->select($camera);
		// The selection in flight read the patches before this group finished, so the group is owed the next one.
		// A due transition needs no note: it is worked out again on every update.
		elseif($this->flight !== null) $this->selectionDeferred = $this->selectionDeferred || $coverReady;
		// While the limiter holds, the changed view or finished group waits for the next allowed selection.
		elseif($hold) $this->selectionDeferred = $viewChanged || $coverReady;
		elseif(!$viewChanged && ($coverReady || $transitionDue)) $this->select($camera);
		// Emptying the key would make the next update select at once, which the limiter must be free to delay.
		elseif($coverReady && $this->selectionInterval > 0) $this->selectionDeferred = true;
		elseif($coverReady) $this->selectionKey = '';

		$this->lastUpdateMs = (microtime(true) - $started) * 1000;

		if($this->retiring !== [] || $this->clock < $this->transitionUntil) $this->changed = true;
		$this->evict();
		// The limiter measures its spacing on the scene clock, and that clock stands still while a host skips
		// updates. Asking for updates until the spacing has passed lets the first changed view after a rest
		// select at once, however soon after its last selection the scene had nothing else left to do.
		$this->selectionRecent = $this->selectionInterval > 0 && $seconds > 0
			&& $this->clock - $this->selectionTime < $this->selectionInterval - $seconds / 2;

		return $this->changed;
	}

	/**
	 * Decide whether the selection limiter makes this update's routine selection wait.
	 *
	 * A routine selection answers a changed view, a finished sibling group or a due transition, and one
	 * selection answers all three. While the camera pose has changed within the last selectionInterval
	 * seconds, routine selections run that far apart, so a fast vehicle stops selecting on nearly every
	 * frame. Whatever waits is answered by the next allowed selection, at most one interval later, so a
	 * finished group or a transition cannot starve behind a view that keeps moving. A selection spread over
	 * several updates delays that answer until it completes, because the next one starts only then. A view
	 * at rest for a whole interval is not limited. By then it has had a selection for its resting pose, and
	 * its finished groups and transitions select at once. So does the first changed view after the rest,
	 * because dirty() keeps the scene clock running for one interval after every selection.
	 *
	 * Four cases never wait, because the last selection is wrong for them, not merely late. Nothing is
	 * drawn yet. The landscape was replaced or republished, which empties the selection key. A setting that
	 * selection reads has changed. Or the eye was relocated: one update moved it farther than trackMotion()
	 * accepts as travel, or turned it by more than half the vertical field of view, which replaces a third
	 * of a 16:9 picture or more. A 300 m/s flight covers 5 m in a 60 Hz frame, so travel never comes near
	 * either limit. Boarding a vehicle, the home view and similar jumps cross them.
	 *
	 * An update of zero seconds never waits either. The scene clock stands still in it, so a host that
	 * updates a paused scene that way would keep a postponed selection waiting for as long as it stays paused.
	 * @param float $seconds Elapsed display seconds for this update.
	 * @param Camera $camera Current viewing camera.
	 * @return bool Whether routine selections must wait for a later update.
	 */
	private function holdSelection(float $seconds, Camera $camera): bool
	{
		$previous = $this->previousPose;
		$pose = $this->previousPose = [$camera->position, $camera->yaw, $camera->pitch, $camera->roll];
		$settings = $this->selectionSettings($camera);
		$adjusted = $settings !== $this->previousSettings;
		$this->previousSettings = $settings;

		// The first pose seen is not motion: a scene that starts with a resting camera is never limited.
		if($previous === null) return false;

		if($pose !== $previous) $this->motionTime = $this->clock;

		if($adjusted || $seconds <= 0 || $this->draw === [] || $this->selectionKey === '') return false;

		// Frames rarely land on the interval exactly. An update within half its own length of the interval
		// counts as on time, so 60 Hz frames select every sixth frame and never alternate between six and seven.
		$wait = $this->selectionInterval - $seconds / 2;

		if($this->clock - $this->motionTime >= $wait || $this->clock - $this->selectionTime >= $wait) return false;

		// From here the view is moving and the last selection is recent, so only a relocation selects now.
		return !$this->relocated($previous, $seconds, $camera);
	}

	/**
	 * List the settings that a selection reads besides the camera pose.
	 * @param Camera $camera Current viewing camera.
	 * @return array Detail, viewport height and aspect, field of view, water level and wave scale.
	 */
	private function selectionSettings(Camera $camera): array
	{
		return [$this->quality, $this->viewportHeight, $this->viewportAspect, $camera->fov
			, $this->water->level(), $this->water->waveScale()];
	}

	/**
	 * Decide whether one update relocated the eye rather than moved it.
	 *
	 * The distance limit is the one trackMotion() uses to drop its lookahead after a jump. The turn limit is
	 * half the vertical field of view, which replaces a third of a 16:9 picture or more.
	 * @param array $previous Eye position, yaw and pitch at the update before this one.
	 * @param float $seconds Elapsed display seconds for this update.
	 * @param Camera $camera Current viewing camera.
	 * @return bool Whether the eye moved farther than travel can or turned by more than half the field of view.
	 */
	private function relocated(array $previous, float $seconds, Camera $camera): bool
	{
		[$from, $yaw, $pitch, $roll] = $previous;
		$reach = 600 * $seconds;
		if($reach < 64) $reach = 64;
		$x = $camera->position[0] - $from[0];
		$y = $camera->position[1] - $from[1];
		$z = $camera->position[2] - $from[2];

		if($x * $x + $y * $y + $z * $z > $reach * $reach) return true;

		$turn = $camera->fov / 2;
		if(abs(atan2(sin($roll - $camera->roll),cos($roll - $camera->roll))) > $turn) return true;
		$pitch -= $camera->pitch;

		if($pitch > $turn || -$pitch > $turn) return true;

		$yaw -= $camera->yaw;
		if($yaw < 0) $yaw = -$yaw;
		// A yaw that wraps past a whole turn has not moved the view.
		if($yaw > M_PI)
		{
			$yaw = fmod($yaw, 2 * M_PI);
			if($yaw > M_PI) $yaw = 2 * M_PI - $yaw;
		}

		// Written as the negation, so that a yaw that is not a number counts as a relocation, as it always has.
		return !($yaw <= $turn);
	}

	/**
	 * Predict a short bounded flight corridor and discard stale motion after stops or teleports.
	 * @param float $seconds Elapsed display seconds, before simulation clamping.
	 * @param Camera $camera Current viewing camera.
	 * @return void Updates optional lookahead without performing terrain queries.
	 */
	private function trackMotion(float $seconds, Camera $camera): void
	{
		$previous = $this->previousEye;
		$this->previousEye = $camera->position;
		$this->lookahead = null;

		if($previous === null || $seconds <= 0 || $seconds > .25) return;

		$delta = [];

		for($axis = 0; $axis < 3; $axis++) $delta[$axis] = $camera->position[$axis] - $previous[$axis];
		$distance = sqrt($delta[0] ** 2 + $delta[1] ** 2 + $delta[2] ** 2);

		// Large jumps are relocation, not a reason to prepare a corridor across the world.
		if($distance < $seconds || $distance > max(64, 600 * $seconds)) return;

		$scale = min(1.5 / $seconds, 512 / $distance);
		// The padded current view already requests immediate neighbors. Reserve the
		// extra corridor traversals for travel beyond a finest patch's diagonal.
		if($distance * $scale <= M_SQRT2 * Lod::CELLS * $this->landscape->recipe()['spacing']) return;
		$half = $this->lod->extent / 2;
		$this->lookahead = [
			max(-$half, min($half, $camera->position[0] + $delta[0] * $scale))
			, $camera->position[1] + $delta[1] * $scale
			, max(-$half, min($half, $camera->position[2] + $delta[2] * $scale))
		];
	}

	/**
	 * Start choosing a complete resident cover and reserving bounded sibling-generation work.
	 *
	 * The selection runs as much as this update's slice allows, and advanceSelection() carries on with it in
	 * later updates. It runs whole when nothing is drawn yet: the picture stays empty until a selection
	 * completes, and a walk over an empty or root-only cache is short. It also runs whole while both slice
	 * settings are zero, and after update() dropped two selections in a row for a changed view or setting.
	 * @param Camera $camera Current viewing camera.
	 * @return void Starts a selection, which is complete on return when it runs whole.
	 */
	private function select(Camera $camera): void
	{
		// Selection work is timed with hrtime(), as in advanceSelection().
		$from = hrtime(true);
		$this->selections++;
		// The limiter counts its spacing from the start of a selection. While the view keeps moving, a selection
		// that takes longer than one interval is therefore followed at once by the next, never by a further wait.
		// Whatever was owed until now, this selection answers.
		$this->selectionTime = $this->clock;
		$this->selectionDeferred = false;

		// A one-patch margin leaves room for camera turns and the neighboring
		// splits needed for 2:1 seams. Out-of-view ground retains coarse coverage.
		// The selector applies that margin itself, to ground and water bounds alike, when it gets the
		// planes: a predicate here would cost a call and a copied node for every patch that wants to split.
		$planes = $camera->clipPlanes(
			max(1, (int)round($this->viewportHeight * $this->viewportAspect))
			, $this->viewportHeight
		);

		// Nothing drawn cannot wait for a slice. Nor can a cover whose last two selections were dropped for a changed
		// view or setting: a setting that keeps changing would drop every spread selection and hold the cover still
		// for as long as it changes. Running the third whole lets the cover follow such a change every third update,
		// where it used to follow it on every update.
		$this->flight = [
			'phase' => 'walk'
			, 'whole' => $this->draw === [] || ($this->selectionSliceMs <= 0 && $this->selectionSliceSteps <= 0)
				|| $this->selectionsDropped >= 2
			, 'task' => null
			, 'first' => $this->updates
			, 'pose' => [$camera->position, $camera->yaw, $camera->pitch, $camera->roll]
		];

		// Everything the walks and the work order read from the view is captured here, so a selection that runs
		// over several updates chooses the cover of the view it started with.
		$this->flight += [
			'camera' => clone $camera
			, 'settings' => $this->selectionSettings($camera)
			, 'planes' => $planes
			, 'water' => $this->water->heightRange()
			, 'quality' => $this->quality
			, 'projection' => $this->viewportHeight / (2 * tan($camera->fov / 2))
			, 'lookahead' => $this->lookahead
		];

		$this->sliceMs += (hrtime(true) - $from) / 1e6;
		$this->advanceSelection($this->flight['whole']);
	}

	/**
	 * Run phases of the selection in flight until it completes or this update's slice is spent.
	 *
	 * A selection has eight phases: the view walk, balance, apply, groups, the midpoint and endpoint walks of
	 * the lookahead, speculate and publish. The walks, groups and speculate pause after a slice of work. Balance,
	 * apply and publish run whole, and one of them starts in an update only when its last cost fits what is left
	 * of the slice, or when it is the first work of the update. An update therefore does its slice and at most
	 * one of those three phases more. Each walk keeps the resident set it started with and reads the camera,
	 * planes, projection, detail and water range that select() captured.
	 *
	 * Apply alone changes the drawn cover, in one update, through transition(). The phases before it change
	 * neither the draw list, the reservation nor the jobs, and the phases after it only reserve and queue
	 * work. The lookahead walks only feed speculative jobs, so they come after the cover and the current-view
	 * groups. When every phase runs in one call they run in the order select() always used, and the result is
	 * the one an unspread selection gives.
	 *
	 * Every drawn patch stays resident, and so does the parent of every queued job, which buildVertex() reads for
	 * the job's morph heights. evict() removes only patches outside the reservation, and whenever it runs, the
	 * reservation holds every drawn patch and its ancestors and the parent of every queued job:
	 *  - Until apply, the draw list, the jobs and the reservation are the ones the previous selection left together.
	 *  - Apply adds a changed cover and its ancestors to the reservation in the update that changes the draw
	 *    list, and groups reserves afresh from that cover, which has not changed since.
	 *  - Groups and speculate add what they reserve to the reservation in force and keep the rest of it, which the
	 *    jobs still queued rely on. Publish replaces the jobs and installs the new reservation alone in one step.
	 *  - prepareLandscape() and clearPatches() drop the selection in flight before they change either.
	 * The patches that apply draws are resident when it draws them, whatever left the cache after the walk read
	 * it. transition() splits a patch only into children resident at that moment, retires a group only to its
	 * parent, and coarsens only to ancestors of the drawn cover, which are reserved and so resident. A patch
	 * evicted while a selection is in flight is skipped by it in this way. Speculate asks again whether a parent
	 * is resident, and speculate and publish drop the wanted patches that arrived meanwhile, so none is built twice.
	 * @param bool $whole Whether to run every remaining phase now, whatever the slice.
	 * @return void Advances the selection in flight, and completes it when it fits.
	 */
	private function advanceSelection(bool $whole): void
	{
		// The slice is timed with hrtime(), not microtime(). In the browser's PHP, microtime() reads Date.now(),
		// which moves in whole milliseconds, coarser than the slice itself. hrtime() reads performance.now() there.
		// It is a float in that 32-bit build and an integer natively, so the readings stay untyped.
		$from = hrtime(true);
		$steps = $this->selectionSliceSteps;
		$left = $this->selectionSliceMs - $this->sliceMs;
		// A walk pauses after a number of refined nodes and a grouping task after units of work, one unit being
		// roughly a quarter of a microsecond of native work. Either pause comes after some thirty to fifty
		// microseconds of native work: often enough to keep near the slice, and seldom enough that looking at the
		// clock costs under one percent. With a slice in steps, each refined node or loop pass is one step.
		[$nodes, $units] = $whole ? [0, PHP_INT_MAX] : ($steps > 0 ? [1, 1] : [16, 128]);

		while($this->flight !== null)
		{
			$phase = $this->flight['phase'];
			$resumable = $phase !== 'balance' && $phase !== 'apply' && $phase !== 'publish';

			// Each update does at least one step, so every selection completes. A phase that cannot pause starts only
			// if it fits what is left of the slice, judged by the forecast below.
			if(!$whole && $this->sliceSteps > 0 && ($steps > 0
				? $this->sliceSteps >= $steps
				: (hrtime(true) - $from) / 1e6 + ($resumable ? 0.0 : $this->phaseCost[$phase] ?? 0.0) > $left
			)) break;

			if($resumable)
			{
				$credit = $phase === 'groups' || $phase === 'speculate' ? $units : $nodes;
				$task = $this->flight['task'];

				if($task === null)
				{
					$task = $this->flight['task'] = $this->startTask($phase, $credit);
					$task->current();
				}
				else $task->send($credit);

				$this->sliceSteps++;

				// The same task carries on while the slice lasts, without the round of the loop above.
				while($task->valid()
					&& ($steps > 0 ? $this->sliceSteps < $steps : (hrtime(true) - $from) / 1e6 < $left)
				){
					$task->send($credit);
					$this->sliceSteps++;
				}

				if(!$task->valid()) $this->finishTask($phase, $task->getReturn());

				continue;
			}

			$begin = hrtime(true);

			match($phase)
			{
				'balance' => $this->balancePhase()
				, 'apply' => $this->applyPhase()
				, 'publish' => $this->publishPhase()
			};

			$this->sliceSteps++;
			// The forecast follows a dearer run at once and a cheaper one slowly. One phase that ran long makes the
			// next few wait for an update of their own rather than overrun the one they would have shared.
			$cost = (hrtime(true) - $begin) / 1e6;
			$this->phaseCost[$phase] = max($cost, .75 * ($this->phaseCost[$phase] ?? 0.0));
		}

		$this->sliceMs += (hrtime(true) - $from) / 1e6;
	}

	/**
	 * Create the resumable task of a phase.
	 *
	 * The view walk refines inside the padded view with the captured planes and water range. The lookahead
	 * walks have no planes. Their eyes are the predicted position and the midpoint between it and the eye.
	 * The groups and speculate tasks are reserveView() and reserveCorridor().
	 * @param string $phase Phase that starts: walk, groups, midpoint, endpoint or speculate.
	 * @param int $credit Refined nodes or units of work before the first pause; see advanceSelection().
	 * @return \Generator Task that has not yet run.
	 */
	private function startTask(string $phase, int $credit): \Generator
	{
		if($phase === 'groups') return $this->reserveView($credit);

		if($phase === 'speculate') return $this->reserveCorridor($credit);

		if($phase === 'walk')
		{
			[$low, $high] = $this->flight['water'];

			return $this->lod->walk($this->flight['camera']->position, $this->patches, $this->flight['quality']
				, $this->flight['projection'], null, $this->flight['planes'], $low, $high, $credit);
		}

		$eye = $this->flight['lookahead'];

		if($phase === 'midpoint')
		{
			$position = $this->flight['camera']->position;
			$eye = [];

			for($axis = 0; $axis < 3; $axis++) $eye[] = ($position[$axis] + $this->flight['lookahead'][$axis]) / 2;
		}

		return $this->lod->walk($eye, $this->patches, $this->flight['quality'], $this->flight['projection']
			, credit: $credit);
	}

	/**
	 * Keep what a completed task found and move to the next phase.
	 * @param string $phase Phase whose task completed.
	 * @param array $result What the task returned.
	 * @return void Records the result in the selection in flight.
	 */
	private function finishTask(string $phase, array $result): void
	{
		$this->flight['task'] = null;

		if($phase === 'walk')
		{
			$this->flight['view'] = $result;
			$this->flight['phase'] = 'balance';

			return;
		}

		if($phase === 'speculate')
		{
			unset($this->flight['corridor']);
			$this->flight['wanted'] = $result;
			$this->flight['phase'] = 'publish';

			return;
		}

		if($phase !== 'groups')
		{
			// Speculate merges these with pauses. Merging them here would be one loop of several hundred passes.
			$this->flight['corridor'][] = $result['request'];
			$this->flight['phase'] = $phase === 'midpoint' ? 'endpoint' : 'speculate';

			return;
		}

		unset($this->flight['view']);
		$this->flight['wanted'] = $result;
		$this->flight['corridor'] = [];
		$corridor = $this->flight['lookahead'] !== null;
		$this->flight['phase'] = $corridor ? 'midpoint' : 'publish';
	}

	/**
	 * Balance the cover that the view walk chose, which becomes the target of the apply phase.
	 *
	 * Balancing a large cover is the longest phase that cannot pause: its passes depend on each other, and
	 * the cover it starts from has to stay whole until the last of them.
	 * @return void Records the balanced target and moves to the apply phase.
	 */
	private function balancePhase(): void
	{
		$this->flight['desired'] = $this->balance($this->flight['view']['draw']);
		$this->flight['phase'] = 'apply';
	}

	/**
	 * Move the drawn cover toward the balanced target, keep it within capacity and protect it from eviction.
	 * @return void Changes the draw list in one step and moves to the groups phase.
	 */
	private function applyPhase(): void
	{
		$old = array_column($this->draw, 'key');
		$desired = $this->flight['desired'];

		// Each split adds four resident nodes and replaces one leaf with four.
		$capacity = $this->capacity();
		$leafLimit = 1 + 3 * intdiv($capacity - 1, 4);
		while(count($desired) > $leafLimit)
		$desired = $this->balance($this->coarsen($desired));
		$this->transition($desired);

		while(count($this->draw) > $leafLimit)
		{
			$this->draw = $this->balance($this->coarsen($this->draw));
			$this->retiring = [];
		}

		$drawn = array_column($this->draw, 'key');
		$until = -1.0;

		foreach($drawn as $key)
		{
			$settles = ($this->activated[$key] ?? -1) + .5;
			if($settles > $until) $until = $settles;
		}

		$this->transitionUntil = $until;

		if($old !== $drawn)
		{
			$this->changed = true;
			$this->drawIndex = null;
			$this->forgetSeamsAround(array_merge(array_diff($old, $drawn), array_diff($drawn, $old)));
			if(count($this->groundCull) + count($this->waterCull) > 4096) $this->groundCull = $this->waterCull = [];
		}

		if($drawn !== $this->drawnKeys)
		{
			$this->drawnKeys = $drawn;
			$this->drawnBranches = $this->ancestors($this->draw);
			// Until groups reserves afresh, an update may end and evict. The new cover joins the old reservation so
			// that neither the patches just drawn nor the work already reserved can go. An unchanged cover is in
			// the reservation already: every reservation since it was drawn started from it, and the two places
			// that change the draw list outside a selection also empty this cache. A whole selection reaches
			// groups before any eviction and skips this.
			if(!$this->flight['whole']) $this->reserved += $this->drawnBranches;
		}

		// The frame drawn after this update reads everything above. What it does not read waits for the groups
		// task, which can pause.
		$this->flight['desired'] = $desired;
		$this->flight['drawn'] = $drawn;
		$this->flight['appliedAt'] = $this->clock;
		$this->flight['applied'] = $this->updates;
		$this->flight['phase'] = 'groups';
	}

	/**
	 * Reserve the missing sibling groups of the current view in order of importance.
	 *
	 * The new reservation is built apart. Until it is complete an eviction still sees the one in force, which holds
	 * the drawn cover and the work that the last selection reserved. A whole selection then installs it; a spread
	 * one adds it to the one in force and leaves publish to install it with the new jobs.
	 * @param int $credit Units of work before the first pause; see advanceSelection().
	 * @return \Generator Pauses with yield and returns the wanted patches of the current view, keyed by patch key.
	 */
	private function reserveView(int $credit): \Generator
	{
		$drawn = $this->flight['drawn'];
		// A loose array comparison ignores order, so counting each key compares the two covers as sets.
		// Sorting both lists of keys to compare them took thousands of string comparisons per selection.
		$this->selectionPending = array_count_values($drawn)
			!= array_count_values(array_column($this->flight['desired'], 'key'));
		$clock = $this->flight['appliedAt'];
		unset($this->flight['desired'], $this->flight['drawn']);

		// The drawn patches are reserved, so eviction does not read their last use until a later selection.
		foreach($drawn as $key)
		{
			if(--$credit <= 0) $credit = yield;

			$this->patches[$key]['lastUse'] = $clock;
		}

		$drawKeys = array_column($this->draw, null, 'key');
		$wanted = [];
		$view = $this->flight['view'];
		$requests = array_column($view['request'], null, 'key');

		// Retain ready siblings while their shared morph/neighbor transition is pending.
		// Otherwise reserving the next requests can evict a completed group before it draws.
		foreach($view['refine'] as $node)
		{
			if(($credit -= 2) <= 0) $credit = yield;

			if(isset($drawKeys[$node['key']]))
			foreach($this->lod->children($node) as $child)
			$requests[$child['key']] = $child + ['priority' => $node['priority']];
		}

		[$groups, $blockers, $credit] = yield from $this->viewGroups(
			$requests
			, $drawKeys
			, $this->flight['camera']
			, $credit
		);
		// The reservation starts from the drawn cover and its ancestors. It only grows from here, so its size is
		// counted once and then kept in step.
		$reservation = $this->drawnBranches;
		$reserved = count($reservation);
		$capacity = $this->capacity();

		foreach($groups as $parent => $nodes)
		{
			if(($credit -= 2) <= 0) $credit = yield;

			if($parent !== '' && !isset($drawKeys[$parent])) continue;

			if(($blockers[$parent] ?? []) !== []) continue;

			$extra = 0;

			foreach($nodes as $n) if(!isset($reservation[$n['key']])) $extra++;

			if($reserved + $extra > $capacity) continue;

			$reserved += $extra;

			foreach($nodes as $n)
			{
				$key = $n['key'];
				$reservation[$key] = true;
				if(!isset($this->patches[$key])) $wanted[$key] = $n + ['speculative' => false];
			}
		}

		// A whole selection meets no eviction before publish, so its reservation takes over here, as it always has.
		// A spread one leaves the jobs of the last selection queued until publish, and buildVertex() reads each
		// job's parent, which only the reservation in force may hold. So the new reservation joins that one here,
		// and publish installs it alone in the update that replaces the jobs.
		if($this->flight['whole']) $this->reserved = $reservation;
		else
		{
			$this->flight['reservation'] = $reservation;
			$this->reserved += $reservation;
		}

		return $wanted;
	}

	/**
	 * Reserve speculative sibling groups for the predicted corridor in the cache space left over.
	 *
	 * Prediction reserves only unused cache space after all current-view demand. The midpoint and endpoint
	 * walks cover the short swept corridor, and their patches are never drawn before the view asks for them.
	 * Each group it takes joins the reservation at once. A pause therefore leaves the reservation larger, never
	 * missing a patch that an eviction must keep.
	 * @param int $credit Units of work before the first pause; see advanceSelection().
	 * @return \Generator Pauses with yield and returns the wanted patches with the speculative ones added.
	 */
	private function reserveCorridor(int $credit): \Generator
	{
		$wanted = $this->flight['wanted'];
		$requests = [];

		// The midpoint's requests come first and the endpoint's replace them where both ask for a patch.
		foreach($this->flight['corridor'] as $list)
		foreach($list as $node)
		{
			if(--$credit <= 0) $credit = yield;

			if(!isset($wanted[$node['key']])) $requests[$node['key']] = $node;
		}

		// Patches that arrived since groups need no job. A whole selection has had no update in between.
		if(!$this->flight['whole']) foreach($wanted as $key => $n) if(isset($this->patches[$key])) unset($wanted[$key]);

		[$groups, $credit] = yield from $this->requestGroups($requests, $credit);
		// The budgets below count the reservation this selection builds. For a spread selection that is not yet the
		// one in force, which also keeps what the queued jobs need until publish.
		$whole = $this->flight['whole'];

		if($whole) $reservation = &$this->reserved;
		else $reservation = &$this->flight['reservation'];

		$reserved = count($reservation);
		$capacity = $this->capacity();
		$speculative = 0;

		foreach($groups as $parent => $nodes)
		{
			if(($credit -= 6) <= 0) $credit = yield;

			if($parent === '' || !isset($this->patches[$parent])) continue;

			// The keys counted below include the four siblings, so the unreserved siblings are a
			// floor for $extra. A group that breaks a budget on that floor alone is refused before
			// the rest of its keys are built. Once a budget is spent, that is every later group with
			// an unreserved sibling.
			$children = $this->lod->children($this->patches[$parent]);
			$floor = 0;

			foreach($children as $child) if(!isset($reservation[$child['key']])) $floor++;

			if($speculative + $floor > 32 || $reserved + $floor > $capacity) continue;

			$keys = $this->ancestors($children);
			$extra = count(array_diff_key($keys, $reservation));

			// Merging the keys into the reservation adds exactly the $extra that are new to it.
			if($speculative + $extra > 32 || $reserved + $extra > $capacity
				|| count($this->patches) + count($wanted) + count($nodes) > $capacity
			) continue;

			$speculative += $extra;
			$reserved += $extra;
			$reservation += $keys;

			// The parent of this group must survive every eviction until publish queues the group.
			if(!$whole) $this->reserved += $keys;

			foreach($nodes as $n) $wanted[$n['key']] = $n + ['speculative' => true];
		}

		return $wanted;
	}

	/**
	 * Replace the job list with the wanted patches, keep what is already built of them and release the rest.
	 * @return void Completes the selection in flight.
	 */
	private function publishPhase(): void
	{
		// The key caches gain an entry for every patch the view asks about. Residents are looked up again
		// on every selection, so a trim keeps their entries and drops the rest.
		if(count($this->parentKeys) > 4096)
		{
			$this->parentKeys = array_intersect_key($this->parentKeys, $this->patches);
			$this->coarserKeys = array_intersect_key($this->coarserKeys, $this->patches);
		}

		$wanted = $this->flight['wanted'];

		if(!$this->flight['whole']) foreach($wanted as $key => $n) if(isset($this->patches[$key])) unset($wanted[$key]);

		// Rebuild order on every selection while retaining partially generated payloads.
		// Current-view sibling groups always run before speculative work.
		$ordered = [];

		foreach($wanted as $n)
		$ordered[$n['key']] = $n + ($this->jobs[$n['key']] ?? ['index' => 0,'bytes' => '', 'heights' => []]);
		$this->jobs = $ordered;
		// The jobs and the reservation they were queued under change together, before the eviction below.
		if(!$this->flight['whole']) $this->reserved = $this->flight['reservation'];

		$this->evict();
		$this->selectionUpdates = $this->updates - $this->flight['first'] + 1;
		$this->selectionApplyUpdate = $this->flight['applied'] - $this->flight['first'] + 1;
		$this->selectionsCompleted++;
		$this->selectionsDropped = 0;
		$this->flight = null;
	}

	/**
	 * Drop the selection in flight, if any, and owe the view a new one.
	 * @return void Clears the staged selection and empties the selection key.
	 */
	private function abandonSelection(): void
	{
		if($this->flight === null) return;

		$this->flight = null;
		$this->selectionKey = '';
	}

	/**
	 * Decide whether the selection in flight was started for a view that is now gone.
	 *
	 * That is so when a setting it captured has changed, or when this update relocated the eye as the limiter
	 * defines it. Ordinary travel and turns only make the result a few updates late.
	 * @param float $seconds Elapsed display seconds for this update.
	 * @param Camera $camera Current viewing camera.
	 * @return bool Whether the selection in flight must be dropped.
	 */
	private function flightOutdated(float $seconds, Camera $camera): bool
	{
		$previous = $this->flight['pose'];
		$this->flight['pose'] = [$camera->position, $camera->yaw, $camera->pitch, $camera->roll];

		return $this->flight['settings'] !== $this->selectionSettings($camera)
			|| $this->relocated($previous, $seconds, $camera);
	}

	/**
	 * Keep siblings together and prioritize groups by projected current-view demand.
	 * @param array $requests Missing nodes with parent-group priority from the selector.
	 * @param int $credit Units of work before the first pause; see advanceSelection().
	 * @return \Generator Pauses with yield and returns the missing sibling groups ordered from greatest to least
	 *     importance, with the credit left.
	 */
	private function requestGroups(array $requests, int $credit): \Generator
	{
		$parents = &$this->parentKeys;
		$groups = $priority = [];

		foreach($requests as $node)
		{
			if(--$credit <= 0) $credit = yield;

			$key = $node['key'];
			$parent = $parents[$key] ??= self::parentOf($key);

			if(!isset($groups[$parent])) $priority[$parent] = $node['priority'];
			$groups[$parent][] = $node;
		}

		// A comparison callback costs a PHP call for every pair it ranks. arsort() compares each group's
		// first priority itself, ranks them as the callback did and keeps equal ones in their first order.
		arsort($priority);
		$ordered = [];

		foreach($priority as $parent => $first) $ordered[$parent] = $groups[$parent];

		return [$ordered, $credit];
	}

	/**
	 * Favor visible refinement and promote its coarser neighbors before unrelated detail.
	 *
	 * A group outside a conservative camera cone keeps its coverage and loses screen importance: its
	 * priority is scaled by one where the parent's bounds meet the cone, by a tenth beside it and by a
	 * fiftieth behind the camera.
	 * @param array $requests Current-view candidate siblings, including resident transition data.
	 * @param array $drawKeys Current complete cover indexed by patch key.
	 * @param Camera $camera Viewing direction and position used only to order work.
	 * @param int $credit Units of work before the first pause; see advanceSelection().
	 * @return \Generator Pauses with yield and returns the ordered groups, the coarser neighbors blocking each
	 *     direct split, and the credit left.
	 */
	private function viewGroups(array $requests, array $drawKeys, Camera $camera, int $credit): \Generator
	{
		[$groups, $credit] = yield from $this->requestGroups($requests, $credit);
		$priority = $blockers = $byLevel = [];
		[$forward] = $camera->basis();
		$eye = $camera->position;
		// The cone depends on the camera alone, so its slope is worked out once for all the groups.
		$halfAngle = atan(tan($camera->fov / 2) * max(1.0, $this->viewportAspect)) + .1;
		$slope = tan(min(1.4, $halfAngle));
		$deepest = 0;

		foreach($groups as $parent => $nodes)
		{
			if(($credit -= 8) <= 0) $credit = yield;

			if($parent === '')
			{
				$priority[$parent] = PHP_FLOAT_MAX;
				continue;
			}

			if(!isset($drawKeys[$parent]))
			{
				unset($groups[$parent]);
				continue;
			}

			$node = $drawKeys[$parent];
			$key = $node['key'];
			$patch = $this->patches[$key] ?? [];
			$x = $node['x'] + $node['size'] / 2 - $eye[0];
			$y = ($patch['centerHeight'] ?? 0) - $eye[1];
			$z = $node['z'] + $node['size'] / 2 - $eye[2];
			$along = $x * $forward[0] + $y * $forward[1] + $z * $forward[2];
			$radius = $node['size'] * .75 + ($patch['geometricError'] ?? 0);

			if($along + $radius < 0) $weight = .02;
			else
			{
				// Comparisons stand in for max() here: this runs for every group of every selection.
				$spread = $x ** 2 + $y ** 2 + $z ** 2 - $along * $along;
				$side = $spread <= 0 ? 0.0 : sqrt($spread);
				$weight = $side <= ($along <= 0 ? 0 : $along) * $slope + $radius ? 1.0 : .1;
			}

			$priority[$parent] = $nodes[0]['priority'] * $weight;
			$blocking = [];

			foreach($this->coarserKeys[$key] ??= self::coarserNeighborKeys($node) as $coarser)
			if(isset($drawKeys[$coarser])) $blocking[] = $coarser;

			$blockers[$parent] = $blocking;

			if($blocking === []) continue;

			$level = (int)$parent;
			$byLevel[$level][] = $parent;
			if($level > $deepest) $deepest = $level;
		}

		// Dependencies always point to a lower depth, so one deepest-first pass
		// propagates urgency through the entire balancing chain without cycles.
		for($level = $deepest; $level >= 0; $level--)
		foreach($byLevel[$level] ?? [] as $parent)
		foreach($blockers[$parent] as $blocker)
		if(isset($priority[$blocker]) && $priority[$blocker] < $priority[$parent])
		$priority[$blocker] = $priority[$parent];

		// Generate the highest visible or prerequisite demand first. $priority lists the surviving
		// groups in their current order, which arsort() keeps for equal priorities.
		arsort($priority);
		$ordered = [];

		foreach($priority as $parent => $propagated)
		{
			if(--$credit <= 0) $credit = yield;

			$nodes = $groups[$parent];

			// Siblings arrive carrying their group's priority, so only a group whose priority the view
			// or a blocked neighbor changed has its records copied.
			foreach($nodes as $i => $node)
			if($node['priority'] !== $propagated)
			{
				$node['priority'] = $propagated;
				$nodes[$i] = $node;
			}

			$ordered[$parent] = $nodes;
		}

		return [$ordered, $blockers, $credit];
	}

	/**
	 * List the keys of the coarser cells that touch a patch, in the order neighborsAt() visits them.
	 * @param array $node Quadtree patch metadata.
	 * @return array Keys of the up to three cells one level up that meet the patch at an edge or a corner.
	 */
	private static function coarserNeighborKeys(array $node): array
	{
		$level = $node['level'] - 1;

		if($level < 0) return [];

		$x = $node['ix']; $z = $node['iz'];
		$cx = $x >> 1; $cz = $z >> 1;
		$last = (1 << $level) - 1;
		$keys = [];

		for($nz = max(0, ($z - 1) >> 1); $nz <= min($last, ($z + 1) >> 1); $nz++)
		for($nx = max(0, ($x - 1) >> 1); $nx <= min($last, ($x + 1) >> 1); $nx++)
		if($nx !== $cx || $nz !== $cz) $keys[] = "$level:$nx:$nz";

		return $keys;
	}

	/**
	 * Look up the cells touching a node at an equal or coarser quadtree level.
	 * @param array $node Patch in the current leaf cover.
	 * @param array $leaves Complete cover indexed by quadtree key.
	 * @param int $level Neighbor depth to inspect.
	 * @return array Touching leaves indexed by key, including corner contacts.
	 */
	private static function neighborsAt(array $node, array $leaves, int $level): array
	{
		if($level < 0) return [];
		$shift = $node['level'] - $level;
		$x = $node['ix']; $z = $node['iz'];
		$cx = $x >> $shift; $cz = $z >> $shift;
		$last = (1 << $level) - 1;
		$result = [];
		for($nz = max(0, ($z - 1) >> $shift); $nz <= min($last, ($z + 1) >> $shift); $nz++)
		for($nx = max(0, ($x - 1) >> $shift); $nx <= min($last, ($x + 1) >> $shift); $nx++)
		{
			if($nx === $cx && $nz === $cz) continue;
			$key = "$level:$nx:$nz";
			if(isset($leaves[$key])) $result[$key] = $leaves[$key];
		}
		return $result;
	}

	/**
	 * Coarsen a complete cover until all touching leaves differ by at most one level.
	 * @param array $nodes Complete list of quadtree leaf patches.
	 * @return array Complete cover satisfying edge and corner balance.
	 */
	private function balance(array $nodes): array
	{
		if($nodes === []) return $nodes;

		// Index the leaves by level, row and column. Lod::node() builds every key from those three
		// integers, so they identify a leaf exactly as its key does, and a probe becomes three array
		// lookups in place of one "level:x:z" string composed for each candidate cell.
		$grid = [];

		foreach($nodes as $n) $grid[$n['level']][$n['iz']][$n['ix']] = true;

		// No leaf is coarser than this level. A replacement is always finer than the coarse
		// leaf that forces it, so this holds in every pass and no probe has to go further.
		$coarsest = min(array_keys($grid));

		// Most covers arrive balanced, so the first scan only has to learn whether any leaf is out
		// of balance. Until one is, it may skip a leaf whose probes another leaf is sure to repeat.
		$quick = true;

		// Coarsen, never request new data, until shared edges differ by at most one level.
		for($pass = count($nodes); $pass > 0;)
		{
			$replace = null;

			foreach($nodes as $a)
			{
				$level = $a['level'] - 2;

				if($level < $coarsest) continue;

				$x = $a['ix'];
				$z = $a['iz'];

				// Bit 1 of an index plus one is set exactly when the index is one of the middle two of
				// the four its grandparent spans. Such a leaf lies on neither grandparent edge of that axis.
				if(($x + 1) & 2)
				{
					// Strictly inside its grandparent on both axes, the leaf cannot touch that
					// ancestor's boundary or the boundary of any still larger ancestor.
					if(($z + 1) & 2) continue;

					// On one edge of its grandparent, a leaf probes only the cells across that edge,
					// here and at every coarser level. The sibling beside it along that edge lies on
					// the grandparent's corner and probes all of those cells among its own. While
					// that sibling is in the cover, skipping this leaf cannot hide an imbalance.
					if($quick && isset($grid[$level + 2][$z][$x ^ 1])) continue;
				}
				// The same holds on the other axis, where that sibling shares the column.
				elseif($quick && (($z + 1) & 2) && isset($grid[$level + 2][$z ^ 1][$x])) continue;

				// Track two cells per axis up the tree: the ancestor holding the leaf, and the ancestor
				// of the cell one step outward from the leaf. An even index can only lie on the low
				// edge of an ancestor and an odd index only on the high edge, so the step goes down
				// for even and up for odd. Where the two cells agree, no ancestor edge lies between.
				$cx = $x >> 2;
				$cz = $z >> 2;
				$nx = ($x + (($x & 1) << 1) - 1) >> 2;
				$nz = ($z + (($z & 1) << 1) - 1) >> 2;

				// Each level up halves all four indices, also after a probe that found nothing.
				for(; $level >= $coarsest; --$level, $cx >>= 1, $nx >>= 1, $cz >>= 1, $nz >>= 1)
				{
					if($nx === $cx)
					{
						// Once interior on both axes, the leaf stays interior under every larger ancestor.
						if($nz === $cz) break;

						if(!isset($grid[$level][$nz][$cx])) continue;
					}
					elseif($nz === $cz)
					{
						if(!isset($grid[$level][$cz][$nx])) continue;
					}
					elseif(!isset($grid[$level][$cz][$nx]) && !isset($grid[$level][$nz][$cx])
						&& !isset($grid[$level][$nz][$nx])
					) continue;

					// One touching leaf two or more levels coarser is enough to decide.
					$shift = $a['level'] - $level - 1;
					$replace = $this->lod->node($level + 1, $x >> $shift, $z >> $shift);
					break 2;
				}
			}

			if($replace === null) break;

			// The shortcut proves that some leaf is out of balance, but the leaf it stopped at need
			// not be the first one in the list, and the first one decides what is replaced and in
			// which order. Scan again without the shortcut. That scan does not use up a pass.
			if($quick)
			{
				$quick = false;
				continue;
			}

			// Keep patches outside the replacement subtree, and take the others out of the
			// index in the same loop so that no pass has to build the index again.
			$minX = $replace['x'];
			$minZ = $replace['z'];
			$maxX = $minX + $replace['size'];
			$maxZ = $minZ + $replace['size'];
			$kept = [];

			foreach($nodes as $n)
			{
				if($n['x'] >= $minX && $n['z'] >= $minZ
					&& $n['x'] + $n['size'] <= $maxX && $n['z'] + $n['size'] <= $maxZ
				) unset($grid[$n['level']][$n['iz']][$n['ix']]);
				else $kept[] = $n;
			}

			$kept[] = $replace;
			$grid[$replace['level']][$replace['iz']][$replace['ix']] = true;
			$nodes = $kept;
			$pass--;
		}

		return $nodes;
	}

	/**
	 * Include corner contacts when balancing and coordinating boundary transitions.
	 * @param array $a First quadtree patch.
	 * @param array $b Second quadtree patch.
	 * @return bool Whether patch boundaries meet at an edge or corner.
	 */
	private static function meets(array $a, array $b): bool
	{
		$level = max($a['level'], $b['level']);
		$sa = 1 << ($level - $a['level']); $sb = 1 << ($level - $b['level']);
		$x = $a['ix']*$sa - $b['ix']*$sb;
		$z = $a['iz']*$sa - $b['iz']*$sb;
		return $a['key'] !== $b['key'] && $x >= -$sa && $x <= $sb && $z >= -$sa && $z <= $sb;
	}

	/**
	 * Return a direct parent's stable key, or the empty string for the world root.
	 * @param array $node Quadtree patch metadata.
	 * @return string Parent key, or an empty string for the world root.
	 */
	private function parentKey(array $node): string
	{
		return $node['level'] === 0
			? ''
			: ($node['level'] - 1) . ':' . intdiv($node['ix'], 2) . ':' . intdiv($node['iz'], 2);
	}

	/**
	 * Derive a parent's key from a patch key alone, for callers that fill the parent-key cache.
	 * @param string $key Quadtree key in the level:x:z form that Lod::node() writes.
	 * @return string Parent key, or an empty string for the world root.
	 */
	private static function parentOf(string $key): string
	{
		[$level, $x, $z] = explode(':', $key);

		return $level === '0' ? '' : ((int)$level - 1) . ':' . ((int)$x >> 1) . ':' . ((int)$z >> 1);
	}

	/**
	 * Return every resident ancestor needed to preserve fallback coverage and parent heights.
	 * @param array $nodes Complete list of quadtree leaf patches.
	 * @return array Set of keys needed for coverage and parent interpolation.
	 */
	private function ancestors(array $nodes): array
	{
		$parents = &$this->parentKeys;
		// The root's parent is the empty key. Listing it stops each climb at the root with the same
		// test that stops it at an ancestor an earlier patch already listed.
		$result = ['' => true];

		foreach($nodes as $node)
		{
			$key = $node['key'];

			while(!isset($result[$key]))
			{
				$result[$key] = true;
				$key = $parents[$key] ??= self::parentOf($key);
			}
		}

		unset($result['']);

		return $result;
	}

	/**
	 * Coarsen one deepest subtree using an already resident parent.
	 * @param array $nodes Complete list of quadtree leaf patches.
	 * @return array Cover with one deepest subtree replaced by its parent.
	 */
	private function coarsen(array $nodes): array
	{
		if(count($nodes) <= 1) return $nodes;

		$deepest = $nodes[0];

		foreach($nodes as $node) if($node['level'] > $deepest['level']) $deepest = $node;
		$parent = $this->lod->node($deepest['level'] - 1, intdiv($deepest['ix'], 2), intdiv($deepest['iz'], 2));

		// Retain leaves outside the coarsened parent. This is the test contains() makes, written
		// out because a closure and a static call for every leaf cost more than the test itself.
		$level = $parent['level'];
		$x = $parent['ix'];
		$z = $parent['iz'];
		$kept = [];

		foreach($nodes as $node)
		{
			$shift = $node['level'] - $level;

			if($shift < 0 || ($node['ix'] >> $shift) !== $x || ($node['iz'] >> $shift) !== $z) $kept[] = $node;
		}

		$kept[] = $parent;
		$this->activated[$parent['key']] = $this->clock - .35;

		return $kept;
	}

	/**
	 * Test complete spatial containment without floating-point distance heuristics.
	 * @param array $outer Potential containing patch.
	 * @param array $inner Potential contained patch.
	 * @return bool Whether the outer patch contains the entire inner patch.
	 */
	private static function contains(array $outer, array $inner): bool
	{
		$shift = $inner['level'] - $outer['level'];
		return $shift >= 0 && ($inner['ix'] >> $shift) === $outer['ix'] && ($inner['iz'] >> $shift) === $outer['iz'];
	}

	/**
	 * Advance reversible whole-sibling transitions while retaining a complete 2:1 cover.
	 * @param array $desired Desired complete resident leaf cover.
	 * @return void Updates sibling transitions without exposing partial coverage.
	 */
	private function transition(array $desired): void
	{
		if($this->draw === [])
		{
			if(isset($this->patches['0:0:0']))
			{
				$this->draw = [$this->lod->node()];
				$this->activated['0:0:0'] = $this->clock - .35;
			}

			return;
		}

		$nodes = array_column($this->draw, null, 'key');

		foreach($this->retiring as $key => $group)
		{
			if($this->clock - $group['start'] < .35) continue;

			foreach($group['children'] as $child) unset($nodes[$child['key']]);
			$nodes[$key] = $group['parent'];
			$this->activated[$key] = $this->clock - .35;
			unset($this->retiring[$key]);
		}

		// Only a patch that the target does not keep as a leaf can retire or split. Those are a few among
		// hundreds, so both passes below visit them alone.
		$target = array_column($desired, 'key');
		$unsettled = array_diff_key($nodes, array_flip($target));
		$splitting = $tried = [];

		if($unsettled !== [] && $target !== $this->targetKeys)
		{
			$this->targetKeys = $target;
			$this->targetBranches = $this->ancestors($desired);
		}

		foreach($unsettled as $node)
		{
			$nodeKey = $node['key'];

			// The target refines below this patch, so it is a candidate to split in the second pass.
			if(isset($this->targetBranches[$nodeKey]))
			{
				$splitting[] = $node;
				continue;
			}

			if($node['level'] === 0) continue;

			$key = $this->parentKeys[$nodeKey] ??= self::parentOf($nodeKey);

			// Nothing this pass changes can alter a group's answer, so its first sibling settles it for
			// all four. A group held back by a finer neighbor used to be scanned again for each of them.
			if(isset($tried[$key]) || isset($this->retiring[$key])) continue;

			$tried[$key] = true;
			$parent = $this->lod->node($node['level'] - 1, intdiv($node['ix'], 2), intdiv($node['iz'], 2));
			$children = $this->lod->children($parent);
			$ready = true;

			foreach($children as $child)
			if(!isset($nodes[$child['key']]) || $this->morph($child) > 0)
			{
				$ready = false;
				break;
			}

			if(!$ready) continue;

			$level = $parent['level'];

			// A patch two or more levels finer that touches the parent from outside would break the 2:1
			// cover. Measured in that patch's own cells the parent spans 0 to $span - 1 on each axis, so
			// such a patch lies within one cell of the span and not inside it. The comparisons are written
			// out because this loop visits every drawn patch, and two calls for each cost more than the
			// rest of the pass.
			foreach($nodes as $neighbor)
			{
				$shift = $neighbor['level'] - $level;

				if($shift < 2) continue;

				$span = 1 << $shift;
				$x = $neighbor['ix'] - $parent['ix'] * $span;
				$z = $neighbor['iz'] - $parent['iz'] * $span;

				if($x >= -1 && $x <= $span && $z >= -1 && $z <= $span
					&& ($x < 0 || $x >= $span || $z < 0 || $z >= $span)
				){
					$ready = false;
					break;
				}
			}

			if($ready) $this->retiring[$key] = ['parent' => $parent, 'children' => $children, 'start' => $this->clock];
		}

		foreach($splitting as $node)
		{
			$nodeKey = $node['key'];

			if($this->morph($node) > 0
				|| isset($this->retiring[$this->parentKeys[$nodeKey] ??= self::parentOf($nodeKey)])
			) continue;

			$children = $this->lod->children($node);
			$ready = true;

			foreach($children as $child)
			if(!isset($this->patches[$child['key']]))
			{
				$ready = false;
				break;
			}

			if(!$ready) continue;

			// A coarser patch beside this one has to split first.
			foreach($this->coarserKeys[$nodeKey] ??= self::coarserNeighborKeys($node) as $coarser)
			if(isset($nodes[$coarser])) continue 2;

			foreach(self::neighborsAt($node, $nodes, $node['level']) as $neighbor)
			{
				$key = $neighbor['key'];

				if($this->morph($neighbor) > 0
					|| isset($this->retiring[$this->parentKeys[$key] ??= self::parentOf($key)])
				){
					$ready = false;
					break;
				}
			}

			if(!$ready) continue;

			unset($nodes[$nodeKey]);

			foreach($children as $child)
			{
				$nodes[$child['key']] = $child;
				$this->activated[$child['key']] = $this->clock;
			}
		}

		$this->draw = array_values($nodes);
	}

	/**
	 * Return the common scalar transition for a patch's sibling group.
	 * @param array $node Quadtree patch metadata.
	 * @return float Parent blend weight between zero and one.
	 */
	private function morph(array $node): float
	{
		$key = $node['key'];
		if($this->morphMemo !== null && isset($this->morphMemo[$key])) return $this->morphMemo[$key];

		// Only a retiring sibling group needs its parent's key, and building that string is the costly part,
		// so each key's parent is kept once it has been built.
		if($this->retiring !== []
			&& isset($this->retiring[$parent = $this->parentKeys[$key] ??= self::parentOf($key)])
		){
			$value = (float)min(1, max(0, ($this->clock - $this->retiring[$parent]['start']) / .35));
		}
		else
		{
			$since = $this->clock - ($this->activated[$key] ?? ($this->clock - .35));
			$value = (float)min(1, max(0, 1 - $since / .35));
		}

		if($this->morphMemo !== null) $this->morphMemo[$key] = $value;

		return $value;
	}

	/**
	 * Coordinate edge and corner weights, including neighbors activated on earlier frames.
	 * @param array $node Quadtree patch metadata.
	 * @return array Shared edge weights, corner weights and the coarse-neighbor index mask.
	 */
	private function boundaryMorph(array $node): array
	{
		$key = $node['key'];
		$settled = $this->settledBoundary($key);
		if($settled !== null) return $settled;

		$own = $this->morph($node);
		$ownParent = $this->parentKey($node);
		$settled = $own <= 0.0 && !isset($this->retiring[$ownParent]);
		$parents = [$ownParent => true];
		$edges = $corners = [$own,$own,$own,$own];
		$mask = 0;

		$neighbors = &$this->neighbors[$key];
		if($neighbors === null)
		{
			$this->drawIndex ??= array_column($this->draw, null, 'key');
			$neighbors = [];

			// Where a neighbor meets this patch is fixed for one draw set, so find the edge and corners it
			// touches once. Only its transition weight changes from frame to frame.
			foreach(self::neighborsAt($node, $this->drawIndex, $node['level'])
				+ self::neighborsAt($node, $this->drawIndex, $node['level'] - 1) as $neighbor)
			{
				// Locate the neighbor in this patch's integer coordinates. Fractional
				// world extents must produce the same topology as power-of-two worlds.
				$span = 1 << ($node['level'] - $neighbor['level']);
				$x = $neighbor['ix']*$span - $node['ix'];
				$z = $neighbor['iz']*$span - $node['iz'];
				$edge = -1;
				if($z < 1 && $z+$span > 0) $edge = $x < 0 ? 0 : 1;
				elseif($x < 1 && $x+$span > 0) $edge = $z < 0 ? 2 : 3;
				$touched = [];

				foreach([[0,0],[1,0],[0,1],[1,1]] as $i => [$px,$pz])
				if($px >= $x && $px <= $x+$span && $pz >= $z && $pz <= $z+$span) $touched[] = $i;

				$coarser = $neighbor['level'] < $node['level'];
				$neighbors[] = [$neighbor, $coarser, $edge, $touched, $this->parentKey($neighbor)];
			}
		}
		foreach($neighbors as [$neighbor, $coarser, $edge, $touched, $parent])
		{
			$weight = $coarser ? 1.0 : $this->morph($neighbor);

			// A group that has just begun to retire still has weight zero on its first frame.
			if(!$coarser)
			{
				$parents[$parent] = true;
				if($weight > 0.0 || isset($this->retiring[$parent])) $settled = false;
			}

			if($edge >= 0)
			{
				if($weight > $edges[$edge]) $edges[$edge] = $weight;
				if($coarser) $mask |= 1 << $edge;
			}

			foreach($touched as $i) if($weight > $corners[$i]) $corners[$i] = $weight;
		}
		unset($neighbors);

		if($settled)
		{
			$this->settledBoundaries[$key] = [$edges,$corners,$mask];
			$this->settledParents[$key] = array_keys($parents);
		}
		return [$edges,$corners,$mask];
	}

	/**
	 * Drop the cached neighbors and seams that adding or removing these patches can change.
	 *
	 * A patch reads only the drawn patches beside it at its own level and one level coarser. So a patch that
	 * enters or leaves the draw set matters to the eight around it at its level and to the finer patches
	 * along its edges, and to no others. Everything else keeps its cached seam through the change. Clearing
	 * the whole cache instead made each selection in a moving view recompute every seam.
	 * @param array $keys Quadtree keys that entered or left the draw set.
	 * @return void Forgets the affected cache entries.
	 */
	private function forgetSeamsAround(array $keys): void
	{
		// This runs in the update that changes the cover, which cannot pause, for 25 keys around every patch that
		// entered or left. Most of those keys name no drawn patch. boundaryMorph() files every patch it settles
		// under neighbors first, so one lookup there decides whether any of the three caches holds the key, and
		// a column prefix shared by the keys of one column saves building each key from four parts.
		foreach($keys as $key)
		{
			[$level, $x, $z] = explode(':', $key);
			$level = (int)$level; $x = (int)$x; $z = (int)$z;

			for($nx = $x - 1; $nx <= $x + 1; $nx++)
			{
				$column = $level . ':' . $nx . ':';

				for($nz = $z - 1; $nz <= $z + 1; $nz++)
				if(isset($this->neighbors[$near = $column . $nz]))
				unset($this->neighbors[$near], $this->settledBoundaries[$near], $this->settledParents[$near]);
			}

			$fine = $level + 1;

			for($nx = 2 * $x - 1; $nx <= 2 * $x + 2; $nx++)
			{
				$column = $fine . ':' . $nx . ':';

				for($nz = 2 * $z - 1; $nz <= 2 * $z + 2; $nz++)
				if(isset($this->neighbors[$near = $column . $nz]))
				unset($this->neighbors[$near], $this->settledBoundaries[$near], $this->settledParents[$near]);
			}
		}
	}

	/**
	 * Return a patch's cached seam weights while they still hold.
	 *
	 * A seam is cached once the patch and its same-level neighbors have finished their transitions. From
	 * then on it can change in only two ways. A new draw set clears the cache. A sibling group that starts
	 * to retire blends the patches inside it, so the entry is refused, and dropped, while the parent of
	 * the patch or of one of those neighbors is retiring. Patches away from a retirement keep their entry;
	 * previously one retirement anywhere made every patch recompute its seams on every frame.
	 * @param string $key Quadtree key of a drawn patch.
	 * @return array|null Edge weights, corner weights and coarse-neighbor mask, or null when they must be recomputed.
	 */
	private function settledBoundary(string $key): ?array
	{
		if(!isset($this->settledBoundaries[$key])) return null;
		if($this->retiring === []) return $this->settledBoundaries[$key];

		foreach($this->settledParents[$key] as $parent)
		if(isset($this->retiring[$parent]))
		{
			unset($this->settledBoundaries[$key], $this->settledParents[$key]);

			return null;
		}

		return $this->settledBoundaries[$key];
	}

	/**
	 * Bound live meshes plus reserved generation and replacement jobs.
	 * @return int Hard upper limit on resident and pending patch slots.
	 */
	private function capacity(): int { return max(1, min(512, $this->maximumPatches)); }

	/**
	 * Include the parent's triangle footprint when changing stored parent-morph heights.
	 * @param array|null $region Optional horizontal dirty rectangle; actual changes are also derived.
	 * @return array Resident patches affected by edits or parent interpolation changes.
	 */
	private function affected(?array $region): array
	{
		if($region === []) return [];

		$result = [];

		foreach($this->patches as $key => $patch)
		{
			$node = $this->lod->node($patch['level'], $patch['ix'], $patch['iz']);
			$halo = max(2 * $this->landscape->recipe()['spacing'], 2 * $node['size'] / Lod::CELLS);

			if($region !== null && ($node['x'] > $region[2] + $halo || $node['x'] + $node['size'] < $region[0] - $halo
				|| $node['z'] > $region[3] + $halo || $node['z'] + $node['size'] < $region[1] - $halo
			)) continue;

			$result[$key] = $node;
		}

		return $result;
	}

	/**
	 * Abort rather than publish meshes computed from mixed source/candidate revisions.
	 * @return void Rejects and cancels any mixed-revision preparation.
	 */
	private function checkPreparation(): void
	{
		if($this->preparing !== null && ($this->preparing->revision() !== $this->preparingRevision
			|| $this->landscape->revision() !== $this->preparingSourceRevision || $this->preparing->stats()['closed']
			|| $this->landscape->stats()['closed']
		)){
			$this->cancelPreparedLandscape();
			throw new \LogicException('Terrain changed during staged preparation');
		}
	}

	/**
	 * Advance one height-sampling or visual-normal packing unit for an authoritative mesh.
	 * @param array $job Mutable incremental mesh generation record.
	 * @param Landscape|null $surface Candidate surface override, or the live landscape.
	 * @param array|null $parents Available parent patch data, including staged replacements.
	 * @return bool Whether to postpone the remaining work.
	 */
	private function buildVertex(array &$job, ?Landscape $surface = null, ?array $parents = null): bool
	{
		$source = $surface ?? $this->landscape;
		$stride = Lod::CELLS + 1;
		$vertices = $stride * $stride;
		$sample = $job['sampleIndex'] ?? 0;
		$step = $job['size'] / Lod::CELLS;
		$half = $source->recipe()['worldSize'] / 2;

		$parentPatch = null;
		if($job['level'] > 0)
		{
			$key = $this->parentKey($job);
			$parentPatch = $parents[$key] ?? $this->patches[$key] ?? null;
		}


		// Pass one samples only this mesh lattice and a shared one-node normal halo.
		// A single call performs one height query, never a complete patch allocation.
		if($sample < $vertices + 4 * $stride)
		{
			$job['sampleIndex'] = $sample + 1;

			if($sample < $vertices)
			{
				$x = $sample % $stride;
				$z = intdiv($sample, $stride);
				$wx = $job['x'] + $x*$step; $wz = $job['z'] + $z*$step;
				$px = ($job['ix']%2)*16 + intdiv($x,2);
				$pz = ($job['iz']%2)*16 + intdiv($z,2);
				if($parentPatch && !($x%2) && !($z%2)
					&& $wx === $parentPatch['x'] + $px*($parentPatch['size']/32)
					&& $wz === $parentPatch['z'] + $pz*($parentPatch['size']/32))
					$job['heights'][] = $parentPatch['heights'][$pz*33+$px];
				else $job['heights'][] = $source->meshHeight($wx,$wz,$step);
			}
			else
			{
				$side = intdiv($sample - $vertices, $stride);
				$offset = ($sample - $vertices) % $stride;
				[$x,$z] = match($side)
				{
					0 => [-1,$offset], 1 => [$stride,$offset], 2 => [$offset,-1], 3 => [$offset,$stride]
				};

				$job['halo'][$side][$offset] = $source->meshHeight(
					max(-$half, min($half, $job['x'] + $x * $step))
					, max(-$half, min($half, $job['z'] + $z * $step)), $step
				);
			}

			return false;
		}

		// Pass two derives visual normals from these exact mesh samples. Physics still
		// uses Landscape::sample() and its authoritative one-meter reconstruction.
		$i = $job['index']++;
		$x = $i % $stride;
		$z = intdiv($i, $stride);
		$step = $job['size'] / Lod::CELLS;
		$wx = $job['x'] + $x * $step;
		$wz = $job['z'] + $z * $step;
		$h = $job['heights'][$i];
		$left = $x > 0 ? $job['heights'][$i - 1] : $job['halo'][0][$z];
		$right = $x < Lod::CELLS ? $job['heights'][$i + 1] : $job['halo'][1][$z];
		$front = $z > 0 ? $job['heights'][$i - $stride] : $job['halo'][2][$x];
		$back = $z < Lod::CELLS ? $job['heights'][$i + $stride] : $job['halo'][3][$x];
		$dx = ($right - $left) / (min($half, $wx + $step) - max(-$half, $wx - $step));
		$dz = ($back - $front) / (min($half, $wz + $step) - max(-$half, $wz - $step));
		$length = sqrt(1 + $dx * $dx + $dz * $dz);
		$normal = [-$dx / $length, 1 / $length, -$dz / $length];
		$job['normals'] ??= '';
		$job['normals'] .= pack('f*', ...$normal);
		$parent = $h;
		$parentNormal = $normal;

		if($job['level'] > 0)
		{
			$p = $parentPatch['heights'] ?? null;

			if($p !== null)
			{
				$px = ($job['ix'] % 2) * 16 + $x / 2;
				$pz = ($job['iz'] % 2) * 16 + $z / 2;
				$cx = min(31, (int)floor($px));
				$cz = min(31, (int)floor($pz));
				$fx = $px - $cx;
				$fz = $pz - $cz;
				$a = $cz * 33 + $cx;
				$parent = $fx + $fz <= 1
					? $p[$a] + ($p[$a + 1] - $p[$a]) * $fx + ($p[$a + 33] - $p[$a]) * $fz
					: $p[$a + 34] + ($p[$a + 33] - $p[$a + 34]) * (1 - $fx) + ($p[$a + 1] - $p[$a + 34]) * (1 - $fz);

				if(isset($parentPatch['normals']))
				{
					$indices = $fx + $fz <= 1 ? [$a,$a + 1,$a + 33] : [$a + 34,$a + 33,$a + 1];
					$weights = $fx + $fz <= 1 ? [1 - $fx - $fz,$fx,$fz] : [$fx + $fz - 1,1 - $fx,1 - $fz];
					$parentNormal = [0.0,0.0,0.0];

					foreach($indices as $index => $vertex)
					{
						$parentVertexNormal = array_values(
							unpack('f3', $parentPatch['normals'], $vertex * 12)
						);

						for($axis = 0; $axis < 3; $axis++)
						$parentNormal[$axis] += $weights[$index] * $parentVertexNormal[$axis];
					}
				}
			}
		}

		$material = array_search($source->material($wx, $wz), ['rock','grass','sand','snow','road'], true);
		if($material !== false)
		{
			$job['materials'] ??= str_repeat("\0", $vertices);
			$job['materials'][$i] = chr(1 + $material);
		}
		$job['geometricError'] = max($job['geometricError'] ?? 0.0, abs($h - $parent));

		if($x>0 && $x<32 && $z>0 && $z<32)
		{
			$axial = abs($left-2*$h+$right) + abs($front-2*$h+$back);
			$mixed = abs($h-$right-$back+$job['heights'][$i+34]);
			$job['geometricError'] = max($job['geometricError'], .5*$axial+$mixed);
		}
		$job['minY'] = min($job['minY'] ?? INF,$h,$parent);
		$job['maxY'] = max($job['maxY'] ?? -INF,$h,$parent);

		$job['bytes'] .= pack('f*', ...[
			$x * $step
			, $h
			, $z * $step
			, ...$normal
			, $parent
			, $material === false ? -1 : $material, ...$parentNormal
		]);

		if($job['index'] === $vertices) unset($job['halo']);
		return false;
	}

	/**
	 * Release unreserved mesh resources while honoring the shared generation capacity.
	 * @param bool $allUnreserved Whether to evict all unreserved patches immediately.
	 * @return void Releases every permitted excess patch.
	 */
	private function evict(bool $allUnreserved = false): void
	{
		$limit = $this->capacity() - count($this->jobs) - count($this->replacementJobs) - count($this->replacements);
		$excess = count($this->patches) - $limit;

		if(!$allUnreserved && $excess <= 0) return;

		// Order the patches by last use. A comparison callback costs a PHP call for every pair it ranks,
		// a few thousand for a full cache. asort() compares the times itself, ranks them as the callback
		// did and keeps equal ones in their current order.
		$lastUse = [];

		foreach($this->patches as $key => $p) $lastUse[$key] = $p['lastUse'];

		asort($lastUse);
		$this->patches = array_replace($lastUse, $this->patches);

		foreach($this->patches as $key => $p)
		{
			if(!$allUnreserved && $excess <= 0) break;

			if(isset($this->reserved[$key])) continue;

			$p['mesh']->close();
			unset($this->patches[$key], $this->activated[$key]);
			$excess--;
		}
	}

	/**
	 * Bind a patch's common grid and transition and select its watertight edge topology.
	 * @param int $uPatch Location of the program's uPatch uniform.
	 * @param int $uEdges Location of the program's uEdges uniform.
	 * @param int $uCorners Location of the program's uCorners uniform.
	 * @param array $node Visible quadtree leaf.
	 * @param array|null $lastBoundary Previous boundary uniforms on this program.
	 * @return int Index pattern connecting fine edges to their coarser neighbors.
	 */
	private function bindPatch(int $uPatch, int $uEdges, int $uCorners, array $node, ?array &$lastBoundary): int
	{
		$half = (1 << $node['level']) * (Lod::CELLS / 2);
		// A settled seam means the patch itself finished its transition, so its own weight is zero.
		$boundary = $this->settledBoundary($node['key']);
		glUniform4f($uPatch, $node['ix']*Lod::CELLS-$half, $node['iz']*Lod::CELLS-$half,
			$node['size']/Lod::CELLS, $boundary === null ? $this->morph($node) : 0.0);
		$boundary ??= $this->boundaryMorph($node);
		if($lastBoundary !== $boundary)
		{
			glUniform4f($uEdges, ...$boundary[0]);
			glUniform4f($uCorners, ...$boundary[1]);
			$lastBoundary = $boundary;
		}
		return $boundary[2];
	}

	/**
	 * Draw the sky, terrain and physical wave surface into the caller's framebuffer.
	 * @param Camera $camera Current viewing camera.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param float $time Interpolated simulation time in seconds.
	 * @param array|null $craftState Optional demonstration hull pose.
	 * @param callable|null $drawOpaque Draw additional depth-tested opaque objects before the water surface.
	 * @return void Submits draws without presenting the caller-owned framebuffer.
	 */
	public function render(Camera $camera, int $width, int $height, float $time, ?array $craftState = null, ?callable $drawOpaque = null): void
	{
		if($this->closed) return;

		$this->viewportHeight = max(1, $height);
		$this->viewportAspect = $width / max(1, $height);
		$this->frames++;
		// Nothing below changes a transition, so each patch's weight is worked out once for both passes.
		$this->morphMemo = [];
		$this->atmosphereCache->update($camera->position[1], $this->textures[0], $this->water->level());

		$environment = $this->atmosphere->uniforms();
		$bounds = $this->landscape->bounds();
		$this->clipmap?->updateShadows($camera->position,$environment['sunDirection'],$this->heightTextureRevision);
		$this->terrainShadows->update(
			$this->clipmap ? $this->clipmap->overview()[0] : (isset($this->patches['0:0:0']) ? $this->heightTexture[0] : 0)
			, $this->lod->extent
			, $environment['sunDirection']
			, $this->heightTextureRevision, $this->clipmap?->packed() ?? false, $bounds['minY'], $bounds['maxY']
		);

		glViewport(0, 0, $width, $height);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_BLEND);
		glDepthFunc(GL_LESS);
		glDepthMask(true);
		glEnable(GL_DEPTH_TEST);
		glEnable(GL_CULL_FACE);

		// Both pairs of programs are linked up front, and each lit program draws once in the first frame with patches
		// (litDrawn), so changing pair later costs no more than drawing with the same one.
		$palette = $this->palette();
		$this->selectMaterial($palette);
		$lit = ($this->spotLights?->count() ?? 0) > 0;
		$warm = $this->clipmap !== null || $this->draw !== [];
		$groundProgram = $lit || $warm && !$this->litDrawn['ground'] ? $this->litGroundProgram : $this->groundProgram;
		$waterProgram = $lit || $warm && !$this->litDrawn['water'] ? $this->litWaterProgram : $this->waterProgram;
		$groundProgram->use();
		$this->common($groundProgram, $camera, $width, $height, $environment);
		$this->water->appearance->bind($groundProgram);

		$id = spl_object_id($groundProgram);
		$paint = $this->paint ?? $this->appearance;
		if(($this->boundPaint[$id] ?? null) !== $paint) {
			$paint->bindPaint($groundProgram);
			$this->boundPaint[$id] = $paint;
		}
		if(($this->layerBinding[$id] ?? null) !== $palette) {
			$palette->bind($groundProgram);
			$seed = crc32((string)$this->landscape->recipe()['seed']);
			glUniform2f($groundProgram->location('uPaletteOffset'),$seed & 65535,($seed >> 16) & 65535);
			$this->layerBinding[$id] = $palette;
		}
		$this->vector($groundProgram, 'uCraftRotation', [0,0,0]);
		$this->scalar($groundProgram, 'uCraft', 0);

		$planes = $camera->clipPlanes($width,$height);
		$eye = $camera->position;
		$this->groundDraws = $this->waterDraws = 0;
		$lastBoundary = null;
		$uPatch = $groundProgram->location('uPatch');
		$uEdges = $groundProgram->location('uEdges');
		$uCorners = $groundProgram->location('uCorners');
		if($this->clipmap) {
			$recipe = $this->landscape->recipe();
			$floor = $bounds['minY'];
			$ceiling = $bounds['maxY'];
			if($recipe['surround'] === 'sea') $floor = min($floor, $recipe['waterLevel'] - 200);
			if($recipe['surround'] === 'mountains') {
				$floor = min($floor, -3 * $recipe['heightScale']);
				$ceiling = max($ceiling, $recipe['heightScale']);
			}
			$this->groundDraws = $this->clipmap->draw($groundProgram, $camera, $planes, [$floor, $ceiling]);
			// The craft shares the terrain program and has its own clipping.
			glUniform1f($groundProgram->location('uPlayHalf'), 0);
		}
		foreach($this->draw as $n)
		{
			$key = $n['key'];
			$p = $this->patches[$key];
			$hidden = Camera::rejectingPlane($planes, $eye, $n, $p['minY'], $p['maxY'], $this->groundCull[$key] ?? -1);
			if($hidden >= 0)
			{
				$this->groundCull[$key] = $hidden;
				continue;
			}
			unset($this->groundCull[$key]);
			$this->groundDraws++;

			$p['mesh']->draw($this->bindPatch($uPatch, $uEdges, $uCorners, $n, $lastBoundary));
		}

		if($craftState !== null)
		{
			$this->scalar($groundProgram, 'uCraft', 1);

			$this->vector(
				$groundProgram
				, 'uOrigin'
				, [
					$craftState['x'] - $camera->position[0]
					, $craftState['y'] - $camera->position[1]
					, $craftState[
					'z'
					] - $camera->position[2]
				]
			);

			$this->vector(
				$groundProgram
				, 'uCraftRotation'
				, [$craftState['yaw'],$craftState['pitch'],$craftState['roll']]
			);

			$this->craftMesh->draw();
		}

		if($groundProgram === $this->litGroundProgram && $this->groundDraws > 0) $this->litDrawn['ground'] = true;

		glDisable(GL_CULL_FACE);
		if($drawOpaque !== null) $drawOpaque();
		// Opaque depth is complete. Shade the sky only through pixels left at the far depth.
		glEnable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDepthFunc(GL_LEQUAL);
		glDepthMask(false);
		$this->skyProgram->use();
		$this->common($this->skyProgram, $camera, $width, $height, $environment);
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		if($environment['nightVisibility'] > 0)
		{
			$this->starsProgram->use();
			glUniformMatrix4fv($this->starsProgram->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
			$this->scalar($this->starsProgram, 'uPixelScale', min(3.0, max(.8, $height / 720)));
			$this->scalar($this->starsProgram, 'uNightVisibility', $environment['nightVisibility']);
			$this->scalar($this->starsProgram, 'uExposure', $environment['exposure']);
			glEnable(GL_BLEND);
			glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE, GL_ZERO, GL_ONE);
			glDrawArrays(GL_POINTS, 0, 2400);
		}
		glDepthMask(true);
		glDepthFunc(GL_LESS);
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
		$waterProgram->use();
		$this->water->appearance->bind($waterProgram);
		$this->common($waterProgram, $camera, $width, $height, $environment);
		[$wakeCount, $wakeBounds] = $this->waterUniforms($waterProgram, $time);
		$lastCount = $lastBoundary = null;
		$wakeLocation = $waterProgram->location('uFrameWakeCount');

		[$waterMin,$waterMax] = $this->water->heightRange();
		$uPatch = $waterProgram->location('uPatch');
		$uEdges = $waterProgram->location('uEdges');
		$uCorners = $waterProgram->location('uCorners');
		if($this->clipmap && ($recipe['surround'] === 'sea' || $waterMax >= $floor)) {
			glUniform1i($wakeLocation, $wakeCount);
			$this->waterDraws = $this->clipmap->draw($waterProgram, $camera, $planes, [$waterMin, $waterMax], water: true);
		}
		foreach($this->draw as $n)
		{
			$key = $n['key'];
			$patch = $this->patches[$key];
			if($patch['minY'] > $waterMax) continue;
			$hidden = Camera::rejectingPlane($planes, $eye, $n, $waterMin, $waterMax, $this->waterCull[$key] ?? -1);
			if($hidden >= 0)
			{
				$this->waterCull[$key] = $hidden;
				continue;
			}
			unset($this->waterCull[$key]);
			$this->waterDraws++;
			$count = $wakeCount;
			if($count && ($n['x'] > $wakeBounds[2] || $n['x'] + $n['size'] < $wakeBounds[0]
				|| $n['z'] > $wakeBounds[3] || $n['z'] + $n['size'] < $wakeBounds[1])) $count = 0;
			if($count !== $lastCount)
			{
				glUniform1i($wakeLocation, $count);
				$lastCount = $count;
			}
			$patch['mesh']->draw($this->bindPatch($uPatch, $uEdges, $uCorners, $n, $lastBoundary));
		}

		if($waterProgram === $this->litWaterProgram && $this->waterDraws > 0) $this->litDrawn['water'] = true;
		glDisable(GL_BLEND);
		glBindVertexArray(0);
		$this->morphMemo = null;
	}

	/**
	 * Upload camera, atmosphere and common surface uniforms to one draw program.
	 * @param Program $program Linked shader program receiving uniforms.
	 * @param Camera $camera Current viewing camera.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param array $environment Atmosphere parameters for this frame.
	 * @return void Updates shared draw uniforms.
	 */
	private function common(Program $program, Camera $camera, int $width, int $height, array $environment): void
	{
		$this->vector($program, 'uEye', $camera->position);
		if($program === $this->skyProgram)
		{
			[$forward,$right,$up] = $camera->basis();
			$this->vector($program, 'uForward', $forward);
			$this->vector($program, 'uRight', $right);
			$this->vector($program, 'uUp', $up);
			glUniform2f($program->location('uScreen'), $width / max(1, $height), tan($camera->fov / 2));
		}
		else glUniformMatrix4fv($program->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		$id = spl_object_id($program);
		$previous = $this->boundEnvironment[$id] ?? [];
		if($previous !== $environment)
		{
			foreach($environment as $name => $value)
			{
				if(($previous[$name] ?? null) === $value) continue;
				$key = str_starts_with($name, 'u') ? $name : 'u' . ucfirst($name);
				if(is_array($value) && count($value) === 3) $this->vector($program, $key, $value);
				elseif(is_array($value) && count($value) === 2) glUniform2f($program->location($key), ...$value);
				elseif(is_numeric($value)) $this->scalar($program, $key, $value);
			}
			$this->boundEnvironment[$id] = $environment;
		}

		if($program !== $this->skyProgram) $this->scalar($program, 'uWaterLevel', $this->water->level());
		if($program === $this->litGroundProgram || $program === $this->litWaterProgram)
			$this->spotLights?->bind($program, $environment['nightVisibility']);
		if($program === $this->groundProgram || $program === $this->litGroundProgram)
		{
			$this->terrainShadows->bind($program);
			$this->clipmap?->bindShadows($program);
		}
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->textures[0]);
		glUniform1i($program->location('uTransmittance'), 0);
		$this->atmosphereCache->bind($program);
	}

	/**
	 * Upload bounded analytic waves and wakes for the requested simulation time.
	 * @param Program $program Water program in use.
	 * @param float $time Interpolated simulation time in seconds.
	 * @return array Active packet count and their combined world-space bounds.
	 */
	private function waterUniforms(Program $program, float $time): array
	{
		$this->water->update($time);
		$data = $this->water->uniforms();
		$this->scalar($program, 'uWaterTime', $time);
		glUniform1i($program->location('uWaveCount'), $data['waveCount']);
		if($data['waveCount'])
		{
			glUniform4fv($program->location('uWaveVectors[0]'), $data['waveCount'], array_merge(...$data['waveVectors']));
			glUniform2fv($program->location('uWaveDynamics[0]'), $data['waveCount'], array_merge(...$data['waveDynamics']));
		}
		$count = count($data['frameWakes']);
		if($count)
		{
			glUniform4fv($program->location('uFrameWakes[0]'), $count, array_merge(...$data['frameWakes']));
			glUniform2fv($program->location('uJetVelocities[0]'), $count, array_merge(...$data['jetVelocities']));
			glUniform4f($program->location('uWakeBounds'), ...$data['wakeBounds']);
		}
		return [$count, $data['wakeBounds']];
	}

	/**
	 * Upload a three-component shader uniform.
	 * @param Program $program Linked shader program receiving uniforms.
	 * @param string $name Shader uniform identifier.
	 * @param array $values Three floating-point uniform components.
	 * @return void Sets the vector uniform.
	 */
	private function vector(Program $program, string $name, array $values): void
	{
		glUniform3f($program->location($name), ...$values);
	}

	/**
	 * Upload one floating-point shader uniform.
	 * @param Program $program Linked shader program receiving uniforms.
	 * @param string $name Shader uniform identifier.
	 * @param float $value Floating-point uniform value.
	 * @return void Sets the scalar uniform.
	 */
	private function scalar(Program $program, string $name, float $value): void
	{
		glUniform1f($program->location($name), $value);
	}

	/**
	 * Create the small retained demonstration hull mesh.
	 * @return GpuPatch Restorable hull mesh.
	 */
	private function makeCraft(): GpuPatch
	{
		$vertices = '';
		$points = [[-.7,0,1.6],[.7,0,1.6],[.65,0,-1.2],[-.65,0,-1.2],[0,.65,-.8],[0,.35,1.4]];

		foreach($points as [$x,$y,$z]) $vertices .= pack('f*', $x, $y, $z, 0, 1, 0, $y, 5, 0, 1, 0);

		return new GpuPatch(
			$vertices
			, pack('v*', 0, 5, 1, 1, 5, 4, 1, 4, 2, 2, 4, 3, 3, 4, 5, 3, 5, 0, 0, 1, 2, 0, 2, 3)
		);
	}

	/**
	 * Draw an overlay on the same triangles as the finest resident terrain, including edits and LOD blending.
	 * @param Program $program Active overlay program using the vertex functions from Clipmap::gridShader().
	 * @param Camera $camera Camera for the current terrain pass.
	 * @param array $bounds Minimum X and Z, then maximum X and Z, in world meters.
	 * @return bool Whether the grid covered the whole rectangle and submitted a draw.
	 */
	public function drawGroundPatch(Program $program, Camera $camera, array $bounds): bool
	{
		return $this->clipmap?->drawGroundPatch($program, $camera, $bounds) ?? false;
	}

	/**
	 * @return bool Whether the previous view submitted water that can change with time.
	 */
	public function showsWater(): bool { return $this->waterDraws > 0; }

	/**
	 * Whether preparation or LOD animation still requires frames.
	 * @return bool Whether another frame or preparation step is required.
	 */
	public function dirty(): bool
	{
		// A selection the limiter postponed needs later updates even when the camera has stopped. So does the
		// limiter's own spacing after a selection, which only updates can count.
		if($this->clipmap) return $this->changed || $this->clipmap->pending || $this->preparing !== null;
		return $this->selectionKey === '' || $this->changed || $this->jobs !== [] || $this->preparing !== null
			|| $this->selectionPending || $this->selectionDeferred || $this->selectionRecent || $this->flight !== null;
	}

	/**
	 * Bounded renderer counters for diagnostics.
	 * @return array Counts and packed-memory totals for rendering diagnostics.
	 */
	public function stats(): array
	{
		$coverage = [];
		$finest = null;
		$cameraSpacing = null;

		foreach($this->draw as $node)
		{
			$spacing = $node['size'] / Lod::CELLS;
			$coverage[] = [$node['x'],$node['z'],$node['size'],$spacing];
			$finest = $finest === null ? $spacing : min($finest, $spacing);

			if($this->previousEye !== null && $this->previousEye[0] >= $node['x']
				&& $this->previousEye[0] <= $node['x'] + $node['size']
				&& $this->previousEye[2] >= $node['z'] && $this->previousEye[2] <= $node['z'] + $node['size']
			)
			$cameraSpacing = $cameraSpacing === null ? $spacing : min($cameraSpacing, $spacing);
		}

		return ($this->clipmap?->stats() ?? []) + [
			'patches' => count($this->patches)
			, 'cameraSpacing' => $cameraSpacing
			, 'finestVisibleSpacing' => $finest
			, 'visibleCoverage' => $coverage
			, 'groundDraws' => $this->groundDraws, 'waterDraws' => $this->waterDraws
			, 'visible' => count($this->draw)
			, 'pending' => count($this->jobs)
			, 'pendingEdits' => count($this->replacementJobs)
			, 'replacementPatches' => count($this->replacements)
			, 'reservedPatches' => count($this->patches) + count($this->jobs) + count($this->replacementJobs) + count(
				$this->replacements
			)
			, 'maximumPatches' => $this->capacity()
			, 'transitions' => count($this->retiring)
			, 'meshBytes' => 16 * strlen($this->indices) + array_sum(array_map(
				/** Count retained packed mesh bytes.
			 * @param array $p Resident or staged patch.
			 * @return int CPU mesh payload bytes.
			 */
				static fn($p) => $p['mesh']->bytes(false)
				, [...array_values($this->patches), ...array_values($this->replacements)]
			))
			, 'generationMs' => $this->lastUpdateMs
			, 'frames' => $this->frames
			, 'selections' => $this->selections
			, 'selectionsCompleted' => $this->selectionsCompleted
			// A postponed selection is still owed, so callers that wait for a settled scene must see it here.
			// So is one that has started and not yet completed.
			, 'selectionPending' => $this->selectionPending || $this->selectionDeferred || $this->flight !== null
			, 'selectionInFlight' => $this->flight !== null
			, 'selectionPhase' => $this->flight['phase'] ?? null
			// Selection work in the last update. Of the last completed selection: how many updates it took part
			// in, and in which of them it changed the drawn cover. A whole selection reports one and one.
			, 'selectionWorkMs' => $this->sliceMs
			, 'selectionUpdates' => $this->selectionUpdates
			, 'selectionApplyUpdate' => $this->selectionApplyUpdate
			, 'revision' => $this->revision
			, 'pendingVertices' => array_sum(array_column($this->jobs, 'index'))
			, 'pendingHeightSamples' => array_sum(array_column($this->jobs, 'sampleIndex'))
			, 'prefetchJobs' => count(array_filter($this->jobs, /** Count work outside current-view requests.
			 * @param array $job Incremental generation record.
			 * @return bool Whether this request is speculative.
			 */
				static fn($job) => $job['speculative'] ?? false
			))
			, 'lookahead' => $this->lookahead
			, 'atmosphere' => $this->atmosphereCache->stats()
			, 'shadows' => $this->terrainShadows->stats()
		];
	}

	/** Link both lighting variants and the minimap before replacing a committed surface. */
	public function prepareMaterial(SurfaceLayers $stack): void
	{
		$key = $stack->structure;
		if(isset($this->materialPrograms[$key])) return;
		$source = MaterialShader::assemble($this->groundFragmentSource,$stack,clipmap:$this->useClipmap);
		$set = [];
		try {
			foreach([false,true] as $lamps)
				$set[] = new Program($this->groundVertexSource,str_replace('// SPOT LIGHTS',SpotLights::shaderSource($lamps),$source));
			if($this->useClipmap) {
				$root = dirname(__DIR__).'/shaders/';
				$set[] = new Program(file_get_contents($root.'shadows.vert'),
					MaterialShader::assemble(file_get_contents($root.'chart.frag'),$stack,chart:true));
			}
		} catch(\Throwable $error) {
			foreach($set as $program) $program->close();
			throw $error;
		}
		// Bound user-authored program residency; never evict the displayed material during validation.
		if(count($this->materialPrograms) >= 6) foreach($this->materialPrograms as $old=>$programs) {
			if($old === $this->materialKey) continue;
			foreach($programs as $program) {
				$id = spl_object_id($program);
				unset($this->boundEnvironment[$id],$this->layerBinding[$id],$this->boundPaint[$id]);
				$program->close();
			}
			$this->spotLights?->forget();
			unset($this->materialPrograms[$old]);
			break;
		}
		$this->materialPrograms[$key] = $set;
	}

	private function selectMaterial(SurfaceLayers $stack): void
	{
		if($this->materialKey === $stack->structure) return;
		$this->prepareMaterial($stack);
		$this->materialKey = $stack->structure;
		[$this->groundProgram,$this->litGroundProgram] = $this->materialPrograms[$this->materialKey];
		$this->chartProgram = $this->materialPrograms[$this->materialKey][2] ?? null;
		$this->litDrawn['ground'] = false;
	}

	private function palette(): SurfaceLayers
	{
		if($this->layers) return $this->layers;
		if(!$this->defaultLayers || $this->paletteAppearance!==$this->appearance) {
			$this->defaultLayers=SurfaceLayers::landscape($this->landscape->recipe(),$this->appearance);
			$this->paletteAppearance=$this->appearance;
		}
		return $this->defaultLayers;
	}

	public function drawChart(array $xz,float $bearing,bool $wide,int $width,int $height,array $waterColor,
		?int $size = null, ?float $localSpan = null, bool $circular = false): ?float
	{
		if(!$this->clipmap) return null;
		$this->selectMaterial($this->palette());
		return $this->clipmap->chart($this->chartProgram,$xz,$bearing,$wide,$width,$height,$this->water->level(),$waterColor,
			$this->palette(),$this->paint ?? $this->appearance,$size,$localSpan,$circular);
	}

	public function restore(): void
	{
		if($this->closed) return;

		// The meshes are rebuilt in place, so a selection in flight would still be right. It is dropped anyway,
		// like every selection whose surroundings were replaced, and its replacement is spread like any other.
		$this->abandonSelection();
		if($this->clipmap) { $this->clipmap->close(); $this->clipmap = new Clipmap; }
		foreach($this->materialPrograms as $key=>$set) {
			if($key === $this->materialKey) foreach($set as $program) $program->restore();
			else {
				foreach($set as $program) $program->close();
				unset($this->materialPrograms[$key]);
			}
		}
		$this->boundPaint = []; $this->layerBinding=[];
		$this->boundEnvironment = [];
		$this->skyProgram->restore();
		$this->starsProgram->restore();
		$this->waterProgram->restore();
		$this->litWaterProgram->restore();
		$this->litDrawn = ['ground' => false, 'water' => false];
		$this->spotLights?->forget();

		if($this->gridBuffer) glDeleteBuffers(1,$this->gridBuffer);
		if($this->vao) glDeleteVertexArrays(1,$this->vao);
		glGenVertexArrays(1, $this->vao);
		if(!$this->clipmap) $this->createGridBuffer();
		foreach($this->patches as $p) $p['mesh']->restore($this->gridBuffer[0]);
		$this->craftMesh->restore();
		$this->atmosphereCache->restore();
		$this->terrainShadows->restore();
		$this->textures = $this->heightTexture = [];
		$this->createTextures();
		glGenTextures(1, $this->heightTexture);
		$this->uploadHeightMap();
		$this->changed = true;

		foreach($this->replacements as $p) $p['mesh']->restore($this->gridBuffer[0]);
	}

	/**
	 * Release every terrain mesh and cancel pending selection and replacement work.
	 * @return void Clears every mesh, transition and pending job.
	 */
	private function clearPatches(): void
	{
		$this->abandonSelection();

		foreach($this->patches as $p) $p['mesh']->close();

		foreach($this->replacements as $p) $p['mesh']->close();
		$this->preparing = null;
		$this->replacements = $this->replacementJobs = [];

		$this->patches = $this->jobs = $this->draw = $this->activated = $this->retiring = $this->reserved = [];
		$this->drawnKeys = $this->drawnBranches = [];
		$this->neighbors = $this->settledBoundaries = $this->settledParents = $this->groundCull = $this->waterCull = [];
		$this->drawIndex = null;
		$this->selectionPending = $this->selectionDeferred = $this->selectionRecent = false;
		$this->transitionUntil = -1;
		$this->previousEye = $this->lookahead = null;
	}

	/**
	 * Release all owned GPU and preparation resources.
	 * @return void Permanently releases all scene-owned graphics resources.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$this->clearPatches();

		$this->clipmap?->close();
		foreach($this->materialPrograms as $set) foreach($set as $program) $program->close();
		$this->materialPrograms = [];
		$this->skyProgram->close();
		$this->starsProgram->close();
		$this->waterProgram->close();
		$this->litWaterProgram->close();
		$this->craftMesh?->close();
		$this->atmosphereCache->close();
		$this->terrainShadows->close();

		if($this->textures !== []) glDeleteTextures(count($this->textures), $this->textures);

		if($this->heightTexture !== []) glDeleteTextures(count($this->heightTexture), $this->heightTexture);

		if($this->gridBuffer !== []) glDeleteBuffers(1, $this->gridBuffer);

		if($this->vao !== []) glDeleteVertexArrays(1, $this->vao);

		$this->textures = $this->heightTexture = $this->vao = [];
	}
}

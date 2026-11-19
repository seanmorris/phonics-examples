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
	private AtmosphereRenderer $atmosphereCache;
	private TerrainShadows $terrainShadows;
	private Lod $lod;
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
	/** Default for selectionInterval, shared with applications that expose the setting. */
	public const DEFAULT_SELECTION_INTERVAL = .1;
	/** Seconds between detail selections while the view moves. Zero selects on every change, as before the limiter. */
	public float $selectionInterval = self::DEFAULT_SELECTION_INTERVAL;
	/** Detail multiplier: .5 economical, 1 balanced, 2 detailed. */
	public float $quality = 1;
	/** Time slice for incremental sampling. Slow display frames may also upload one ready sibling group. */
	public float $budgetMs = 2;
	/** Maximum retained patches including parents. */
	public int $maximumPatches = 384;

	/**
	 * Create an incremental terrain scene with independent simulation surfaces.
	 * @param Landscape $landscape Authoritative committed terrain surface.
	 * @param Water $water Shared analytic water model.
	 * @param Atmosphere $atmosphere Shared atmosphere parameters and numerical lookup data.
	 * @param BrowserPatchBuilder|null $builder Optional background render mesh generator.
	 * @return void Initializes bounded preparation and graphics resources.
	 */
	public function __construct(
		private Landscape $landscape,
		private Water $water,
		private Atmosphere $atmosphere,
		private ?BrowserPatchBuilder $builder = null
	){
		$this->indices = GpuPatch::indices(Lod::CELLS);
		$this->lod = new Lod($landscape->recipe()['worldSize'], $landscape->recipe()['spacing']);
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
		$grid = file_get_contents($root . 'patch-grid.glsl');
		$optics = WaterAppearance::shaderSource();

		$this->groundProgram = new Program(
			str_replace('// PATCH', $grid, file_get_contents($root . 'terrain.vert'))
			, str_replace(['// ATMOSPHERE','// OPTICS'], [$atmosphere,$optics], file_get_contents($root . 'terrain.frag'))
		);

		$this->skyProgram = new Program(
			file_get_contents($root . 'sky.vert')
			, str_replace('// ATMOSPHERE', $atmosphere, file_get_contents($root . 'sky.frag'))
		);

		$this->starsProgram = new Program(file_get_contents($root . 'stars.vert'), file_get_contents($root . 'stars.frag'));

		$this->waterProgram = new Program(
			str_replace(['// PATCH','// WATER'], [$grid,$water], file_get_contents($root . 'water.vert'))
			, str_replace(['// ATMOSPHERE','// WATER','// OPTICS'], [$atmosphere,$water,$optics], file_get_contents($root . 'water.frag'))
		);

		glGenVertexArrays(1, $this->vao);
		$this->createTextures();
		$this->atmosphereCache = new AtmosphereRenderer($this->atmosphere);
		$this->terrainShadows = new TerrainShadows;
		glGenTextures(1, $this->heightTexture);
		$this->uploadHeightMap();
		$this->createGridBuffer();
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

		foreach($this->replacements as $key => $patch)
		{
			$this->patches[$key]['mesh']->close();
			$this->patches[$key] = $patch;
		}

		$this->landscape = $this->preparing;
		$this->preparing = null;
		$this->replacements = [];
		$this->revision = $this->landscape->revision();
		$this->jobs = [];
		$this->selectionKey = '';
		$this->changed = true;
		$this->uploadHeightMap();

		return $this->landscape;
	}

	/**
	 * Cancel staged meshes and return the candidate without taking its ownership.
	 * @return Landscape|null Cancelled candidate, still owned by the caller, or null.
	 */
	public function cancelPreparedLandscape(): ?Landscape
	{
		$candidate = $this->preparing;

		foreach($this->replacements as $patch) $patch['mesh']->close();
		$this->preparing = null;
		$this->replacementJobs = $this->replacements = [];
		$this->replacementRegion = null;
		$this->selectionKey = '';
		$this->changed = true;

		return $candidate;
	}

	/**
	 * Upload the resident coarse terrain heights for shared water and atmospheric occlusion.
	 * @return void Updates the height lookup texture.
	 */
	private function uploadHeightMap(): void
	{
		if($this->heightTexture === []) return;

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
		$this->landscape = $landscape;
		$this->lod = new Lod($landscape->recipe()['worldSize'], $landscape->recipe()['spacing']);
		$this->revision = -1;
		$this->selectionKey = '';
		$this->changed = true;
		$this->uploadHeightMap();
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
		 * @return int Eight-metre position bucket.
		 */
			static fn($v) => (int)floor($v / 8)
			, $camera->position
		)) . ':' . $this->quality . ':' . $this->viewportHeight . ':' . $this->viewportAspect . ':' . $camera->fov
			. ':' . $this->water->level() . ':' . $this->water->waveScale()
			. ':' . (int)floor($camera->yaw * 16) . ':' . (int)floor($camera->pitch * 16)
			. ':' . ($this->lookahead === null
				? 'stopped'
				: implode(':', array_map(
					/** Quantize the predicted eye independently of current-view motion.
					 * @param float $value Predicted world coordinate.
					 * @return int Thirty-two-metre prediction bucket.
					 */
					static fn($value) => (int)floor($value / 32)
					, $this->lookahead
				))
			);

		$transitioning = $this->retiring !== [] || $this->clock < $this->transitionUntil;

		// A selection the limiter postponed stays wanted until it runs, exactly like a changed view.
		$viewChanged = $key !== $this->selectionKey || $this->selectionDeferred;
		$transitionDue = ($transitioning || $this->selectionPending) && $this->clock - $this->selectionTime > .15;
		$hold = $this->selectionInterval > 0 && $this->holdSelection($seconds, $camera);
		if($viewChanged && !$hold)
		{
			$this->select($camera);
			$this->selectionKey = $key;
		}

		$uploads = 0;
		$coverReady = false;
		// On slow displays, accept one ready sibling group during a fraction of
		// the frame interval. CPU sampling still obeys the original time slice.
		$uploadDeadline = $started + min(.032, max(0, $seconds - 1 / 60) * .1);
		// Let a queued worker result make progress even on a frame spent selecting LOD.
		$poll = $this->builder !== null;
		while($this->jobs !== [] && ($poll || microtime(true) < $deadline))
		{
			$id = array_key_first($this->jobs);
			$job = &$this->jobs[$id];
			$poll = false;
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
				$uploads++;
				if($this->builder === null || $uploads === 4) break;
				$poll = $next && empty($next['incremental']) && microtime(true) < $uploadDeadline;
				continue;
			}

			unset($job);
		}

		// A new root must draw immediately. Otherwise combine transitions and completed
		// siblings in one selection, even when a moving camera also reordered work.
		if($coverReady && $this->draw === []) $this->select($camera);
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
	 * finished group or a transition cannot starve behind a view that keeps moving. A view at rest for a
	 * whole interval is not limited. By then it has had a selection for its resting pose, and its finished
	 * groups and transitions select at once. So does the first changed view after the rest, because dirty()
	 * keeps the scene clock running for one interval after every selection.
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
		$pose = $this->previousPose = [$camera->position, $camera->yaw, $camera->pitch];
		$settings = [$this->quality, $this->viewportHeight, $this->viewportAspect, $camera->fov
			, $this->water->level(), $this->water->waveScale()];
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
		// The distance limit is the one trackMotion() uses to drop its lookahead after a jump.
		[$from, $yaw, $pitch] = $previous;
		$reach = 600 * $seconds;
		if($reach < 64) $reach = 64;
		$x = $pose[0][0] - $from[0];
		$y = $pose[0][1] - $from[1];
		$z = $pose[0][2] - $from[2];

		if($x * $x + $y * $y + $z * $z > $reach * $reach) return false;

		$turn = $camera->fov / 2;
		$pitch -= $camera->pitch;

		if($pitch > $turn || -$pitch > $turn) return false;

		$yaw -= $camera->yaw;
		if($yaw < 0) $yaw = -$yaw;
		// A yaw that wraps past a whole turn has not moved the view.
		if($yaw > M_PI)
		{
			$yaw = fmod($yaw, 2 * M_PI);
			if($yaw > M_PI) $yaw = 2 * M_PI - $yaw;
		}

		return $yaw <= $turn;
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
	 * Choose a complete resident cover and reserve bounded sibling-generation work.
	 * @param Camera $camera Current viewing camera.
	 * @return void Updates draw coverage and reserved generation jobs.
	 */
	private function select(Camera $camera): void
	{
		$this->selections++;

		// A one-patch margin leaves room for camera turns and the neighboring
		// splits needed for 2:1 seams. Out-of-view ground retains coarse coverage.
		// The selector applies that margin itself, to ground and water bounds alike, when it gets the
		// planes: a predicate here would cost a call and a copied node for every patch that wants to split.
		$planes = $camera->clipPlanes(
			max(1, (int)round($this->viewportHeight * $this->viewportAspect))
			, $this->viewportHeight
		);

		[$waterMin, $waterMax] = $this->water->heightRange();

		$result = $this->lod->select(
			$camera->position
			, $this->patches
			, $this->quality
			, $this->viewportHeight / (2 * tan($camera->fov / 2))
			, null
			, $planes
			, $waterMin
			, $waterMax
		);

		$old = array_column($this->draw, 'key');
		$desired = $this->balance($result['draw']);

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

		// The key caches gain an entry for every patch the view asks about. Residents are looked up again
		// on every selection, so a trim keeps their entries and drops the rest.
		if(count($this->parentKeys) > 4096)
		{
			$this->parentKeys = array_intersect_key($this->parentKeys, $this->patches);
			$this->coarserKeys = array_intersect_key($this->coarserKeys, $this->patches);
		}

		$drawn = array_column($this->draw, 'key');
		// A loose array comparison ignores order, so counting each key compares the two covers as sets.
		// Sorting both lists of keys to compare them took thousands of string comparisons per selection.
		$this->selectionPending = array_count_values($drawn) != array_count_values(array_column($desired, 'key'));

		$clock = $this->clock;
		$until = -1.0;

		foreach($drawn as $key)
		{
			$this->patches[$key]['lastUse'] = $clock;
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

		// The reservation starts from the drawn cover and its ancestors.
		if($drawn !== $this->drawnKeys)
		{
			$this->drawnKeys = $drawn;
			$this->drawnBranches = $this->ancestors($this->draw);
		}

		$this->reserved = $this->drawnBranches;
		$drawKeys = array_column($this->draw, null, 'key');
		$wanted = [];
		$requests = array_column($result['request'], null, 'key');

		// Retain ready siblings while their shared morph/neighbor transition is pending.
		// Otherwise reserving the next requests can evict a completed group before it draws.
		foreach($result['refine'] as $node)
		if(isset($drawKeys[$node['key']]))
		foreach($this->lod->children($node) as $child)
		$requests[$child['key']] = $child + ['priority' => $node['priority']];

		[$groups, $blockers] = $this->viewGroups($requests, $drawKeys, $camera);
		// The reservation only grows from here, so its size is counted once and then kept in step.
		$reserved = count($this->reserved);

		foreach($groups as $parent => $nodes)
		{
			if($parent !== '' && !isset($drawKeys[$parent])) continue;

			if(($blockers[$parent] ?? []) !== []) continue;

			$extra = 0;

			foreach($nodes as $n) if(!isset($this->reserved[$n['key']])) $extra++;

			if($reserved + $extra > $capacity) continue;

			$reserved += $extra;

			foreach($nodes as $n)
			{
				$key = $n['key'];
				$this->reserved[$key] = true;
				if(!isset($this->patches[$key])) $wanted[$key] = $n + ['speculative' => false];
			}
		}

		// Prediction reserves only unused cache space after all current-view demand.
		// Midpoint plus endpoint cover the short swept corridor, without speculative draws.
		// Twelve missing patches already fill all three worker slots with current-view
		// sibling groups. Reconsider the flight corridor as soon as a slot can open.
		if($this->lookahead !== null && ($this->builder === null || count($wanted) < 12))
		{
			$midpoint = [];

			for($axis = 0; $axis < 3; $axis++) $midpoint[] = ($camera->position[$axis] + $this->lookahead[$axis]) / 2;
			$requests = [];

			foreach([$midpoint, $this->lookahead] as $eye)
			{
				$predicted = $this->lod->select(
					$eye
					, $this->patches
					, $this->quality
					, $this->viewportHeight / (2 * tan($camera->fov / 2))
				);

				foreach($predicted['request'] as $node)
				if(!isset($wanted[$node['key']])) $requests[$node['key']] = $node;
			}

			$speculative = 0;

			foreach($this->requestGroups($requests) as $parent => $nodes)
			{
				if($parent === '' || !isset($this->patches[$parent])) continue;

				// The keys counted below include the four siblings, so the unreserved siblings are a
				// floor for $extra. A group that breaks a budget on that floor alone is refused before
				// the rest of its keys are built. Once a budget is spent, that is every later group with
				// an unreserved sibling.
				$children = $this->lod->children($this->patches[$parent]);
				$floor = 0;

				foreach($children as $child) if(!isset($this->reserved[$child['key']])) $floor++;

				if($speculative + $floor > 32 || $reserved + $floor > $capacity) continue;

				$keys = $this->ancestors($children);
				$extra = count(array_diff_key($keys, $this->reserved));

				// Merging the keys into the reservation adds exactly the $extra that are new to it.
				if($speculative + $extra > 32 || $reserved + $extra > $capacity
					|| count($this->patches) + count($wanted) + count($nodes) > $capacity
				) continue;

				$speculative += $extra;
				$reserved += $extra;
				$this->reserved += $keys;

				foreach($nodes as $n) $wanted[$n['key']] = $n + ['speculative' => true];
			}
		}

		// Rebuild order on every selection while retaining partially generated payloads.
		// Current-view sibling groups always run before speculative work.
		$ordered = [];

		foreach($wanted as $n)
		$ordered[$n['key']] = $n + ($this->jobs[$n['key']] ?? ['index' => 0,'bytes' => '', 'heights' => []]);
		$this->jobs = $ordered;
		if($this->builder !== null)
		{
			$queued = [];
			foreach($ordered as $key => $node)
			{
				$parent = $this->parentKeys[$key] ??= self::parentOf($key);
				if(isset($queued[$parent])) continue;
				$this->builder->prime($this->landscape, $node, $this->patches[$parent] ?? null);
				$queued[$parent] = true;
				if(count($queued) === 3) break;
			}
		}
		$this->evict();
		$this->selectionTime = $this->clock;
		$this->selectionDeferred = false;
	}

	/**
	 * Keep siblings together and prioritize groups by projected current-view demand.
	 * @param array $requests Missing nodes with parent-group priority from the selector.
	 * @return array Missing sibling groups ordered from greatest to least importance.
	 */
	private function requestGroups(array $requests): array
	{
		$parents = &$this->parentKeys;
		$groups = $priority = [];

		foreach($requests as $node)
		{
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

		return $ordered;
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
	 * @return array Ordered groups and the coarser neighbors blocking each direct split.
	 */
	private function viewGroups(array $requests, array $drawKeys, Camera $camera): array
	{
		$groups = $this->requestGroups($requests);
		$priority = $blockers = $byLevel = [];
		[$forward] = $camera->basis();
		$eye = $camera->position;
		// The cone depends on the camera alone, so its slope is worked out once for all the groups.
		$halfAngle = atan(tan($camera->fov / 2) * max(1.0, $this->viewportAspect)) + .1;
		$slope = tan(min(1.4, $halfAngle));
		$deepest = 0;

		foreach($groups as $parent => $nodes)
		{
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

		return [$ordered, $blockers];
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
		foreach($keys as $key)
		{
			[$level, $x, $z] = explode(':', $key);
			$level = (int)$level; $x = (int)$x; $z = (int)$z;

			for($nz = $z - 1; $nz <= $z + 1; $nz++)
			for($nx = $x - 1; $nx <= $x + 1; $nx++)
			{
				$near = $level . ':' . $nx . ':' . $nz;
				unset($this->neighbors[$near], $this->settledBoundaries[$near], $this->settledParents[$near]);
			}

			$fine = $level + 1;

			for($nz = 2 * $z - 1; $nz <= 2 * $z + 2; $nz++)
			for($nx = 2 * $x - 1; $nx <= 2 * $x + 2; $nx++)
			{
				$near = $fine . ':' . $nx . ':' . $nz;
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
	 * @return bool Whether the caller should yield until the worker responds.
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

		if($this->builder !== null && empty($job['incremental']))
		{
			if($this->builder->advance($source, $job, $parentPatch)) return $job['index'] !== $vertices;
			$job['incremental'] = true;
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
		// uses Landscape::sample() and its authoritative one-metre reconstruction.
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
		$this->terrainShadows->update(
			isset($this->patches['0:0:0']) ? $this->heightTexture[0] : 0
			, $this->lod->extent
			, $environment['sunDirection']
			, $this->heightTextureRevision
		);

		glViewport(0, 0, $width, $height);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_BLEND);
		glDepthFunc(GL_LESS);
		glDepthMask(true);
		glEnable(GL_DEPTH_TEST);
		glEnable(GL_CULL_FACE);
		$this->groundProgram->use();
		$this->common($this->groundProgram, $camera, $width, $height);
		$this->water->appearance->bind($this->groundProgram);
		$this->vector($this->groundProgram, 'uCraftRotation', [0,0,0]);
		$this->scalar($this->groundProgram, 'uCraft', 0);

		$planes = $camera->clipPlanes($width,$height);
		$eye = $camera->position;
		$this->groundDraws = $this->waterDraws = 0;
		$lastBoundary = null;
		$uPatch = $this->groundProgram->location('uPatch');
		$uEdges = $this->groundProgram->location('uEdges');
		$uCorners = $this->groundProgram->location('uCorners');
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
			$this->scalar($this->groundProgram, 'uCraft', 1);

			$this->vector(
				$this->groundProgram
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
				$this->groundProgram
				, 'uCraftRotation'
				, [$craftState['yaw'],$craftState['pitch'],$craftState['roll']]
			);

			$this->craftMesh->draw();
		}

		glDisable(GL_CULL_FACE);
		if($drawOpaque !== null) $drawOpaque();
		// Opaque depth is complete. Shade the sky only through pixels left at the far depth.
		glEnable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glDepthFunc(GL_LEQUAL);
		glDepthMask(false);
		$this->skyProgram->use();
		$this->common($this->skyProgram, $camera, $width, $height);
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
		$this->waterProgram->use();
		$this->water->appearance->bind($this->waterProgram);
		$this->common($this->waterProgram, $camera, $width, $height);
		[$wakeCount, $wakeBounds] = $this->waterUniforms($time);
		$lastCount = $lastBoundary = null;
		$wakeLocation = $this->waterProgram->location('uFrameWakeCount');

		[$waterMin,$waterMax] = $this->water->heightRange();
		$uPatch = $this->waterProgram->location('uPatch');
		$uEdges = $this->waterProgram->location('uEdges');
		$uCorners = $this->waterProgram->location('uCorners');
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
	 * @return void Updates shared draw uniforms.
	 */
	private function common(Program $program, Camera $camera, int $width, int $height): void
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
		$environment = $this->atmosphere->uniforms();

		foreach($environment as $name => $value)
		{
			$key = str_starts_with($name, 'u') ? $name : 'u' . ucfirst($name);

			if(is_array($value) && count($value) === 3) $this->vector($program, $key, $value);
			elseif(is_array($value) && count($value) === 2) glUniform2f($program->location($key), ...$value);
			elseif(is_numeric($value)) $this->scalar($program, $key, $value);
		}

		if($program !== $this->skyProgram) $this->scalar($program, 'uWaterLevel', $this->water->level());
		if($program === $this->groundProgram)
		{
			$recipe = $this->landscape->recipe();
			$this->scalar($program, 'uTerrainStyle', match($recipe['preset'])
			{
				'canyon' => $recipe['version'] === 2 ? 3 : 2, 'mountain' => 1, default => 0
			});
			if($recipe['preset'] === 'canyon' && $recipe['version'] === 2)
			{
				glUniform4fv($program->location('uBedContacts[0]'), 2, CanyonBeds::CONTACTS);
				$spacing = $recipe['terraceSpacing'] ?: max(1, $recipe['heightScale']/8);
				glUniform3f($program->location('uBedDatum'), CanyonBeds::DIP[0], CanyonBeds::DIP[1], max(.001, $spacing));
			}
			$this->terrainShadows->bind($program);
		}
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->textures[0]);
		glUniform1i($program->location('uTransmittance'), 0);
		$this->atmosphereCache->bind($program);
	}

	/**
	 * Upload bounded analytic waves and wakes for the requested simulation time.
	 * @param float $time Interpolated simulation time in seconds.
	 * @return array Active packet count and their combined world-space bounds.
	 */
	private function waterUniforms(float $time): array
	{
		$this->water->update($time);
		$data = $this->water->uniforms();
		$program = $this->waterProgram;
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
		return $this->selectionKey === '' || $this->changed || $this->jobs !== [] || $this->preparing !== null
			|| $this->selectionPending || $this->selectionDeferred || $this->selectionRecent;
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

		return [
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
			// A postponed selection is still owed, so callers that wait for a settled scene must see it here.
			, 'selectionPending' => $this->selectionPending || $this->selectionDeferred
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

	/**
	 * Rebuild graphics resources after context restoration.
	 * @return void Recreates expired graphics-context resources.
	 */
	public function restore(): void
	{
		if($this->closed) return;

		$this->groundProgram->restore();
		$this->skyProgram->restore();
		$this->starsProgram->restore();
		$this->waterProgram->restore();

		if($this->gridBuffer) glDeleteBuffers(1,$this->gridBuffer);
		if($this->vao) glDeleteVertexArrays(1,$this->vao);
		glGenVertexArrays(1, $this->vao);
		$this->createGridBuffer();
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
		foreach($this->patches as $p) $p['mesh']->close();

		foreach($this->replacements as $p) $p['mesh']->close();
		$this->preparing = null;
		$this->replacements = $this->replacementJobs = [];
		$this->patches = $this->jobs = $this->draw = $this->activated = $this->retiring = $this->reserved = [];
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
		$this->builder?->close();
		$this->clearPatches();
		$this->groundProgram->close();
		$this->skyProgram->close();
		$this->starsProgram->close();
		$this->waterProgram->close();
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

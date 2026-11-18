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
	private Program $groundProgram, $skyProgram, $waterProgram;
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
	private array $activated = [];
	/** Reverse sibling transitions and the exact residency reservation for this selection. */
	private array $retiring = [], $reserved = [];
	private string $indices;
	private float $clock = 0, $lastUpdateMs = 0;
	private int $revision = -1, $frames = 0, $selections = 0;
	private bool $changed = true, $closed = false;
	private ?GpuPatch $craftMesh = null;
	private float $selectionTime = -1;
	private string $selectionKey = '';
	private bool $selectionPending = false;
	/** Last observed eye and bounded predicted position; speculative jobs never drive visibility. */
	private ?array $previousEye = null, $lookahead = null;
	private int $viewportHeight = 720;
	/** Detail multiplier: .5 economical, 1 balanced, 2 detailed. */
	public float $quality = 1;
	/** Maximum generation milliseconds spent in one update. */
	public float $budgetMs = 2;
	/** Maximum retained patches including parents. */
	public int $maximumPatches = 384;

	/**
	 * Create an incremental terrain scene with independent simulation surfaces.
	 * @param Landscape $landscape Authoritative committed terrain surface.
	 * @param Water $water Shared analytic water model.
	 * @param Atmosphere $atmosphere Shared atmosphere parameters and numerical lookup data.
	 * @return void Initializes bounded preparation and graphics resources.
	 */
	public function __construct(private Landscape $landscape, private Water $water, private Atmosphere $atmosphere)
	{
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

		$this->groundProgram = new Program(
			file_get_contents($root . 'terrain.vert')
			, str_replace('// ATMOSPHERE', $atmosphere, file_get_contents($root . 'terrain.frag'))
		);

		$this->skyProgram = new Program(
			file_get_contents($root . 'sky.vert')
			, str_replace('// ATMOSPHERE', $atmosphere, file_get_contents($root . 'sky.frag'))
		);

		$this->waterProgram = new Program(
			str_replace('// WATER', $water, file_get_contents($root . 'water.vert'))
			, str_replace(['// ATMOSPHERE','// WATER'], [$atmosphere,$water], file_get_contents($root . 'water.frag'))
		);

		glGenVertexArrays(1, $this->vao);
		$this->createTextures();
		$this->atmosphereCache = new AtmosphereRenderer($this->atmosphere);
		$this->terrainShadows = new TerrainShadows;
		glGenTextures(1, $this->heightTexture);
		$this->uploadHeightMap();
		$this->craftMesh = $this->makeCraft();
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

		$this->checkPreparation();
		$this->trackMotion($seconds, $camera);
		$this->clock += max(0, min(.1, $seconds));
		$this->changed = false;
		$lut = $this->atmosphere->transmittanceLut(64, 16, 24);

		if($lut['generation'] !== $this->textureGeneration)
		{
			$this->createTextures();
			$this->changed = true;
		}

		if($this->preparing !== null)
		{
			$start = microtime(true);
			$deadline = $start + max(.25, $this->budgetMs) / 1000;

			while($this->replacementJobs !== [] && microtime(true) < $deadline)
			{
				$id = array_key_first($this->replacementJobs);
				$job = &$this->replacementJobs[$id];
				$this->buildVertex($job, $this->preparing, $this->replacements + $this->patches);

				if($job['index'] === 1089)
				{
					$job['geometricError'] = $this->meshError($job);
					$job['mesh'] = new GpuPatch($job['bytes'], $this->indices);
					unset($job['bytes']);
					$job['centerHeight'] = $job['heights'][544];
					$job['lastUse'] = $this->clock;
					$this->replacements[$id] = $job;
					unset($this->replacementJobs[$id], $job);
					break;
				}

				unset($job);
			}

			$this->lastUpdateMs = (microtime(true) - $start) * 1000;

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
		)) . ':' . $this->quality . ':' . $this->viewportHeight . ':' . $camera->fov
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

		$transitioning = $this->retiring !== [];

		foreach($this->draw as $node)
		if($this->morph($node) > 0 || $this->clock - ($this->activated[$node['key']] ?? -1) < .5) $transitioning = true;

		if($key !== $this->selectionKey || (($this->jobs !== [] || $transitioning || $this->selectionPending)
			&& $this->clock - $this->selectionTime > .15
		)){
			$this->select($camera);
			$this->selectionKey = $key;
		}

		$started = microtime(true);
		$deadline = $started + max(.25, $this->budgetMs) / 1000;

		while($this->jobs !== [] && microtime(true) < $deadline)
		{
			$id = array_key_first($this->jobs);
			$job = &$this->jobs[$id];
			$this->buildVertex($job);

			if($job['index'] === (Lod::CELLS + 1) ** 2)
			{
				$job['geometricError'] = $this->meshError($job);
				$job['mesh'] = new GpuPatch($job['bytes'], $this->indices);
				unset($job['bytes']);
				$job['centerHeight'] = $job['heights'][16 * 33 + 16];
				$job['lastUse'] = $this->clock;
				$this->patches[$id] = $job;

				if($id === '0:0:0') $this->uploadHeightMap();

				unset($this->jobs[$id], $job);
				$this->changed = true;
				$this->select($camera);
				break; // A GL upload is a separate bounded unit; never upload several in one frame.
			}

			unset($job);
		}

		$this->lastUpdateMs = (microtime(true) - $started) * 1000;

		foreach($this->draw as $n)
		if($this->morph($n) > 0 || isset($this->retiring[$this->parentKey($n)])
			|| $this->clock - ($this->activated[$n['key']] ?? -1) < .5
		) $this->changed = true;
		$this->evict();

		return $this->changed;
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

		$result = $this->lod->select(
			$camera->position
			, $this->patches
			, $this->quality
			, $this->viewportHeight / (2 * tan($camera->fov / 2))
		);

		$old = array_column($this->draw, 'key');
		$desired = $this->balance($result['draw']);

		while(count($this->ancestors($desired)) > $this->capacity())
		$desired = $this->balance($this->coarsen($desired));
		$this->transition($desired);

		while(count($this->ancestors($this->draw)) > $this->capacity())
		{
			$this->draw = $this->balance($this->coarsen($this->draw));
			$this->retiring = [];
		}

		$actualKeys = array_column($this->draw, 'key');
		$desiredKeys = array_column($desired, 'key');
		sort($actualKeys);
		sort($desiredKeys);
		$this->selectionPending = $actualKeys !== $desiredKeys;

		foreach($this->draw as $n)
		{
			$this->patches[$n['key']]['lastUse'] = $this->clock;
		}

		if($old !== array_column($this->draw, 'key')) $this->changed = true;

		$this->reserved = $this->ancestors($this->draw);
		$drawKeys = array_column($this->draw, null, 'key');
		$wanted = [];
		$requests = array_column($result['request'], null, 'key');

		// Retain ready siblings while their shared morph/neighbor transition is pending.
		// Otherwise reserving the next requests can evict a completed group before it draws.
		foreach($result['refine'] as $node)
		if(isset($drawKeys[$node['key']]))
		foreach($this->children($node) as $child)
		$requests[$child['key']] = $child + ['priority' => $node['priority']];

		foreach($this->requestGroups(array_values($requests)) as $parent => $nodes)
		{
			if($parent !== '' && !isset($drawKeys[$parent])) continue;

			$blocked = false;

			if($parent !== '')
			foreach($this->draw as $neighbor)
			if($neighbor['level'] < $drawKeys[$parent]['level'] && self::meets($drawKeys[$parent], $neighbor))
			{
				$blocked = true;
				break;
			}

			if($blocked) continue;

			$keys = $this->reserved;

			if($parent === '') $keys['0:0:0'] = true;
			else foreach($this->children($drawKeys[$parent]) as $child) $keys[$child['key']] = true;

			if(count($keys) > $this->capacity()) continue;

			$this->reserved = $keys;

			foreach($nodes as $n)
			if(!isset($this->patches[$n['key']])) $wanted[$n['key']] = $n + ['speculative' => false];
		}

		// Prediction reserves only unused cache space after all current-view demand.
		// Midpoint plus endpoint cover the short swept corridor, without speculative draws.
		if($this->lookahead !== null)
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

			foreach($this->requestGroups(array_values($requests)) as $parent => $nodes)
			{
				if($parent === '' || !isset($this->patches[$parent])) continue;

				$keys = $this->ancestors($this->children($this->patches[$parent]));
				$extra = count(array_diff_key($keys, $this->reserved));

				if($speculative + $extra > 32 || count($this->reserved + $keys) > $this->capacity()
					|| count($this->patches) + count($wanted) + count($nodes) > $this->capacity()
				) continue;

				$speculative += $extra;
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
		$this->evict();
		$this->selectionTime = $this->clock;
	}

	/**
	 * Keep siblings together and prioritize groups by projected current-view demand.
	 * @param array $requests Missing nodes with parent-group priority from the selector.
	 * @return array Missing sibling groups ordered from greatest to least importance.
	 */
	private function requestGroups(array $requests): array
	{
		$groups = [];

		foreach($requests as $node) $groups[$this->parentKey($node)][] = $node;

		uasort($groups, /** Compare complete sibling-group refinement priorities.
		 * @param array $a First group of missing siblings.
		 * @param array $b Second group of missing siblings.
		 * @return int Descending importance order.
		 */
			static fn($a, $b) => $b[0]['priority'] <=> $a[0]['priority']
		);

		return $groups;
	}

	/**
	 * Coarsen a complete cover until all touching leaves differ by at most one level.
	 * @param array $nodes Complete list of quadtree leaf patches.
	 * @return array Complete cover satisfying edge and corner balance.
	 */
	private function balance(array $nodes): array
	{
		// Coarsen, never request new data, until shared edges differ by at most one level.
		for($pass = count($nodes); $pass > 0; $pass--)
		{
			$replace = null;

			foreach($nodes as $a)
			foreach($nodes as $b)
			if($a['level'] > $b['level'] + 1 && self::meets($a, $b))
			{
				$level = $b['level'] + 1;
				$factor = 2 ** ($a['level'] - $level);
				$replace = $this->lod->node($level, (int)floor($a['ix'] / $factor), (int)floor($a['iz'] / $factor));
				break 2;
			}

			if($replace === null) break;

			$nodes = array_values(array_filter($nodes, /** Keep patches outside the replacement subtree.
			 * @param array $n Candidate leaf patch.
			 * @return bool Whether to retain this leaf.
			 */
				static fn($n) => !(
					$n['x'] >= $replace['x'] && $n['z'] >= $replace['z']
					&& $n['x'] + $n['size'] <= $replace['x'] + $replace['size']
					&& $n['z'] + $n['size'] <= $replace['z'] + $replace['size']
				)
			));

			$nodes[] = $replace;
		}

		return $nodes;
	}

	/**
	 * Test whether two patches share an edge of positive length.
	 * @param array $a First quadtree patch.
	 * @param array $b Second quadtree patch.
	 * @return bool Whether a nonzero shared edge exists.
	 */
	private static function touches(array $a, array $b): bool
	{
		$xOverlap = min($a['x'] + $a['size'], $b['x'] + $b['size']) > max($a['x'], $b['x']);
		$zOverlap = min($a['z'] + $a['size'], $b['z'] + $b['size']) > max($a['z'], $b['z']);

		return ($xOverlap && ($a['z'] + $a['size'] === $b['z'] || $b['z'] + $b['size'] === $a['z']))
			|| ($zOverlap && ($a['x'] + $a['size'] === $b['x'] || $b['x'] + $b['size'] === $a['x']));
	}

	/**
	 * Include corner contacts when balancing and coordinating boundary transitions.
	 * @param array $a First quadtree patch.
	 * @param array $b Second quadtree patch.
	 * @return bool Whether patch boundaries meet at an edge or corner.
	 */
	private static function meets(array $a, array $b): bool
	{
		return $a['key'] !== $b['key']
			&& min($a['x'] + $a['size'], $b['x'] + $b['size']) >= max($a['x'], $b['x'])
			&& min($a['z'] + $a['size'], $b['z'] + $b['size']) >= max($a['z'], $b['z']);
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
	 * Describe all direct siblings together so coverage never contains a partial split.
	 * @param array $node Quadtree patch metadata.
	 * @return array Four direct children in stable spatial order.
	 */
	private function children(array $node): array
	{
		$children = [];

		for($z = 0; $z < 2; $z++)
		for($x = 0; $x < 2; $x++)
		$children[] = $this->lod->node($node['level'] + 1, $node['ix'] * 2 + $x, $node['iz'] * 2 + $z);

		return $children;
	}

	/**
	 * Return every resident ancestor needed to preserve fallback coverage and parent heights.
	 * @param array $nodes Complete list of quadtree leaf patches.
	 * @return array Set of keys needed for coverage and parent interpolation.
	 */
	private function ancestors(array $nodes): array
	{
		$result = [];

		foreach($nodes as $node)
		for($level = $node['level']; $level >= 0; $level--)
		{
			$factor = 2 ** ($node['level'] - $level);
			$result[$level . ':' . (int)floor($node['ix'] / $factor) . ':' . (int)floor($node['iz'] / $factor)] = true;
		}

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

		$nodes = array_values(array_filter($nodes, /** Retain leaves outside the coarsened parent.
		 * @param array $node Candidate leaf.
		 * @return bool Whether the leaf survives.
		 */
			static fn($node) => !self::contains($parent, $node)
		));

		$nodes[] = $parent;
		$this->activated[$parent['key']] = $this->clock - .35;

		return $nodes;
	}

	/**
	 * Test complete spatial containment without floating-point distance heuristics.
	 * @param array $outer Potential containing patch.
	 * @param array $inner Potential contained patch.
	 * @return bool Whether the outer patch contains the entire inner patch.
	 */
	private static function contains(array $outer, array $inner): bool
	{
		return $inner['x'] >= $outer['x'] && $inner['z'] >= $outer['z']
			&& $inner['x'] + $inner['size'] <= $outer['x'] + $outer['size']
			&& $inner['z'] + $inner['size'] <= $outer['z'] + $outer['size'];
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

		foreach(array_values($nodes) as $node)
		{
			if($node['level'] === 0) continue;

			$key = $this->parentKey($node);

			if(isset($this->retiring[$key])) continue;

			$parent = $this->lod->node($node['level'] - 1, intdiv($node['ix'], 2), intdiv($node['iz'], 2));
			$wanted = false;

			foreach($desired as $target)
			if(self::contains($target, $parent))
			{
				$wanted = true;
				break;
			}

			if(!$wanted) continue;

			$children = $this->children($parent);
			$ready = true;

			foreach($children as $child)
			if(!isset($nodes[$child['key']]) || $this->morph($child) > 0)
			{
				$ready = false;
				break;
			}

			if(!$ready) continue;

			foreach($nodes as $neighbor)
			if(!self::contains($parent, $neighbor) && self::meets($parent, $neighbor) && $neighbor['level'] > $parent[
				'level'
			] + 1
			){
				$ready = false;
				break;
			}

			if($ready) $this->retiring[$key] = ['parent' => $parent, 'children' => $children, 'start' => $this->clock];
		}

		foreach(array_values($nodes) as $node)
		{
			if($this->morph($node) > 0 || isset($this->retiring[$this->parentKey($node)])) continue;

			$wanted = false;

			foreach($desired as $target)
			if($target['level'] > $node['level'] && self::contains($node, $target))
			{
				$wanted = true;
				break;
			}

			if(!$wanted) continue;

			$children = $this->children($node);
			$ready = true;

			foreach($children as $child)
			if(!isset($this->patches[$child['key']]))
			{
				$ready = false;
				break;
			}

			if(!$ready) continue;

			foreach($nodes as $neighbor)
			if(self::meets($node, $neighbor) && ($neighbor['level'] < $node['level']
				|| ($neighbor['level'] === $node['level'] && ($this->morph(
					$neighbor
				) > 0 || isset($this->retiring[$this->parentKey($neighbor)])))
			)){
				$ready = false;
				break;
			}

			if(!$ready) continue;

			unset($nodes[$node['key']]);

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
		$parent = $this->parentKey($node);

		if(isset($this->retiring[$parent]))
		return min(1, max(0, ($this->clock - $this->retiring[$parent]['start']) / .35));

		return min(1, max(0, 1 - ($this->clock - ($this->activated[$node['key']] ?? ($this->clock - .35))) / .35));
	}

	/**
	 * Coordinate edge and corner weights, including neighbors activated on earlier frames.
	 * @param array $node Quadtree patch metadata.
	 * @return array Shared edge and corner blend-weight vectors.
	 */
	private function boundaryMorph(array $node): array
	{
		$own = $this->morph($node);
		$edges = $corners = [$own,$own,$own,$own];
		$points = [
			[$node['x'],$node['z']],[$node['x'] + $node['size'],$node['z']]
			, [$node['x'],$node['z'] + $node['size']]
			, [$node['x'] + $node['size'],$node['z'] + $node['size']]
		];

		foreach($this->draw as $neighbor)
		{
			if(!self::meets($node, $neighbor) || $neighbor['level'] > $node['level']) continue;

			$weight = $neighbor['level'] < $node['level'] ? 1.0 : $this->morph($neighbor);

			if(self::touches($node, $neighbor))
			{
				if($neighbor['x'] + $neighbor['size'] === $node['x']) $edges[0] = max($edges[0], $weight);

				if($node['x'] + $node['size'] === $neighbor['x']) $edges[1] = max($edges[1], $weight);

				if($neighbor['z'] + $neighbor['size'] === $node['z']) $edges[2] = max($edges[2], $weight);

				if($node['z'] + $node['size'] === $neighbor['z']) $edges[3] = max($edges[3], $weight);
			}

			foreach($points as $i => [$x,$z])
			if($x >= $neighbor['x'] && $x <= $neighbor['x'] + $neighbor['size']
				&& $z >= $neighbor['z'] && $z <= $neighbor['z'] + $neighbor['size']
			)
			$corners[$i] = max($corners[$i], $weight);
		}

		return [$edges,$corners];
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
	 * @return void Samples one mesh/halo node or packs one eleven-float vertex with its parent data.
	 */
	private function buildVertex(array &$job, ?Landscape $surface = null, ?array $parents = null): void
	{
		$source = $surface ?? $this->landscape;
		$stride = Lod::CELLS + 1;
		$vertices = $stride * $stride;
		$sample = $job['sampleIndex'] ?? 0;
		$step = $job['size'] / Lod::CELLS;
		$half = $source->recipe()['worldSize'] / 2;

		// Pass one samples only this mesh lattice and a shared one-node normal halo.
		// A single call performs one height query, never a complete patch allocation.
		if($sample < $vertices + 4 * $stride)
		{
			$job['sampleIndex'] = $sample + 1;

			if($sample < $vertices)
			{
				$x = $sample % $stride;
				$z = intdiv($sample, $stride);
				$job['heights'][] = $source->height($job['x'] + $x * $step, $job['z'] + $z * $step);
			}
			else
			{
				$side = intdiv($sample - $vertices, $stride);
				$offset = ($sample - $vertices) % $stride;
				[$x,$z] = match($side)
				{
					0 => [-1,$offset], 1 => [$stride,$offset], 2 => [$offset,-1], 3 => [$offset,$stride]
				};

				$job['halo'][$side][$offset] = $source->height(
					max(-$half, min($half, $job['x'] + $x * $step))
					, max(-$half, min($half, $job['z'] + $z * $step))
				);
			}

			return;
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
		$job['normals'] = ($job['normals'] ?? '') . pack('f*', ...$normal);
		$parent = $h;
		$parentNormal = $normal;

		if($job['level'] > 0)
		{
			$key = ($job['level'] - 1) . ':' . intdiv($job['ix'], 2) . ':' . intdiv($job['iz'], 2);
			$parentPatch = ($parents ?? $this->patches)[$key] ?? null;
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
							unpack('f3', substr($parentPatch['normals'], $vertex * 12, 12))
						);

						for($axis = 0; $axis < 3; $axis++)
						$parentNormal[$axis] += $weights[$index] * $parentVertexNormal[$axis];
					}
				}
			}
		}

		$material = array_search($source->material($wx, $wz), ['rock','grass','sand','snow','road'], true);
		$job['geometricError'] = max($job['geometricError'] ?? 0.0, abs($h - $parent));

		$job['bytes'] .= pack('f*', ...[
			$x * $step
			, $h
			, $z * $step
			, ...$normal
			, $parent
			, $material === false ? -1 : $material, ...$parentNormal
		]);

		if($job['index'] === $vertices) unset($job['halo']);
	}

	/**
	 * Estimate mesh deviation from sampled curvature; this does not certify unsampled features.
	 * @param array $patch Completed patch samples and accumulated parent deviation.
	 * @return float Sampled geometric-error estimate in world metres.
	 */
	private function meshError(array $patch): float
	{
		$height = $patch['heights'];
		$error = $patch['geometricError'] ?? 0.0;

		for($z = 1; $z < 32; $z++)
		for($x = 1; $x < 32; $x++)
		{
			$i = $z * 33 + $x;
			$axial = abs($height[$i - 1] - 2 * $height[$i] + $height[$i + 1])
				+ abs($height[$i - 33] - 2 * $height[$i] + $height[$i + 33]);

			$mixed = abs($height[$i] - $height[$i + 1] - $height[$i + 33] + $height[$i + 34]);
			$error = max($error, .5 * $axial + $mixed);
		}

		return $error;
	}

	/**
	 * Release unreserved mesh resources while honoring the shared generation capacity.
	 * @param bool $allUnreserved Whether to evict all unreserved patches immediately.
	 * @return void Releases every permitted excess patch.
	 */
	private function evict(bool $allUnreserved = false): void
	{
		$limit = $this->capacity() - count($this->jobs) - count($this->replacementJobs) - count($this->replacements);

		if(!$allUnreserved && count($this->patches) <= $limit) return;

		uasort($this->patches, /** Order unreserved resources by last use.
		 * @param array $a First retained patch.
		 * @param array $b Second retained patch.
		 * @return int Least-recently-used ordering.
		 */
			static fn($a, $b) => $a['lastUse'] <=> $b['lastUse']
		);

		foreach($this->patches as $key => $p)
		{
			if(!$allUnreserved && count($this->patches) <= $limit) break;

			if(isset($this->reserved[$key])) continue;

			$p['mesh']->close();
			unset($this->patches[$key], $this->activated[$key]);
		}
	}

	/**
	 * Draw the sky, terrain and physical wave surface into the caller's framebuffer.
	 * @param Camera $camera Current viewing camera.
	 * @param int $width Viewport width in pixels.
	 * @param int $height Viewport height in pixels.
	 * @param float $time Interpolated simulation time in seconds.
	 * @param array|null $craftState Optional demonstration hull pose.
	 * @return void Submits draws without presenting the caller-owned framebuffer.
	 */
	public function render(Camera $camera, int $width, int $height, float $time, ?array $craftState = null): void
	{
		if($this->closed) return;

		$this->viewportHeight = max(1, $height);
		$this->frames++;
		$this->atmosphereCache->update($camera->position[1], $this->textures[0], $this->water->level());

		$this->terrainShadows->update(
			isset($this->patches['0:0:0']) ? $this->heightTexture[0] : 0
			, $this->lod->extent
			, $this->atmosphere->uniforms()['sunDirection']
			, $this->heightTextureRevision
		);

		glViewport(0, 0, $width, $height);
		glDisable(GL_SCISSOR_TEST);
		glDisable(GL_BLEND);
		glDisable(GL_CULL_FACE);
		glDisable(GL_DEPTH_TEST);
		glDepthMask(false);
		$this->skyProgram->use();
		$this->common($this->skyProgram, $camera, $width, $height);
		glBindVertexArray($this->vao[0]);
		glDrawArrays(GL_TRIANGLES, 0, 3);
		glDepthMask(true);
		glEnable(GL_DEPTH_TEST);
		glEnable(GL_CULL_FACE);
		$this->groundProgram->use();
		$this->common($this->groundProgram, $camera, $width, $height);
		$this->vector($this->groundProgram, 'uCraftRotation', [0,0,0]);
		$this->scalar($this->groundProgram, 'uCraft', 0);

		foreach($this->draw as $n)
		{
			$p = $this->patches[$n['key']];

			$this->vector(
				$this->groundProgram
				, 'uOrigin'
				, [$n['x'] - $camera->position[0],-$camera->position[1],$n['z'] - $camera->position[2]]
			);

			$this->scalar($this->groundProgram, 'uSize', $n['size']);
			$this->scalar($this->groundProgram, 'uMorph', $this->morph($n));
			[$edges,$corners] = $this->boundaryMorph($n);
			glUniform4f($this->groundProgram->location('uEdges'), ...$edges);
			glUniform4f($this->groundProgram->location('uCorners'), ...$corners);
			$p['mesh']->draw();
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
		$this->waterProgram->use();
		$this->common($this->waterProgram, $camera, $width, $height);
		$this->waterUniforms($time);
		$this->scalar($this->waterProgram, 'uWaterExtent', $this->lod->extent);

		foreach($this->draw as $n)
		{
			$this->vector(
				$this->waterProgram
				, 'uOrigin'
				, [$n['x'] - $camera->position[0],-$camera->position[1],$n['z'] - $camera->position[2]]
			);

			$this->patches[$n['key']]['mesh']->draw();
		}

		glBindVertexArray(0);
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
		glUniformMatrix4fv($program->location('uViewProjection'), 1, false, $camera->viewProjection($width, $height));
		$this->vector($program, 'uEye', $camera->position);
		[$forward,$right,$up] = $camera->basis();
		$this->vector($program, 'uForward', $forward);
		$this->vector($program, 'uRight', $right);
		$this->vector($program, 'uUp', $up);
		glUniform2f($program->location('uScreen'), $width / max(1, $height), tan($camera->fov / 2));
		$environment = $this->atmosphere->uniforms();

		foreach($environment as $name => $value)
		{
			$key = str_starts_with($name, 'u') ? $name : 'u' . ucfirst($name);

			if(is_array($value) && count($value) === 3) $this->vector($program, $key, $value);
			elseif(is_array($value) && count($value) === 2) glUniform2f($program->location($key), ...$value);
			elseif(is_numeric($value)) $this->scalar($program, $key, $value);
		}

		$this->scalar($program, 'uWaterLevel', $this->water->uniforms()['level']);

		$this->scalar($program, 'uTerrainStyle', match($this->landscape->recipe()['preset'])
		{
			'canyon' => 2, 'mountain' => 1, default => 0
		});

		$this->scalar($program, 'uWorldSize', $this->lod->extent);
		glActiveTexture(GL_TEXTURE0);
		glBindTexture(GL_TEXTURE_2D, $this->textures[0]);
		glUniform1i($program->location('uTransmittance'), 0);
		glActiveTexture(GL_TEXTURE1);
		glBindTexture(GL_TEXTURE_2D, $this->heightTexture[0]);
		glUniform1i($program->location('uTerrainHeights'), 1);
		$this->scalar($program, 'uHasHeightMap', isset($this->patches['0:0:0']) ? 1 : 0);
		$this->atmosphereCache->bind($program);
		$this->terrainShadows->bind($program);
	}

	/**
	 * Upload bounded analytic waves and wakes for the requested simulation time.
	 * @param float $time Interpolated simulation time in seconds.
	 * @return void Updates water program uniforms.
	 */
	private function waterUniforms(float $time): void
	{
		$this->water->update($time);
		$data = $this->water->uniforms();
		$this->scalar($this->waterProgram, 'uTime', $time);
		$program = $this->waterProgram;
		$this->scalar($program, 'uWaterTime', $time);
		glUniform1i($program->location('uWaveCount'), $data['waveCount']);
		glUniform1i($program->location('uWakeCount'), $data['wakeCount']);

		foreach($data['waveVectors'] as $i => $wave) glUniform4f($program->location("uWaveVectors[$i]"), ...$wave);

		foreach($data['waveDynamics'] as $i => $d) glUniform2f($program->location("uWaveDynamics[$i]"), ...$d);

		foreach($data['wakeVectors'] as $i => $w) glUniform4f($program->location("uWakeVectors[$i]"), ...$w);
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
	 * Whether preparation or LOD animation still requires frames.
	 * @return bool Whether another frame or preparation step is required.
	 */
	public function dirty(): bool
	{
		return $this->changed || $this->jobs !== [] || $this->preparing !== null || $this->selectionPending;
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
			, 'visible' => count($this->draw)
			, 'pending' => count($this->jobs)
			, 'pendingEdits' => count($this->replacementJobs)
			, 'replacementPatches' => count($this->replacements)
			, 'reservedPatches' => count($this->patches) + count($this->jobs) + count($this->replacementJobs) + count(
				$this->replacements
			)
			, 'maximumPatches' => $this->capacity()
			, 'transitions' => count($this->retiring)
			, 'meshBytes' => array_sum(array_map(
				/** Count retained packed mesh bytes.
			 * @param array $p Resident or staged patch.
			 * @return int CPU mesh payload bytes.
			 */
				static fn($p) => $p['mesh']->bytes()
				, [...array_values($this->patches), ...array_values($this->replacements)]
			))
			, 'generationMs' => $this->lastUpdateMs
			, 'frames' => $this->frames
			, 'selections' => $this->selections
			, 'selectionPending' => $this->selectionPending
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
		$this->waterProgram->restore();

		foreach($this->patches as $p) $p['mesh']->restore();
		$this->craftMesh->restore();
		$this->atmosphereCache->restore();
		$this->terrainShadows->restore();
		$this->textures = $this->heightTexture = [];
		$this->createTextures();
		glGenTextures(1, $this->heightTexture);
		$this->uploadHeightMap();
		glGenVertexArrays(1, $this->vao);
		$this->changed = true;

		foreach($this->replacements as $p) $p['mesh']->restore();
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
		$this->selectionPending = false;
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
		$this->groundProgram->close();
		$this->skyProgram->close();
		$this->waterProgram->close();
		$this->craftMesh?->close();
		$this->atmosphereCache->close();
		$this->terrainShadows->close();

		if($this->textures !== []) glDeleteTextures(count($this->textures), $this->textures);

		if($this->heightTexture !== []) glDeleteTextures(count($this->heightTexture), $this->heightTexture);

		if($this->vao !== []) glDeleteVertexArrays(1, $this->vao);

		$this->textures = $this->heightTexture = $this->vao = [];
	}
}

<?php
declare(strict_types=1);
namespace TerrainPhonics;

/** Optional render-only sibling preparation in a dedicated browser worker. */
final class BrowserPatchBuilder
{
	private ?Landscape $source = null;
	private int $revision = -1;
	private bool $available = true;
	/** @var resource|null A temporary file in this runtime’s in-memory filesystem. */
	private $transfer = null;
	private string $path = '';

	/**
	 * Connect the browser module's synchronous command bridge to this scene.
	 * @param \Closure $bridge Accepts an action, transfer path, patch key, and payload.
	 * @return void Stores the bridge until the first mesh request.
	 */
	public function __construct(private \Closure $bridge) {}

	/**
	 * Configure the worker for the current landscape and inspect the sample footprint.
	 * @param Landscape $source Current committed or staged terrain.
	 * @param array $job Quadtree patch descriptor.
	 * @return bool Whether this job can run on the worker.
	 */
	private function eligible(Landscape $source, array $job): bool
	{
		if(!$this->available || !$source->proceduralMeshPatch($job['x'], $job['z'], $job['size'])) return false;

		if($this->source !== $source || $this->revision !== $source->revision())
		{
			if($this->transfer === null)
			{
				$file = tmpfile();
				if($file === false)
				{
					$this->available = false;
					return false;
				}
				$this->transfer = $file;
				$this->path = stream_get_meta_data($this->transfer)['uri'];
			}
			$recipe = $source->recipe();
			$noise = new Noise($recipe['seed']);
			$this->available = ($this->bridge)('configure', $this->path, '', json_encode([
				'recipe' => $recipe, 'permutation' => $noise->permutation(),
				'beds' => CanyonBeds::CONTACTS, 'ramps' => CanyonBeds::RAMPS, 'dip' => CanyonBeds::DIP
			], JSON_THROW_ON_ERROR)) !== 'unavailable';
			$this->source = $source;
			$this->revision = $source->revision();
		}
		return $this->available;
	}

	/**
	 * Send sibling coordinates and the parent data needed for seamless transitions.
	 * @param array $node First requested child.
	 * @param array|null $parent Resident parent mesh, or null for the root.
	 * @return string Empty while pending, or unavailable after a worker failure.
	 */
	private function request(array $node, ?array $parent): string
	{
		return (string)($this->bridge)('request', $this->path, $node['key'], json_encode([
			'node' => array_intersect_key($node, array_flip(['key','level','ix','iz','size','x','z'])),
			'parent' => $parent === null ? null : base64_encode(pack('e*', ...$parent['heights']) . $parent['normals']),
		], JSON_THROW_ON_ERROR));
	}

	/**
	 * Start a sibling group early so generation can continue between display frames.
	 * @param Landscape $source Current terrain.
	 * @param array $job One patch in the requested group.
	 * @param array|null $parent Resident parent mesh.
	 * @return void Queues the group once.
	 */
	public function prime(Landscape $source, array $job, ?array $parent): void
	{
		if(!$this->eligible($source, $job)) return;
		if(($this->bridge)('check', $this->path, $job['key'], '') === 'request') $this->request($job, $parent);
	}

	/**
	 * Poll for a finished mesh and copy its packed data to the render job.
	 * @param Landscape $source Current terrain.
	 * @param array $job Mutable generation record, completed when all vertices are present.
	 * @param array|null $parent Resident parent mesh.
	 * @return bool True for a pending or completed worker job, false to use the PHP sampler.
	 */
	public function advance(Landscape $source, array &$job, ?array $parent): bool
	{
		if(!$this->eligible($source, $job)) return false;
		$reply = (string)($this->bridge)('take', $this->path, $job['key'], '');
		if($reply === 'request') $reply = $this->request($job, $parent);
		if($reply === '') return true;


		$count = (Lod::CELLS + 1) ** 2;
		// The bridge fills one reusable MEMFS file synchronously. Binary vertices
		// never travel through the PHP/JavaScript string conversion.
		$bytes = $reply === 'ready' ? file_get_contents($this->path) : false;
		if($bytes === false || strlen($bytes) !== 24 + $count * 64)
		{
			$this->available = false;
			$this->close();
			return false;
		}

		foreach(unpack('eminY/emaxY/egeometricError', $bytes) as $name => $value) $job[$name] = $value;
		$job['heights'] = array_values(unpack("e$count", $bytes, 24));
		$job['normals'] = substr($bytes, 24 + $count*8, $count*12);
		$job['bytes'] = substr($bytes, 24 + $count*20);
		$job['index'] = $count;
		return true;
	}

	/**
	 * Release the worker and transfer file owned by this scene.
	 * @return void Drops buffered results and the landscape reference.
	 */
	public function close(): void
	{
		if(is_resource($this->transfer))
		{
			($this->bridge)('close', $this->path, '', '');
			fclose($this->transfer);
		}
		$this->transfer = null;
		$this->source = null;
	}
}

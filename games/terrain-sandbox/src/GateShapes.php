<?php
declare(strict_types=1);
namespace TerrainSandbox;

/**
 * Dimensions of the three race gate kinds, read from assets/gates/gates.json when the package ships it.
 *
 * Each kind is measured in the gate's own frame: the origin sits at the bottom centre of the window (on the ground
 * for road, at the water line for water, at the window's lower edge for air), +Y is up, +Z is the direction of
 * travel and +X lies across the gate. Every figure is in metres.
 */
final class GateShapes
{
	public const KINDS = ['road', 'water', 'air'];
	/** Prints the meshes carry, one per role, plus the print of a start gate that also ends the race. */
	public const PRINTS = ['start', 'checkpoint', 'finish', 'start-finish'];
	/**
	 * Built-in shapes, used when the package has no gates.json. They follow the file's layout and carry the
	 * provisional numbers agreed before the meshes were measured.
	 */
	public const DEFAULTS = [
		'version' => 1,
		'roles' => self::PRINTS,
		'kinds' => [
			'road' => ['mesh' => 'road.mesh.bin.gz', 'window' => ['halfWidth' => 6.76, 'bottom' => 0, 'top' => 2.94],
				'posts' => [['x' => -7.16, 'z' => 0, 'halfSize' => .4], ['x' => 7.16, 'z' => 0, 'halfSize' => .4]],
				'extent' => ['halfWidth' => 7.58, 'top' => 4.14]],
			'water' => ['mesh' => 'water.mesh.bin.gz', 'window' => ['halfWidth' => 4.2, 'bottom' => 0, 'top' => 5.5],
				'posts' => [], 'extent' => ['halfWidth' => 6, 'top' => 7.8]],
			'air' => ['mesh' => 'air.mesh.bin.gz', 'window' => ['halfWidth' => 22.0, 'bottom' => 0, 'top' => 30.0],
				'posts' => [], 'extent' => ['halfWidth' => 22.8, 'top' => 33.6]],
		],
		'atlas' => ['roles' => ['start' => [0, 0], 'checkpoint' => [0, .25], 'finish' => [0, .5],
			'start-finish' => [0, .75]]],
	];
	/**
	 * How far below the window's bottom edge a vehicle still counts as passing through it. A car on ground that
	 * slopes across a road gate runs below the gate's origin on the downhill side, where the posts reach 8 m into
	 * the ground. A jet ski in a trough sits below the water line at the gate's centre. A jet has no such excuse.
	 */
	public const BELOW = ['road' => 8.0, 'water' => 3.0, 'air' => 0.0];
	/** Default depth of a road post's foot below the gate origin. */
	public const POST_DEPTH = 8.0;
	/** Shapes by kind: mesh file name, window, extent and posts with their vertical reach. */
	public readonly array $kinds;
	/**
	 * Texture offset [u, v] that selects each print, keyed by print name, or null when gates.json lays its prints out
	 * some other way. Only the renderer uses it, so another layout is not an error.
	 */
	public readonly ?array $atlas;
	/** The whole document the shapes came from, for fields only the renderer reads. */
	public readonly array $document;
	/** Whether the shapes came from gates.json rather than the built-in defaults. */
	public readonly bool $fromFile;
	private static ?self $packaged = null;

	private function __construct(array $data, bool $fromFile)
	{
		$kinds = [];
		$kindData = $data['kinds'] ?? null;
		if(!is_array($kindData)) throw new \RuntimeException('Gate shapes need a kinds object');
		foreach(self::KINDS as $kind) $kinds[$kind] = self::readKind($kind, $kindData[$kind] ?? null);
		$atlas = $data['atlas']['roles'] ?? null;
		$offsets = is_array($atlas) ? [] : null;
		foreach(self::PRINTS as $print)
		{
			$offset = $atlas[$print] ?? null;
			if(!is_array($offset) || !array_is_list($offset) || count($offset) !== 2 || !self::number($offset[0])
				|| !self::number($offset[1])) { $offsets = null; break; }
			$offsets[$print] = [(float)$offset[0], (float)$offset[1]];
		}
		$this->kinds = $kinds;
		$this->atlas = $offsets;
		$this->document = $data;
		$this->fromFile = $fromFile;
	}

	/**
	 * Read the shapes the package ships, once per process.
	 * @return self Shapes from assets/gates/gates.json, or the built-in defaults when the file is absent.
	 * @throws \RuntimeException When the file exists but does not describe all three kinds.
	 */
	public static function packaged(): self
	{
		return self::$packaged ??= self::load(dirname(__DIR__) . '/assets/gates/gates.json');
	}

	/**
	 * Read shapes from one file.
	 * @param string $file Path to a gates.json document.
	 * @return self The file's shapes, or the built-in defaults when no file exists at that path.
	 * @throws \RuntimeException When the file is not valid JSON or a shape is missing or out of range.
	 */
	public static function load(string $file): self
	{
		if(!is_file($file)) return new self(self::DEFAULTS, false);
		$data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
		if(!is_array($data) || ($data['version'] ?? null) !== 1)
			throw new \RuntimeException('Unsupported gates.json version');
		return new self($data, true);
	}

	/** Build the shapes from the built-in defaults alone. */
	public static function defaults(): self { return new self(self::DEFAULTS, false); }

	/**
	 * Validate one kind and add the vertical reach of each post.
	 * @param string $kind Kind name, for messages.
	 * @param mixed $data The kind's object from the file.
	 * @return array Mesh name, window, extent, posts and the crossing allowance below the window.
	 */
	private static function readKind(string $kind, mixed $data): array
	{
		if(!is_array($data)) throw new \RuntimeException("Gate shapes lack the $kind kind");
		$window = $data['window'] ?? null;
		$extent = $data['extent'] ?? null;
		if(!is_array($window) || !self::number($window['halfWidth'] ?? null) || !self::number($window['bottom'] ?? null)
			|| !self::number($window['top'] ?? null) || $window['halfWidth'] <= 0
			|| $window['top'] <= $window['bottom'])
			throw new \RuntimeException("The $kind gate window needs a positive half width and a top above its bottom");
		if(!is_array($extent) || !self::number($extent['halfWidth'] ?? null) || !self::number($extent['top'] ?? null)
			|| $extent['halfWidth'] < $window['halfWidth'] || $extent['top'] < $window['top'])
			throw new \RuntimeException("The $kind gate extent must enclose its window");
		$mesh = $data['mesh'] ?? self::DEFAULTS['kinds'][$kind]['mesh'];
		if(!is_string($mesh) || $mesh === '' || basename($mesh) !== $mesh)
			throw new \RuntimeException("The $kind gate mesh must be a file name beside gates.json");
		$posts = $data['posts'] ?? [];
		if(!is_array($posts) || !array_is_list($posts) || count($posts) > 8)
			throw new \RuntimeException("The $kind gate posts must be a list of at most eight");
		$solid = [];
		foreach($posts as $post)
		{
			if(!is_array($post) || !self::number($post['x'] ?? null) || !self::number($post['z'] ?? null)
				|| !self::number($post['halfSize'] ?? null) || $post['halfSize'] <= 0)
				throw new \RuntimeException("Each $kind gate post needs x, z and a positive halfSize");
			$bottom = $post['bottom'] ?? -self::POST_DEPTH;
			$top = $post['top'] ?? $extent['top'];
			if(!self::number($bottom) || !self::number($top) || $top <= $bottom)
				throw new \RuntimeException("A $kind gate post must end above its foot");
			$solid[] = ['x' => (float)$post['x'], 'z' => (float)$post['z'], 'halfSize' => (float)$post['halfSize'],
				'bottom' => (float)$bottom, 'top' => (float)$top];
		}
		return ['mesh' => $mesh,
			'window' => ['halfWidth' => (float)$window['halfWidth'], 'bottom' => (float)$window['bottom'],
				'top' => (float)$window['top']],
			'extent' => ['halfWidth' => (float)$extent['halfWidth'], 'top' => (float)$extent['top']],
			'posts' => $solid, 'below' => self::BELOW[$kind]];
	}

	private static function number(mixed $value): bool
	{
		return (is_int($value) || is_float($value)) && is_finite((float)$value);
	}

	/**
	 * Return one kind's shape.
	 * @param string $kind road, water or air.
	 * @return array Mesh name, window, extent, posts and the crossing allowance below the window.
	 */
	public function kind(string $kind): array { return $this->kinds[$kind]; }

	/** Report the shapes in the layout of gates.json, with each post's vertical reach filled in. */
	public function export(): array
	{
		$kinds = [];
		foreach($this->kinds as $kind => $shape) $kinds[$kind] = array_diff_key($shape, ['below' => 0]);
		return ['version' => 1, 'roles' => self::PRINTS, 'kinds' => $kinds, 'atlas' => ['roles' => $this->atlas]];
	}
}

<?php
declare(strict_types=1);
namespace TerrainSandbox;

/**
 * Dimensions of the three race gate kinds, read from assets/gates/gates.json when the package ships it.
 *
 * Each kind is measured in the gate's own frame: the origin sits at the bottom center of the window (on the ground
 * for road, at the water line for water, at the window's lower edge for air), +Y is up, +Z is the direction of
 * travel and +X lies across the gate. Every figure is in meters.
 */
final class GateShapes
{
	public const KINDS = ['road', 'water', 'air'];
	/** Prints the meshes carry, one per role, plus the print of a start gate that also ends the race. */
	public const PRINTS = ['start', 'checkpoint', 'finish', 'start-finish'];
	/**
	 * Built-in shapes, used when the package has no gates.json. They follow the file's layout and repeat the
	 * measurements the gate importer wrote to it; tests/race.php fails when the two differ.
	 */
	public const DEFAULTS = [
		'version' => 1,
		'roles' => self::PRINTS,
		'kinds' => [
			'road' => ['mesh' => 'road.mesh.bin.gz', 'window' => ['halfWidth' => 6.662, 'bottom' => 0, 'top' => 3.073],
				'posts' => [['x' => -7.062, 'z' => 0, 'halfSize' => .4, 'bottom' => -8.0, 'top' => 5.132],
					['x' => 7.062, 'z' => 0, 'halfSize' => .4, 'bottom' => -8.0, 'top' => 5.132]],
				'extent' => ['halfWidth' => 7.582, 'bottom' => -8.0, 'top' => 5.275, 'halfDepth' => .53],
				'size' => ['width' => [6, 60], 'height' => [2, 40]],
				'board' => ['label' => [3.0008, 2.9774], 'column' => [.32052, .32183], 'columns' => 14, 'baked' => 88]],
			'water' => ['mesh' => 'water.mesh.bin.gz', 'window' => ['halfWidth' => 4.458, 'bottom' => 0, 'top' => 3.596],
				'posts' => [], 'extent' => ['halfWidth' => 6.0, 'bottom' => -2.7, 'top' => 7.034, 'halfDepth' => .621],
				'size' => ['width' => [4.5, 60], 'height' => [2, 40], 'stretch' => 2]],
			'air' => ['mesh' => 'air.mesh.bin.gz', 'window' => ['halfWidth' => 19.986, 'bottom' => 0, 'top' => 8.813],
				'posts' => [], 'extent' => ['halfWidth' => 22.746, 'bottom' => 0, 'top' => 12.433, 'halfDepth' => 1.591],
				'size' => ['width' => [20, 300], 'height' => [5, 150]],
				'board' => ['label' => [9.0024, 8.9322], 'column' => [.96157, .9655], 'columns' => 14, 'baked' => 150]],
		],
		'atlas' => ['roles' => ['start' => [0, 0], 'checkpoint' => [0, .25], 'finish' => [0, .5],
			'start-finish' => [0, .75]]],
	];
	/**
	 * How far below the window's bottom edge a vehicle still counts as passing through it. A jet ski in a trough sits
	 * below the water line at the gate's center. A jet has no such excuse. A road gate measures its own from the
	 * ground across it, Course::ROAD_BELOW under the lowest, so its figure here applies only on level ground.
	 */
	public const BELOW = ['road' => Course::ROAD_BELOW, 'water' => 3.0, 'air' => 0.0];
	/**
	 * Default depth of a road post's foot below the gate origin in the mesh. A placed gate sets each foot from the
	 * ground under the post, which Course::measure() reports.
	 */
	public const POST_DEPTH = 8.0;
	/** Default half depth of a gate's extent along its direction of travel, in meters. */
	public const HALF_DEPTH = 1.0;
	/**
	 * Shapes by kind: mesh file name, window, extent (with its bottom and half depth), posts with their reach, the
	 * range of sizes its opening takes and, on the road and air gates, the layout of the board's checker bands.
	 */
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
		// The renderer culls each gate by its extent's box, which also reaches below the window and along +Z.
		$extentBottom = $extent['bottom'] ?? $window['bottom'];
		$halfDepth = $extent['halfDepth'] ?? self::HALF_DEPTH;
		if(!self::number($extentBottom) || !self::number($halfDepth) || $extentBottom > $window['bottom']
			|| $halfDepth <= 0)
			throw new \RuntimeException("The $kind gate extent needs a bottom at or below its window and a positive depth");
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
		$width = 2 * (float)$window['halfWidth'];
		$height = (float)$window['top'] - (float)$window['bottom'];
		$size = $data['size'] ?? self::DEFAULTS['kinds'][$kind]['size'];
		$range = static fn(mixed $pair, float $default): bool => is_array($pair) && array_is_list($pair)
			&& count($pair) === 2 && self::number($pair[0]) && self::number($pair[1]) && $pair[0] > 0
			&& $pair[0] <= $default + 1e-9 && $pair[1] >= $default - 1e-9;
		$stretch = is_array($size) ? $size['stretch'] ?? null : null;
		if(!is_array($size) || !$range($size['width'] ?? null, $width) || !$range($size['height'] ?? null, $height)
			|| $stretch !== null && (!self::number($stretch) || $stretch < 1))
			throw new \RuntimeException("The $kind gate size needs width and height ranges that hold its window");
		// Without a board layout the board keeps its own width, and only the posts and tabs move.
		$board = $data['board'] ?? null;
		if($board !== null)
		{
			$pair = static fn(mixed $value): bool => is_array($value) && array_is_list($value) && count($value) === 2
				&& self::number($value[0]) && self::number($value[1]) && $value[0] > 0 && $value[1] > 0;
			if(!is_array($board) || !$pair($board['label'] ?? null) || !$pair($board['column'] ?? null)
				|| !is_int($board['columns'] ?? null) || !is_int($board['baked'] ?? null) || $board['columns'] < 1
				|| $board['baked'] < $board['columns'])
				throw new \RuntimeException("The $kind gate board needs its label, column widths and column counts");
			$board = ['label' => array_map('floatval', $board['label']), 'column' => array_map('floatval', $board['column']),
				'columns' => $board['columns'], 'baked' => $board['baked']];
		}
		return ['mesh' => $mesh,
			'window' => ['halfWidth' => (float)$window['halfWidth'], 'bottom' => (float)$window['bottom'],
				'top' => (float)$window['top']],
			'extent' => ['halfWidth' => (float)$extent['halfWidth'], 'bottom' => (float)$extentBottom,
				'top' => (float)$extent['top'], 'halfDepth' => (float)$halfDepth],
			'posts' => $solid, 'below' => self::BELOW[$kind],
			'size' => ['width' => array_map('floatval', $size['width']), 'height' => array_map('floatval', $size['height']),
				'default' => [$width, $height], 'stretch' => $stretch === null ? null : (float)$stretch],
			'board' => $board];
	}

	private static function number(mixed $value): bool
	{
		return (is_int($value) || is_float($value)) && is_finite((float)$value);
	}

	/**
	 * Return one kind's shape.
	 * @param string $kind road, water or air.
	 * @return array Mesh name, window, extent, posts, the crossing allowance below the window, the size range with
	 * the default size and the arch's stretch, and the board layout or null.
	 */
	public function kind(string $kind): array { return $this->kinds[$kind]; }

	/**
	 * Fit a requested opening to a kind's sizes.
	 * @param string $kind road, water or air.
	 * @param float|null $width Opening width in meters, or null for the kind's own.
	 * @param float|null $height Opening height in meters, or null for the kind's own.
	 * @param bool $pavementSpan Fit the full range of painted road widths, including skew crossings.
	 * @return array{width:float,height:float,limits:list<string>} The size within the kind's ranges, and a phrase
	 * for each limit that changed it, such as "a road gate is at most 60 m wide". A water arch also keeps its scale
	 * up within its stretch of its scale across, by changing the height.
	 */
	public function fit(string $kind, ?float $width, ?float $height, bool $pavementSpan = false): array
	{
		$size = $this->kinds[$kind]['size'];
		$name = ['road' => 'a road gate', 'water' => 'a water arch', 'air' => 'an air gate'][$kind];
		$limits = [];
		$clamp = static function(?float $value, array $range, float $default, string $wide) use (&$limits, $name): float {
			if($value === null) return $default;
			$held = max($range[0], min($range[1], $value));
			if($held !== $value)
				$limits[] = "$name is " . ($held < $value ? 'at most ' : 'at least ') . DisplayUnits::feet($held) . " ft $wide";
			return $held;
		};
		[$defaultWidth, $defaultHeight] = $size['default'];
		$widthRange = $size['width'];
		if($pavementSpan && $kind === 'road')
			$widthRange = [min($widthRange[0], CircuitRoad::MIN_GATE_SPAN), max($widthRange[1], CircuitRoad::MAX_GATE_SPAN)];
		$width = $clamp($width, $widthRange, $defaultWidth, 'wide');
		$height = $clamp($height, $size['height'], $defaultHeight, 'tall');
		if($size['stretch'] !== null)
		{
			$across = $width / $defaultWidth;
			$low = max($size['height'][0], $defaultHeight * $across / $size['stretch']);
			$high = min($size['height'][1], $defaultHeight * $across * $size['stretch']);
			$held = max($low, min($high, $height));
			if(abs($held - $height) > 1e-9)
				$limits[] = "$name " . DisplayUnits::feet($width) . ' ft wide is ' . ($held < $height ? 'at most ' : 'at least ')
					. DisplayUnits::feet($held) . ' ft tall';
			$height = $held;
		}
		return ['width' => $width, 'height' => $height, 'limits' => $limits];
	}

	/**
	 * Whether a size fits a kind exactly as fit() would leave it.
	 * @param string $kind road, water or air.
	 * @param float $width Opening width in meters.
	 * @param float $height Opening height in meters.
	 * @param bool $pavementSpan Use the circuit road span limits.
	 */
	public function fits(string $kind, float $width, float $height, bool $pavementSpan = false): bool
	{
		$fitted = $this->fit($kind, $width, $height, $pavementSpan);
		return abs($fitted['width'] - $width) < 1e-6 && abs($fitted['height'] - $height) < 1e-6;
	}

	/** Report the shapes in the layout of gates.json, with each post's vertical reach filled in. */
	public function export(): array
	{
		$kinds = [];
		foreach($this->kinds as $kind => $shape)
		{
			$kinds[$kind] = array_diff_key($shape, ['below' => 0, 'board' => 0]);
			$kinds[$kind]['size'] = array_diff_key($shape['size'], ['default' => 0]);
			if($shape['size']['stretch'] === null) unset($kinds[$kind]['size']['stretch']);
			if($shape['board'] !== null) $kinds[$kind]['board'] = $shape['board'];
		}
		return ['version' => 1, 'roles' => self::PRINTS, 'kinds' => $kinds, 'atlas' => ['roles' => $this->atlas]];
	}
}
